<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ForensicDatabase\ForensicDatabaseConfigurator;
use App\Service\ForensicDatabase\ForensicDatabaseConnection;
use App\Service\ForensicDatabase\ForensicDatabaseSettings;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use App\Service\ViewSync\ViewSyncSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:forensic-database:configure',
    description: 'Test the forensic database connection and save it to .env.local as FORENSIC_DATABASE_URL',
)]
final class ForensicDatabaseConfigureCommand extends Command
{
    public function __construct(
        private readonly ForensicDatabaseConfigurator $configurator,
        private readonly ForensicDatabaseSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'mysql (MySQL or MariaDB), pgsql, sqlsrv or sqlite')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Database server', 'localhost')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port; the type’s default when omitted')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Database name, or for SQLite a file path (default '.ForensicDatabaseConnection::DEFAULT_SQLITE_FILE.')')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'User name')
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from standard input instead of asking for it')
            ->addOption('trust-certificate', null, InputOption::VALUE_NONE, 'SQL Server: trust a self-signed server certificate')
            ->addOption('url-stdin', null, InputOption::VALUE_NONE, 'Read a complete URL, written like DATABASE_URL, from standard input')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove FORENSIC_DATABASE_URL from .env.local')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Once the connection is saved, turn the sync on for the tables and views selected (all of them unless changed)')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'With --enable: minutes between syncs ('.implode(', ', ViewSyncSettings::INTERVALS).')')
            ->setHelp(<<<'HELP'
Tests a connection the way a sync uses it (connect, create and drop a table,
check that the database may receive forensic copies) and saves it to
.env.local only when the test passes. The application and reporting databases
are refused. Mark the database first (see the forensic database guide), or,
on MySQL, SQL Server and SQLite, set forensic_database_access_restricted: true.
The password is asked for, or read from standard input:

  php bin/console app:forensic-database:configure --type=pgsql --host=db.example.net \
      --database=aggregate_forensic --user=aggregate_forensic_sync

--enable also turns the sync on in the same step, for the tables and views
selected (every one of them unless changed), and --interval sets how often it
copies them. Without --enable the sync stays as it was: turn it on on the
Forensic database page or with forensic_database_enabled: true.
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
                $io->success(ForensicDatabaseConnection::VARIABLE.' was removed from .env.local. Copies already made stay in the forensic database until you drop them there.');

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
            'Connected to %s (server version %s) and saved %s to .env.local. This user can create and drop tables. %s',
            ForensicDatabaseConnection::label($check),
            $check['server_version'],
            ForensicDatabaseConnection::VARIABLE,
            ForensicDatabaseConnection::markSentence($check['mark']),
        )));
        if (!$input->getOption('enable')) {
            $io->writeln('Next: choose what to copy and turn the sync on, on the Forensic database page or with forensic_database_* settings in config/aggregate.yaml. Or run this again with --enable.');

            return Command::SUCCESS;
        }

        try {
            $settings = $this->settings->enable($interval === null ? null : (int) $interval);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error(['The connection is saved, but the sync was not turned on.', $e->getMessage()]);

            return Command::FAILURE;
        }
        if (!$settings[ForensicDatabaseSettings::KEY_ENABLED]) {
            $io->warning('The connection is saved, but '.strtoupper(ForensicDatabaseSettings::KEY_ENABLED).' in the server environment keeps the sync off. Change it there.');

            return Command::FAILURE;
        }
        $copies = count(ForensicDatabaseSettings::selectedViews($settings));
        $io->success(sprintf(
            'The sync is on: %s, every %d minutes. The scheduled task makes the first copy within five minutes; app:forensic-database:sync --force copies them now.',
            $copies === 1 ? 'one table or view' : $copies.' tables and views',
            $settings[ForensicDatabaseSettings::KEY_INTERVAL],
        ));

        return Command::SUCCESS;
    }

    private static function readLine(): string
    {
        $line = fgets(\STDIN);

        return $line === false ? '' : rtrim($line, "\r\n");
    }
}
