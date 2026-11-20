<?php

declare(strict_types=1);

namespace App\Delivery\Cli\TrafficClassification;

use App\Features\TrafficClassification\RefreshRanges\RefreshRanges;
use App\Features\TrafficClassification\ShowRanges\ShowRanges;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:traffic:ranges',
    description: 'Show, or with --refresh download, the published IP range lists of AI and '
        .'search bots and cloud networks',
)]
final class RangesCommand
{
    public function __construct(
        private readonly RefreshRanges $refresh,
        private readonly ShowRanges $show,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Option('Download the lists now and save them under var/traffic-ranges')]
        bool $refresh = false,
        #[Option('Refresh only this source')]
        ?string $source = null,
        #[Option('Print the result as JSON')]
        bool $json = false,
    ): int {
        if ($refresh) {
            return $this->refresh($io, $output, $source, $json);
        }
        $rows = $this->show->show();
        if ($json) {
            $output->writeln(self::encode(array_map(static fn (array $row): array => [
                ...$row,
                'fetched_at' => $row['fetched_at']?->format(\DATE_ATOM),
            ], $rows)));

            return Command::SUCCESS;
        }
        $io->table(['Source', 'Operator', 'Kind', 'Ranges', 'Downloaded (UTC)'], array_map(
            static fn (array $row): array => [
                $row['source'],
                $row['operator'],
                $row['cloud'] ? 'cloud network' : ($row['class'] ?? 'by User-Agent'),
                $row['count'],
                $row['fetched_at']?->format('Y-m-d H:i') ?? 'never',
            ],
            $rows,
        ));
        $io->text('Run with --refresh to download the lists. Collection does not use them yet.');

        return Command::SUCCESS;
    }

    private function refresh(
        SymfonyStyle $io,
        OutputInterface $output,
        ?string $source,
        bool $json,
    ): int
    {
        try {
            $results = $this->refresh->refresh(new \DateTimeImmutable('now'), $source);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $failed = array_filter($results, static fn (array $r): bool => !$r['ok']);
        if ($json) {
            $output->writeln(self::encode($results));
        } else {
            $io->table(['Source', 'Result'], array_map(static fn (array $r): array => [
                $r['source'],
                $r['ok'] ? sprintf('%d ranges saved', $r['count']) : 'kept previous: '.$r['error'],
            ], $results));
        }

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function encode(array $rows): string
    {
        return json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
