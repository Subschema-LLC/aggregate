<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnalyticsPrivacySettingsTest extends TestCase
{
    public function testReadsBothThresholdsFromConfiguration(): void
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('getWithEnvFallback')->willReturnMap([
            [AnalyticsPrivacySettings::ANONYMOUS_MINIMUM_KEY, AnalyticsPrivacySettings::DEFAULT_MINIMUM_CELL_COUNT, false, '14'],
            [AnalyticsPrivacySettings::GEO_MINIMUM_KEY, AnalyticsPrivacySettings::DEFAULT_GEO_MINIMUM_CELL_COUNT, false, 40],
        ]);

        self::assertSame(
            ['anonymous' => 14, 'geo' => 40],
            (new AnalyticsPrivacySettings($config))->getMinimumCellCounts(),
        );
    }

    public function testSavesBothThresholdsToConfiguration(): void
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->expects(self::once())
            ->method('setMany')
            ->with([
                AnalyticsPrivacySettings::ANONYMOUS_MINIMUM_KEY => 14,
                AnalyticsPrivacySettings::GEO_MINIMUM_KEY => 40,
            ]);

        (new AnalyticsPrivacySettings($config))->saveMinimumCellCounts(14, 40);
    }

    #[DataProvider('invalidMinimums')]
    public function testRejectsInvalidThresholdsBeforeWriting(int $minimum, int $geoMinimum): void
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->expects(self::never())->method('setMany');

        $this->expectException(\InvalidArgumentException::class);

        (new AnalyticsPrivacySettings($config))->saveMinimumCellCounts($minimum, $geoMinimum);
    }

    public static function invalidMinimums(): iterable
    {
        yield 'hourly below floor' => [1, 25];
        yield 'hourly above maximum' => [1001, 25];
        yield 'geography below floor' => [5, 9];
        yield 'geography above maximum' => [5, 1001];
    }

    #[DataProvider('invalidConfiguredMinimums')]
    public function testInvalidConfiguredThresholdFailsClosed(mixed $minimum, mixed $geoMinimum): void
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('getWithEnvFallback')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => match ($key) {
                AnalyticsPrivacySettings::ANONYMOUS_MINIMUM_KEY => $minimum,
                AnalyticsPrivacySettings::GEO_MINIMUM_KEY => $geoMinimum,
                default => $default,
            },
        );

        $this->expectException(\RuntimeException::class);

        (new AnalyticsPrivacySettings($config))->getMinimumCellCounts();
    }

    public static function invalidConfiguredMinimums(): iterable
    {
        yield 'hourly non-numeric' => ['five', 25];
        yield 'hourly below floor' => [1, 25];
        yield 'geography non-numeric' => [5, 'twenty-five'];
        yield 'geography below floor' => [5, 9];
    }

    public function testReportsEnvironmentOverrideFlags(): void
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->expects(self::exactly(2))
            ->method('hasEnvironmentOverride')
            ->willReturnMap([
                [AnalyticsPrivacySettings::ANONYMOUS_MINIMUM_KEY, false, true],
                [AnalyticsPrivacySettings::GEO_MINIMUM_KEY, false, false],
            ]);

        $settings = new AnalyticsPrivacySettings($config);
        self::assertTrue($settings->hasAnonymousMinimumEnvironmentOverride());
        self::assertFalse($settings->hasGeoMinimumEnvironmentOverride());
    }
}
