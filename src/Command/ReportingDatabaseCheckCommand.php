<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReportingDatabase\ReportingDatabaseBackgroundSync;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\ReportingDatabase\ReportingDatabaseSyncRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reporting-database:check',
    description: 'Show the reporting database sync settings and status, and test the connection',
)]
final class ReportingDatabaseCheckCommand extends Command
{
    public function __construct(
        private readonly ReportingDatabaseSyncRunner $runner,
        private readonly ReportingDatabaseSettings $settings,
        private readonly ReportingDatabaseConnection $database,
        private readonly ReportingDatabaseBackgroundSync $background,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-connect', null, InputOption::VALUE_NONE, 'Only show settings and status; do not connect to the reporting database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $settings = $this->settings->toArray();
        } catch (\InvalidArgumentException $e) {
            $io->error(['The reporting database settings are invalid.', $e->getMessage()]);

            return Command::FAILURE;
        }

        try {
            $location = ReportingDatabaseConnection::label($this->database->describe());
        } catch (ReportingDatabaseException $e) {
            $location = $e->getMessage();
        }
        $io->definitionList(
            ['Sync' => $settings[ReportingDatabaseSettings::KEY_ENABLED] ? 'on, every '.$settings[ReportingDatabaseSettings::KEY_INTERVAL].' minutes' : 'off'],
            ['Reporting database' => $location],
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
            $io->warning('The sync has not run recently. Have the server run app:scheduler:run every five minutes; app:scheduler:status shows how on this server. With cron: '.$this->background->cronLine());
        }

        if ($input->getOption('no-connect')) {
            return Command::SUCCESS;
        }
        try {
            $check = $this->runner->check();
        } catch (ReportingDatabaseException $e) {
            $io->error(['The connection test failed.', $e->getMessage()]);

            return Command::FAILURE;
        }
        $io->success(sprintf(
            'Connected to %s (server version %s). This user can create and drop tables. %s',
            ReportingDatabaseConnection::label($check),
            $check['server_version'],
            $check['tables'] === [] ? 'No views have been copied yet.' : count($check['tables']).' copied view'.(count($check['tables']) === 1 ? '' : 's').' found.',
        ));

        return Command::SUCCESS;
    }
}
