<?php

declare(strict_types=1);

namespace App\Tests\Service\GeoIp;

use App\Service\GeoIp\MaxMindMmdbGeoCodeLookup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaxMindMmdbGeoCodeLookupTest extends TestCase
{
    #[DataProvider('unsafePaths')]
    public function testMissingAndNonLocalDatabasePathsFailClosed(string $path): void
    {
        $lookup = new MaxMindMmdbGeoCodeLookup(sys_get_temp_dir());

        self::assertNull($lookup->lookup($path, '8.8.8.8'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'empty' => [''];
        yield 'missing' => ['missing.mmdb'];
        yield 'HTTP URI' => ['https://geo.example.test/country.mmdb'];
        yield 'file wrapper' => ['file:///tmp/country.mmdb'];
        yield 'PHP wrapper' => ['php://memory'];
        yield 'named scheme without slashes' => ['data:text/plain,mmdb'];
        yield 'Windows UNC share' => ['\\\\server\\share\\country.mmdb'];
        yield 'slash UNC share' => ['//server/share/country.mmdb'];
        yield 'Windows device namespace' => ['\\\\?\\C:\\GeoIP\\country.mmdb'];
        yield 'NUL byte' => ["country.mmdb\0.txt"];
        yield 'wrong extension' => [__FILE__];
    }

    public function testCorruptLocalDatabaseReturnsUnknownWithoutThrowing(): void
    {
        $projectDir = sys_get_temp_dir().'/aggregate-geo-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($projectDir));
        $databasePath = $projectDir.'/GeoLite2-Country.mmdb';
        file_put_contents($databasePath, 'not an MMDB');

        try {
            $lookup = new MaxMindMmdbGeoCodeLookup($projectDir);

            self::assertNull($lookup->lookup('GeoLite2-Country.mmdb', '8.8.8.8'));
        } finally {
            unlink($databasePath);
            rmdir($projectDir);
        }
    }

    public function testRelativePathCannotEscapeProjectDirectory(): void
    {
        $baseDir = sys_get_temp_dir().'/aggregate-geo-'.bin2hex(random_bytes(6));
        $projectDir = $baseDir.'/project';
        self::assertTrue(mkdir($baseDir));
        self::assertTrue(mkdir($projectDir));
        $outsidePath = $baseDir.'/outside.mmdb';
        file_put_contents($outsidePath, 'not an MMDB');

        try {
            $lookup = new MaxMindMmdbGeoCodeLookup($projectDir);

            self::assertNull($lookup->lookup('../outside.mmdb', '8.8.8.8'));
        } finally {
            unlink($outsidePath);
            rmdir($projectDir);
            rmdir($baseDir);
        }
    }
}
