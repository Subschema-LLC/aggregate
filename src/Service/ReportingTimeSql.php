<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;

/**
 * View expressions that relabel a stored UTC hour in the reporting timezone.
 *
 * The label is the local hour containing the UTC hour's midpoint, then floored.
 * A zone a whole number of hours from UTC lands on one local hour; India,
 * Newfoundland and Nepal would be half an hour off if the hour were shifted by
 * a flat offset and then truncated. One stored UTC hour stays one cell.
 *
 * Daily filters compare the stored column with the UTC instant of the local
 * day boundary, so the existing index on created_at can still be used.
 */
final class ReportingTimeSql
{
    public function __construct(
        private readonly AbstractPlatform $platform,
        private readonly string $timezone,
    ) {
    }

    /** Local hour label of a UTC hour column. Not an identity when the zone is UTC. */
    public function hourLabel(string $column): string
    {
        return match (true) {
            $this->platform instanceof PostgreSQLPlatform => sprintf(
                "date_trunc('hour', ((%s + INTERVAL '30 minutes') AT TIME ZONE 'UTC') AT TIME ZONE %s)",
                $column,
                $this->quote($this->timezone),
            ),
            $this->platform instanceof AbstractMySQLPlatform => sprintf(
                "CAST(DATE_FORMAT(CONVERT_TZ(DATE_ADD(%s, INTERVAL 30 MINUTE), 'UTC', %s), '%%Y-%%m-%%d %%H:00:00') AS DATETIME)",
                $column,
                $this->quote($this->timezone),
            ),
            $this->platform instanceof SQLServerPlatform => sprintf(
                'CAST(DATEADD(hour, DATEDIFF(hour, 0, CAST(DATEADD(minute, 30, %1$s) AT TIME ZONE \'UTC\' AT TIME ZONE %2$s AS datetime2)), 0) AS datetime2)',
                $column,
                $this->quote(ReportingTimezone::windowsName($this->timezone)),
            ),
            $this->platform instanceof SQLitePlatform => sprintf(
                "strftime('%%Y-%%m-%%d %%H:00:00', datetime(%1\$s, '+30 minutes', %2\$s))",
                $column,
                $this->sqliteOffsetModifier(sprintf("datetime(%s, '+30 minutes')", $column)),
            ),
            default => 'NULL',
        };
    }

    /** Local date of a UTC hour, the date of its hour label. */
    public function dayOfUtcHour(string $column): string
    {
        return $this->dateOf($this->hourLabel($column));
    }

    /** Date of a column that is already a local wall-clock timestamp. */
    public function localDayExpression(string $column): string
    {
        return $this->dateOf($column);
    }

    /**
     * Stored UTC column is before the first UTC hour of the current local day.
     * The boundary is computed at query time and is not baked into the SQL.
     */
    public function completedReportingDayPredicate(string $column): string
    {
        return sprintf('%s < %s', $column, $this->utcCutoff());
    }

    /** A local wall-clock column is before the current local midnight. */
    public function completedLocalDayPredicate(string $column): string
    {
        return sprintf('%s < %s', $column, $this->localMidnightNow());
    }

    private function dateOf(string $expression): string
    {
        return $this->platform instanceof SQLitePlatform
            ? sprintf('date(%s)', $expression)
            : sprintf('CAST(%s AS date)', $expression);
    }

    private function utcCutoff(): string
    {
        return match (true) {
            $this->platform instanceof PostgreSQLPlatform => $this->hourCeil(
                sprintf('(%s - INTERVAL \'30 minutes\')', $this->postgresUtcMidnight()),
                'postgres',
            ),
            $this->platform instanceof AbstractMySQLPlatform => $this->hourCeil(
                sprintf('DATE_SUB(%s, INTERVAL 30 MINUTE)', $this->mysqlUtcMidnight()),
                'mysql',
            ),
            $this->platform instanceof SQLServerPlatform => $this->hourCeil(
                sprintf('DATEADD(minute, -30, %s)', $this->sqlServerUtcMidnight()),
                'sqlserver',
            ),
            $this->platform instanceof SQLitePlatform => $this->sqliteUtcCutoff(),
            default => 'NULL',
        };
    }

    private function postgresUtcMidnight(): string
    {
        $zone = $this->quote($this->timezone);

        return sprintf(
            "((date_trunc('day', CURRENT_TIMESTAMP AT TIME ZONE %1\$s) AT TIME ZONE %1\$s) AT TIME ZONE 'UTC')",
            $zone,
        );
    }

    private function mysqlUtcMidnight(): string
    {
        $zone = $this->quote($this->timezone);

        return sprintf(
            "CONVERT_TZ(CAST(DATE(CONVERT_TZ(UTC_TIMESTAMP(), 'UTC', %1\$s)) AS DATETIME), %1\$s, 'UTC')",
            $zone,
        );
    }

    private function sqlServerUtcMidnight(): string
    {
        $windows = $this->quote(ReportingTimezone::windowsName($this->timezone));
        $localMidnight = sprintf(
            "CAST(CAST(SYSUTCDATETIME() AT TIME ZONE 'UTC' AT TIME ZONE %1\$s AS date) AS datetime2)",
            $windows,
        );

        return sprintf('CAST(%1$s AT TIME ZONE %2$s AT TIME ZONE \'UTC\' AS datetime2)', $localMidnight, $windows);
    }

