<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BigQuery\BigQueryBackgroundSync;
use App\Service\BigQuery\BigQueryException;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\BigQuery\BigQuerySyncRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bigquery:check',
    description: 'Show the BigQuery sync settings and status, and test the connection',
)]
final class BigQueryCheckCommand extends Command
{
    public function __construct(
        private readonly BigQuerySyncRunner $runner,
        private readonly BigQuerySettings $settings,
        private readonly BigQueryBackgroundSync $background,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-connect', null, InputOption::VALUE_NONE, 'Only show settings and status; do not contact Google');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $settings = $this->settings->toArray();
        } catch (\InvalidArgumentException $e) {
            $io->error(['The BigQuery settings are invalid.', $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Sync' => $settings[BigQuerySettings::KEY_ENABLED] ? 'on, every '.$settings[BigQuerySettings::KEY_INTERVAL].' minutes' : 'off'],
            ['Sign-in' => BigQuerySettings::AUTH_METHODS[$settings[BigQuerySettings::KEY_AUTH]]],
            ['Project' => $settings[BigQuerySettings::KEY_PROJECT] !== '' ? $settings[BigQuerySettings::KEY_PROJECT] : '(from the credentials)'],
            ['Dataset' => $settings[BigQuerySettings::KEY_DATASET].' in '.$settings[BigQuerySettings::KEY_LOCATION]],
        );

        $status = $this->runner->status($settings);
        $io->table(['View', 'Status', 'Last sync', 'Rows', 'Next', 'Details'], array_map(static fn (array $view): array => [
            $view['view'].($view['private'] ? ' (private)' : ''),
            $view['status'],
            $view['succeeded_at']?->format('Y-m-d H:i').($view['succeeded_at'] ? ' UTC' : ''),
            $view['row_count'] ?? '',
            $view['next_due'] === null ? 'next run' : $view['next_due']->format('Y-m-d H:i').' UTC',
            $view['message'] ?? '',
        ], $status['views']));
        if ($status['scheduler_late']) {
            $io->warning('app:bigquery:sync has not run recently. Schedule it every five minutes, for example: '.$this->background->cronLine());
        }

        if ($input->getOption('no-connect')) {
            return Command::SUCCESS;
        }
        try {
            $check = $this->runner->check();
        } catch (BigQueryException|\InvalidArgumentException $e) {
            $io->error(['The connection test failed.', $e->getMessage()]);

            return Command::FAILURE;
        }
        $io->success(sprintf(
            'Signed in as %s. Project %s can run jobs. Dataset %s %s.',
            $check['identity'],
            $check['project'],
            $check['dataset'],
            $check['dataset_exists'] ? 'exists in '.$check['dataset_location'] : 'does not exist yet; the first sync creates it in '.$check['location'],
        ));

        return Command::SUCCESS;
    }
}
