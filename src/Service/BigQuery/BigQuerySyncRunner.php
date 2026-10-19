<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use App\Service\Operations\ProcessingTasks;
use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewExporter;
use App\Service\ViewSync\ViewSnapshot;
use App\Service\ViewSync\ViewSyncException;
use App\Service\ViewSync\ViewSyncRunner;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Copies the selected reporting views to BigQuery (see ViewSyncRunner). Each
 * view's snapshot file replaces its BigQuery table with one load job, so a
 * table always holds a complete copy of the view as of its last sync. Each
 * view sync is a processing task of type bigquery_sync.
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
        ViewExporter $exporter,
        ProcessingTasks $tasks,
        private readonly HttpClientInterface $http,
        LoggerInterface $logger,
        ?\Closure $clock = null,
        ?\Closure $clientFactory = null,
    ) {
        parent::__construct($settings, $exporter, $tasks, $logger, $clock);
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
            $export = $this->exporter->export($view, BigQuerySettings::definition()->exportDirectory);
            $file = null;
            if ($outputDirectory !== null) {
                $file = rtrim($outputDirectory, '/').'/'.$view.'.ndjson';
                if (!@rename($export->file, $file)) {
                    $export->delete();
                    throw new BigQueryException('The export could not be written to '.$outputDirectory.'.');
                }
                file_put_contents(rtrim($outputDirectory, '/').'/'.$view.'.schema.json', json_encode($export->fields, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
            } else {
                $export->delete();
            }
            $results[] = ['view' => $view, 'rows' => $export->rows, 'bytes' => $export->bytes, 'fields' => $export->fields, 'file' => $file];
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

    /**
     * @param BigQueryClient $destination
     *
     * @param-out string $externalId the load or query job
     */
    protected function replace(ViewSnapshot $snapshot, object $destination, array $settings, ?string &$externalId): void
    {
        $view = $snapshot->view;
        $result = $snapshot->rows === 0
            ? $destination->replaceWithEmpty($settings[BigQuerySettings::KEY_DATASET], $view, $snapshot->fields)
            : $destination->load($settings[BigQuerySettings::KEY_DATASET], $view, $snapshot->fields, $snapshot->file, 'Copy of the Aggregate reporting view '.$view.', replaced when the view changes. '.ViewCatalog::describe($view).'.');
        $externalId = $result['job_id'];
        if ($snapshot->rows !== 0 && $result['rows'] !== $snapshot->rows) {
            throw new BigQueryException(sprintf('BigQuery loaded %d of %d rows.', $result['rows'], $snapshot->rows));
        }
    }

    /** @param BigQueryClient $destination */
    protected function copiedRows(string $view, object $destination, array $settings): ?int
    {
        return $destination->tableRows($settings[BigQuerySettings::KEY_DATASET], $view);
    }

    /** @param BigQueryClient $destination */
    protected function copyName(string $view, object $destination, array $settings): string
    {
        return $destination->project().'.'.$settings[BigQuerySettings::KEY_DATASET].'.'.$view;
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
