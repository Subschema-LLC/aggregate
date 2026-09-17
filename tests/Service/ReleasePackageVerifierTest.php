<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Service\ApplicationUpdateService;
use App\Service\ReleasePackageVerifier;
use App\Service\UpdateSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleasePackageVerifierTest extends TestCase
{
    private string $directory;
    private string $secretKey;
    private string $publicKey;
    private array $identity;

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('The release verifier requires the zip and sodium extensions.');
        }
        $this->directory = sys_get_temp_dir().'/aggregate-release-verifier-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/config', 0700, true);
        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
        file_put_contents($this->directory.'/config/release-signing.pub', base64_encode($this->publicKey)."\n");
        file_put_contents($this->directory.'/installed.php', 'original installation');
        $this->identity = [
            'schema' => 1,
            'version' => '1.2.3',
            'repository' => ApplicationUpdateService::REPOSITORY,
            'branch' => 'master',
            'commit' => str_repeat('a', 40),
            'built_at' => '2026-09-14T12:00:00Z',
            'requirements' => ['php' => '>=8.2', 'extensions' => ['ctype']],
        ];
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
    }

    public function testValidPackageAuthenticatesIdentityAndLeavesAllLocalFilesUnchanged(): void
    {
        $manifest = $this->package();
        $before = $this->snapshot();

        self::assertSame($manifest, $this->verify());
        self::assertSame($before, $this->snapshot());
        self::assertFileDoesNotExist($this->directory.'/public/index.php');
        self::assertSame('original installation', file_get_contents($this->directory.'/installed.php'));
    }

    #[DataProvider('downloadedFileArguments')]
    public function testVerificationRejectsRemoteStreamPathsBeforeAnyNetworkOperation(int $argument): void
    {
        $this->package();
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn([]);
        $verifier = new ReleasePackageVerifier($config, new UpdateSettings($config), $this->directory, new FeatureFlags($config));
        $paths = [
            $this->directory.'/aggregate-1.2.3.zip',
            $this->directory.'/aggregate-release.json',
            $this->directory.'/aggregate-release.json.sig',
        ];
        $paths[$argument] = 'aggregate-verifier-remote://example.invalid/release-file';
        ReleaseVerifierRemoteStream::$accesses = 0;
        self::assertTrue(stream_wrapper_register('aggregate-verifier-remote', ReleaseVerifierRemoteStream::class, STREAM_IS_URL));
        try {
            try {
                $verifier->verify(...$paths);
                self::fail('Remote files must not be accepted by the offline verifier.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('local', $e->getMessage());
            }
            self::assertSame(0, ReleaseVerifierRemoteStream::$accesses, 'No remote stat/read may occur before rejecting a URL.');
        } finally {
            stream_wrapper_unregister('aggregate-verifier-remote');
        }
    }

    public static function downloadedFileArguments(): iterable
    {
        yield 'ZIP' => [0];
        yield 'manifest' => [1];
        yield 'signature' => [2];
    }

    public function testYamlPublicKeyTakesPrecedenceOverBundledKeyFile(): void
    {
        $manifest = $this->package();
        file_put_contents($this->directory.'/config/release-signing.pub', base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));

        self::assertSame($manifest, $this->verify(['updates_signing_public_key' => base64_encode($this->publicKey)]));
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidExplicitYamlKeyNeverFallsBackToTheBundledKey(mixed $key): void
    {
        $this->package();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('public key');

        $this->verify(['updates_signing_public_key' => $key]);
    }

    public static function invalidKeys(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'array' => [[]];
        yield 'boolean' => [false];
        yield 'invalid base64' => ['not a key!'];
        yield 'incorrect key length' => [base64_encode('short')];
    }

    public function testMissingTrustedKeyCannotBeObtainedFromThePackage(): void
    {
        $this->package();
        unlink($this->directory.'/config/release-signing.pub');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Obtain it independently');

        $this->verify();
    }

    public function testSignatureFromAnotherKeyIsRejected(): void
    {
        $this->package();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signature is invalid');

        $this->verify(['updates_signing_public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);
    }

    public function testManifestTamperingInvalidatesSignatureBeforeMetadataIsUsed(): void
    {
        $this->package();
        file_put_contents($this->directory.'/aggregate-release.json', '{invalid-json');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signature is invalid');

        $this->verify();
    }

    public function testAlteredSignatureIsRejected(): void
    {
        $this->package();
        $path = $this->directory.'/aggregate-release.json.sig';
        $signature = base64_decode((string) file_get_contents($path), true);
        $signature[0] = chr(ord($signature[0]) ^ 1);
        file_put_contents($path, base64_encode($signature));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signature is invalid');

        $this->verify();
    }

    #[DataProvider('archiveTampering')]
    public function testArchiveTamperingFailsBeforeArchiveInspection(bool $changeSize, string $message): void
    {
        $this->package();
        $path = $this->directory.'/aggregate-1.2.3.zip';
        $contents = (string) file_get_contents($path);
        if ($changeSize) {
            $contents .= 'tampered';
        } else {
            $contents[10] = chr(ord($contents[10]) ^ 1);
        }
        file_put_contents($path, $contents);
        clearstatcache(true, $path);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->verify();
    }

    public static function archiveTampering(): iterable
    {
        yield 'changed length' => [true, 'ZIP size does not match'];
        yield 'same length but changed contents' => [false, 'ZIP checksum does not match'];
    }

    public function testEmbeddedIdentityMustMatchTheAuthenticatedManifest(): void
    {
        $entries = $this->entries();
        $entries['release.json'] = json_encode(array_replace($this->identity, ['commit' => str_repeat('b', 40)]), JSON_THROW_ON_ERROR);
        $this->package($entries);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('embedded release identity does not match');

        $this->verify();
    }

    public function testReleaseForAnotherBranchIsRejectedDespiteAValidSignature(): void
    {
        $this->identity['branch'] = 'releases/stable';
        $this->package();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match updates_branch');

        $this->verify();
    }

    public function testMatchingConfiguredBranchOverrideAllowsVerification(): void
    {
        $this->identity['branch'] = 'releases/stable';
        $manifest = $this->package();

        self::assertSame($manifest, $this->verify(['updates_branch' => 'releases/stable']));
    }

    #[DataProvider('incompatibleRequirements')]
    public function testIncompatibleRuntimeRequirementsAreRejected(array $requirements, string $message): void
    {
        $this->identity['requirements'] = $requirements;
        $this->package();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->verify();
    }

    public static function incompatibleRequirements(): iterable
    {
        yield 'newer PHP' => [['php' => '>=99.0', 'extensions' => []], 'Requires PHP >=99.0'];
        yield 'missing extension' => [['php' => '>=8.2', 'extensions' => ['aggregate_nonexistent_extension']], 'aggregate_nonexistent_extension'];
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafeZipPathsAreRejectedWithoutExtraction(string $name): void
    {
        $this->package($this->entries() + [$name => 'unsafe contents']);
        $before = $this->snapshot();
        try {
            $this->verify();
            self::fail('The unsafe archive should have been rejected.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('unsafe path', $e->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }

    public static function unsafePaths(): iterable
    {
        foreach (['../escaped.php', '/absolute.php', 'public/../escaped.php', 'C:/escaped.php',
            'public\\escaped.php', 'public/.git/config', 'public/aux.php', 'public/file.', 'public/file ', 'public//file.php'] as $path) {
            yield $path => [$path];
        }
    }

    public function testUnixSymbolicLinkEntryIsRejected(): void
    {
        $this->package($this->entries() + ['public/link' => '/tmp/outside'], static function (\ZipArchive $zip): void {
            self::assertTrue($zip->setExternalAttributesName('public/link', \ZipArchive::OPSYS_UNIX, 0120777 << 16));
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symbolic link or special file');

        $this->verify();
    }

    public function testCaseConflictingZipPathsAreRejected(): void
    {
        $this->package($this->entries() + ['public/INDEX.php' => 'different file']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate or case-conflicting');

        $this->verify();
    }

    public function testConflictingParentFilePathsAreRejected(): void
    {
        $this->package($this->entries() + ['assets' => 'file', 'assets/app.js' => 'child of file']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflicting parent file paths');

        $this->verify();
    }

    #[DataProvider('requiredFiles')]
    public function testMissingRequiredApplicationFileIsRejected(string $path): void
    {
        $entries = $this->entries();
        unset($entries[$path]);
        $this->package($entries);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing a required application file');

        $this->verify();
    }

    public static function requiredFiles(): iterable
    {
        foreach (['release.json', 'composer.json', 'composer.lock', 'vendor/autoload.php', 'vendor/autoload_runtime.php', 'public/index.php', 'bin/console', 'LICENSE'] as $path) {
            yield $path => [$path];
        }
    }

    public function testMandatoryFileMustUseTheExactCaseExpectedByPhp(): void
    {
        $entries = $this->entries();
        $entries['vendor/AUTOLOAD.php'] = $entries['vendor/autoload.php'];
        unset($entries['vendor/autoload.php']);
        $this->package($entries);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing a required application file');

        $this->verify();
    }

    private function verify(array $configuration = []): array
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn($configuration);
        $verifier = new ReleasePackageVerifier($config, new UpdateSettings($config), $this->directory, new FeatureFlags($config));

        return $verifier->verify(
            $this->directory.'/aggregate-1.2.3.zip',
            $this->directory.'/aggregate-release.json',
            $this->directory.'/aggregate-release.json.sig',
        );
    }

    private function package(?array $entries = null, ?callable $configure = null): array
    {
        $path = $this->directory.'/aggregate-1.2.3.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries ?? $this->entries() as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        if ($configure !== null) {
            $configure($zip);
        }
        self::assertTrue($zip->close());
        clearstatcache(true, $path);
        $manifest = $this->identity + ['package' => [
            'filename' => 'aggregate-1.2.3.zip',
            'sha256' => hash_file('sha256', $path),
            'size' => filesize($path),
        ]];
        $bytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        file_put_contents($this->directory.'/aggregate-release.json', $bytes);
        file_put_contents($this->directory.'/aggregate-release.json.sig', base64_encode(sodium_crypto_sign_detached($bytes, $this->secretKey))."\n");

        return $manifest;
    }

    private function entries(): array
    {
        return [
            'release.json' => json_encode($this->identity, JSON_THROW_ON_ERROR),
            'composer.json' => '{}',
            'composer.lock' => '{}',
            'vendor/autoload.php' => '<?php // fixture autoloader',
            'vendor/autoload_runtime.php' => '<?php // fixture runtime',
            'public/index.php' => '<?php // fixture front controller',
            'bin/console' => '#!/usr/bin/env php',
            'LICENSE' => 'AGPL-3.0-only',
        ];
    }

    private function snapshot(): array
    {
        $result = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $result[substr($file->getPathname(), strlen($this->directory) + 1)] = hash_file('sha256', $file->getPathname());
        }
        ksort($result);

        return $result;
    }
}

/** A URL wrapper that records attempted I/O without ever contacting a network. */
final class ReleaseVerifierRemoteStream
{
    public mixed $context;
    public static int $accesses = 0;

    public function url_stat(string $path, int $flags): false
    {
        ++self::$accesses;

        return false;
    }
}
