<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BigQuery\BigQueryException;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\BigQuery\BigQuerySyncRunner;
use App\Service\BigQuery\BigQueryViewCatalog;
use App\Service\Operations\TaskTrigger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bigquery:sync',
    description: 'Copy the selected reporting views to BigQuery when their interval has passed',
)]
final class BigQuerySyncCommand extends Command
{
    public function __construct(
        private readonly BigQuerySyncRunner $runner,
        private readonly BigQuerySettings $settings,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Sync now, without waiting for the interval')
            ->addOption('view', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sync only this view (repeatable)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Export the views locally and report rows and columns; upload nothing and use no credentials')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'With --dry-run, keep the exported NDJSON and schema files in this folder')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
            ->addOption('requested-by', null, InputOption::VALUE_REQUIRED, 'Set by the dashboard’s Sync now: the administrator recorded with each task')
            ->setHelp(<<<'HELP'
Schedule this command every five minutes, for example with cron:

  */5 * * * * cd /path/to/aggregate && php bin/console app:bigquery:sync --no-interaction

Each selected view is copied when the configured interval has passed since its
last sync. Settings come from the BigQuery admin page, config/aggregate.yaml or
BIGQUERY_* environment variables. Every view sync is recorded in the
processing_tasks table and the audit trail. --dry-run reads every selected view
(or the --view names, which may be any syncable view) and uploads nothing.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $views = array_values(array_unique(array_map('strval', (array) $input->getOption('view'))));
        $json = (bool) $input->getOption('json');

        if ($input->getOption('dry-run')) {
            return $this->dryRun($io, $output, $views, $input->getOption('output'), $json);
        }
        if ($input->getOption('output') !== null) {
            $io->error('--output can only be used with --dry-run.');

            return Command::INVALID;
        }

        try {
            $requestedBy = $input->getOption('requested-by');
            $result = $this->runner->run((bool) $input->getOption('force'), $views === [] ? null : $views, $json ? null : static function (string $view) use ($io): void {
                $io->writeln('Syncing '.$view.'…');
            }, is_string($requestedBy) && $requestedBy !== '' ? TaskTrigger::dashboard($requestedBy) : null);
        } catch (\InvalidArgumentException $e) {
            $io->error(['The BigQuery settings are invalid; nothing was synced.', $e->getMessage()]);

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->logger->error('BigQuery sync failed.', ['exception' => $e]);
            $io->error('BigQuery sync failed; see the application log.');

            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif (!$result['enabled']) {
            $io->note('BigQuery sync is turned off. Turn it on in the admin page or set bigquery_enabled: true.');
        } else {
            $rows = array_map(static fn (array $item): array => [$item['view'], $item['status'], $item['rows'] ?? '', $item['message']], $result['results']);
            $io->table(['View', 'Result', 'Rows', 'Details'], $rows);
        }

        foreach ($result['results'] as $item) {
            if ($item['status'] === 'failed') {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /** @param list<string> $views */
    private function dryRun(SymfonyStyle $io, OutputInterface $output, array $views, mixed $directory, bool $json): int
    {
        try {
            if ($views === []) {
                $views = BigQuerySettings::selectedViews($this->settings->toArray());
            }
            foreach ($views as $view) {
                if (!BigQueryViewCatalog::isKnown($view)) {
                    throw new \InvalidArgumentException($view.' is not a reporting view that can be synced.');
                }
            }
            if ($directory !== null && (!is_string($directory) || !is_dir($directory) || !is_writable($directory))) {
                throw new \InvalidArgumentException('--output must be an existing, writable folder.');
            }
            $results = $this->runner->dryRun($views, $directory);
        } catch (\InvalidArgumentException|BigQueryException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->logger->error('BigQuery dry run failed.', ['exception' => $e]);
            $io->error('The export failed ('.(new \ReflectionClass($e))->getShortName().'); see the application log.');

            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln(json_encode($results, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        $io->table(['View', 'Rows', 'Bytes', 'Columns'], array_map(static fn (array $item): array => [
            $item['view'],
            $item['rows'],
            $item['bytes'],
            implode(', ', array_map(static fn (array $field): string => $field['name'].' '.$field['type'], $item['fields'])),
        ], $results));
        $io->success('Dry run: nothing was uploaded.');

        return Command::SUCCESS;
    }
}
