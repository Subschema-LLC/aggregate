<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Copies the selected reporting views to BigQuery. Each view is exported to
 * a temporary file and replaces its BigQuery table with one load job, so a
 * table always holds a complete copy of the view as of its last sync.
 * app:bigquery:sync runs this every few minutes; a view syncs when its
 * interval has passed (or with --force).
 */
class BigQuerySyncRunner
{
    /** A scheduled run every five minutes lands within this margin of the interval. */
    private const SCHEDULE_SLACK_MINUTES = 5;
    private const RETRY_AFTER_FAILURE_MINUTES = 15;

    private \Closure $clock;
    private \Closure $clientFactory;

    /**
     * @param \Closure(): \DateTimeImmutable|null $clock
     * @param \Closure(GoogleCredentials, string, string): BigQueryClient|null $clientFactory
     */
    public function __construct(
        private readonly BigQuerySettings $settings,
        private readonly BigQueryCredentialsFactory $credentials,
        private readonly BigQueryViewExporter $exporter,
        private readonly BigQuerySyncStateStore $states,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null,
        ?\Closure $clientFactory = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->clientFactory = $clientFactory ?? fn (GoogleCredentials $credentials, string $project, string $location): BigQueryClient => new BigQueryClient($this->http, $credentials, $project, $location);
    }

