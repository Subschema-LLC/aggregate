<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsDataLifecyclePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AnalyticsDataLifecyclePolicyTest extends TestCase
{
    private const KEYS = [
        'analytics_archiving_enabled',
        'analytics_archive_after_days',
        'analytics_retention_enabled',
        'analytics_anonymous_retention_days',
        'analytics_enhanced_retention_days',
        'analytics_archive_retention_days',
        'analytics_maintenance_batch_size',
    ];

    private string $projectDir;

    /** @var array<string, array{env_exists: bool, env: mixed, server_exists: bool, server: mixed}> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-lifecycle-policy-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));

        foreach (self::KEYS as $key) {
            $this->saveAndUnsetEnvironment(strtoupper($key));
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

    public function testUsesConservativeDefaultsWhenLifecycleConfigurationIsAbsent(): void
    {
        $policy = $this->policy([]);

        self::assertSame([
            'analytics_archiving_enabled' => false,
            'analytics_archive_after_days' => 90,
            'analytics_retention_enabled' => false,
            'analytics_anonymous_retention_days' => 365,
            'analytics_enhanced_retention_days' => 90,
            'analytics_archive_retention_days' => 730,
            'analytics_maintenance_batch_size' => 1000,
        ], $policy->toArray());
    }

    public function testReadsACompleteValidPolicyFromYaml(): void
    {
        $policy = $this->policy([
            'analytics_archiving_enabled' => true,
            'analytics_archive_after_days' => 120,
            'analytics_retention_enabled' => true,
            'analytics_anonymous_retention_days' => 400,
            'analytics_enhanced_retention_days' => 180,
            'analytics_archive_retention_days' => 800,
            'analytics_maintenance_batch_size' => 2500,
        ]);

        self::assertTrue($policy->isArchivingEnabled());
        self::assertSame(120, $policy->getArchiveAfterDays());
        self::assertTrue($policy->isRetentionEnabled());
        self::assertSame(400, $policy->getAnonymousRetentionDays());
        self::assertSame(180, $policy->getEnhancedRetentionDays());
        self::assertSame(800, $policy->getArchiveRetentionDays());
        self::assertSame(2500, $policy->getMaintenanceBatchSize());
    }

    public function testEnvironmentValuesOverrideYamlAndAreReportedToTheUi(): void
    {
        $this->setEnvironment('ANALYTICS_ARCHIVING_ENABLED', 'yes');
        $this->setEnvironment('ANALYTICS_ARCHIVE_AFTER_DAYS', '180');

        $policy = $this->policy([
            'analytics_archiving_enabled' => false,
            'analytics_archive_after_days' => 90,
        ]);

        self::assertTrue($policy->isArchivingEnabled());
        self::assertSame(180, $policy->getArchiveAfterDays());
        self::assertTrue($policy->isEnvironmentOverridden('analytics_archiving_enabled'));
        self::assertTrue($policy->isEnvironmentOverridden('analytics_archive_after_days'));
        self::assertFalse($policy->isEnvironmentOverridden('analytics_retention_enabled'));
        self::assertSame([
            'analytics_archiving_enabled' => true,
            'analytics_archive_after_days' => true,
            'analytics_retention_enabled' => false,
            'analytics_anonymous_retention_days' => false,
            'analytics_enhanced_retention_days' => false,
            'analytics_archive_retention_days' => false,
            'analytics_maintenance_batch_size' => false,
        ], $policy->getEnvironmentOverrides());
    }

    #[DataProvider('invalidScalarValues')]
    public function testRejectsOutOfRangeOrMalformedScalarValues(string $key, mixed $value): void
    {
        $values = $this->validValues();
        $values[$key] = $value;

        $this->expectException(\InvalidArgumentException::class);

        $this->policy($values)->toArray();
    }

    public static function invalidScalarValues(): iterable
    {
        yield 'invalid archiving switch' => ['analytics_archiving_enabled', 'sometimes'];
        yield 'invalid retention switch' => ['analytics_retention_enabled', []];
        yield 'archive cutoff below minimum' => ['analytics_archive_after_days', 0];
        yield 'archive cutoff above maximum' => ['analytics_archive_after_days', 36_501];
        yield 'anonymous retention below minimum' => ['analytics_anonymous_retention_days', 0];
        yield 'enhanced retention above maximum' => ['analytics_enhanced_retention_days', 36_501];
        yield 'archive retention is not an integer' => ['analytics_archive_retention_days', 'one year'];
        yield 'batch below minimum' => ['analytics_maintenance_batch_size', 99];
        yield 'batch above maximum' => ['analytics_maintenance_batch_size', 10_001];
    }

    #[DataProvider('unsafeCombinedPolicies')]
    public function testRejectsPoliciesThatCouldDeleteRawOrArchivedDataTooEarly(array $changes): void
    {
        $values = array_replace($this->validValues(), $changes);

        $this->expectException(\InvalidArgumentException::class);

        AnalyticsDataLifecyclePolicy::validate($values);
    }

    public static function unsafeCombinedPolicies(): iterable
    {
        yield 'anonymous raw retention precedes archive cutoff' => [[
            'analytics_archive_after_days' => 91,
            'analytics_anonymous_retention_days' => 90,
        ]];
        yield 'enhanced raw retention precedes archive cutoff' => [[
            'analytics_archive_after_days' => 91,
            'analytics_enhanced_retention_days' => 90,
        ]];
        yield 'archive retention precedes longest raw retention' => [[
            'analytics_anonymous_retention_days' => 731,
            'analytics_archive_retention_days' => 730,
        ]];
        yield 'archive retention remains safe when new archiving is disabled' => [[
            'analytics_archiving_enabled' => false,
            'analytics_anonymous_retention_days' => 731,
            'analytics_archive_retention_days' => 730,
        ]];
    }

    public function testRawArchiveOrderingAppliesOnlyWhenBothProcessesAreEnabled(): void
    {
        $unsafeOrdering = [
            'analytics_archive_after_days' => 500,
            'analytics_anonymous_retention_days' => 100,
            'analytics_enhanced_retention_days' => 50,
            'analytics_archive_retention_days' => 100,
        ];

        AnalyticsDataLifecyclePolicy::validate(array_replace(
            $this->validValues(),
            $unsafeOrdering,
            ['analytics_archiving_enabled' => false],
        ));
        self::addToAssertionCount(1);

        AnalyticsDataLifecyclePolicy::validate(array_replace(
            $this->validValues(),
            $unsafeOrdering,
            ['analytics_retention_enabled' => false],
        ));
        self::addToAssertionCount(1);
    }

    /** @return array<string, bool|int> */
    private function validValues(): array
    {
        return [
            'analytics_archiving_enabled' => true,
            'analytics_archive_after_days' => 90,
            'analytics_retention_enabled' => true,
            'analytics_anonymous_retention_days' => 365,
            'analytics_enhanced_retention_days' => 90,
            'analytics_archive_retention_days' => 730,
            'analytics_maintenance_batch_size' => 1000,
        ];
    }

    private function policy(array $config): AnalyticsDataLifecyclePolicy
    {
        file_put_contents(
            $this->projectDir.'/config/aggregate.yaml',
            Yaml::dump($config, 4, 2),
        );

        return new AnalyticsDataLifecyclePolicy(
            new AggregateConfigLoader($this->projectDir, 'test'),
        );
    }

    private function saveAndUnsetEnvironment(string $key): void
    {
        $this->savedEnvironment[$key] = [
            'env_exists' => array_key_exists($key, $_ENV),
            'env' => $_ENV[$key] ?? null,
            'server_exists' => array_key_exists($key, $_SERVER),
            'server' => $_SERVER[$key] ?? null,
        ];
        unset($_ENV[$key], $_SERVER[$key]);
    }

    private function setEnvironment(string $key, mixed $value): void
    {
        $_ENV[$key] = $value;
        unset($_SERVER[$key]);
    }
}
