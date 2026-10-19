<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use App\Service\Operations\ProcessingTasks;
use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewSyncException;
use App\Service\ViewSync\ViewSyncRunner;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Copies the selected reporting views to BigQuery (see ViewSyncRunner). Each
 * view is exported to a temporary file and replaces its BigQuery table with
 * one load job, so a table always holds a complete copy of the view as of its
 * last sync. Each view sync is a processing task of type bigquery_sync.
 *
 * @extends ViewSyncRunner<BigQueryClient>
 */
class BigQuerySyncRunner extends ViewSyncRunner
{
    public const TASK_TYPE = 'bigquery_sync';

    private \Closure $clientFactory;

    /**
     * @param \Closure(): \DateTimeImmutable|null $clock
     * @param \Closure(GoogleCredentials, string, string): BigQueryClient|null $clientFactory
     */
    public function __construct(
        BigQuerySettings $settings,
        private readonly BigQueryCredentialsFactory $credentials,
        private readonly BigQueryViewExporter $exporter,
        ProcessingTasks $tasks,
        private readonly HttpClientInterface $http,
        LoggerInterface $logger,
        ?\Closure $clock = null,
        ?\Closure $clientFactory = null,
    ) {
        parent::__construct($settings, $tasks, $logger, $clock);
        $this->clientFactory = $clientFactory ?? fn (GoogleCredentials $credentials, string $project, string $location): BigQueryClient => new BigQueryClient($this->http, $credentials, $project, $location);
    }

    /**
     * Exports views without uploading anything or needing credentials, to
     * check the database side. With an output folder, keeps the files.
     *
     * @param list<string> $views
     *
     * @return list<array{view: string, rows: int, bytes: int, fields: list<array{name: string, type: string}>, file: ?string}>
     */
    public function dryRun(array $views, ?string $outputDirectory = null): array
    {
        $results = [];
        foreach ($views as $view) {
            $export = $this->exporter->export($view);
            $file = null;
            if ($outputDirectory !== null) {
                $file = rtrim($outputDirectory, '/').'/'.$view.'.ndjson';
                if (!@rename($export['file'], $file)) {
                    @unlink($export['file']);
                    throw new BigQueryException('The export could not be written to '.$outputDirectory.'.');
                }
                file_put_contents(rtrim($outputDirectory, '/').'/'.$view.'.schema.json', json_encode($export['fields'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
            } else {
                @unlink($export['file']);
            }
            $results[] = ['view' => $view, 'rows' => $export['rows'], 'bytes' => $export['bytes'], 'fields' => $export['fields'], 'file' => $file];
        }

        return $results;
    }

    /**
     * Signs in and checks the project, dataset and permission to run jobs,
     * without changing anything in BigQuery.
     *
     * @return array{identity: string, project: string, dataset: string, dataset_exists: bool, dataset_location: string, location: string}
     *
     * @throws BigQueryException|\InvalidArgumentException
     */
    public function check(): array
    {
        $settings = $this->settings->toArray();
        $credentials = $this->credentials->create($settings);
        $credentials->accessToken();
        $project = BigQueryCredentialsFactory::project($settings, $credentials);
        $client = ($this->clientFactory)($credentials, $project, $settings[BigQuerySettings::KEY_LOCATION]);
        $dataset = $client->datasetStatus($settings[BigQuerySettings::KEY_DATASET]);
        if ($dataset['exists'] && $dataset['location'] !== '' && strcasecmp($dataset['location'], $settings[BigQuerySettings::KEY_LOCATION]) !== 0) {
            throw new BigQueryException(sprintf('The dataset %s is in %s, not %s. Set the location to %s, or choose another dataset.', $settings[BigQuerySettings::KEY_DATASET], $dataset['location'], $settings[BigQuerySettings::KEY_LOCATION], $dataset['location']));
        }
        $client->checkJobPermission();

        return [
            'identity' => $credentials->identity(),
            'project' => $project,
            'dataset' => $settings[BigQuerySettings::KEY_DATASET],
            'dataset_exists' => $dataset['exists'],
            'dataset_location' => $dataset['location'],
            'location' => $settings[BigQuerySettings::KEY_LOCATION],
        ];
    }

    protected function open(array $settings): BigQueryClient
    {
        $credentials = $this->credentials->create($settings);
        $project = BigQueryCredentialsFactory::project($settings, $credentials);
        $client = ($this->clientFactory)($credentials, $project, $settings[BigQuerySettings::KEY_LOCATION]);
        $client->ensureDataset($settings[BigQuerySettings::KEY_DATASET]);

        return $client;
    }

    /** @param BigQueryClient $destination */
    protected function copy(string $view, object $destination, array $settings, ?string &$externalId): array
    {
        $export = $this->exporter->export($view);
        try {
            $result = $export['rows'] === 0
                ? $destination->replaceWithEmpty($settings[BigQuerySettings::KEY_DATASET], $view, $export['fields'])
                : $destination->load($settings[BigQuerySettings::KEY_DATASET], $view, $export['fields'], $export['file'], 'Copy of the Aggregate reporting view '.$view.', replaced on every sync. '.ViewCatalog::describe($view).'.');
            $externalId = $result['job_id'];
            if ($export['rows'] !== 0 && $result['rows'] !== $export['rows']) {
                throw new BigQueryException(sprintf('BigQuery loaded %d of %d rows.', $result['rows'], $export['rows']));
            }

            return [
                'rows' => $export['rows'],
                'message' => sprintf('Replaced %s.%s.%s with %d row%s.', $destination->project(), $settings[BigQuerySettings::KEY_DATASET], $view, $export['rows'], $export['rows'] === 1 ? '' : 's'),
            ];
        } finally {
            if (is_file($export['file'])) {
                @unlink($export['file']);
            }
        }
    }

    protected function setupFailure(\Throwable $e): string
    {
        if ($e instanceof BigQueryException) {
            return $e->getMessage();
        }
        $this->logger->error('BigQuery sync could not start.', ['exception' => $e]);

        return 'BigQuery sync could not start ('.(new \ReflectionClass($e))->getShortName().'); see the application log.';
    }

    protected function copyFailure(string $view, \Throwable $e): string
    {
        // Reading the view fails with a ViewSyncException, BigQuery with a BigQueryException.
        if ($e instanceof ViewSyncException) {
            return $e->getMessage();
        }
        $this->logger->error('BigQuery sync of a view failed.', ['view' => $view, 'exception' => $e]);

        return 'The view could not be synced ('.(new \ReflectionClass($e))->getShortName().'); see the application log.';
    }

    /** The BigQuery job of the latest attempt. */
    protected function attemptDetails(?array $attempt): array
    {
        return ['job_id' => $attempt['external_id'] ?? null];
    }
}
