<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\IpRateLimiter;
use PHPUnit\Framework\TestCase;

final class IpRateLimiterTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir().'/aggregate-rate-limit-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->storageDir)) {
            return;
        }

        foreach (scandir($this->storageDir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($this->storageDir.DIRECTORY_SEPARATOR.$file);
            }
        }
        @rmdir($this->storageDir);
    }

    public function testBucketFilenameDoesNotPersistTheRawAddress(): void
    {
        $limiter = new IpRateLimiter($this->storageDir, 'test-secret-that-is-not-public', 2);

        self::assertTrue($limiter->allow('2001:db8::1234'));
        self::assertTrue($limiter->allow('2001:db8::1234'));
        self::assertFalse($limiter->allow('2001:db8::1234'));

        $files = array_values(array_filter(
            scandir($this->storageDir) ?: [],
            static fn (string $file): bool => str_ends_with($file, '.json'),
        ));
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}\.json$/D', $files[0]);
        self::assertStringNotContainsString('2001', $files[0]);
        self::assertStringNotContainsString('db8', file_get_contents($this->storageDir.DIRECTORY_SEPARATOR.$files[0]) ?: '');
    }

    public function testExpiredAndLegacyBucketsAreRemoved(): void
    {
        $limiter = new IpRateLimiter($this->storageDir, 'test-secret-that-is-not-public', 2);
        $legacyBucket = $this->storageDir.'/192.0.2.10.json';
        file_put_contents($legacyBucket, '{"count":1}');
        touch($legacyBucket, time() - 180);

        self::assertTrue($limiter->allow('192.0.2.10'));
        self::assertFileDoesNotExist($legacyBucket);
    }
}
