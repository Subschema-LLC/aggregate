<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\AnonymousBiViewSqlBuilder;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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
        $threshold = (new AnonymousBiViewManager($this->connection))->compiledEventThreshold();

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

    /** Kept for compatibility; the parsing lives in AnonymousBiViewManager. */
    public static function thresholdFromDefinition(?string $definition): ?int
    {
        return AnonymousBiViewManager::thresholdFromDefinition($definition);
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
