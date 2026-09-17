<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260828000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expose thresholded completed-day anonymous goal counts to BI tools';
    }

    public function isTransactional(): bool
    {
        // MySQL and MariaDB implicitly commit view DDL statements.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addDropViewSql('bi_anonymous_goals_v1');
        $this->addSql(sprintf(<<<'SQL'
CREATE VIEW bi_anonymous_goals_v1 AS
SELECT
    events.website_token,
    %1$s AS event_day,
    events.goal_event,
    COUNT(*) AS event_count
FROM events
CROSS JOIN analytics_privacy_settings privacy
WHERE privacy.id = 1
  AND privacy.anonymous_min_cell_count BETWEEN 2 AND 1000
  AND events.privacy_mode = 'anonymous'
  AND events.goal_event IS NOT NULL
  AND %2$s
GROUP BY
    events.website_token,
    %1$s,
    events.goal_event,
    privacy.anonymous_min_cell_count
HAVING COUNT(*) >= privacy.anonymous_min_cell_count
SQL, $this->eventDayExpression(), $this->completedDayPredicate()));
    }

    public function down(Schema $schema): void
    {
        $this->addDropViewSql('bi_anonymous_goals_v1');
    }

    private function addDropViewSql(string $viewName): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLServerPlatform) {
            $this->addSql(
                sprintf("IF OBJECT_ID('%1\$s', 'V') IS NOT NULL DROP VIEW %1\$s", $viewName),
            );

            return;
        }

        $this->addSql(sprintf('DROP VIEW IF EXISTS %s', $viewName));
    }

    private function eventDayExpression(): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform,
            $platform instanceof PostgreSQLPlatform,
            $platform instanceof SQLServerPlatform => 'CAST(events.created_at AS date)',
            $platform instanceof SQLitePlatform => 'date(events.created_at)',
            default => 'NULL',
        };
    }

    private function completedDayPredicate(): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform =>
                'events.created_at < UTC_DATE()',
            $platform instanceof PostgreSQLPlatform =>
                "events.created_at < CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS date)",
            $platform instanceof SQLServerPlatform =>
                'events.created_at < CAST(SYSUTCDATETIME() AS date)',
            $platform instanceof SQLitePlatform =>
                "events.created_at < date('now')",
            default => '1 = 0',
        };
    }
}
