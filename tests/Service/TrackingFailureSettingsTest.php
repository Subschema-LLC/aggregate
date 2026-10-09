<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\TrackingFailureSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TrackingFailureSettingsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (array_keys(TrackingFailureSettings::defaults()) as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-tracking-retry-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        @unlink($this->projectDir.'/config/aggregate.yaml');
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);
    }

    public function testDefaultsAndValidation(): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([]));
        $settings = new TrackingFailureSettings(new AggregateConfigLoader($this->projectDir, 'test'));

        self::assertSame([
            TrackingFailureSettings::KEY_ENABLED => true,
            TrackingFailureSettings::KEY_BATCH_SIZE => 100,
        ], $settings->toArray());
        self::expectException(\InvalidArgumentException::class);
        TrackingFailureSettings::validate([TrackingFailureSettings::KEY_BATCH_SIZE => 0]);
    }

    public function testSaveRespectsEnvironmentOverrides(): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            TrackingFailureSettings::KEY_ENABLED => true,
            TrackingFailureSettings::KEY_BATCH_SIZE => 120,
        ]));
        $_ENV['TRACKING_RETRY_BATCH_SIZE'] = $_SERVER['TRACKING_RETRY_BATCH_SIZE'] = '300';
        $settings = new TrackingFailureSettings(new AggregateConfigLoader($this->projectDir, 'test'));
        $settings->save([
            TrackingFailureSettings::KEY_ENABLED => false,
            TrackingFailureSettings::KEY_BATCH_SIZE => '40',
        ]);

        $saved = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertFalse($saved[TrackingFailureSettings::KEY_ENABLED]);
        self::assertSame(120, $saved[TrackingFailureSettings::KEY_BATCH_SIZE]);
        self::assertSame(300, $settings->toArray()[TrackingFailureSettings::KEY_BATCH_SIZE]);
    }
}
