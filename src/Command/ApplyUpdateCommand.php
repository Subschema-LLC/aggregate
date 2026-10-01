<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Update\ApplicationUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:updates:apply',
    description: 'Install the latest update from a signed release package or the official Git branch, then migrate and rebuild',
)]
final class ApplyUpdateCommand extends Command
{
    public function __construct(private readonly ApplicationUpdater $updater)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('release', null, InputOption::VALUE_REQUIRED, 'Release installations: install this version (YYYY.MM.NN) instead of the latest')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Release installations: a downloaded aggregate-YYYY.MM.NN.zip to install instead of downloading')
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'With --package: the downloaded aggregate-release.json')
            ->addOption('signature', null, InputOption::VALUE_REQUIRED, 'With --package: the downloaded aggregate-release.json.sig')
            ->addOption('database-backup-confirmed', null, InputOption::VALUE_NONE, 'Confirm a current database backup exists (required for PostgreSQL, MySQL, MariaDB and SQL Server)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Continue an interrupted update from its last step')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Show the last update and exit')
            ->addOption('preflight', null, InputOption::VALUE_NONE, 'Run the system check: whether an update can run here, without changing anything')
            ->addOption('json', null, InputOption::VALUE_NONE, 'With --status or --preflight: print JSON')
            ->setHelp(<<<'HELP'
Run as the user that owns the application files. It uses the update method chosen with
app:updates:method or updates_method (until one is chosen: repository for a Git clone,
release otherwise):

  release (recommended): downloads the newest signed release for updates_branch
  from GitHub, or installs the files given with --package, --manifest and --signature. The
  signature is verified with the installation's trusted key before anything changes.

  repository (advanced, Git clones only): fast-forwards from the configured repository, then runs composer install when
  dependencies changed and compiles dashboard assets.

Both then run database migrations, sync the BI glossary, rebuild the cache and signal workers.

  deployment: the code is deployed another way (a hosting panel's Git deployment, CI/CD), so this command
  installs nothing. Run app:updates:deployed after each deployment instead; --resume still
  continues its steps if they stopped.

Your configuration is never overwritten: .env.local, config/aggregate*.yaml, config/websites.yaml,
config/tag-manager/sites/, config/*.local.yaml and var/ are left alone. Edits to the shipped
config/goals.yaml, config/navigation.yaml or config/quick_search.yaml move to the matching
config/NAME.local.yaml override first. New keys in the shipped .env are appended; existing values stay.

Web requests receive a 503 maintenance page while files change. Changed files are backed up in
var/updates/backups/ (the last three updates are kept). If a step fails before files change, nothing
is changed. If installing files fails, they are restored automatically. If a later step fails, the site
stays in maintenance mode: fix the cause and run --resume, or run app:updates:rollback.

SQLite databases are snapshotted automatically. For other databases, back up first and pass
--database-backup-confirmed. Rolling back files does not reverse database migrations.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');

        if ($input->getOption('status')) {
            $status = $this->updater->status();
            if ($json) {
                $output->writeln(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

                return Command::SUCCESS;
            }
            if ($status === null) {
                $io->text('No update has been recorded on this installation.');

                return Command::SUCCESS;
            }
            $io->definitionList(
                ['Update' => $status['id']],
                ['Type' => $status['type']],
                ['Status' => $status['status'].($status['running'] ? ' (running now)' : '')],
                ['Step' => (string) ($status['step'] ?? '-')],
                ['From' => $this->describe($status['from'] ?? [])],
                ['To' => $this->describe($status['to'] ?? [])],
                ['Backup' => (string) ($status['backup'] ?? '-')],
            );
            foreach (array_slice($status['log'] ?? [], -15) as $entry) {
                $io->text(gmdate('H:i:s', (int) $entry['at']).'  '.OutputFormatter::escape((string) $entry['message']));
            }

            return Command::SUCCESS;
        }

        if ($input->getOption('preflight')) {
            try {
                $result = $this->updater->preflight();
            } catch (\Throwable $e) {
                $result = ['problems' => [$e->getMessage()], 'warnings' => []];
            }
            if ($json) {
                $output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            } else {
                $marks = ['ok' => '<info>OK</info>', 'info' => 'Info', 'warning' => '<comment>Warning</comment>', 'error' => '<error>Error</error>'];
                $table = $io->createTable()->setHeaders(['Status', 'Check', 'Details'])->setColumnMaxWidth(1, 28)->setColumnMaxWidth(2, 72);
                $table->setRows(array_map(static fn (array $check): array => [
                    $marks[$check['status']] ?? $check['status'],
                    OutputFormatter::escape($check['label']).($check['scope'] === 'dashboard' ? ' (dashboard only)' : ''),
                    OutputFormatter::escape($check['detail']),
                ], $result['checks'] ?? []));
                $table->render();
                $io->newLine();
                $result['problems'] === [] ? $io->success('This installation is ready to update from the command line.') : $io->error($result['problems']);
            }

            return $result['problems'] === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $write = static function (string $message, string $level) use ($output): void {
            if ($message === '') {
                return;
            }
            match ($level) {
                'raw' => $output->writeln($message, OutputInterface::OUTPUT_RAW),
                'warning' => $output->writeln('<comment>! '.OutputFormatter::escape($message).'</comment>'),
                'error' => $output->writeln('<error>'.OutputFormatter::escape($message).'</error>'),
                'success' => $output->writeln('<info>'.OutputFormatter::escape($message).'</info>'),
                default => $output->writeln(' '.OutputFormatter::escape($message)),
            };
        };

        try {
            if ($input->getOption('resume')) {
                $state = $this->updater->resume($write);
            } else {
                if ($this->updater->installationType() === 'deployment') {
                    $io->error('This installation is deployed another way (such as a hosting panel\'s Git deployment or CI/CD), so this command installs nothing. Deploy with that tool, then run php bin/console app:updates:deployed.');

                    return Command::FAILURE;
                }
                $confirmed = (bool) $input->getOption('database-backup-confirmed');
                if (!$input->getOption('yes')) {
                    if (!$input->isInteractive()) {
                        $io->error('Pass --yes to update without prompts.');

                        return Command::FAILURE;
                    }
                    $io->text([
                        'This installs the update in place ('.$this->updater->installationType().' installation), runs database migrations,',
                        'and shows a maintenance page to visitors while it runs. Your configuration files are kept.',
                    ]);
                    if (!$this->updater->isSqlite() && !$confirmed) {
                        $confirmed = $io->confirm('Have you backed up the database? Migrations cannot be reversed automatically.', false);
                        if (!$confirmed) {
                            $io->warning('Back up the database, then run the update again.');

                            return Command::FAILURE;
                        }
                    }
                    if (!$io->confirm('Install the update now?', false)) {
                        return Command::FAILURE;
                    }
                }
                $state = $this->updater->start([
                    'version' => $input->getOption('release'),
                    'package' => $input->getOption('package'),
                    'manifest' => $input->getOption('manifest'),
                    'signature' => $input->getOption('signature'),
                    'database_backup_confirmed' => $confirmed,
                ], $write);
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (($state['status'] ?? null) === 'up_to_date') {
            $io->success('Already up to date. '.$state['message']);

            return Command::SUCCESS;
        }
        $code = ($state['status'] ?? null) === 'completed' ? Command::SUCCESS : Command::FAILURE;
        if ($state['handed_off'] ?? false) {
            // The new release finished the update and reported it. This process
            // still runs the previous code and its compiled container is gone, so
            // stop here instead of letting shutdown listeners load missing files.
            exit($code);
        }
        $this->report($io, $state);

        return $code;
    }

    /** @param array<string, mixed> $state */
    private function report(SymfonyStyle $io, array $state): void
    {
        $files = $state['report']['files'] ?? [];
        foreach ($files['overrides_created'] ?? [] as $override) {
            $io->note('Your edits now live in '.$override.'. It replaces the matching shipped parameter as a whole, so compare it with the new default for added entries.');
        }
        foreach ($files['kept'] ?? [] as $path => $reason) {
            $io->note($path.': '.$reason);
        }
        if (($files['replaced_modified_count'] ?? 0) > 0) {
            $io->warning(sprintf('%d locally edited application files were replaced. The edited copies are in %s/files/.', $files['replaced_modified_count'], $state['backup']));
        }
        if (($files['env_keys_added'] ?? []) !== []) {
            $io->note('New release defaults were added to .env: '.implode(', ', $files['env_keys_added']).'. Override them in .env.local.');
        }
        if (($state['type'] ?? null) === 'deployment') {
            match ($state['status'] ?? null) {
                'completed' => $io->success('The post-deployment steps are complete.'),
                'failed' => $io->error('The post-deployment steps did not run. '.($state['error'] ?? '')),
                default => $io->error('The post-deployment steps need attention. '.($state['error'] ?? '').' Run app:updates:apply --status for details.'),
            };

            return;
        }
        match ($state['status'] ?? null) {
            'completed' => $io->success('The update is complete.'),
            'failed' => $io->error('The update did not complete. '.($state['error'] ?? '')),
            default => $io->error('The update needs attention. '.($state['error'] ?? '').' Run app:updates:apply --status for details.'),
        };
    }

    /** @param array<string, mixed> $point */
    private function describe(array $point): string
    {
        return trim(($point['version'] ?? '').' '.(isset($point['commit']) ? substr((string) $point['commit'], 0, 12) : '')) ?: '-';
    }
}
