<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ForensicDatabase\ForensicDatabaseSyncRunner;
use App\Service\ViewSync\ViewSyncConsole;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:forensic-database:sync',
    description: 'Copy the selected tables and private views to the forensic database when their interval has passed',
)]
final class ForensicDatabaseSyncCommand extends Command
{
    public function __construct(
        private readonly ForensicDatabaseSyncRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Sync now, without waiting for the interval, and replace copies of unchanged tables too')
            ->addOption('view', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sync only this table or view (repeatable)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
            ->addOption('requested-by', null, InputOption::VALUE_REQUIRED, 'Set by the dashboard’s Sync now: the administrator recorded with each task')
            ->setHelp(<<<'HELP'
The server's scheduled task (app:scheduler:run every five minutes) runs this
sync when it is on; run it yourself to copy now.

Each selected table copy and private view is copied to a table of the same
name in the database that FORENSIC_DATABASE_URL names, replacing the previous
copy in one step. Visitor and session IDs in forensic_events_v1 are replaced
by hashes made with a new random key at every copy; the key is never stored,
so a hash cannot be traced back to a browser or matched across copies.

Nothing is written unless the forensic database allows it: its comment marks
it with aggregate-forensic-copy (MariaDB 10.5+, PostgreSQL), or, on an engine
that keeps no comment, forensic_database_access_restricted is true. Settings
come from the forensic database admin page, config/aggregate.yaml or
FORENSIC_DATABASE_* environment variables. Every copy is recorded in the
processing_tasks table and the audit trail.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $views = array_values(array_unique(array_map('strval', (array) $input->getOption('view'))));
        $json = (bool) $input->getOption('json');

        try {
            $result = $this->runner->run((bool) $input->getOption('force'), $views === [] ? null : $views, $json ? null : static function (string $view) use ($io): void {
                $io->writeln('Copying '.$view.'…');
            }, ViewSyncConsole::trigger($input));
        } catch (\InvalidArgumentException $e) {
            $io->error(['The forensic database settings are invalid; nothing was copied.', $e->getMessage()]);

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->logger->error('Forensic database sync failed.', ['exception' => $e]);
            $io->error('Forensic database sync failed; see the application log.');

            return Command::FAILURE;
        }

        return ViewSyncConsole::results($io, $output, $result, $json, 'Forensic database sync is turned off. Turn it on in the admin page or set forensic_database_enabled: true.');
    }
}
