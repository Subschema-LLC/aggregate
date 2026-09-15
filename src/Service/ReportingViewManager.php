<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;

/** Private projections of retained raw events, separate from the thresholded BI views. */
class ReportingViewManager
{
    public const VIEW_NAMES = [
        'analytics_custom_events_v1',
        'analytics_custom_pageviews_v1',
        'analytics_custom_goals_v1',
    ];

    public const BUILTIN_COLUMNS = [
        'id', 'website_token', 'event_name', 'page_path', 'referrer_channel',
        'privacy_mode', 'device_class', 'viewport_bucket', 'geo_area',
        'goal_event', 'created_at', 'archived_at',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly CustomDataSettings $settings,
    ) {
    }

    /**
     * Discover property metadata without returning any captured values.
     *
     * The bounded sample covers the latest retained rows with custom data,
     * including rows already archived but not yet deleted by retention.
     *
     * @return list<array{key: string, types: list<string>, event_count: int}>
     */
    public function discoverProperties(int $limit = 1000): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('The discovery sample size must be between 1 and 10000 events.');
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('custom_data')
            ->from('events')
            ->where('custom_data IS NOT NULL')
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();

        $properties = [];
        foreach ($rows as $json) {
            if (!is_string($json)) {
                continue;
            }
            try {
                $data = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!$data instanceof \stdClass) {
                continue;
            }
            foreach ($data as $key => $value) {
                $key = (string) $key;
                // Control characters and unbounded keys are not useful model
                // suggestions and must not enter terminal or browser output.
                if ($key === '' || strlen($key) > 128 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
                    continue;
                }
                $properties[$key] ??= ['key' => $key, 'types' => [], 'event_count' => 0];
                $type = match (true) {
                    $value === null => 'null',
                    is_bool($value) => 'boolean',
                    is_int($value), is_float($value) => 'number',
                    is_string($value) => 'string',
                    is_array($value) => 'array',
                    default => 'object',
                };
                $properties[$key]['types'][$type] = true;
                ++$properties[$key]['event_count'];
            }
        }

        ksort($properties, SORT_STRING);
        foreach ($properties as &$property) {
            $property['types'] = array_keys($property['types']);
            sort($property['types'], SORT_STRING);
        }
        unset($property);

