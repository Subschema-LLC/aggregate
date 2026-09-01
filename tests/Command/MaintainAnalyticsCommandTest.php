<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MaintainAnalyticsCommand;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsArchiveService;
use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\AnalyticsMaintenanceLease;
use App\Service\AnalyticsMaintenanceRunner;
use App\Service\AnalyticsRetentionService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

final class MaintainAnalyticsCommandTest extends TestCase
{
    private const POLICY_KEYS = [
        'ANALYTICS_ARCHIVING_ENABLED',
        'ANALYTICS_ARCHIVE_AFTER_DAYS',
        'ANALYTICS_RETENTION_ENABLED',
        'ANALYTICS_ANONYMOUS_RETENTION_DAYS',
        'ANALYTICS_ENHANCED_RETENTION_DAYS',
        'ANALYTICS_ARCHIVE_RETENTION_DAYS',
        'ANALYTICS_MAINTENANCE_BATCH_SIZE',
    ];

    private string $projectDir;

    /** @var array<string, array{env: mixed, env_exists: bool, server: mixed, server_exists: bool}> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-maintain-command-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));

        foreach (self::POLICY_KEYS as $key) {
            $this->savedEnvironment[$key] = [
                'env' => $_ENV[$key] ?? null,
                'env_exists' => array_key_exists($key, $_ENV),
                'server' => $_SERVER[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
            ];
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => $saved) {
            if ($saved['env_exists']) {
                $_ENV[$key] = $saved['env'];
            } else {
                unset($_ENV[$key]);
            }
            if ($saved['server_exists']) {
                $_SERVER[$key] = $saved['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }

        @unlink($this->projectDir.'/config/aggregate.yaml');
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);
    }

    public function testDryRunReportsEligibleCountsWithoutTakingTheLease(): void
    {
        $this->writePolicy([
            'analytics_archiving_enabled' => true,
            'analytics_retention_enabled' => false,
        ]);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(self::stringContains('SELECT COUNT(*) FROM events'))
            ->willReturn('7');
        $connection->expects(self::never())->method('executeStatement');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $tester = $this->tester($connection, $logger);
        $status = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Eligible (no changes made)', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Raw events to archive\s+7/', $tester->getDisplay());
        self::assertStringContainsString('dry run completed', $tester->getDisplay());
    }

    public function testLeaseContentionIsReportedAsASuccessfulNoOp(): void
    {
        $this->writePolicy([
            'analytics_archiving_enabled' => true,
            'analytics_retention_enabled' => false,
        ]);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(0);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT id FROM analytics_maintenance_lock WHERE id = 1')
            ->willReturn(1);
        $logger = $this->createStub(LoggerInterface::class);

        $tester = $this->tester($connection, $logger);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Another analytics maintenance process', $tester->getDisplay());
    }

    public function testInvalidPolicyFailsBeforeAnyDatabaseMutationAndIsLogged(): void
    {
        $this->writePolicy(['analytics_archiving_enabled' => 'sometimes']);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('executeStatement');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Analytics maintenance failed.',
                self::callback(static fn (array $context): bool => $context['exception'] instanceof \InvalidArgumentException),
            );

        $tester = $this->tester($connection, $logger);
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Analytics maintenance failed', $tester->getDisplay());
        self::assertStringContainsString('analytics_archiving_enabled must be a boolean', $tester->getDisplay());
    }

    /** @param array<string, mixed> $changes */
    private function writePolicy(array $changes): void
    {
        $policy = array_replace([
            'analytics_archiving_enabled' => false,
            'analytics_archive_after_days' => 90,
            'analytics_retention_enabled' => false,
            'analytics_anonymous_retention_days' => 365,
            'analytics_enhanced_retention_days' => 90,
            'analytics_archive_retention_days' => 730,
            'analytics_maintenance_batch_size' => 100,
        ], $changes);

        file_put_contents(
            $this->projectDir.'/config/aggregate.yaml',
            Yaml::dump($policy, 4, 2),
        );
    }

    private function tester(Connection $connection, LoggerInterface $logger): CommandTester
    {
        $policy = new AnalyticsDataLifecyclePolicy(
            new AggregateConfigLoader($this->projectDir, 'test'),
        );
        $runner = new AnalyticsMaintenanceRunner(
            $policy,
            new AnalyticsArchiveService($connection),
            new AnalyticsRetentionService($connection),
            new AnalyticsMaintenanceLease($connection),
            new MockClock('2026-09-01 18:00:00 UTC'),
        );

        return new CommandTester(new MaintainAnalyticsCommand($runner, $logger));
    }
}
