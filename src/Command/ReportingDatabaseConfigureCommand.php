<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReportingDatabase\ReportingDatabaseConfigurator;
use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use App\Service\ReportingDatabase\ReportingDatabaseSettings;
use App\Service\ViewSync\ViewSyncSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reporting-database:configure',
    description: 'Test the reporting database connection and save it to .env.local as REPORTING_DATABASE_URL',
)]
final class ReportingDatabaseConfigureCommand extends Command
{
    public function __construct(
        private readonly ReportingDatabaseConfigurator $configurator,
        private readonly ReportingDatabaseSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'mysql (MySQL or MariaDB), pgsql, sqlsrv or sqlite')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Database server', 'localhost')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port; the type’s default when omitted')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Database name, or for SQLite a file path (default '.ReportingDatabaseConnection::DEFAULT_SQLITE_FILE.')')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User name')
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from standard input instead of asking for it')
            ->addOption('trust-certificate', null, InputOption::VALUE_NONE, 'SQL Server: trust a self-signed server certificate')
            ->addOption('url-stdin', null, InputOption::VALUE_NONE, 'Read a complete URL, written like DATABASE_URL, from standard input')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove REPORTING_DATABASE_URL from .env.local')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Once the connection is saved, turn the sync on for every approved view and no private view')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'With --enable: minutes between syncs ('.implode(', ', ViewSyncSettings::INTERVALS).')')
            ->setHelp(<<<'HELP'
Tests a connection the way a sync uses it (connect, then create and drop a
table) and saves it to .env.local only when the test passes. The application
database is refused. The password is asked for, or read from standard input,
so it stays out of the shell history:

  php bin/console app:reporting-database:configure --type=pgsql --host=db.example.net \
      --database=aggregate_reporting --user=aggregate_sync

  printf '%s\n' "$PASSWORD" | php bin/console app:reporting-database:configure \
      --type=mysql --database=aggregate_reporting --user=aggregate_sync --password-stdin

  php bin/console app:reporting-database:configure --type=sqlite

Leave the password empty to keep the saved one for the same type, server and
user. The Reporting database admin page does the same.

--enable also turns the sync on for every approved view, and none of the
private ones, in one step: what a reporting copy for BI users needs.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $interval = $input->getOption('interval');
        if ($interval !== null && (!$input->getOption('enable') || preg_match('/^[0-9]{1,5}$/D', (string) $interval) !== 1 || !in_array((int) $interval, ViewSyncSettings::INTERVALS, true))) {
            $io->error('--interval goes with --enable and must be one of '.implode(', ', ViewSyncSettings::INTERVALS).' minutes.');

            return Command::INVALID;
        }
        try {
            if ($input->getOption('remove')) {
                $this->configurator->remove();
                $io->success('REPORTING_DATABASE_URL was removed from .env.local. Tables already copied stay in the reporting database.');

                return Command::SUCCESS;
            }
            if ($input->getOption('url-stdin')) {
                $check = $this->configurator->saveUrl(self::readLine());
            } else {
                $type = (string) $input->getOption('type');
                if ($type === '') {
                    $io->error('Choose --type: mysql (MySQL or MariaDB), pgsql, sqlsrv or sqlite. Or pass a URL with --url-stdin.');

                    return Command::INVALID;
                }
                $password = '';
                if ($type !== 'sqlite') {
                    $password = $input->getOption('password-stdin')
                        ? self::readLine()
                        : (string) ($input->isInteractive() ? $io->askHidden('Password (leave empty to keep the saved one)', static fn (?string $value): string => (string) $value) : '');
                }
                $check = $this->configurator->save([
                    'type' => $type,
                    'host' => (string) $input->getOption('host'),
                    'port' => (string) $input->getOption('port'),
                    'database' => (string) $input->getOption('database'),
                    'user' => (string) $input->getOption('user'),
                    'password' => $password,
                    'trust_certificate' => $input->getOption('trust-certificate') ? '1' : '',
                ]);
            }
        } catch (\InvalidArgumentException|ReportingDatabaseException $e) {
            $io->error(['Nothing was saved.', $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->success(trim(sprintf(
            'Connected to %s (server version %s) and saved REPORTING_DATABASE_URL to .env.local. This user can create and drop tables. %s',
            ReportingDatabaseConnection::label($check),
            $check['server_version'],
            ReportingDatabaseConnection::markSentence($check['mark']),
        )));
        if (!$input->getOption('enable')) {
            $io->writeln('Next: choose the views and turn the sync on, on the Reporting database page or with reporting_database_* settings in config/aggregate.yaml. Or run this again with --enable.');

            return Command::SUCCESS;
        }

        try {
            $settings = $this->settings->enableApprovedViews($interval === null ? null : (int) $interval);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error(['The connection is saved, but the sync was not turned on.', $e->getMessage()]);

            return Command::FAILURE;
        }
        if (!$settings[ReportingDatabaseSettings::KEY_ENABLED]) {
            $io->warning('The connection is saved, but '.strtoupper(ReportingDatabaseSettings::KEY_ENABLED).' in the server environment keeps the sync off. Change it there.');

            return Command::FAILURE;
        }
        $io->success(sprintf(
            'The sync is on: %d approved view%s%s every %d minutes. The scheduled task copies them; app:reporting-database:sync --force copies them now.',
            count($settings[ReportingDatabaseSettings::KEY_VIEWS]),
            count($settings[ReportingDatabaseSettings::KEY_VIEWS]) === 1 ? '' : 's',
            $settings[ReportingDatabaseSettings::KEY_PRIVATE_VIEWS] === [] ? '' : ' and '.count($settings[ReportingDatabaseSettings::KEY_PRIVATE_VIEWS]).' private view(s) the environment sets',
            $settings[ReportingDatabaseSettings::KEY_INTERVAL],
        ));

        return Command::SUCCESS;
    }

    private static function readLine(): string
    {
        $line = fgets(\STDIN);

        return $line === false ? '' : rtrim($line, "\r\n");
    }
}
