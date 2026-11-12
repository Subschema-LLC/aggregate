<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\DocumentationLinks;
use App\Service\Operations\QueueRetention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows the queue (messenger_messages) and how long its messages are kept,
 * or sets queue_retention_days with the validation the Data lifecycle page
 * uses. Daily maintenance deletes older messages.
 */
#[AsCommand(
    name: 'app:queue:retention',
    description: 'Show the queued and failed enhanced events and how long they are kept, or set queue_retention_days',
)]
final class QueueRetentionCommand extends Command
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AnalyticsDataLifecyclePolicy $policy,
        private readonly QueueRetention $queue,
        private readonly ?DocumentationLinks $documentation = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('days', InputArgument::OPTIONAL, 'Days after which queued and failed messages are deleted, 1 to '.AnalyticsDataLifecyclePolicy::MAX_QUEUE_RETENTION_DAYS.'. Omit it to show the queue.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the queue and the setting as JSON')
            ->setHelp(<<<HELP
                Enhanced events wait in messenger_messages for a worker, and stay in its failed transport
                when every attempt to write them fails. Daily maintenance on the administration server
                (app:analytics:maintain, run by the scheduled task) deletes every message older than
                queue_retention_days, 7 by default. It cannot be turned off. A deleted message's event is
                lost. QUEUE_RETENTION_DAYS takes precedence; the Data lifecycle page has the same setting.

                {$this->guide()}
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = $input->getArgument('days');
        if (is_string($days) && $days !== '') {
            try {
                $value = AnalyticsDataLifecyclePolicy::validate([AnalyticsDataLifecyclePolicy::KEY_QUEUE_RETENTION_DAYS => $days])[AnalyticsDataLifecyclePolicy::KEY_QUEUE_RETENTION_DAYS];
                $this->config->updateMany(static fn (): array => [AnalyticsDataLifecyclePolicy::KEY_QUEUE_RETENTION_DAYS => $value]);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->success(sprintf('Queued and failed messages are now deleted after %d days, at the next daily maintenance.', $value));
            if ($this->policy->isEnvironmentOverridden(AnalyticsDataLifecyclePolicy::KEY_QUEUE_RETENTION_DAYS)) {
                $io->note('QUEUE_RETENTION_DAYS overrides the saved value.');
            }

            return Command::SUCCESS;
        }

        try {
            $retention = $this->policy->getQueueRetentionDays();
            $queues = $this->queue->summary();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                AnalyticsDataLifecyclePolicy::KEY_QUEUE_RETENTION_DAYS => $retention,
                'queues' => array_map(static fn (array $queue): array => [
                    'messages' => $queue['messages'],
                    'oldest' => $queue['oldest']?->format(\DATE_ATOM),
                ], $queues),
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        $io->writeln(sprintf('Queued and failed messages are deleted after %d days.', $retention));
        if ($queues === []) {
            $io->writeln('Nothing is queued.');

            return Command::SUCCESS;
        }
        $io->table(['Queue', 'Messages', 'Oldest (UTC)'], array_map(static fn (string $name, array $queue): array => [
            $name === 'failed' ? 'failed (every attempt failed)' : $name,
            (string) $queue['messages'],
            $queue['oldest']?->format('Y-m-d H:i') ?? '-',
        ], array_keys($queues), $queues));

        return Command::SUCCESS;
    }

    private function guide(): string
    {
        return $this->documentation?->reference('configuration.lifecycle') ?? DocumentationLinks::file('configuration.lifecycle');
    }
}
