<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\AnonymousBiViewSqlBuilder;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds bi_anonymous_daily_v1 with the threshold already compiled into
 * bi_anonymous_events_v1. The daily view's complementary suppression is only
 * sound when both views use the same threshold, and reading it from the
 * hourly view keeps whatever value an installation already publishes. When it
 * cannot be read, both views are rebuilt from the configured YAML threshold.
 * Page groups start empty; regenerating the BI views applies configured ones.
 */
final class Version20261012000000 extends AbstractMigration
{
    private const DEFAULT_MINIMUM_CELL_COUNT = 5;

    public function getDescription(): string
    {
        return 'Add bi_anonymous_daily_v1, daily event counts by page group with complementary suppression';
    }

    public function isTransactional(): bool
    {
        return !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform);
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $sql = new AnonymousBiViewSqlBuilder($platform);
        $threshold = self::thresholdFromDefinition($this->hourlyViewDefinition($platform));

        if ($threshold === null) {
            $threshold = $this->configuredThreshold();
            $this->write(sprintf('Rebuilding bi_anonymous_events_v1 with the configured threshold %d.', $threshold));
            $this->addView($platform, $sql, 'bi_anonymous_events_v1', $sql->eventViewSql($threshold));
        }

        $this->addView($platform, $sql, 'bi_anonymous_daily_v1', $sql->dailyViewSql($threshold));
    }

    public function down(Schema $schema): void
    {
        $this->addSql((new AnonymousBiViewSqlBuilder($this->connection->getDatabasePlatform()))->dropViewIfExistsSql('bi_anonymous_daily_v1'));
    }

    /**
     * The threshold compiled into the hourly view, from the definition each
     * engine reports (MySQL and MariaDB rewrite it with backticks and
     * parentheses). Null unless exactly one valid threshold comparison is found.
     */
    public static function thresholdFromDefinition(?string $definition): ?int
    {
        if ($definition === null
            || preg_match_all('/event_count`?\s*\)?\s*>=\s*\(?\s*([0-9]{1,4})\b/i', $definition, $matches) !== 1) {
            return null;
        }
        $value = (int) $matches[1][0];

        return $value >= AnalyticsPrivacySettings::MINIMUM_CELL_COUNT && $value <= AnalyticsPrivacySettings::MAXIMUM_CELL_COUNT ? $value : null;
    }

    private function hourlyViewDefinition(AbstractPlatform $platform): ?string
    {
        $query = match (true) {
            $platform instanceof SQLitePlatform => "SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'bi_anonymous_events_v1'",
            $platform instanceof PostgreSQLPlatform => "SELECT pg_get_viewdef(c.oid) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = 'bi_anonymous_events_v1' AND c.relkind = 'v' AND n.nspname = current_schema()",
            $platform instanceof SQLServerPlatform => "SELECT OBJECT_DEFINITION(OBJECT_ID('bi_anonymous_events_v1', 'V'))",
            $platform instanceof AbstractMySQLPlatform => "SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_anonymous_events_v1'",
            default => null,
        };
        if ($query === null) {
            return null;
        }

        try {
            $definition = $this->connection->fetchOne($query);
        } catch (\Throwable) {
            return null;
        }

        return is_string($definition) ? $definition : null;
    }

    private function configuredThreshold(): int
    {
        $environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod';
        try {
            $settings = new AnalyticsPrivacySettings(new AggregateConfigLoader(dirname(__DIR__), is_string($environment) ? $environment : 'prod'));

            return $settings->getMinimumCellCounts()['anonymous'];
        } catch (\Throwable) {
            return self::DEFAULT_MINIMUM_CELL_COUNT;
        }
    }

    private function addView(AbstractPlatform $platform, AnonymousBiViewSqlBuilder $sql, string $name, string $createSql): void
    {
        if ($platform instanceof SQLitePlatform) {
            $this->addSql($sql->dropViewIfExistsSql($name));
            $this->addSql($createSql);

            return;
        }

        $this->addSql(AnonymousBiViewManager::replaceViewSql($platform, $name, $createSql));
    }
}
