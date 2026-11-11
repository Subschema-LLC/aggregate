<?php

declare(strict_types=1);

namespace App\Tests\Service\Update;

use App\Service\Update\SystemCheck;
use PHPUnit\Framework\TestCase;

final class SystemCheckApcuTest extends TestCase
{
    public function testSharedApcuIsReportedAsUsed(): void
    {
        foreach (['fpm-fcgi', 'apache2handler', 'frankenphp'] as $sapi) {
            $row = SystemCheck::apcuCheck($sapi, true, true);

            self::assertSame(SystemCheck::OK, $row['status'] ?? null, $sapi);
            self::assertStringContainsString('kept in memory', $row['detail']);
        }
    }

    public function testApcuThatIsMissingOffOrNotSharedIsOnlyInformation(): void
    {
        $missing = SystemCheck::apcuCheck('fpm-fcgi', false, false);
        $off = SystemCheck::apcuCheck('fpm-fcgi', true, false);
        $cgi = SystemCheck::apcuCheck('cgi-fcgi', true, true);

        self::assertSame([SystemCheck::INFO, SystemCheck::INFO, SystemCheck::INFO], [$missing['status'] ?? null, $off['status'] ?? null, $cgi['status'] ?? null]);
        self::assertStringContainsString('-apcu', $missing['detail']);
        self::assertStringContainsString('apc.enabled', $off['detail']);
        self::assertStringContainsString('cgi-fcgi', $cgi['detail']);
        self::assertStringContainsString('var/rate_limit/', $cgi['detail']);
    }

    public function testTheCommandLineReportsNothing(): void
    {
        // Its APCu, usually off, is not the one the website uses.
        self::assertNull(SystemCheck::apcuCheck('cli', true, true));
    }
}
