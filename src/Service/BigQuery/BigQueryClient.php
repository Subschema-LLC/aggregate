<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The few BigQuery REST calls the sync needs: datasets, load jobs with a
 * resumable upload, and job status. Load jobs replace a table atomically
 * (WRITE_TRUNCATE) and do not incur streaming-insert charges.
 */
class BigQueryClient
{
    public const API = 'https://bigquery.googleapis.com/bigquery/v2';
    public const UPLOAD = 'https://bigquery.googleapis.com/upload/bigquery/v2';
    /** Resumable chunks must be multiples of 256 KiB. */
    public const CHUNK_BYTES = 32 * 262144;
    private const UPLOAD_RETRIES = 5;

    private \Closure $sleep;

    /** @param \Closure(int): void|null $sleep Seconds to wait between job checks and retries. */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly GoogleCredentials $credentials,
        private readonly string $project,
        private readonly string $location,
        ?\Closure $sleep = null,
        private readonly int $jobTimeoutSeconds = 1800,
        private readonly int $chunkBytes = self::CHUNK_BYTES,
    ) {
        if ($chunkBytes < 262144 || $chunkBytes % 262144 !== 0) {
            throw new \InvalidArgumentException('Upload chunks must be a multiple of 256 KiB.');
        }
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function project(): string
    {
        return $this->project;
    }

    /** @return array{exists: bool, location: string} */
    public function datasetStatus(string $dataset): array
    {
        $response = $this->call('GET', '/projects/'.rawurlencode($this->project).'/datasets/'.rawurlencode($dataset), allowNotFound: true);
        if ($response === null) {
            return ['exists' => false, 'location' => ''];
        }

        return ['exists' => true, 'location' => is_string($response['location'] ?? null) ? $response['location'] : ''];
    }

    /** Creates the dataset in the configured location when it does not exist yet. */
    public function ensureDataset(string $dataset): void
    {
        $status = $this->datasetStatus($dataset);
        if (!$status['exists']) {
            $this->call('POST', '/projects/'.rawurlencode($this->project).'/datasets', [
                'datasetReference' => ['projectId' => $this->project, 'datasetId' => $dataset],
                'location' => $this->location,
                'description' => 'Reporting views copied from Aggregate. Each table is replaced on every sync.',
                'labels' => ['source' => 'aggregate'],
            ], conflictIsSuccess: true);

            return;
        }
        if ($status['location'] !== '' && strcasecmp($status['location'], $this->location) !== 0) {
            throw new BigQueryException(sprintf('The dataset %s is in %s, not %s. Set the location to %s, or choose another dataset.', $dataset, $status['location'], $this->location, $status['location']));
        }
    }

    /** Confirms these credentials may run jobs in the project, with a free dry-run query. */
    public function checkJobPermission(): void
    {
        $this->call('POST', '/projects/'.rawurlencode($this->project).'/jobs', [
            'configuration' => ['dryRun' => true, 'query' => ['query' => 'SELECT 1', 'useLegacySql' => false]],
            'jobReference' => ['projectId' => $this->project, 'location' => $this->location],
        ]);
    }

    /**
     * Replaces a table with newline-delimited JSON rows from a local file.
     *
     * @param list<array{name: string, type: string}> $fields
     *
     * @return array{job_id: string, rows: int}
     */
    public function load(string $dataset, string $table, array $fields, string $file, string $description): array
    {
        $size = filesize($file);
        if ($size === false || $size === 0) {
            throw new \LogicException('An empty export is replaced with replaceWithEmpty().');
        }
        $jobId = self::jobId($table);
        $job = [
            'jobReference' => ['projectId' => $this->project, 'jobId' => $jobId, 'location' => $this->location],
            'configuration' => [
                'labels' => ['source' => 'aggregate'],
                'load' => [
                    'destinationTable' => ['projectId' => $this->project, 'datasetId' => $dataset, 'tableId' => $table],
                    'sourceFormat' => 'NEWLINE_DELIMITED_JSON',
                    'writeDisposition' => 'WRITE_TRUNCATE',
                    'createDisposition' => 'CREATE_IF_NEEDED',
                    'schema' => ['fields' => self::schema($fields)],
                    'destinationTableProperties' => ['description' => $description],
                    'maxBadRecords' => 0,
                    'ignoreUnknownValues' => false,
                ],
            ],
        ];
        $session = $this->startUpload($job, $size);
        $this->upload($session, $file, $size);
        $result = $this->waitForJob($jobId);

        return ['job_id' => $jobId, 'rows' => (int) ($result['statistics']['load']['outputRows'] ?? 0)];
    }

    /**
     * Replaces a table with an empty one of the given schema. A query job that
     * selects no rows keeps the table (and its access settings) like a load.
     *
     * @param list<array{name: string, type: string}> $fields
     *
     * @return array{job_id: string, rows: int}
     */
    public function replaceWithEmpty(string $dataset, string $table, array $fields): array
    {
        $columns = array_map(static fn (array $field): string => 'CAST(NULL AS '.$field['type'].') AS `'.$field['name'].'`', self::schema($fields));
        $jobId = self::jobId($table);
        $this->call('POST', '/projects/'.rawurlencode($this->project).'/jobs', [
            'jobReference' => ['projectId' => $this->project, 'jobId' => $jobId, 'location' => $this->location],
            'configuration' => [
                'labels' => ['source' => 'aggregate'],
                'query' => [
                    'query' => 'SELECT '.implode(', ', $columns).' LIMIT 0',
                    'useLegacySql' => false,
                    'destinationTable' => ['projectId' => $this->project, 'datasetId' => $dataset, 'tableId' => $table],
                    'writeDisposition' => 'WRITE_TRUNCATE',
                    'createDisposition' => 'CREATE_IF_NEEDED',
                ],
            ],
        ]);
        $this->waitForJob($jobId);

        return ['job_id' => $jobId, 'rows' => 0];
    }

    /** @return array<string, mixed> The finished job. */
    public function waitForJob(string $jobId): array
    {
        $waited = 0;
        $pause = 1;
        while (true) {
            $job = $this->call('GET', '/projects/'.rawurlencode($this->project).'/jobs/'.rawurlencode($jobId).'?location='.rawurlencode($this->location));
            if (($job['status']['state'] ?? null) === 'DONE') {
                $error = $job['status']['errorResult'] ?? null;
                if (is_array($error)) {
                    $details = [];
                    foreach (array_slice(is_array($job['status']['errors'] ?? null) ? $job['status']['errors'] : [], 0, 3) as $item) {
                        if (is_array($item) && is_string($item['message'] ?? null) && $item['message'] !== ($error['message'] ?? null)) {
                            $details[] = $item['message'];
                        }
                    }
                    throw new BigQueryException('BigQuery job '.$jobId.' failed: '.TokenCache::clean(self::withoutValues(implode(' ', [is_string($error['message'] ?? null) ? $error['message'] : 'unknown error', ...$details])), 500));
                }

                return $job;
            }
            if ($waited >= $this->jobTimeoutSeconds) {
                throw new BigQueryException('BigQuery job '.$jobId.' is still running after '.intdiv($this->jobTimeoutSeconds, 60).' minutes. It may still finish; the next sync replaces the table again.');
            }
            ($this->sleep)($pause);
            $waited += $pause;
            $pause = min(10, $pause + 1);
        }
    }

    /** Removes data values BigQuery quotes in job errors, keeping field names and positions. */
    private static function withoutValues(string $message): string
    {
        return (string) preg_replace(['/\'(?:[^\'\\\\]|\\\\.)*\'/', '/"(?:[^"\\\\]|\\\\.)*"/', '/\bValue: [^;.]*/i'], ["'…'", '"…"', 'Value: …'], $message);
    }

    /** @param list<array{name: string, type: string}> $fields @return list<array{name: string, type: string, mode: string}> */
    private static function schema(array $fields): array
    {
        $schema = [];
        foreach ($fields as $field) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,299}$/D', $field['name']) !== 1 || !in_array($field['type'], BigQueryViewCatalog::TYPES, true)) {
                throw new BigQueryException('The column '.TokenCache::clean($field['name'], 80).' cannot be used as a BigQuery column name.');
            }
            $schema[] = ['name' => $field['name'], 'type' => $field['type'], 'mode' => 'NULLABLE'];
        }

        return $schema;
    }

    private static function jobId(string $table): string
    {
        return 'aggregate_'.substr(preg_replace('/[^A-Za-z0-9_]/', '_', $table) ?? 'table', 0, 100).'_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(4));
    }

    /** @param array<string, mixed> $job */
    private function startUpload(array $job, int $size): string
    {
        try {
            $response = $this->http->request('POST', self::UPLOAD.'/projects/'.rawurlencode($this->project).'/jobs?uploadType=resumable', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->credentials->accessToken(),
                    'X-Upload-Content-Type' => 'application/octet-stream',
                    'X-Upload-Content-Length' => (string) $size,
                ],
                'json' => $job,
                'timeout' => 60,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $location = $response->getHeaders(false)['location'][0] ?? '';
        } catch (ExceptionInterface $e) {
            throw new BigQueryException('BigQuery could not be reached to start the upload: '.TokenCache::transportMessage($e), previous: $e);
        }
        if ($status !== 200) {
            throw $this->apiError($response, $status);
        }
        if (!str_starts_with($location, 'https://bigquery.googleapis.com/upload/') && !str_starts_with($location, 'https://www.googleapis.com/upload/')) {
            throw new BigQueryException('BigQuery did not return an upload address.');
        }

        return $location;
    }

    private function upload(string $session, string $file, int $size): void
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new BigQueryException('The export file could not be opened for upload.');
        }
        try {
            $offset = 0;
            $failures = 0;
            while (true) {
                if (fseek($handle, $offset) !== 0) {
                    throw new BigQueryException('The export file could not be read for upload.');
                }
                $chunk = (string) fread($handle, $this->chunkBytes);
                $end = $offset + strlen($chunk) - 1;
                try {
                    $response = $this->http->request('PUT', $session, [
                        'headers' => [
                            'Content-Type' => 'application/octet-stream',
                            'Content-Range' => 'bytes '.$offset.'-'.$end.'/'.$size,
                        ],
                        'body' => $chunk,
                        'timeout' => 300,
                        'max_redirects' => 0,
                    ]);
                    $status = $response->getStatusCode();
                } catch (ExceptionInterface $e) {
                    $status = 0;
                    $response = null;
                    $transport = $e;
                }
                if ($status === 200 || $status === 201) {
                    return;
                }
                if ($status === 308) {
                    $offset = self::nextOffset($response);
                    $failures = 0;
                    continue;
                }
                if (($status === 0 || $status === 408 || $status === 429 || $status >= 500) && ++$failures <= self::UPLOAD_RETRIES) {
                    ($this->sleep)(min(30, 2 ** $failures));
                    $offset = $this->uploadedBytes($session, $size);
                    if ($offset === null) {
                        return;
                    }
                    continue;
                }
                if ($response === null) {
                    throw new BigQueryException('The upload to BigQuery failed: '.TokenCache::transportMessage($transport ?? new \RuntimeException('no response')));
                }
                throw $this->apiError($response, $status);
            }
        } finally {
            fclose($handle);
        }
    }

    /** How many bytes Google has, or null when the upload already completed. */
    private function uploadedBytes(string $session, int $size): ?int
    {
        try {
            $response = $this->http->request('PUT', $session, [
                'headers' => ['Content-Range' => 'bytes */'.$size],
                'body' => '',
                'timeout' => 60,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface) {
            return 0;
        }
        if ($status === 200 || $status === 201) {
            return null;
        }

        return $status === 308 ? self::nextOffset($response) : 0;
    }

    private static function nextOffset(ResponseInterface $response): int
    {
        $range = $response->getHeaders(false)['range'][0] ?? '';

        return preg_match('/^bytes=0-([0-9]+)$/', $range, $matches) === 1 ? (int) $matches[1] + 1 : 0;
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>|null Null for a 404 when allowed.
     */
    private function call(string $method, string $path, ?array $json = null, bool $allowNotFound = false, bool $conflictIsSuccess = false): ?array
    {
        $options = [
            'headers' => ['Authorization' => 'Bearer '.$this->credentials->accessToken()],
            'timeout' => 60,
            'max_redirects' => 0,
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }
        try {
            $response = $this->http->request($method, self::API.$path, $options);
            $status = $response->getStatusCode();
            if ($status === 404 && $allowNotFound) {
                return null;
            }
            if ($status === 409 && $conflictIsSuccess) {
                return [];
            }
            if ($status >= 200 && $status < 300) {
                return $response->toArray(false);
            }
        } catch (ExceptionInterface $e) {
            throw new BigQueryException('BigQuery could not be reached: '.TokenCache::transportMessage($e), previous: $e);
        }

        throw $this->apiError($response, $status);
    }

    private function apiError(ResponseInterface $response, int $status): BigQueryException
    {
        try {
            $body = $response->toArray(false);
        } catch (\Throwable) {
            $body = [];
        }
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'HTTP '.$status;
        $reason = is_array($error['errors'][0] ?? null) && is_string($error['errors'][0]['reason'] ?? null) ? $error['errors'][0]['reason'] : '';
        $hint = match (true) {
            $status === 401 => ' Check the credentials on the BigQuery page.',
            $status === 403 && str_contains(strtolower($message), 'api') && str_contains(strtolower($message), 'disabled') => ' Enable the BigQuery API for the project.',
            $status === 403 => ' The account needs the BigQuery Job User role on the project and BigQuery Data Editor on the dataset (or on the project, so it can create the dataset).',
            $status === 404 => ' Check the project ID and dataset.',
            default => '',
        };

        return new BigQueryException('BigQuery refused the request: '.TokenCache::clean($message).($reason !== '' && !str_contains($message, $reason) ? ' ('.$reason.')' : '').$hint, $status);
    }
}
