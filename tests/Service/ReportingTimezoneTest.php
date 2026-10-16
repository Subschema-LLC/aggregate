<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\ReportingTimezone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ReportingTimezoneTest extends TestCase
{
    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['REPORTING_TIMEZONE'], $_SERVER['REPORTING_TIMEZONE']);
        $this->directory = sys_get_temp_dir().'/aggregate-reporting-tz-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/config', 0700, true);
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump([
            'operator_setting' => 'preserve',
        ]));
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach (glob($this->directory.'/config/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory.'/config');
        rmdir($this->directory);
    }

    public function testTheDefaultIsUtcAndSavingPreservesOtherSettings(): void
    {
        $timezone = new ReportingTimezone(new AggregateConfigLoader($this->directory, 'test'));

        self::assertSame('UTC', $timezone->name());
        self::assertFalse($timezone->hasEnvironmentOverride());

        $timezone->save('America/Chicago');

        $saved = Yaml::parseFile($this->directory.'/config/aggregate.yaml');
        self::assertSame('preserve', $saved['operator_setting']);
        self::assertSame('America/Chicago', $saved[ReportingTimezone::KEY]);
        self::assertSame('America/Chicago', $timezone->name());
    }

    public function testAnEnvironmentOverrideWinsAndIsReported(): void
    {
        $_ENV['REPORTING_TIMEZONE'] = $_SERVER['REPORTING_TIMEZONE'] = 'Europe/London';
        $timezone = new ReportingTimezone(new AggregateConfigLoader($this->directory, 'test'));
        $timezone->save('America/Chicago');

        self::assertTrue($timezone->hasEnvironmentOverride());
        self::assertSame('Europe/London', $timezone->name());
        self::assertSame('America/Chicago', Yaml::parseFile($this->directory.'/config/aggregate.yaml')[ReportingTimezone::KEY]);
    }

    public function testOffsetsAbbreviationsAndUnknownNamesAreRejected(): void
    {
        foreach (['EST', 'UTC-5', '+05:30', 'Etc/GMT+5', 'america/chicago', '', 'Not/AZone'] as $value) {
            try {
                ReportingTimezone::validate($value);
                self::fail($value.' was accepted.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('IANA time zone name', $e->getMessage());
            }
        }
    }

    public function testAPermanentUtcZoneNeedsNoConversionAndChicagoMapsToWindows(): void
    {
        self::assertTrue(ReportingTimezone::isIdentity('UTC'));
        self::assertTrue(ReportingTimezone::isIdentity('Africa/Abidjan'));
        self::assertFalse(ReportingTimezone::isIdentity('America/Chicago'));
        self::assertFalse(ReportingTimezone::isIdentity('Asia/Kolkata'));
        self::assertSame('Central Standard Time', ReportingTimezone::windowsName('America/Chicago'));
        self::assertSame('India Standard Time', ReportingTimezone::windowsName('Asia/Kolkata'));
        self::assertSame('Nepal Standard Time', ReportingTimezone::windowsName('Asia/Kathmandu'));
    }

    public function testAnUnmappedSqlServerNameIsRefused(): void
    {
        $this->expectExceptionMessage('no Windows time zone');
        ReportingTimezone::windowsName('Antarctica/Troll');
    }

    public function testCompiledTransitionsCoverAChicagoDaylightSavingChange(): void
    {
        $transitions = ReportingTimezone::transitions('America/Chicago');
        $spring = null;
        foreach ($transitions as $transition) {
            if ($transition['until'] === '2024-03-10 08:00:00') {
                $spring = $transition;
            }
        }

        self::assertNotNull($spring);
        self::assertSame(-21600, $spring['offset']);
        self::assertNull($transitions[array_key_last($transitions)]['until']);
    }
}
