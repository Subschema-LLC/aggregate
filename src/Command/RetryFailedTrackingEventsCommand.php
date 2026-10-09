<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Operations\TaskTrigger;
use App\Service\TrackingFailureRetryRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tracking:retry-failed',
    description: 'Retry failed enhanced tracking messages from the Messenger failed transport',
)]
final class RetryFailedTrackingEventsCommand extends Command
{
    public function __construct(private readonly TrackingFailureRetryRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum failed tracking messages to re-dispatch in this run (1-1000)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $input->getOption('limit');
        if ($limit !== null && (!is_string($limit) || preg_match('/^[0-9]{1,4}$/D', $limit) !== 1)) {
            $io->error('--limit must be a whole number from 1 to 1000.');

            return Command::INVALID;
        }

        $result = $this->runner->retryNow(
            TaskTrigger::command(),
            $limit === null ? null : (int) $limit,
        );

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $io->table(['Status', 'Retried', 'Scanned', 'Ignored', 'Details'], [[
                $result['status'],
                (string) $result['retried'],
                (string) $result['scanned'],
                (string) $result['ignored'],
                $result['message'],
            ]]);
        }

        return $result['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;
    }
}
