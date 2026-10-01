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

#[AsCommand(name: 'app:updates:rollback', description: 'Restore the application files from before the last update')]
final class RollbackUpdateCommand extends Command
{
    public function __construct(private readonly ApplicationUpdater $updater)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('restore-database', null, InputOption::VALUE_NONE, 'Also restore the SQLite snapshot taken before the update (discards data recorded since)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation')
            ->setHelp(<<<'HELP'
Restores the files the last update changed: release installations from the backup in
var/updates/backups/, Git checkouts by returning to the previous commit. Configuration you
own is not part of the update and is left as it is. Then the cache is rebuilt.

Files installed by a deployment tool are left as they are, because that tool owns them:
redeploy the previous commit with it. Rolling back such a deployment turns maintenance
mode off and, with --restore-database, restores its SQLite snapshot.

Database changes are separate. Migrations that already ran are not reversed. SQLite
installations can add --restore-database to put back the snapshot taken before the update,
which also discards anything recorded since. For other databases, restore your own backup.

If the console cannot start because files are half-replaced, run instead:
  php scripts/restore-update-files.php
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $status = $this->updater->status();
        if ($status === null) {
            $io->error('No update has been recorded, so there is nothing to roll back.');

            return Command::FAILURE;
        }
        $restoreDatabase = (bool) $input->getOption('restore-database');
        if (!$input->getOption('yes')) {
            if (!$input->isInteractive()) {
                $io->error('Pass --yes to roll back without prompts.');

                return Command::FAILURE;
            }
            $io->text(sprintf('Roll back update %s (%s, status: %s)?', $status['id'], $status['type'], $status['status']));
            if ($restoreDatabase) {
                $io->warning('Restoring the database snapshot discards all data recorded since the update started.');
            }
            if (!$io->confirm(($status['type'] ?? null) === 'deployment'
                ? 'Roll back now? Files from your deployment tool stay as they are; redeploy the previous commit with it.'
                : 'Restore the previous application files now?', false)) {
                return Command::FAILURE;
            }
        }

        try {
            $state = $this->updater->rollback($restoreDatabase, static function (string $message, string $level) use ($output): void {
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
            });
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ($state['files_restored'] ?? false) {
            // This process ran the newer code, whose files and compiled container
            // were just replaced: stop before shutdown listeners load them.
            exit(Command::SUCCESS);
        }

        return Command::SUCCESS;
    }
}
