<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\AnonymousBiViewSqlBuilder;
use App\Service\BiPageGroups;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds bi_anonymous_daily_rollup_v1 and bi_anonymous_coverage_v1.
 *
 * The roll-up's most detailed rows must equal bi_anonymous_daily_v1 row for
 * row, and both apply complementary suppression against the hourly view, so
 * all three need one threshold and the daily views one set of page groups.
 * The threshold is read from the hourly view, keeping what an installation
 * already publishes; when it cannot be read, the hourly view is rebuilt from
 * the configured YAML threshold too. The daily view is rebuilt with the
 * configured page groups so it and the roll-up match.
 */
final class Version20261013000000 extends AbstractMigration
{
    private const DEFAULT_MINIMUM_CELL_COUNT = 5;

    public function getDescription(): string
    {
        return 'Add bi_anonymous_daily_rollup_v1 (withheld daily cells rolled up) and bi_anonymous_coverage_v1 (released share bands)';
    }

    public function isTransactional(): bool
    {
        return !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform);
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $sql = new AnonymousBiViewSqlBuilder($platform);
        $config = $this->configuration();
        $threshold = (new AnonymousBiViewManager($this->connection))->compiledEventThreshold();

        if ($threshold === null) {
            $threshold = $this->configuredThreshold($config);
            $this->write(sprintf('Rebuilding bi_anonymous_events_v1 with the configured threshold %d.', $threshold));
            $this->addView($platform, $sql, 'bi_anonymous_events_v1', $sql->eventViewSql($threshold));
        }

        $pageGroups = $this->configuredPageGroups($config);
        $this->addView($platform, $sql, 'bi_anonymous_daily_v1', $sql->dailyViewSql($threshold, $pageGroups));
        $this->addView($platform, $sql, 'bi_anonymous_daily_rollup_v1', $sql->rollupViewSql($threshold, $pageGroups));
        $this->addView($platform, $sql, 'bi_anonymous_coverage_v1', $sql->coverageViewSql($threshold));
    }

    public function down(Schema $schema): void
    {
        $sql = new AnonymousBiViewSqlBuilder($this->connection->getDatabasePlatform());
        $this->addSql($sql->dropViewIfExistsSql('bi_anonymous_coverage_v1'));
        $this->addSql($sql->dropViewIfExistsSql('bi_anonymous_daily_rollup_v1'));
    }

    private function configuration(): AggregateConfigLoader
    {
        $environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod';

        return new AggregateConfigLoader(dirname(__DIR__), is_string($environment) ? $environment : 'prod');
    }

    private function configuredThreshold(AggregateConfigLoader $config): int
    {
        try {
            return (new AnalyticsPrivacySettings($config))->getMinimumCellCounts()['anonymous'];
        } catch (\Throwable) {
            return self::DEFAULT_MINIMUM_CELL_COUNT;
        }
    }

    /** @return list<array{pattern: string, group: string}> */
    private function configuredPageGroups(AggregateConfigLoader $config): array
    {
        try {
            return (new BiPageGroups($config))->rules();
        } catch (\Throwable $e) {
            $this->write('bi_page_groups is invalid, so the daily views were created without page groups: '.$e->getMessage());

            return [];
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
