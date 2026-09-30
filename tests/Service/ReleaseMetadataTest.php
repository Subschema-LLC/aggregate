<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ApplicationUpdateService;
use App\Service\InstalledRelease;
use App\Service\ReleaseMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseMetadataTest extends TestCase
{
    public function testParsesEmbeddedIdentityAndExternalPackageMetadata(): void
    {
        $local = self::metadata();
        self::assertSame($local, ReleaseMetadata::parse(json_encode($local, JSON_THROW_ON_ERROR)));
        $manifest = $local + ['package' => ['filename' => 'aggregate-1.2.3.zip', 'sha256' => str_repeat('b', 64), 'size' => 2345]];
        self::assertSame($manifest, ReleaseMetadata::parse(json_encode($manifest, JSON_THROW_ON_ERROR), true));
        $manifest['commit'] = str_repeat('a', 64);
        $manifest['built_at'] = '2026-09-14T12:00:00+00:00';
        self::assertSame($manifest, ReleaseMetadata::parse(json_encode($manifest, JSON_THROW_ON_ERROR), true));
    }

    #[DataProvider('invalidMetadata')]
    public function testRefusesInvalidMetadata(array $changes, bool $requirePackage = false): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReleaseMetadata::parse(json_encode(array_replace(self::metadata(), $changes), JSON_THROW_ON_ERROR), $requirePackage);
    }

    public static function invalidMetadata(): iterable
    {
        yield 'future schema' => [['schema' => 2]];
        yield 'string schema' => [['schema' => '1']];
        yield 'unknown field' => [['installer' => 'shell command']];
        yield 'other repository' => [['repository' => 'attacker/aggregate']];
        yield 'version tag' => [['version' => 'v1.2.3']];
        yield 'prerelease' => [['version' => '1.2.3-beta.1']];
        yield 'leading zero' => [['version' => '01.2.3']];
        yield 'version overflow' => [['version' => '99999999999.2.3']];
        yield 'calendar tag prefix' => [['version' => 'v2026.09.01']];
        yield 'calendar index zero' => [['version' => '2026.09.00']];
        yield 'calendar month thirteen' => [['version' => '2026.13.01']];
        yield 'calendar three-digit index' => [['version' => '2026.09.100']];
        yield 'calendar prerelease' => [['version' => '2026.09.01-rc1']];
        yield 'missing branch' => [['branch' => null]];
        yield 'invalid branch' => [['branch' => '../master']];
        yield 'invalid commit' => [['commit' => 'main']];
        yield 'wrong timezone' => [['built_at' => '2026-09-14T12:00:00-05:00']];
        yield 'impossible date' => [['built_at' => '2026-02-30T12:00:00Z']];
        yield 'unsupported PHP constraint' => [['requirements' => ['php' => '^8.2', 'extensions' => []]]];
        yield 'invalid extensions' => [['requirements' => ['php' => '>=8.2', 'extensions' => ['shell;run']]]];
        yield 'duplicate extensions' => [['requirements' => ['php' => '>=8.2', 'extensions' => ['json', 'json']]]];
        yield 'unknown requirement' => [['requirements' => ['php' => '>=8.2', 'extensions' => [], 'install' => 'run']]];
        yield 'missing package' => [[], true];
        yield 'wrong package filename' => [['package' => ['filename' => '../aggregate-1.2.3.zip', 'sha256' => str_repeat('a', 64), 'size' => 123]], true];
        yield 'wrong hash' => [['package' => ['filename' => 'aggregate-1.2.3.zip', 'sha256' => 'a', 'size' => 123]], true];
        yield 'negative size' => [['package' => ['filename' => 'aggregate-1.2.3.zip', 'sha256' => str_repeat('a', 64), 'size' => -1]], true];
        yield 'string size' => [['package' => ['filename' => 'aggregate-1.2.3.zip', 'sha256' => str_repeat('a', 64), 'size' => '123']], true];
    }

    public function testCalendarVersionsParseAndSortAfterLegacyVersions(): void
    {
        $calendar = array_replace(self::metadata(), ['version' => '2026.09.01']);
        $calendar['package'] = ['filename' => 'aggregate-2026.09.01.zip', 'sha256' => str_repeat('b', 64), 'size' => 1];
        self::assertSame('2026.09.01', ReleaseMetadata::parse(json_encode($calendar, JSON_THROW_ON_ERROR), true)['version']);

        self::assertTrue(ReleaseMetadata::isCalendarVersion('2026.12.10'));
        self::assertFalse(ReleaseMetadata::isCalendarVersion('2026.9.1'));
        self::assertTrue(ReleaseMetadata::isVersion('0.2.0'), 'Earlier X.Y.Z installations stay readable so they can update.');
        // The index counts releases within a month, so the tenth release sorts after the ninth.
        $ordered = ['2026.10.01', '0.2.0', '2026.09.10', '2027.01.01', '2026.09.02'];
        usort($ordered, 'version_compare');
        self::assertSame(['0.2.0', '2026.09.02', '2026.09.10', '2026.10.01', '2027.01.01'], $ordered);
    }

    #[DataProvider('invalidJson')]
    public function testRefusesMalformedOrOversizedJson(string $json): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReleaseMetadata::parse($json);
    }

    public static function invalidJson(): iterable
    {
        yield 'syntax' => ['{broken'];
        yield 'scalar' => ['"release"'];
        yield 'array' => ['[]'];
        yield 'size' => [str_repeat(' ', ReleaseMetadata::MAX_BYTES + 1)];
    }

    public function testCompatibilityChecksActualPhpAndExtensions(): void
    {
        self::assertSame([], ReleaseMetadata::compatibilityErrors(self::metadata()));
        $metadata = self::metadata();
        $metadata['requirements'] = ['php' => '>=99.0', 'extensions' => ['aggregate_missing_extension']];
        $errors = ReleaseMetadata::compatibilityErrors($metadata);
        self::assertCount(2, $errors);
        self::assertStringContainsString('Requires PHP >=99.0', $errors[0]);
        self::assertStringContainsString('aggregate_missing_extension', $errors[1]);
    }

    public function testCompatibilityAcceptsLowestSupportedAndNewerPhpVersions(): void
    {
        $metadata = self::metadata();
        $metadata['requirements'] = ['php' => '>=8.2', 'extensions' => []];
        self::assertSame([], ReleaseMetadata::compatibilityErrors($metadata));

        $metadata['requirements'] = ['php' => '8.2.0', 'extensions' => []];
        self::assertSame([], ReleaseMetadata::compatibilityErrors($metadata));

        $metadata['requirements'] = ['php' => '>=8.0', 'extensions' => []];
        self::assertSame([], ReleaseMetadata::compatibilityErrors($metadata));
    }

    public function testInstalledIdentityDoesNotRequireGitAndIsNeverCached(): void
    {
        $directory = sys_get_temp_dir().'/aggregate-release-identity-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $installed = new InstalledRelease($directory);
            self::assertFalse($installed->present());
            self::assertNull($installed->read());
            file_put_contents($directory.'/release.json', json_encode(self::metadata(), JSON_THROW_ON_ERROR));
            self::assertTrue($installed->present());
            self::assertSame('1.2.3', $installed->read()['version']);
            $metadata = self::metadata();
            $metadata['version'] = '1.3.0';
            file_put_contents($directory.'/release.json', json_encode($metadata, JSON_THROW_ON_ERROR));
            self::assertSame('1.3.0', $installed->read()['version']);
            file_put_contents($directory.'/release.json', '{broken');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Installed release.json is invalid');
            $installed->read();
        } finally {
            @unlink($directory.'/release.json');
            rmdir($directory);
        }
    }

    public function testMetadataDirectoryIsPresentButUnreadableAsAnIdentity(): void
    {
        $directory = sys_get_temp_dir().'/aggregate-release-identity-'.bin2hex(random_bytes(8));
        mkdir($directory.'/release.json', 0700, true);
        try {
            $installed = new InstalledRelease($directory);
            self::assertTrue($installed->present());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('not a readable');
            $installed->read();
        } finally {
            rmdir($directory.'/release.json');
            rmdir($directory);
        }
    }

    private static function metadata(): array
    {
        return [
            'schema' => 1, 'version' => '1.2.3', 'repository' => ApplicationUpdateService::REPOSITORY,
            'branch' => 'master', 'commit' => str_repeat('a', 40), 'built_at' => '2026-09-14T12:00:00Z',
            'requirements' => ['php' => '>=8.2', 'extensions' => ['json']],
        ];
    }
}
