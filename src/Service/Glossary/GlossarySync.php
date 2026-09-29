<?php

declare(strict_types=1);

namespace App\Service\Glossary;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;

/** Publishes declared metadata only; never queries event or reporting data. */
class GlossarySync
{
    private const COLUMNS = [
        'entry_type', 'subject', 'code', 'locale', 'label', 'label_locale',
        'group_label', 'description', 'description_locale', 'sort_order',
        'is_default_locale', 'source',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly GlossaryResolver $resolver,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return $this->resolver->resolve();
    }

    /** @return array{insert: int, change: int, delete: int, total: int, changed: bool} */
    public function diff(?array $rows = null): array
    {
        $expected = $this->index($rows ?? $this->rows());
        $actual = $this->index($this->connection->fetchAllAssociative(
            'SELECT '.implode(', ', self::COLUMNS).' FROM analytics_glossary',
        ));
        $insert = count(array_diff_key($expected, $actual));
        $delete = count(array_diff_key($actual, $expected));
        $change = 0;
        foreach (array_intersect_key($expected, $actual) as $key => $row) {
            if ($row !== $actual[$key]) {
                ++$change;
            }
        }

        return [
            'insert' => $insert, 'change' => $change, 'delete' => $delete,
            'total' => count($expected), 'changed' => $insert + $change + $delete > 0,
        ];
    }

    /**
     * @return array{insert: int, change: int, delete: int, total: int, changed: bool,
     *     written: int, dimensions: int, locales: list<string>, fallback_counts: array<string, int>}
     */
    public function sync(): array
    {
        $rows = $this->rows();
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $diff = $this->connection->transactional(function () use ($rows): array {
                    $diff = $this->diff($rows);
                    if (!$diff['changed']) {
                        return $diff;
                    }
                    $this->connection->executeStatement('DELETE FROM analytics_glossary');
                    $this->insert($rows);

                    return $diff;
                });

                return $diff + $this->summary($rows, $diff['changed'] ? count($rows) : 0);
            } catch (RetryableException|UniqueConstraintViolationException $exception) {
                // A concurrent PostgreSQL READ COMMITTED replacement can make
                // a waiting DELETE miss the newly inserted rows. Re-reading in
                // a fresh transaction turns the resulting key conflict into a
                // no-op when both writers resolved the same glossary.
                if ($attempt === 1) {
                    if ($exception instanceof UniqueConstraintViolationException) {
                        throw new \RuntimeException('The BI glossary could not be published after retrying a key conflict. Check for declared codes that compare equal under the database collation, or rerun app:analytics:glossary:sync after concurrent syncs finish.', 0, $exception);
                    }
                    throw new \RuntimeException('The BI glossary is locked by another sync. Retried once; please run app:analytics:glossary:sync again.', 0, $exception);
                }
            }
        }

        throw new \LogicException('The glossary sync did not complete.');
    }

    /** CSV is shared by the headless command and the administrator download. */
    public static function csv(array $rows, bool $missingOnly = false): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Could not create the glossary CSV export.');
        }
        try {
            $columns = [...self::COLUMNS, 'is_fallback'];
            fputcsv($stream, $columns, ',', '"', '');
            foreach ($rows as $row) {
                $row['is_fallback'] = $row['label_locale'] === $row['locale'] ? 0 : 1;
                if ($missingOnly && !$row['is_fallback']) {
                    continue;
                }
                $cells = [];
                foreach ($columns as $column) {
                    $value = (string) ($row[$column] ?? '');
                    // Also protect formulas hidden behind leading whitespace.
                    $cells[] = preg_match('/^[\x00-\x20]*[=+@-]/', $value) === 1 ? "'".$value : $value;
                }
                fputcsv($stream, $cells, ',', '"', '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) {
                throw new \RuntimeException('Could not read the glossary CSV export.');
            }

            return $csv;
        } finally {
            fclose($stream);
        }
    }

    private function index(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $normalized = [];
            foreach (self::COLUMNS as $column) {
                $normalized[$column] = match ($column) {
                    'sort_order', 'is_default_locale' => (int) $row[$column],
                    default => $row[$column] === null ? null : (string) $row[$column],
                };
            }
            $key = json_encode([$row['entry_type'], $row['subject'], (string) $row['code'], $row['locale']], JSON_THROW_ON_ERROR);
            $indexed[$key] = $normalized;
        }

        return $indexed;
    }

    private function insert(array $rows): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $batchSize = match (true) {
            $platform instanceof SQLitePlatform => 70, // Also fits older SQLite's 999 parameter limit.
            $platform instanceof SQLServerPlatform => 150, // SQL Server permits at most 2,100 parameters.
            default => 200,
        };
        $columns = [...self::COLUMNS, 'synced_at'];
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format($platform->getDateTimeFormatString());
        foreach (array_chunk($rows, $batchSize) as $batch) {
            $parameters = $types = $placeholders = [];
            foreach ($batch as $row) {
                $row['synced_at'] = $timestamp;
                $placeholders[] = '('.implode(', ', array_fill(0, count($columns), '?')).')';
                foreach ($columns as $column) {
                    $value = $row[$column];
                    $parameters[] = $value;
                    $types[] = match (true) {
                        $value === null => ParameterType::NULL,
                        in_array($column, ['sort_order', 'is_default_locale'], true) => ParameterType::INTEGER,
                        default => ParameterType::STRING,
                    };
                }
            }
            $this->connection->executeStatement(
                'INSERT INTO analytics_glossary ('.implode(', ', $columns).') VALUES '.implode(', ', $placeholders),
                $parameters,
                $types,
            );
        }
    }

    private function summary(array $rows, int $written): array
    {
        $dimensions = $locales = $fallbackCounts = [];
        foreach ($rows as $row) {
            $locales[$row['locale']] = true;
            $fallbackCounts[$row['locale']] ??= 0;
            if ($row['entry_type'] === 'value') {
                $dimensions[$row['subject']] = true;
            }
            if ($row['label_locale'] !== $row['locale']) {
                ++$fallbackCounts[$row['locale']];
            }
        }

        return [
            'written' => $written, 'dimensions' => count($dimensions),
            'locales' => array_keys($locales), 'fallback_counts' => $fallbackCounts,
        ];
    }
}
