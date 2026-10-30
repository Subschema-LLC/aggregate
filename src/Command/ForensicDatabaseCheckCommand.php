<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ForensicDatabase\ForensicDatabaseBackgroundSync;
use App\Service\ForensicDatabase\ForensicDatabaseConnection;
use App\Service\ForensicDatabase\ForensicDatabaseSettings;
use App\Service\ForensicDatabase\ForensicDatabaseSyncRunner;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use App\Service\ViewSync\ViewSyncConsole;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:forensic-database:check',
    description: 'Show the forensic database sync settings and status, and test the connection',
)]
final class ForensicDatabaseCheckCommand extends Command
{
    public function __construct(
        private readonly ForensicDatabaseSyncRunner $runner,
        private readonly ForensicDatabaseSettings $settings,
        private readonly ForensicDatabaseConnection $database,
        private readonly ForensicDatabaseBackgroundSync $background,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-connect', null, InputOption::VALUE_NONE, 'Only show settings and status; do not connect to the forensic database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $settings = $this->settings->toArray();
        } catch (\InvalidArgumentException $e) {
            $io->error(['The forensic database settings are invalid.', $e->getMessage()]);

            return Command::FAILURE;
        }

        try {
            $location = ForensicDatabaseConnection::label($this->database->describe());
        } catch (ReportingDatabaseException $e) {
            $location = $e->getMessage();
        }
        $io->definitionList(
            ['Sync' => $settings[ForensicDatabaseSettings::KEY_ENABLED] ? 'on, every '.$settings[ForensicDatabaseSettings::KEY_INTERVAL].' minutes' : 'off'],
            ['Forensic database' => $location],
            ['Access confirmed restricted' => $settings[ForensicDatabaseSettings::KEY_ACCESS_RESTRICTED] ? 'yes (for engines that cannot be marked)' : 'no'],
        );

        ViewSyncConsole::status($io, $this->runner->status($settings), $this->background->cronLine());

        if ($input->getOption('no-connect')) {
            return Command::SUCCESS;
        }
        try {
            $check = $this->runner->check();
        } catch (ReportingDatabaseException $e) {
            $io->error(['The connection test failed.', $e->getMessage()]);

            return Command::FAILURE;
        }
        $io->success(trim(sprintf(
            'Connected to %s (server version %s). This user can create and drop tables. %s %s',
            ForensicDatabaseConnection::label($check),
            $check['server_version'],
            $check['tables'] === [] ? 'Nothing has been copied yet.' : count($check['tables']).' cop'.(count($check['tables']) === 1 ? 'y' : 'ies').' found.',
            ForensicDatabaseConnection::markSentence($check['mark']),
        )));

        return Command::SUCCESS;
    }
}
