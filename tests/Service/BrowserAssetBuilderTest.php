<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrowserAssetBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BrowserAssetBuilderTest extends TestCase
{
    public function testConcurrentBuildCannotStartOrReleaseTheOtherBuildersLock(): void
    {
        $directory = sys_get_temp_dir().'/aggregate-builder-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($directory.'/var/browser');
        $owner = fopen($directory.'/var/browser/build.lock', 'c');
        self::assertTrue(flock($owner, LOCK_EX | LOCK_NB));
        try {
            try {
                (new BrowserAssetBuilder($directory))->build();
                self::fail('A concurrent build must not start.');
            } catch (\RuntimeException $error) {
                self::assertSame('Another browser asset build is running. Try again after it finishes.', $error->getMessage());
            }
            $contender = fopen($directory.'/var/browser/build.lock', 'c');
            try {
                self::assertFalse(flock($contender, LOCK_EX | LOCK_NB));
            } finally {
                fclose($contender);
            }
        } finally {
            flock($owner, LOCK_UN);
            fclose($owner);
            (new Filesystem())->remove($directory);
        }
    }
}
