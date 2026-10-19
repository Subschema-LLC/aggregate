<?php

declare(strict_types=1);

namespace App\Service\DataTransfer;

use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Records a transfer in processing_tasks. Finishing the task also writes the
 * audit trail, which is the record of the run. Details are the safe summary
 * only: no row values and no database URL.
 */
final class TransferConsole
{
    public function __construct(
        private readonly ProcessingTasks $tasks,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function trigger(InputInterface $input): TaskTrigger
    {
        $requestedBy = $input->getOption('requested-by');

        return is_string($requestedBy) && $requestedBy !== '' ? TaskTrigger::dashboard($requestedBy) : TaskTrigger::command();
    }

    /** @param callable(): TransferReport $work */
    public function run(SymfonyStyle $io, TaskTrigger $trigger, callable $work): int
    {
        $task = $this->tasks->start(DataTransfer::TASK_TYPE, DataTransfer::TASK_SUBJECT, $trigger, $this->now(), DataTransfer::LOCK_SECONDS);
        if ($task === null) {
            $io->error('A data transfer is already running.');

            return Command::FAILURE;
        }
        try {
            $report = $work();
            $this->tasks->succeed($task, $this->now(), $report->written, $report->summary());
            $io->success($report->summary());

            return Command::SUCCESS;
        } catch (DataTransferException $e) {
            $this->fail($task, $e->getMessage());
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->logger->error('Data transfer failed.', ['exception' => $e]);
            $this->fail($task, 'The transfer failed before it could finish. See the application log.');
            $io->error('The transfer failed before it could finish. See the application log.');

            return Command::FAILURE;
        }
    }

    private function fail(int $task, string $details): void
    {
        try {
            $this->tasks->fail($task, $this->now(), $details);
        } catch (\Throwable $e) {
            $this->logger->error('The data transfer task could not be recorded.', ['exception' => $e]);
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
