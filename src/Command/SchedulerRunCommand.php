<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Operations\TaskTrigger;
use App\Service\Scheduler\Scheduler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:scheduler:run',
    description: 'Run every background job that is due: maintenance, BigQuery sync and the reporting database sync',
)]
final class SchedulerRunCommand extends Command
{
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
            ->addOption('requested-by', null, InputOption::VALUE_REQUIRED, 'Set by the dashboard’s Run now: the administrator recorded with each job’s task')
            ->setHelp(<<<'HELP'
Have the server run this every five minutes; it is the only scheduled task
Aggregate needs. Each job runs when it is turned on and due:

  - analytics maintenance, once a day;
  - BigQuery sync and the reporting database sync, at their intervals.

With cron, as the user that runs Aggregate:

  */5 * * * * cd /path/to/aggregate && php bin/console app:scheduler:run --no-interaction

On Plesk, use a "Run a PHP script" scheduled task instead; the dashboard's
Scheduled tasks page and app:scheduler:status show the exact fields for this
server. A run from a terminal counts as a run by hand, so it does not prove
that the server's scheduled task works.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');
        $requestedBy = $input->getOption('requested-by');
        $trigger = match (true) {
            is_string($requestedBy) && $requestedBy !== '' => TaskTrigger::dashboard($requestedBy),
            // A terminal means a person; cron, Plesk and systemd run without one.
            $input->isInteractive() && self::isTerminal() => TaskTrigger::command(),
            default => TaskTrigger::schedule(),
        };

        try {
            $run = $this->scheduler->run($trigger, $json ? null : static function (string $label) use ($io): void {
                $io->writeln($label.'…', OutputInterface::VERBOSITY_VERBOSE);
            });
        } catch (\Throwable $e) {
            $this->logger->error('The scheduler failed.', ['exception' => $e]);
            $io->error($e instanceof \RuntimeException ? $e->getMessage() : 'The scheduler failed; see the application log.');

            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln(json_encode([
                'ran' => $run['ran'],
                'trigger' => $run['trigger'],
                'started_at' => $run['started_at']->format(\DATE_ATOM),
                'finished_at' => $run['finished_at']->format(\DATE_ATOM),
                'results' => $run['results'],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif (!$run['ran']) {
            $io->writeln('Another scheduler run is still in progress; nothing was started.');
        } else {
            // Quiet when nothing happened, so a scheduler that mails output stays quiet too.
            $done = array_values(array_filter($run['results'], static fn (array $result): bool => in_array($result['status'], ['ran', 'failed', 'busy'], true)));
            if ($done !== [] || $output->isVerbose()) {
                $io->table(['Job', 'Result', 'Details'], array_map(static fn (array $result): array => [$result['label'], $result['status'], $result['message']], $output->isVerbose() ? $run['results'] : $done));
            }
        }

        foreach ($run['results'] as $result) {
            if ($result['status'] === 'failed') {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    private static function isTerminal(): bool
    {
        return \defined('STDIN') && function_exists('stream_isatty') && @stream_isatty(\STDIN);
    }
}
