<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\ParsedYamlCache;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ParsedYamlCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-parsed-yaml-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testAnUnchangedDocumentIsParsedOnceAcrossRequests(): void
    {
        $parses = 0;
        $parse = static function (string $yaml) use (&$parses): array {
            ++$parses;

            return Yaml::parse($yaml);
        };

        // Each request builds its own cache object, as PHP-FPM does.
        $first = (new ParsedYamlCache($this->directory))->remember('aggregate', "a: 1\nb: [x, y]\n", $parse);
        $second = (new ParsedYamlCache($this->directory))->remember('aggregate', "a: 1\nb: [x, y]\n", $parse);

        self::assertSame(['a' => 1, 'b' => ['x', 'y']], $first);
        self::assertSame($first, $second);
        self::assertSame(1, $parses);
    }

    public function testChangedContentsAreParsedAgainAndReplaceTheOldEntry(): void
    {
        $cache = new ParsedYamlCache($this->directory);
        $parse = static fn (string $yaml): array => Yaml::parse($yaml);

        self::assertSame(['a' => 1], $cache->remember('aggregate', 'a: 1', $parse));
        // Same length, so neither size nor a coarse modification time would tell them apart.
        self::assertSame(['a' => 2], $cache->remember('aggregate', 'a: 2', $parse));
        self::assertCount(1, glob($this->directory.'/aggregate.*.php') ?: []);
    }

    public function testDocumentsThatFailAreNeverCached(): void
    {
        $cache = new ParsedYamlCache($this->directory);
        $attempts = 0;
        $parse = static function () use (&$attempts): array {
            ++$attempts;
            throw new \RuntimeException('invalid');
        };

        for ($i = 0; $i < 2; ++$i) {
            try {
                $cache->remember('aggregate', 'broken: [', $parse);
                self::fail('The parse error must reach the caller.');
            } catch (\RuntimeException $exception) {
                self::assertSame('invalid', $exception->getMessage());
            }
        }

        self::assertSame(2, $attempts);
        self::assertSame([], glob($this->directory.'/*.php') ?: []);
    }

    public function testNamesKeepSeparateEntries(): void
    {
        $cache = new ParsedYamlCache($this->directory);
        $cache->remember('aggregate', 'a: 1', static fn (): array => ['from' => 'aggregate']);
        $websites = $cache->remember('websites', 'a: 1', static fn (): array => ['from' => 'websites']);

        self::assertSame(['from' => 'websites'], $websites);
        self::assertSame(['from' => 'aggregate'], $cache->remember('aggregate', 'a: 1', static fn (): array => ['from' => 'parsed again']));
    }

    public function testAnUnusableEntryIsParsedAgain(): void
    {
        $cache = new ParsedYamlCache($this->directory);
        $cache->remember('aggregate', 'a: 1', static fn (): array => ['a' => 1]);
        $files = glob($this->directory.'/aggregate.*.php') ?: [];
        self::assertCount(1, $files);
        file_put_contents($files[0], '<?php return "not an entry";');

        self::assertSame(['a' => 'reparsed'], $cache->remember('aggregate', 'a: 1', static fn (): array => ['a' => 'reparsed']));
    }

    public function testAnUnwritableDirectoryStillParses(): void
    {
        $file = $this->directory.'/not-a-directory';
        file_put_contents($file, '');
        $cache = new ParsedYamlCache($file.'/cache');

        self::assertSame(['a' => 1], $cache->remember('aggregate', 'a: 1', static fn (string $yaml): array => Yaml::parse($yaml)));
        self::assertSame(['a' => 1], $cache->remember('aggregate', 'a: 1', static fn (string $yaml): array => Yaml::parse($yaml)));
    }

    public function testConfigurationEditsAreSeenAndInvalidEditsStillFailClosed(): void
    {
        $projectDir = $this->directory.'/project';
        mkdir($projectDir.'/config', 0700, true);
        $write = static fn (string $yaml) => file_put_contents($projectDir.'/config/aggregate.yaml', $yaml);

        $write("anonymous_tracking_enabled: true\nrate_limit_per_minute: 100\n");
        self::assertSame(100, (new AggregateConfigLoader($projectDir, 'prod'))->get('rate_limit_per_minute'));
        self::assertNotSame([], glob($projectDir.'/var/cache/prod/parsed-yaml/config-aggregate.*.php') ?: []);

        $write("anonymous_tracking_enabled: true\nrate_limit_per_minute: 250\n");
        self::assertSame(250, (new AggregateConfigLoader($projectDir, 'prod'))->get('rate_limit_per_minute'));

        // A broken edit must not fall back to the last good cached settings.
        $write("anonymous_tracking_enabled: [\n");
        $loader = new AggregateConfigLoader($projectDir, 'prod');
        self::assertTrue($loader->hasLoadError());
        self::assertNull($loader->get('rate_limit_per_minute'));
    }

    public function testWebsiteEditsAreSeen(): void
    {
        $projectDir = $this->directory.'/project';
        mkdir($projectDir.'/config', 0700, true);
        $site = static fn (string $token): string => Yaml::dump(['websites' => [['name' => 'Site', 'domain' => 'example.com', 'token' => $token]]], 4);

        file_put_contents($projectDir.'/config/websites.yaml', $site('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', (new WebsiteConfigManager($projectDir))->getWebsites()[0]['token']);

        file_put_contents($projectDir.'/config/websites.yaml', $site('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'));
        self::assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', (new WebsiteConfigManager($projectDir))->getWebsites()[0]['token']);
    }
}