    /**
     * @param list<string>|null $only Views to sync now (they must be selected).
     * @param (callable(string): void)|null $progress
     *
     * @return array{enabled: bool, results: list<array{view: string, status: string, rows: ?int, message: string}>}
     *
     * @throws \InvalidArgumentException when the settings are invalid
     */
    public function run(bool $force = false, ?array $only = null, ?callable $progress = null): array
    {
        $settings = $this->settings->toArray();
        if (!$settings[BigQuerySettings::KEY_ENABLED]) {
            return ['enabled' => false, 'results' => []];
        }
        $selected = BigQuerySettings::selectedViews($settings);
        foreach ($only ?? [] as $view) {
            if (!in_array($view, $selected, true)) {
                throw new \InvalidArgumentException($view.' is not selected for BigQuery sync.');
            }
        }
        $views = $only ?? $selected;
        $now = ($this->clock)();
        $states = $this->states->all();
        $due = $force ? $views : array_values(array_filter($views, fn (string $view): bool => $this->isDue($states[$view] ?? null, $settings[BigQuerySettings::KEY_INTERVAL], $now)));
        $this->states->recordRunner($now, $due === [] ? 'Nothing was due.' : 'Synced '.count($due).' view(s).');
        $results = [];
        foreach (array_diff($views, $due) as $view) {
            $results[] = ['view' => $view, 'status' => 'not_due', 'rows' => null, 'message' => 'Not due yet.'];
        }
        if ($due === []) {
            return ['enabled' => true, 'results' => $results];
        }

        try {
            $credentials = $this->credentials->create($settings);
            $project = BigQueryCredentialsFactory::project($settings, $credentials);
            $client = ($this->clientFactory)($credentials, $project, $settings[BigQuerySettings::KEY_LOCATION]);
            $client->ensureDataset($settings[BigQuerySettings::KEY_DATASET]);
            $setupError = null;
        } catch (BigQueryException $e) {
            $client = null;
            $setupError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->logger->error('BigQuery sync could not start.', ['exception' => $e]);
            $client = null;
            $setupError = 'BigQuery sync could not start ('.(new \ReflectionClass($e))->getShortName().'); see the application log.';
        }

        foreach ($due as $view) {
            $results[] = $this->syncView($view, $settings, $client, $setupError, $progress);
        }
        usort($results, static fn (array $a, array $b): int => array_search($a['view'], $views, true) <=> array_search($b['view'], $views, true));

        return ['enabled' => true, 'results' => $results];
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

    /**
     * Status for the admin page and app:bigquery:check.
     *
     * @return array{runner: ?array{started_at: ?\DateTimeImmutable}, views: list<array{view: string, private: bool, description: string, status: string, started_at: ?\DateTimeImmutable, finished_at: ?\DateTimeImmutable, succeeded_at: ?\DateTimeImmutable, row_count: ?int, message: ?string, job_id: ?string, next_due: ?\DateTimeImmutable}>, scheduler_late: bool}
     */
    public function status(array $settings): array
    {
        $states = $this->states->all();
        $now = ($this->clock)();
        $views = [];
        foreach (BigQuerySettings::selectedViews($settings) as $view) {
            $state = $states[$view] ?? null;
            $views[] = [
                'view' => $view,
                'private' => BigQueryViewCatalog::isPrivate($view),
                'description' => BigQueryViewCatalog::describe($view),
                'status' => $state['status'] ?? 'never',
                'started_at' => $state['started_at'] ?? null,
                'finished_at' => $state['finished_at'] ?? null,
                'succeeded_at' => $state['succeeded_at'] ?? null,
                'row_count' => $state['row_count'] ?? null,
                'message' => $state['message'] ?? null,
                'job_id' => $state['job_id'] ?? null,
                'next_due' => $this->nextDue($state, $settings[BigQuerySettings::KEY_INTERVAL]),
            ];
        }
        $runner = $states[BigQuerySyncStateStore::RUNNER] ?? null;
        $lastRun = $runner['started_at'] ?? null;
        $late = $settings[BigQuerySettings::KEY_ENABLED]
            && ($lastRun === null || $lastRun < $now->modify('-'.max(30, 2 * $settings[BigQuerySettings::KEY_INTERVAL]).' minutes'));

        return ['runner' => $runner === null ? null : ['started_at' => $lastRun], 'views' => $views, 'scheduler_late' => $late];
    }

    /** @param array<string, mixed> $settings @return array{view: string, status: string, rows: ?int, message: string} */
    private function syncView(string $view, array $settings, ?BigQueryClient $client, ?string $setupError, ?callable $progress): array
    {
        $token = $this->states->claim($view, ($this->clock)());
        if ($token === null) {
            return ['view' => $view, 'status' => 'busy', 'rows' => null, 'message' => 'Another sync of this view is running.'];
        }
        if ($progress !== null) {
            $progress($view);
        }
        $export = null;
        $jobId = null;
        try {
            if ($client === null) {
                throw new BigQueryException((string) $setupError);
            }
            $export = $this->exporter->export($view);
            $table = $view;
            $result = $export['rows'] === 0
                ? $client->replaceWithEmpty($settings[BigQuerySettings::KEY_DATASET], $table, $export['fields'])
                : $client->load($settings[BigQuerySettings::KEY_DATASET], $table, $export['fields'], $export['file'], 'Copy of the Aggregate reporting view '.$view.', replaced on every sync. '.BigQueryViewCatalog::describe($view).'.');
            $jobId = $result['job_id'];
            if ($export['rows'] !== 0 && $result['rows'] !== $export['rows']) {
                throw new BigQueryException(sprintf('BigQuery loaded %d of %d rows.', $result['rows'], $export['rows']));
            }
            $message = sprintf('Replaced %s.%s.%s with %d row%s.', $client->project(), $settings[BigQuerySettings::KEY_DATASET], $table, $export['rows'], $export['rows'] === 1 ? '' : 's');
            $this->states->finish($view, $token, true, $export['rows'], $message, $jobId, ($this->clock)());

            return ['view' => $view, 'status' => 'synced', 'rows' => $export['rows'], 'message' => $message];
        } catch (\Throwable $e) {
            if ($e instanceof BigQueryException) {
                $message = $e->getMessage();
            } else {
                $this->logger->error('BigQuery sync of a view failed.', ['view' => $view, 'exception' => $e]);
                $message = 'The view could not be synced ('.(new \ReflectionClass($e))->getShortName().'); see the application log.';
            }
            $this->states->finish($view, $token, false, null, $message, $jobId, ($this->clock)());

            return ['view' => $view, 'status' => 'failed', 'rows' => null, 'message' => $message];
        } finally {
            if ($export !== null && is_file($export['file'])) {
                @unlink($export['file']);
            }
        }
    }

    /** @param array{status: string, started_at: ?\DateTimeImmutable}|null $state */
    private function isDue(?array $state, int $interval, \DateTimeImmutable $now): bool
    {
        $next = $this->nextDue($state, $interval);

        return $next === null || $now >= $next->modify('-'.min(self::SCHEDULE_SLACK_MINUTES, intdiv($interval, 3)).' minutes');
    }

    /** @param array{status: string, started_at: ?\DateTimeImmutable}|null $state */
    private function nextDue(?array $state, int $interval): ?\DateTimeImmutable
    {
        $started = $state['started_at'] ?? null;
        if ($started === null) {
            return null;
        }
        $wait = ($state['status'] ?? '') === 'failed' ? min(self::RETRY_AFTER_FAILURE_MINUTES, $interval) : $interval;

        return $started->modify('+'.$wait.' minutes');
    }
}
