<?php

declare(strict_types=1);

namespace App\Tests\Service\Scheduler;

use App\Service\Scheduler\SchedulerSetup;
use App\Service\Update\Toolchain;
use PHPUnit\Framework\TestCase;

final class SchedulerSetupTest extends TestCase
{
    public function testAPleskSubscriptionGetsTheFieldsOfARunAPhpScriptTask(): void
    {
        $setup = $this->schedulerSetup('/var/www/vhosts/accept.optinmodel.com/httpdocs');

        self::assertSame([
            'subscription' => 'accept.optinmodel.com',
            'script_path' => 'httpdocs/bin/console',
            'arguments' => 'app:scheduler:run',
            'php_version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'schedule' => '*/5 * * * *',
        ], $setup->plesk());
        self::assertSame('analytics.example.com/aggregate/bin/console', $this->schedulerSetup('/var/www/vhosts/example.com/analytics.example.com/aggregate/')->plesk()['script_path']);
    }

    public function testOtherServersGetACrontabLineWithThisPhpBinary(): void
    {
        $setup = $this->schedulerSetup('/srv/aggregate');

        self::assertNull($setup->plesk());
        self::assertSame("*/5 * * * * cd '/srv/aggregate' && '/opt/php/8.3/bin/php' bin/console app:scheduler:run --no-interaction", $setup->cronLine());
        self::assertSame('php bin/console app:scheduler:run', $setup->manualCommand());
    }

    private function schedulerSetup(string $projectDir): SchedulerSetup
    {
        $toolchain = $this->createStub(Toolchain::class);
        $toolchain->method('php')->willReturn(['/opt/php/8.3/bin/php']);

        return new SchedulerSetup($toolchain, $projectDir);
    }
}
