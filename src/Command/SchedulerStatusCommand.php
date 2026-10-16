<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Scheduler\Scheduler;
use App\Service\Scheduler\SchedulerSetup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:scheduler:status',
    description: 'Show whether the scheduled task runs, each job’s schedule and last run, and how to set the task up on this server',
)]
final class SchedulerStatusCommand extends Command
{
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly SchedulerSetup $setup,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the status as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $health = $this->scheduler->health();
        $jobs = [];
        foreach ($this->scheduler->jobs() as $job) {
            $jobs[] = ['job' => $job->name(), 'label' => $job->label()] + $job->status();
        }
        $plesk = $this->setup->plesk();

        if ($input->getOption('json')) {
            $date = static fn (?\DateTimeImmutable $time): ?string => $time?->format(\DATE_ATOM);
            $run = static fn (?array $record): ?array => $record === null ? null : ['started_at' => $date($record['started_at']), 'finished_at' => $date($record['finished_at'])] + $record;
            $output->writeln(json_encode([
                'state' => $health['state'],
                'last_scheduled_run' => $run($health['scheduled']),
                'last_manual_run' => $run($health['manual']),
                'jobs' => array_map(static fn (array $job): array => ['last_run' => $date($job['last_run']), 'next_due' => $date($job['next_due'])] + $job, $jobs),
                'setup' => ['plesk' => $plesk, 'cron' => $this->setup->cronLine()],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $health['state'] === 'working' ? Command::SUCCESS : Command::FAILURE;
        }

        $scheduled = $health['scheduled'];
        match ($health['state']) {
            'working' => $io->success('The scheduled task last ran '.$scheduled['started_at']->format('Y-m-d H:i').' UTC.'),
            'late' => $io->warning('The scheduled task last ran '.$scheduled['started_at']->format('Y-m-d H:i').' UTC, more than '.Scheduler::LATE_AFTER_MINUTES.' minutes ago. Check the server’s scheduled task.'),
            default => $io->warning('The scheduled task has not run yet. Set it up as shown below.'),
        };
        if ($health['manual'] !== null) {
            $io->writeln('Last run by hand: '.$health['manual']['started_at']->format('Y-m-d H:i').' UTC.');
        }
        $results = $scheduled['results'] ?? $health['manual']['results'] ?? [];
        $failed = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 'failed'));
        foreach ($failed as $result) {
            $io->error($result['label'].': '.$result['message']);
        }

        $io->table(['Job', 'Schedule', 'Last run', 'Next', 'Details'], array_map(static fn (array $job): array => [
            $job['label'],
            $job['schedule'],
            $job['last_run'] === null ? 'never' : $job['last_run']->format('Y-m-d H:i').' UTC'.($job['last_status'] !== null ? ' ('.$job['last_status'].')' : ''),
            $job['next_due'] === null ? '' : $job['next_due']->format('Y-m-d H:i').' UTC',
            $job['problem'] ?? '',
        ], $jobs));

        if ($plesk !== null) {
            $io->section('Plesk: Websites & Domains → '.$plesk['subscription'].' → Scheduled Tasks → Add Task');
            $io->definitionList(
                ['Task type' => 'Run a PHP script'],
                ['Script path' => $plesk['script_path']],
                ['Use PHP version' => $plesk['php_version']],
                ['Arguments' => $plesk['arguments']],
                ['Run' => 'Cron style: '.$plesk['schedule']],
                ['Notify' => 'Errors only'],
            );
            $io->writeln('Do not use "Run a command": Plesk runs commands in a restricted shell without PHP or this folder.');
            $io->newLine();
        }
        $io->section($plesk !== null ? 'Other servers: crontab line' : 'Crontab line for the user that runs Aggregate');
        $io->writeln($this->setup->cronLine());

        return $health['state'] === 'working' ? Command::SUCCESS : Command::FAILURE;
    }
}
