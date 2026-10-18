<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReportingDatabase\ReportingDatabaseSyncRunner;
use App\Service\ViewSync\ViewSyncConsole;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reporting-database:sync',
    description: 'Copy the selected reporting views to the reporting database when their interval has passed',
)]
final class ReportingDatabaseSyncCommand extends Command
{
    public function __construct(
        private readonly ReportingDatabaseSyncRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Sync now, without waiting for the interval')
            ->addOption('view', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sync only this view (repeatable)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
            ->addOption('requested-by', null, InputOption::VALUE_REQUIRED, 'Set by the dashboard’s Sync now: the administrator recorded with each task')
            ->setHelp(<<<'HELP'
Schedule this command every five minutes, for example with cron:

  */5 * * * * cd /path/to/aggregate && php bin/console app:reporting-database:sync --no-interaction

Each selected view is copied to a table of the same name in the database that
REPORTING_DATABASE_URL names, when the configured interval has passed since its
last sync. The new copy replaces the table in one step, so BI users always read
a complete copy. Settings come from the reporting database admin page,
config/aggregate.yaml or REPORTING_DATABASE_* environment variables. Every view
sync is recorded in the processing_tasks table and the audit trail.
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
            $io->error(['The reporting database settings are invalid; nothing was copied.', $e->getMessage()]);

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->logger->error('Reporting database sync failed.', ['exception' => $e]);
            $io->error('Reporting database sync failed; see the application log.');

            return Command::FAILURE;
        }

        return ViewSyncConsole::results($io, $output, $result, $json, 'Reporting database sync is turned off. Turn it on in the admin page or set reporting_database_enabled: true.');
    }
}
