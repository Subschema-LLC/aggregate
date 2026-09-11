<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\InternalTrafficSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class InternalTrafficSettingsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-internal-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(InternalTrafficSettings::DEFAULTS), InternalTrafficSettings::TOKEN_KEY] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach (glob($this->projectDir.'/config/*') as $path) {
            unlink($path);
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testDefaultsAreUsefulAndBrowserConfigurationNeverContainsShareToken(): void
    {
        $settings = $this->settings(['internal_traffic_share_token' => str_repeat('a', 64)]);

        self::assertSame([
            'storage' => 'cookie', 'name' => 'orgInternalTraffic', 'value' => 'true', 'cookieDomain' => '',
        ], $settings->toBrowserConfig());
        self::assertSame(str_repeat('a', 64), $settings->getShareToken());
        self::assertSame('true', InternalTrafficSettings::validateMarker(['internal_traffic_value' => true])['internal_traffic_value']);
    }

    public function testNestedEnvironmentSavePreservesOtherValuesAndEnvironmentOverrides(): void
    {
        $_ENV['INTERNAL_TRAFFIC_NAME'] = 'managedMarker';
        $settings = $this->settings([
            'internal_traffic_name' => 'globalMarker',
            'environments' => [
                'test' => ['app_host' => 'https://analytics.example.com', 'internal_traffic_share_token' => str_repeat('b', 64)],
                'prod' => ['internal_traffic_name' => 'prodMarker'],
            ],
        ]);

        $settings->saveMarker([
            'internal_traffic_storage' => 'local_storage',
            'internal_traffic_value' => 'staff',
            'internal_traffic_cookie_domain' => '.Example.com',
        ]);

        $written = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('globalMarker', $written['internal_traffic_name']);
        self::assertArrayNotHasKey('internal_traffic_name', $written['environments']['test']);
        self::assertSame('prodMarker', $written['environments']['prod']['internal_traffic_name']);
        self::assertSame('https://analytics.example.com', $written['environments']['test']['app_host']);
        self::assertSame(str_repeat('b', 64), $written['environments']['test']['internal_traffic_share_token']);
        self::assertSame('.example.com', $written['environments']['test']['internal_traffic_cookie_domain']);
        self::assertSame('managedMarker', $settings->toBrowserConfig()['name']);
        self::assertTrue($settings->getEnvironmentOverrides()['internal_traffic_name']);
    }

    public function testEnvironmentSpecificFileWinsAndReceivesTokenRotationAndRevocation(): void
    {
        $settings = $this->settings(['internal_traffic_name' => 'mainMarker']);
        file_put_contents($this->projectDir.'/config/aggregate_test.yaml', "internal_traffic_name: testMarker\n");

        self::assertSame('testMarker', $settings->toBrowserConfig()['name']);
        self::assertFalse($settings->matchesShareToken(''));
        $first = $settings->rotateShareToken();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first);
        self::assertTrue($settings->matchesShareToken($first));
        $second = $settings->rotateShareToken();
        self::assertNotSame($first, $second);
        self::assertFalse($settings->matchesShareToken($first));
        self::assertTrue($settings->matchesShareToken($second));
        self::assertSame($second, Yaml::parseFile($this->projectDir.'/config/aggregate_test.yaml')['internal_traffic_share_token']);

        $settings->revokeShareToken();
        self::assertFalse($settings->matchesShareToken($second));
        self::assertSame('', Yaml::parseFile($this->projectDir.'/config/aggregate_test.yaml')['internal_traffic_share_token']);
        self::assertSame(['internal_traffic_name' => 'mainMarker'], Yaml::parseFile($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testExplicitEmptyEnvironmentTokenDisablesThePageAndCannotBeOverwritten(): void
    {
        $_ENV['INTERNAL_TRAFFIC_SHARE_TOKEN'] = '';
        $settings = $this->settings(['internal_traffic_share_token' => str_repeat('c', 64)]);
        self::assertFalse($settings->matchesShareToken(str_repeat('c', 64)));
        self::assertSame('', $settings->ensureShareToken());

        $this->expectException(\InvalidArgumentException::class);
        $settings->rotateShareToken();
    }

    public function testInvalidYamlAndMalformedTokensNeverAuthorizePublicAccess(): void
    {
        $settings = $this->settings(['internal_traffic_share_token' => 'guessable']);
        self::assertFalse($settings->matchesShareToken('guessable'));
        self::assertSame('orgInternalTraffic', $settings->toBrowserConfig()['name']);

        file_put_contents($this->projectDir.'/config/aggregate.yaml', 'environments: [broken');
        $settings = new InternalTrafficSettings(new AggregateConfigLoader($this->projectDir, 'test'));
        self::assertFalse($settings->matchesShareToken(str_repeat('a', 64)));
        $this->expectException(\RuntimeException::class);
        $settings->rotateShareToken();
    }

    #[DataProvider('invalidMarkerValues')]
    public function testRejectsUnsafeMarkerSettingsWithoutWriting(string $key, mixed $value): void
    {
        $settings = $this->settings([]);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        try {
            $settings->saveMarker(array_replace(InternalTrafficSettings::DEFAULTS, [$key => $value]));
            self::fail('Invalid marker was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        }
    }

    public static function invalidMarkerValues(): iterable
    {
        yield ['internal_traffic_storage', 'session_storage'];
        yield ['internal_traffic_name', 'name; Domain=attacker.test'];
        yield ['internal_traffic_name', 'aggregate_session'];
        yield ['internal_traffic_name', 'aggregate_visitor_id'];
        yield ['internal_traffic_name', 'aggregate_session_id'];
        yield ['internal_traffic_name', '0'];
        yield ['internal_traffic_name', '-1'];
        yield ['internal_traffic_value', []];
        yield ['internal_traffic_value', ''];
        yield ['internal_traffic_value', "hello\nworld"];
        yield ['internal_traffic_value', str_repeat('a', 257)];
        yield ['internal_traffic_cookie_domain', 'example.com; HttpOnly'];
        yield ['internal_traffic_cookie_domain', 'https://example.com'];
        yield ['internal_traffic_cookie_domain', '..example.com'];
    }

    private function settings(array $values): InternalTrafficSettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($values, 4, 2));

        return new InternalTrafficSettings(new AggregateConfigLoader($this->projectDir, 'test'));
    }
}
