<?php

declare(strict_types=1);

namespace App\Service\ViewSync;

use App\Service\Operations\TaskTrigger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Output shared by the view sync commands, app:bigquery:* and app:reporting-database:*. */
final class ViewSyncConsole
{
    /**
     * Each selected view's latest sync, and a warning with the crontab line
     * when the scheduled sync has not run recently.
     *
     * @param array{views: list<array<string, mixed>>, scheduler_late: bool} $status ViewSyncRunner::status()
     */
    public static function status(SymfonyStyle $io, array $status, string $cronLine): void
    {
        $io->table(['View', 'Status', 'Last sync', 'Rows', 'Next', 'Details'], array_map(static fn (array $view): array => [
            $view['view'].($view['private'] ? ' (private)' : ''),
            $view['status'],
            $view['succeeded_at']?->format('Y-m-d H:i').($view['succeeded_at'] ? ' UTC' : ''),
            $view['row_count'] ?? '',
            $view['next_due'] === null ? 'next run' : $view['next_due']->format('Y-m-d H:i').' UTC',
            $view['message'] ?? '',
        ], $status['views']));
        if ($status['scheduler_late']) {
            $io->warning('The sync has not run recently. Have the server run app:scheduler:run every five minutes; app:scheduler:status shows how on this server. With cron: '.$cronLine);
        }
    }

    /** The administrator the dashboard's "Sync now" passes as --requested-by, as the tasks' trigger. */
    public static function trigger(InputInterface $input): ?TaskTrigger
    {
        $requestedBy = $input->getOption('requested-by');

        return is_string($requestedBy) && $requestedBy !== '' ? TaskTrigger::dashboard($requestedBy) : null;
    }

    /**
     * Prints a sync run's results as a table or JSON, and returns the exit
     * code: failure when any view failed.
     *
     * @param array{enabled: bool, results: list<array{view: string, status: string, rows: ?int, message: string}>} $result ViewSyncRunner::run()
     * @param string                                                                                                 $offNote shown when the sync is turned off
     */
    public static function results(SymfonyStyle $io, OutputInterface $output, array $result, bool $json, string $offNote): int
    {
        if ($json) {
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif (!$result['enabled']) {
            $io->note($offNote);
        } else {
            $io->table(['View', 'Result', 'Rows', 'Details'], array_map(static fn (array $item): array => [$item['view'], $item['status'], $item['rows'] ?? '', $item['message']], $result['results']));
        }

        foreach ($result['results'] as $item) {
            if ($item['status'] === 'failed') {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
