<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RegenerateBiViewsCommand;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RegenerateBiViewsCommandTest extends TestCase
{
    public function testRegeneratesViewsWithTheConfiguredThresholds(): void
    {
        $executed = [];
        $tester = $this->tester(['anonymous' => 12, 'geo' => 40], $executed);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertCount(3, $executed);
        self::assertStringContainsString('event_counts.event_count >= 12', $executed[0]);
        self::assertStringContainsString('40 AS minimum_cell_count', $executed[2]);
        self::assertStringContainsString('12 for events and goals, 40 for geography', self::display($tester));
        self::assertStringNotContainsString('environment override', self::display($tester));
    }

    public function testDryRunPrintsTheSqlWithoutExecutingIt(): void
    {
        $executed = [];
        $tester = $this->tester(['anonymous' => 6, 'geo' => 25], $executed);

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertSame([], $executed);
        self::assertStringContainsString('CREATE OR REPLACE VIEW bi_anonymous_events_v1 AS', self::display($tester));
        self::assertStringContainsString('event_counts.event_count >= 6', self::display($tester));
    }

    public function testInvalidConfigurationFailsWithoutTouchingTheDatabase(): void
    {
        $executed = [];
        $tester = $this->tester(['anonymous' => 1, 'geo' => 25], $executed);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame([], $executed);
        self::assertStringContainsString('The BI views were not regenerated', self::display($tester));
        self::assertStringContainsString('anonymous_min_cell_count must be between 2 and 1000', self::display($tester));
    }

    public function testReportsAnEnvironmentOverride(): void
    {
        $executed = [];
        $tester = $this->tester(['anonymous' => 8, 'geo' => 25], $executed, overridden: true);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('environment override', self::display($tester));
    }

    /** SymfonyStyle wraps blocks at the terminal width; compare words, not lines. */
    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * @param array{anonymous: int, geo: int} $thresholds
     * @param list<string>                    $executed
     */
    private function tester(array $thresholds, array &$executed, bool $overridden = false): CommandTester
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('getWithEnvFallback')->willReturnMap([
            [AnalyticsPrivacySettings::ANONYMOUS_MINIMUM_KEY, AnalyticsPrivacySettings::DEFAULT_MINIMUM_CELL_COUNT, $thresholds['anonymous']],
            [AnalyticsPrivacySettings::GEO_MINIMUM_KEY, AnalyticsPrivacySettings::DEFAULT_GEO_MINIMUM_CELL_COUNT, $thresholds['geo']],
        ]);
        $config->method('hasEnvironmentOverride')->willReturn($overridden);

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$executed): int {
            $executed[] = $sql;

            return 0;
        });

        return new CommandTester(new RegenerateBiViewsCommand(
            new AnalyticsPrivacySettings($config),
            new AnonymousBiViewManager($connection),
        ));
    }
}