        return array_values($properties);
    }

    /** @return array<string, string> View name => portable-platform-specific DDL. */
    public function previewSql(): array
    {
        $platform = $this->platform();
        $statements = [];
        foreach ($this->selectSql($platform) as $name => $select) {
            $statements[$name] = implode(";\n", $this->viewStatements($platform, $name, $select)).';';
        }

        return $statements;
    }

    /**
     * Regenerate only these private views. Server-database replacement retains
     * existing grants. Existing column names/order are kept on every platform,
     * avoiding column-grant reassignment and PostgreSQL replacement failures.
     *
     * @return list<string>
     */
    public function regenerate(): array
    {
        $platform = $this->platform();
        $selects = $this->selectSql($platform);
        if ($this->connection->isTransactionActive()) {
            throw new \RuntimeException('View regeneration requires a connection without an active transaction.');
        }

        $updated = [];
        $regenerate = function () use ($platform, $selects, &$updated): array {
            $this->assertColumnsCanBeReplaced($platform);
            // Resolve every table, column and JSON function before changing any
            // view. Fetch no event data during this schema/permission check.
            foreach ($selects as $select) {
                $this->connection->executeQuery($select.' AND 1 = 0')->free();
            }
            foreach ($selects as $name => $select) {
                foreach ($this->viewStatements($platform, $name, $select) as $statement) {
                    $this->connection->executeStatement($statement);
                }
                $updated[] = $name;
            }

            return $updated;
        };

        try {
            // MySQL/MariaDB implicitly commit DDL. All other supported engines
            // can restore every previous definition when a replacement fails.
            return $platform instanceof AbstractMySQLPlatform
                ? $regenerate()
                : $this->connection->transactional($regenerate);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $message = 'Custom reporting views could not be regenerated. Check the configured database platform, JSON support, and view privileges.';
            if ($platform instanceof AbstractMySQLPlatform && $updated !== []) {
                $message .= ' MySQL/MariaDB committed '.count($updated).' view replacement(s); correct the error and rerun regeneration to finish.';
            }
            throw new \RuntimeException($message, previous: $e);
        }
    }

    private function platform(): AbstractPlatform
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform
            && !$platform instanceof PostgreSQLPlatform
            && !$platform instanceof SqlitePlatform
            && !$platform instanceof SQLServerPlatform) {
            throw new \RuntimeException('Custom reporting views support PostgreSQL, MySQL, MariaDB, SQL Server, and SQLite with JSON functions.');
        }

        return $platform;
    }

    /** @return array<string, string> */
    private function selectSql(AbstractPlatform $platform): array
    {
        $fields = [
            'events.id',
            'events.website_token',
            'events.event_name',
            'events.url AS page_path',
            'events.referrer AS referrer_channel',
            'events.privacy_mode',
            'events.device_class',
            'events.viewport_bucket',
            'events.geo_area',
            'events.goal_event',
            'events.created_at',
            'events.archived_at',
        ];
        foreach ($this->settings->reportingColumns() as $column => $key) {
            $fields[] = $this->scalarExpression($platform, $key).' AS '.$platform->quoteIdentifier($column);
        }
        $select = "SELECT\n    ".implode(",\n    ", $fields)."\nFROM events\nWHERE ";

        return [
            self::VIEW_NAMES[0] => $select.'1 = 1',
            self::VIEW_NAMES[1] => $select."events.event_name = 'view'",
            self::VIEW_NAMES[2] => $select.'events.goal_event IS NOT NULL',
        ];
    }

    private function scalarExpression(AbstractPlatform $platform, string $key): string
    {
        if ($platform instanceof PostgreSQLPlatform) {
            $keyLiteral = $platform->quoteStringLiteral($key);
            $json = 'events.custom_data::jsonb';

            return "CASE WHEN jsonb_typeof($json -> $keyLiteral) IN ('string', 'number', 'boolean') THEN $json ->> $keyLiteral END";
        }

        // A quoted member makes a dot or hyphen part of the literal key. JSON
        // encoding also escapes quotes/backslashes before SQL string quoting.
        $path = $platform->quoteStringLiteral('$.'.json_encode($key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($platform instanceof AbstractMySQLPlatform) {
            $value = "JSON_EXTRACT(events.custom_data, $path)";

            return "CASE WHEN JSON_TYPE($value) IN ('STRING', 'INTEGER', 'DOUBLE', 'BOOLEAN') THEN JSON_UNQUOTE($value) END";
        }
        if ($platform instanceof SQLServerPlatform) {
            // Lax JSON_VALUE returns NULL for missing, null or structured data.
            return "JSON_VALUE(events.custom_data, N$path)";
        }

        $type = "json_type(events.custom_data, $path)";
        $value = "CAST(json_extract(events.custom_data, $path) AS TEXT)";

        return "CASE $type WHEN 'true' THEN 'true' WHEN 'false' THEN 'false' WHEN 'text' THEN $value WHEN 'integer' THEN $value WHEN 'real' THEN $value END";
    }

    /** @return list<string> */
    private function viewStatements(AbstractPlatform $platform, string $name, string $select): array
    {
        if ($platform instanceof SqlitePlatform) {
            $name = $platform->quoteIdentifier('main.'.$name);

            return ['DROP VIEW IF EXISTS '.$name, 'CREATE VIEW '.$name." AS\n".$select];
        }

        $name = $platform->quoteIdentifier($name);
        $verb = $platform instanceof SQLServerPlatform ? 'CREATE OR ALTER' : 'CREATE OR REPLACE';

        return [$verb.' VIEW '.$name." AS\n".$select];
    }

    private function assertColumnsCanBeReplaced(AbstractPlatform $platform): void
    {
        $expected = [...self::BUILTIN_COLUMNS, ...array_keys($this->settings->reportingColumns())];
        // DBAL's table introspection excludes views on PostgreSQL and SQL
        // Server. Query view-capable metadata in the schema CREATE uses, and
        // preserve ordinal positions rather than sorting aliases by name.
        $query = match (true) {
            $platform instanceof PostgreSQLPlatform => 'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :view_name ORDER BY ordinal_position',
            $platform instanceof SQLServerPlatform => 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_NAME = :view_name ORDER BY ORDINAL_POSITION',
            $platform instanceof SqlitePlatform => "SELECT name FROM pragma_table_info(:view_name, 'main') ORDER BY cid",
            default => 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :view_name ORDER BY ORDINAL_POSITION',
        };
        foreach (self::VIEW_NAMES as $name) {
            $existing = $this->connection->fetchFirstColumn($query, ['view_name' => $name]);
            if ($existing !== array_slice($expected, 0, count($existing))) {
                throw new \InvalidArgumentException('Existing reporting columns cannot be removed, renamed, or reordered during regeneration. Keep deployed aliases in their original order and append new columns.');
            }
        }
    }
}
