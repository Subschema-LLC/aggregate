<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ApplicationUpdateService;
use App\Service\DocumentationLinks;
use App\Service\Update\ApplicationUpdater;
use App\Service\Update\DeploymentAction;
use App\Service\UpdateSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The post-deployment steps for code deployed another way, run after Plesk
 * Git, cPanel Git Version Control, a CI/CD job or rsync copies the files
 * (usually from that tool's deployment action). It runs the same steps as
 * app:updates:apply after files change, and records the deployed commit so
 * update checks can show how far behind it is.
 */
#[AsCommand(
    name: 'app:updates:deployed',
    description: 'After deploying the code another way (a hosting panel\'s Git deployment, CI/CD): migrate, rebuild and record the deployed commit',
)]
final class DeployedUpdateCommand extends Command
{
    public function __construct(
        private readonly ApplicationUpdater $updater,
        private readonly ApplicationUpdateService $updates,
        private readonly UpdateSettings $settings,
        private readonly DeploymentAction $action,
        private readonly ?DocumentationLinks $documentation = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('commit', null, InputOption::VALUE_REQUIRED, 'The deployed commit (the full 40-character hash), for example from git rev-parse HEAD or a CI variable')
            ->addOption('git-dir', null, InputOption::VALUE_REQUIRED, 'The deployment tool\'s Git repository, to read the deployed commit from (default: updates_deployment_repository, or this directory when it is a Git clone)')
            ->addOption('database-backup-confirmed', null, InputOption::VALUE_NONE, 'Confirm a current database backup exists (required for PostgreSQL, MySQL, MariaDB and SQL Server)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run the steps again even when this deployment was already finished')
            ->addOption('show-action', null, InputOption::VALUE_NONE, 'Print the deployment action for this server and exit, without changing anything')
            ->setHelp(<<<'HELP'
Run this after each deployment, as the user that owns the files: by hand, or from
your deployment tool's deployment action so it happens automatically. Remove the
compiled cache and install Composer dependencies first, with absolute paths:

  rm -rf /srv/www/analytics/var/cache/prod
  php composer.phar install --working-dir=/srv/www/analytics --no-interaction --optimize-autoloader --no-dev
  php /srv/www/analytics/bin/console app:updates:deployed --git-dir=/srv/git/aggregate.git --database-backup-confirmed

--show-action prints these commands with the paths of this server, as the Updates
page does. A hosting panel runs them as its Git deployment's deployment actions or
tasks, and a CI/CD job or rsync script runs them over SSH. Give
--commit=$(git rev-parse HEAD) instead of --git-dir when the job has the commit at
hand. The update guide shows where common hosting panels take them.

It shows a maintenance page, takes an SQLite snapshot, installs Composer dependencies
when vendor/ does not match composer.lock, runs database migrations, syncs the BI
glossary, installs and compiles dashboard assets, rebuilds the cache, signals workers,
and records the deployed commit. When the deployed files have not changed since the
last finished deployment, it does nothing (add --force to run the steps anyway).

For PostgreSQL, MySQL, MariaDB and SQL Server, pass --database-backup-confirmed only
when the database is backed up, for example by your host's scheduled backups, because
migrations cannot be reversed automatically.

When no update method has been chosen yet, this command chooses "deployed another
way" (updates_method: deployment). It refuses to run when another method is chosen.
If a step fails, the site stays in maintenance mode: fix the cause, then run this
command again or app:updates:apply --resume.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('show-action')) {
            return $this->showAction($io, $output);
        }
        try {
            $method = $this->updates->source()['method'];
            if ($method === null) {
                $problem = $this->updater->methodChangeProblem();
                if ($problem !== null) {
                    $io->error($problem);

                    return Command::FAILURE;
                }
                $this->settings->saveMethod(UpdateSettings::METHOD_DEPLOYMENT);
                $io->note('No update method was chosen, so this installation is now marked as deployed another way (saved as updates_method: deployment in config/aggregate.yaml).');
            } elseif ($method !== UpdateSettings::METHOD_DEPLOYMENT) {
                $io->error('This installation updates '.($method === UpdateSettings::METHOD_REPOSITORY ? 'directly from the repository' : 'with release ZIPs')
                    .', so it does not record deployments. If you deploy the code another way, choose that method first: php bin/console app:updates:method deployment. Guide: '.$this->guide());

                return Command::FAILURE;
            }

            $gitDir = $input->getOption('git-dir');
            if (is_string($gitDir) && $gitDir !== '') {
                $gitDir = realpath($gitDir) ?: $gitDir;
            }
            $state = $this->updater->start([
                'deployed' => true,
                'commit' => $input->getOption('commit'),
                'repository' => is_string($gitDir) && $gitDir !== '' ? $gitDir : null,
                'force' => (bool) $input->getOption('force'),
                'database_backup_confirmed' => (bool) $input->getOption('database-backup-confirmed'),
            ], static function (string $message, string $level) use ($output): void {
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

        if (($state['status'] ?? null) === 'up_to_date') {
            $io->success((string) $state['message']);

            return Command::SUCCESS;
        }
        $code = ($state['status'] ?? null) === 'completed' ? Command::SUCCESS : Command::FAILURE;
        if ($state['handed_off'] ?? false) {
            // A fresh process finished the deployment with the deployed code and
            // reported it. This one may hold an outdated container: stop here.
            exit($code);
        }
        match ($state['status'] ?? null) {
            'completed' => $io->success('The post-deployment steps are complete.'),
            'failed' => $io->error('The post-deployment steps did not run. '.($state['error'] ?? '')),
            default => $io->error('The post-deployment steps need attention. '.($state['error'] ?? '').' Run php bin/console app:updates:apply --status for details.'),
        };

        return $code;
    }

    private function showAction(SymfonyStyle $io, OutputInterface $output): int
    {
        try {
            $action = $this->action->build();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->text('Run these commands after each deployment, as the user that owns the files (for example as your deployment tool\'s deployment action):');
        $io->newLine();
        $output->writeln($action['script'], OutputInterface::OUTPUT_RAW);
        $io->newLine();
        $notes = [];
        if (!$action['composer_found']) {
            $notes[] = 'Composer was not found here. Replace "'.$action['composer'].'" with the path of Composer on this server.';
        }
        if ($action['repository_source'] === null) {
            $notes[] = $action['candidates'] === []
                ? 'Replace the --git-dir placeholder with the folder your deployment tool keeps the Git repository in, or use --commit with the deployed commit (for example --commit=$(git rev-parse HEAD) in a job that has the clone).'
                : 'Replace the --git-dir placeholder with one of: '.implode(', ', $action['candidates']).'.';
        }
        if ($action['backup_flag']) {
            $notes[] = '--database-backup-confirmed states that the database is backed up, for example by scheduled backups: migrations cannot be reversed automatically.';
        }
        $notes[] = 'Guide: '.$this->guide();
        $io->listing($notes);

        return Command::SUCCESS;
    }

    private function guide(): string
    {
        return $this->documentation?->reference('updates.deployment') ?? DocumentationLinks::file('updates.deployment');
    }
}
