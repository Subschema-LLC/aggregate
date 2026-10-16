<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\MysqlTimeZoneTables;
use App\Service\ReportingTimezone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:analytics:mysql-timezones:load',
    description: 'Load MySQL or MariaDB time zone tables so a reporting timezone can be compiled',
)]
final class LoadMysqlTimeZonesCommand extends Command
{
    public function __construct(
        private readonly MysqlTimeZoneTables $timeZones,
        private readonly ReportingTimezone $timezone,
        private readonly AnalyticsPrivacySettings $settings,
        private readonly AnonymousBiViewManager $views,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
Named reporting timezones on MySQL and MariaDB need the server's time zone
tables. This loads them when this process can administer a local database:
Unix-socket root, a readable Plesk admin password, or MYSQL_ROOT_PASSWORD /
MARIADB_ROOT_PASSWORD in the environment. Otherwise it prints the one command
to run on the database server. It does not print passwords.

After a successful load, a reporting timezone other than UTC is compiled into
the event views. PostgreSQL, SQLite and SQL Server do not need this. With this
repository's Docker Compose, make load-mysql-timezones loads the running
database container instead.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->timeZones->applies()) {
            $io->success('This database does not need MySQL time zone tables.');

            return Command::SUCCESS;
        }

        try {
            $message = $this->timeZones->load();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success($message);

        try {
            $name = $this->timezone->name();
        } catch (\Throwable $e) {
            $io->warning('Time zone tables are loaded, but the reporting timezone could not be read: '.$e->getMessage());

            return Command::FAILURE;
        }
        if (ReportingTimezone::isIdentity($name)) {
            $io->note('The reporting timezone is still UTC. Set one when event hours should be labelled in another zone.');

            return Command::SUCCESS;
        }

        try {
            $thresholds = $this->settings->getMinimumCellCounts();
            $this->views->regenerate($thresholds['anonymous'], $thresholds['geo']);
        } catch (\Throwable $e) {
            $io->error('Time zone tables are loaded, but the BI views were not regenerated: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Regenerated the BI views for '.$name.'.');

        return Command::SUCCESS;
    }
}
