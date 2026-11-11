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
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            apcu_delete(new \APCUIterator('/^'.preg_quote(IpRateLimiter::APCU_PREFIX, '/').'/'));
        }

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
        $limiter = new IpRateLimiter($this->storageDir, 'test-secret-that-is-not-public', 2, useApcu: false);

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
        $limiter = new IpRateLimiter($this->storageDir, 'test-secret-that-is-not-public', 2, useApcu: false);
        $legacyBucket = $this->storageDir.'/192.0.2.10.json';
        file_put_contents($legacyBucket, '{"count":1}');
        touch($legacyBucket, time() - 180);

        self::assertTrue($limiter->allow('192.0.2.10'));
        self::assertFileDoesNotExist($legacyBucket);
    }

    public function testTheCommandLineKeepsBucketsInFilesByDefault(): void
    {
        // APCu is not shared between command-line processes, so counting
        // there would not limit anything.
        self::assertFalse(IpRateLimiter::apcuShared());
        $limiter = new IpRateLimiter($this->storageDir, 'test-secret-that-is-not-public', 2);

        self::assertTrue($limiter->allow('192.0.2.20'));
        self::assertCount(1, $this->bucketFiles());
    }

    public function testApcuBucketsUseTheSameKeyedNamesAndLimit(): void
    {
        $this->requireApcu();
        $limiter = new IpRateLimiter($this->storageDir, 'test-secret-that-is-not-public', 2, useApcu: true);

        self::assertTrue($limiter->allow('2001:db8::1234'));
        self::assertTrue($limiter->allow('2001:db8::1234'));
        self::assertFalse($limiter->allow('2001:db8::1234'));
        self::assertTrue($limiter->allow('2001:db8::5678'));

        self::assertSame([], $this->bucketFiles());
        $keys = [];
        foreach (new \APCUIterator('/^'.preg_quote(IpRateLimiter::APCU_PREFIX, '/').'[a-f0-9]{64}$/D', APC_ITER_KEY | APC_ITER_TTL) as $entry) {
            $keys[] = $entry['key'];
            self::assertSame(120, $entry['ttl']);
            self::assertStringNotContainsString('db8', $entry['key']);
        }
        self::assertCount(2, $keys);
    }

    public function testExpiredFilesAreStillRemovedWhenBucketsMoveToApcu(): void
    {
        $this->requireApcu();
        $limiter = new IpRateLimiter($this->storageDir, 'test-secret-that-is-not-public', 2, useApcu: true);
        mkdir($this->storageDir, 0700, true);
        $oldBucket = $this->storageDir.'/'.str_repeat('a', 64).'.json';
        file_put_contents($oldBucket, '{"window":1,"count":1}');
        touch($oldBucket, time() - 180);

        self::assertTrue($limiter->allow('192.0.2.30'));
        self::assertFileDoesNotExist($oldBucket);
    }

    public function testAnEntryApcuCannotCountFallsBackToAFile(): void
    {
        $this->requireApcu();
        if (time() % 60 >= 58) {
            sleep(60 - time() % 60);
        }
        $secret = 'test-secret-that-is-not-public';
        $bucketId = hash_hmac('sha256', intdiv(time(), 60)."\0".'192.0.2.40', $secret);
        apcu_store(IpRateLimiter::APCU_PREFIX.$bucketId, 'not a number', 120);
        $limiter = new IpRateLimiter($this->storageDir, $secret, 2, useApcu: true);

        self::assertTrue($limiter->allow('192.0.2.40'));
        self::assertSame([$bucketId.'.json'], $this->bucketFiles());
    }

    private function requireApcu(): void
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            self::markTestSkipped('APCu is not enabled for the command line (apc.enable_cli=1).');
        }
    }

    /** @return list<string> */
    private function bucketFiles(): array
    {
        return array_values(array_filter(
            is_dir($this->storageDir) ? (scandir($this->storageDir) ?: []) : [],
            static fn (string $file): bool => str_ends_with($file, '.json'),
        ));
    }
}
