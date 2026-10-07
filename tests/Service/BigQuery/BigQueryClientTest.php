<?php

declare(strict_types=1);

namespace App\Tests\Service\BigQuery;

use App\Service\BigQuery\BigQueryClient;
use App\Service\BigQuery\BigQueryException;
use App\Service\BigQuery\GoogleCredentials;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BigQueryClientTest extends TestCase
{
    private const FIELDS = [['name' => 'event_hour', 'type' => 'TIMESTAMP'], ['name' => 'event_count', 'type' => 'INT64']];

    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'bq-test-');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testDatasetIsCreatedInTheConfiguredLocationOrItsLocationMustMatch(): void
    {
        $requests = [];
        $client = $this->client(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, isset($options['body']) ? json_decode($options['body'], true) : null];

            return $method === 'GET' ? new MockResponse('{"error":{"code":404,"message":"Not found"}}', ['http_code' => 404]) : new MockResponse('{}', ['http_code' => 200]);
        }, 'europe-west2');
        $client->ensureDataset('aggregate');

        self::assertSame(['GET', 'https://bigquery.googleapis.com/bigquery/v2/projects/my-project/datasets/aggregate'], array_slice($requests[0], 0, 2));
        self::assertSame(['POST', 'https://bigquery.googleapis.com/bigquery/v2/projects/my-project/datasets'], array_slice($requests[1], 0, 2));
        self::assertSame(['projectId' => 'my-project', 'datasetId' => 'aggregate'], $requests[1][2]['datasetReference']);
        self::assertSame('europe-west2', $requests[1][2]['location']);

        $elsewhere = $this->client(static fn (): MockResponse => new MockResponse('{"location":"US"}', ['http_code' => 200]), 'europe-west2');
        $this->expectException(BigQueryException::class);
        $this->expectExceptionMessage('The dataset aggregate is in US, not europe-west2.');
        $elsewhere->ensureDataset('aggregate');
    }

    public function testLoadReplacesTheTableWithAResumableUploadInChunksAndWaitsForTheJob(): void
    {
        $content = str_repeat("{\"event_hour\":\"2026-10-01 10:00:00.000000\",\"event_count\":\"7\"}\n", 10000);
        file_put_contents($this->file, $content);
        $size = strlen($content);
        $requests = [];
        $received = '';
        $polls = 0;
        $client = $this->client(static function (string $method, string $url, array $options) use (&$requests, &$received, &$polls, $size): MockResponse {
            $requests[] = [$method, $url, $options];
            if ($method === 'POST' && str_contains($url, 'uploadType=resumable')) {
                return new MockResponse('', ['http_code' => 200, 'response_headers' => ['Location' => 'https://bigquery.googleapis.com/upload/bigquery/v2/projects/my-project/jobs?uploadType=resumable&upload_id=abc']]);
            }
            if ($method === 'PUT') {
                $received .= $options['body'];

                return strlen($received) < $size
                    ? new MockResponse('', ['http_code' => 308, 'response_headers' => ['Range' => 'bytes=0-'.(strlen($received) - 1)]])
                    : new MockResponse('{"status":{"state":"RUNNING"}}', ['http_code' => 200]);
            }
            ++$polls;

            return new MockResponse(json_encode($polls < 3
                ? ['status' => ['state' => 'RUNNING']]
                : ['status' => ['state' => 'DONE'], 'statistics' => ['load' => ['outputRows' => '10000']]]), ['http_code' => 200]);
        });

        $result = $client->load('aggregate', 'bi_anonymous_events_v1', self::FIELDS, $this->file, 'Copy of a view.');

        self::assertSame(10000, $result['rows']);
        self::assertMatchesRegularExpression('/^aggregate_bi_anonymous_events_v1_[0-9]{8}_[0-9]{6}_[0-9a-f]{8}$/', $result['job_id']);
        self::assertSame($content, $received);
        [$method, $url, $options] = $requests[0];
        self::assertSame('POST', $method);
        self::assertSame('https://bigquery.googleapis.com/upload/bigquery/v2/projects/my-project/jobs?uploadType=resumable', $url);
        self::assertContains('Authorization: Bearer access-token', $options['headers']);
        self::assertContains('X-Upload-Content-Length: '.$size, $options['headers']);
        $job = json_decode($options['body'], true);
        self::assertSame(['projectId' => 'my-project', 'jobId' => $result['job_id'], 'location' => 'US'], $job['jobReference']);
        $load = $job['configuration']['load'];
        self::assertSame(['projectId' => 'my-project', 'datasetId' => 'aggregate', 'tableId' => 'bi_anonymous_events_v1'], $load['destinationTable']);
        self::assertSame('NEWLINE_DELIMITED_JSON', $load['sourceFormat']);
        self::assertSame('WRITE_TRUNCATE', $load['writeDisposition']);
        self::assertSame('CREATE_IF_NEEDED', $load['createDisposition']);
        self::assertSame([['name' => 'event_hour', 'type' => 'TIMESTAMP', 'mode' => 'NULLABLE'], ['name' => 'event_count', 'type' => 'INT64', 'mode' => 'NULLABLE']], $load['schema']['fields']);
        self::assertSame(0, $load['maxBadRecords']);

        $puts = array_values(array_filter($requests, static fn (array $request): bool => $request[0] === 'PUT'));
        self::assertCount(3, $puts);
        self::assertContains('Content-Range: bytes 0-262143/'.$size, $puts[0][2]['headers']);
        self::assertContains('Content-Range: bytes 262144-524287/'.$size, $puts[1][2]['headers']);
        self::assertContains('Content-Range: bytes 524288-'.($size - 1).'/'.$size, $puts[2][2]['headers']);
        self::assertSame(3, $polls);
        self::assertStringEndsWith('/jobs/'.$result['job_id'].'?location=US', $requests[array_key_last($requests)][1]);
    }

    public function testAnInterruptedChunkResumesFromWhatGoogleReceived(): void
    {
        $content = str_repeat('x', 300000)."\n";
        file_put_contents($this->file, $content);
        $received = '';
        $failed = false;
        $statusQueries = 0;
        $client = $this->client(static function (string $method, string $url, array $options) use (&$received, &$failed, &$statusQueries, $content): MockResponse {
            if (str_contains($url, 'uploadType=resumable') && $method === 'POST') {
                return new MockResponse('', ['http_code' => 200, 'response_headers' => ['Location' => 'https://bigquery.googleapis.com/upload/session']]);
            }
            if ($method === 'PUT' && $options['body'] === '') {
                ++$statusQueries;

                return new MockResponse('', ['http_code' => 308, 'response_headers' => ['Range' => 'bytes=0-'.(strlen($received) - 1)]]);
            }
            if ($method === 'PUT') {
                if (!$failed && strlen($received) === 262144) {
                    $failed = true;

                    return new MockResponse('', ['http_code' => 503]);
                }
                $received .= $options['body'];

                return strlen($received) < strlen($content)
                    ? new MockResponse('', ['http_code' => 308, 'response_headers' => ['Range' => 'bytes=0-'.(strlen($received) - 1)]])
                    : new MockResponse('{}', ['http_code' => 201]);
            }

            return new MockResponse('{"status":{"state":"DONE"},"statistics":{"load":{"outputRows":"1"}}}', ['http_code' => 200]);
        });

        $client->load('aggregate', 'view_v1', self::FIELDS, $this->file, 'x');
        self::assertSame($content, $received);
        self::assertSame(1, $statusQueries);
    }

    public function testFailedJobsAndApiErrorsGiveActionableMessages(): void
    {
        file_put_contents($this->file, "{}\n");
        $failing = $this->client(static function (string $method, string $url): MockResponse {
            if (str_contains($url, 'uploadType=resumable')) {
                return new MockResponse('', ['http_code' => 200, 'response_headers' => ['Location' => 'https://bigquery.googleapis.com/upload/x']]);
            }

            return $method === 'PUT' ? new MockResponse('{}', ['http_code' => 200]) : new MockResponse(json_encode(['status' => [
                'state' => 'DONE',
                'errorResult' => ['reason' => 'invalid', 'message' => 'Error while reading data'],
                'errors' => [['message' => 'Error while reading data'], ['message' => "Could not parse 'private value' as INT64 for field event_count; Value: private value"]],
            ]]), ['http_code' => 200]);
        });
        try {
            $failing->load('aggregate', 'v1', self::FIELDS, $this->file, 'x');
            self::fail('A failed job was reported as successful.');
        } catch (BigQueryException $e) {
            // Values BigQuery quotes in its errors are removed; field names stay.
            self::assertMatchesRegularExpression("/^BigQuery job aggregate_v1_.+ failed: Error while reading data Could not parse '…' as INT64 for field event_count; Value: …$/", $e->getMessage());
            self::assertStringNotContainsString('private value', $e->getMessage());
        }

        foreach ([
            [403, 'Access Denied: Project my-project: User does not have bigquery.jobs.create permission', 'BigQuery Job User'],
            [403, 'BigQuery API has not been used in project 1 before or it is disabled.', 'Enable the BigQuery API'],
            [401, 'Request had invalid authentication credentials.', 'Check the credentials'],
            [404, 'Not found: Project my-project', 'Check the project ID'],
        ] as [$status, $message, $hint]) {
            $client = $this->client(static fn (): MockResponse => new MockResponse(json_encode(['error' => ['code' => $status, 'message' => $message, 'errors' => [['reason' => 'accessDenied']]]]), ['http_code' => $status]));
            try {
                $client->checkJobPermission();
                self::fail('An API error was ignored.');
            } catch (BigQueryException $e) {
                self::assertStringContainsString($hint, $e->getMessage());
                self::assertSame($status, $e->getCode());
            }
        }

        $unreachable = $this->client(static function (): never {
            throw new TransportException('Failed to connect to https://bigquery.googleapis.com/upload/x?upload_id=secret');
        });
        try {
            $unreachable->datasetStatus('aggregate');
            self::fail('A transport error was ignored.');
        } catch (BigQueryException $e) {
            self::assertStringNotContainsString('upload_id', $e->getMessage());
        }
    }

    public function testAnUnfinishedJobTimesOutWithAnExplanation(): void
    {
        $client = new BigQueryClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('{"status":{"state":"RUNNING"}}')), $this->credentials(), 'my-project', 'US', static function (): void {}, 5);
        $this->expectExceptionMessage('is still running after 0 minutes');
        $client->waitForJob('job-1');
    }

    public function testAnEmptyViewReplacesTheTableWithAnEmptyTypedOne(): void
    {
        $requests = [];
        $client = $this->client(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, isset($options['body']) ? json_decode($options['body'], true) : null];

            return new MockResponse('{"status":{"state":"DONE"}}', ['http_code' => 200]);
        });
        $result = $client->replaceWithEmpty('aggregate', 'bi_anonymous_goals_v1', [['name' => 'event_day', 'type' => 'DATE'], ['name' => 'event_count', 'type' => 'INT64']]);

        self::assertSame(0, $result['rows']);
        $query = $requests[0][2]['configuration']['query'];
        self::assertSame('SELECT CAST(NULL AS DATE) AS `event_day`, CAST(NULL AS INT64) AS `event_count` LIMIT 0', $query['query']);
        self::assertFalse($query['useLegacySql']);
        self::assertSame('WRITE_TRUNCATE', $query['writeDisposition']);
        self::assertSame('bi_anonymous_goals_v1', $query['destinationTable']['tableId']);
    }

    public function testColumnNamesBigQueryCannotStoreAreRejectedBeforeAnyRequest(): void
    {
        $client = $this->client(static function (): never {
            throw new \LogicException('No request may be sent.');
        });
        $this->expectException(BigQueryException::class);
        $client->replaceWithEmpty('aggregate', 'v1', [['name' => 'bad-name`; DROP', 'type' => 'STRING']]);
    }

    private function client(callable $responses, string $location = 'US'): BigQueryClient
    {
        return new BigQueryClient(new MockHttpClient($responses), $this->credentials(), 'my-project', $location, static function (): void {}, 60, 262144);
    }

    private function credentials(): GoogleCredentials
    {
        $credentials = $this->createStub(GoogleCredentials::class);
        $credentials->method('accessToken')->willReturn('access-token');

        return $credentials;
    }
}