    private function localMidnightNow(): string
    {
        return match (true) {
            $this->platform instanceof PostgreSQLPlatform => sprintf(
                "date_trunc('day', CURRENT_TIMESTAMP AT TIME ZONE %s)",
                $this->quote($this->timezone),
            ),
            $this->platform instanceof AbstractMySQLPlatform => sprintf(
                "CAST(DATE(CONVERT_TZ(UTC_TIMESTAMP(), 'UTC', %s)) AS DATETIME)",
                $this->quote($this->timezone),
            ),
            $this->platform instanceof SQLServerPlatform => sprintf(
                "CAST(CAST(SYSUTCDATETIME() AT TIME ZONE 'UTC' AT TIME ZONE %s AS date) AS datetime2)",
                $this->quote(ReportingTimezone::windowsName($this->timezone)),
            ),
            $this->platform instanceof SQLitePlatform => sprintf(
                "date(datetime('now', %s)) || ' 00:00:00'",
                $this->sqliteOffsetModifier("strftime('%Y-%m-%d %H:%M:%S', 'now')"),
            ),
            default => 'NULL',
        };
    }

    private function hourCeil(string $shifted, string $engine): string
    {
        return match ($engine) {
            'postgres' => sprintf(
                'CASE WHEN %1$s = date_trunc(\'hour\', %1$s) THEN date_trunc(\'hour\', %1$s) ELSE date_trunc(\'hour\', %1$s) + INTERVAL \'1 hour\' END',
                $shifted,
            ),
            'mysql' => sprintf(
                "CASE WHEN %1\$s = CAST(DATE_FORMAT(%1\$s, '%%Y-%%m-%%d %%H:00:00') AS DATETIME) THEN CAST(DATE_FORMAT(%1\$s, '%%Y-%%m-%%d %%H:00:00') AS DATETIME) ELSE CAST(DATE_FORMAT(DATE_ADD(%1\$s, INTERVAL 1 HOUR), '%%Y-%%m-%%d %%H:00:00') AS DATETIME) END",
                $shifted,
            ),
            'sqlserver' => sprintf(
                'CASE WHEN %1$s = CAST(DATEADD(hour, DATEDIFF(hour, 0, %1$s), 0) AS datetime2) THEN CAST(DATEADD(hour, DATEDIFF(hour, 0, %1$s), 0) AS datetime2) ELSE CAST(DATEADD(hour, DATEDIFF(hour, 0, %1$s) + 1, 0) AS datetime2) END',
                $shifted,
            ),
            default => 'NULL',
        };
    }

    /**
     * Each transition CASE is written once and applied to a column, not inlined
     * into the next CASE. Nesting them repeats the table once per transition.
     */
    private function sqliteUtcCutoff(): string
    {
        $offsetNow = $this->sqliteOffsetModifier('now_utc.ts');
        $inverseNow = $this->sqliteInverseModifier('now_utc.ts');
        $inverseCandidate = $this->sqliteInverseModifier('candidate.ts');

        return <<<SQL
(
    WITH now_utc AS (
        SELECT strftime('%Y-%m-%d %H:%M:%S', 'now') AS ts
    ),
    local_midnight AS (
        SELECT date(datetime('now', {$offsetNow})) || ' 00:00:00' AS wall
        FROM now_utc
    ),
    candidate AS (
        SELECT datetime(local_midnight.wall, {$inverseNow}) AS ts
        FROM local_midnight
        CROSS JOIN now_utc
    ),
    utc_midnight AS (
        SELECT datetime(local_midnight.wall, {$inverseCandidate}) AS ts
        FROM local_midnight
        CROSS JOIN candidate
    )
    SELECT CASE
        WHEN strftime('%M', datetime(utc_midnight.ts, '-30 minutes')) = '00'
         AND strftime('%S', datetime(utc_midnight.ts, '-30 minutes')) = '00'
        THEN strftime('%Y-%m-%d %H:%M:%S', datetime(utc_midnight.ts, '-30 minutes'))
        ELSE strftime('%Y-%m-%d %H:00:00', datetime(utc_midnight.ts, '-30 minutes', '+1 hour'))
    END
    FROM utc_midnight
)
SQL;
    }

    /** A modifier such as '-18000 seconds' for the offset in effect at a UTC timestamp. */
    private function sqliteOffsetModifier(string $utcExpression): string
    {
        return $this->sqliteModifierCase($utcExpression, false);
    }

    private function sqliteInverseModifier(string $utcExpression): string
    {
        return $this->sqliteModifierCase($utcExpression, true);
    }

    private function sqliteModifierCase(string $utcExpression, bool $inverse): string
    {
        $transitions = ReportingTimezone::transitions($this->timezone);
        if (count($transitions) === 1) {
            return $this->quote($this->secondsModifier($inverse ? -$transitions[0]['offset'] : $transitions[0]['offset']));
        }

        $lines = ['CASE'];
        $last = count($transitions) - 1;
        foreach ($transitions as $index => $transition) {
            $modifier = $this->quote($this->secondsModifier($inverse ? -$transition['offset'] : $transition['offset']));
            if ($index === $last || $transition['until'] === null) {
                $lines[] = 'ELSE '.$modifier;
                break;
            }
            $lines[] = sprintf('WHEN %s < %s THEN %s', $utcExpression, $this->quote($transition['until']), $modifier);
        }
        $lines[] = 'END';

        return '('.implode(' ', $lines).')';
    }

    private function secondsModifier(int $offset): string
    {
        return sprintf('%+d seconds', $offset);
    }

    private function quote(string $value): string
    {
        return $this->platform->quoteStringLiteral($value);
    }
}
