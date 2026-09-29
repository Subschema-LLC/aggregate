<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\CollectionProfile;
use App\Service\PrivacyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CollectionProfileTest extends TestCase
{
    private string $projectDir;

    /** @var array{env_exists: bool, env: mixed, server_exists: bool, server: mixed}|null */
    private ?array $savedEnvironment = null;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-profile-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
    }

    protected function tearDown(): void
    {
        if ($this->savedEnvironment !== null) {
            if ($this->savedEnvironment['env_exists']) {
                $_ENV['COLLECTION_PROFILE'] = $this->savedEnvironment['env'];
            } else {
                unset($_ENV['COLLECTION_PROFILE']);
            }
            if ($this->savedEnvironment['server_exists']) {
                $_SERVER['COLLECTION_PROFILE'] = $this->savedEnvironment['server'];
            } else {
                unset($_SERVER['COLLECTION_PROFILE']);
            }
        }

        foreach (glob($this->projectDir.'/config/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);
    }

    public function testStandardIsTheDefaultAndKeepsIngestionEnabled(): void
    {
        $loader = $this->loader([]);
        $profile = new CollectionProfile($loader);

        self::assertSame('standard', $profile->name());
        self::assertFalse($profile->isStrict());
        self::assertSame(['profile' => 'standard'], $profile->toBrowserConfig());
        self::assertFalse($loader->hasLoadError());
        self::assertTrue((new PrivacyPolicy($loader))->isAnonymousTrackingEnabled());
    }

    public function testStrictIsNormalizedFromYaml(): void
    {
        $loader = $this->loader(['collection_profile' => ' Strict ']);

        self::assertTrue((new CollectionProfile($loader))->isStrict());
        self::assertTrue((new PrivacyPolicy($loader))->isStrictCollection());
        self::assertFalse($loader->hasLoadError());
    }

    public function testNestedEnvironmentSettingIsUsed(): void
    {
        $loader = $this->loader(['environments' => ['prod' => ['collection_profile' => 'strict']]]);

        self::assertTrue((new CollectionProfile($loader))->isStrict());
    }

    #[DataProvider('invalidProfiles')]
    public function testInvalidYamlProfileStopsIngestionAndResolvesToStrict(mixed $value): void
    {
        $loader = $this->loader(['collection_profile' => $value]);

        self::assertTrue($loader->hasLoadError());
        self::assertFalse((new PrivacyPolicy($loader))->isAnonymousTrackingEnabled());
        self::assertTrue((new CollectionProfile($loader))->isStrict());
    }

    public static function invalidProfiles(): iterable
    {
        yield 'unknown name' => ['relaxed'];
        yield 'boolean' => [true];
        yield 'list' => [['strict']];
        yield 'empty string' => [''];
    }

    public function testEnvironmentVariableOverridesYamlInBothDirections(): void
    {
        $this->setEnvironment('strict');
        $loader = $this->loader(['collection_profile' => 'standard']);
        $profile = new CollectionProfile($loader);
        self::assertTrue($profile->isStrict());
        self::assertTrue($profile->hasEnvironmentOverride());

        $this->setEnvironment('standard');
        $loader = $this->loader(['collection_profile' => 'strict']);
        self::assertFalse((new CollectionProfile($loader))->isStrict());
    }

    public function testInvalidEnvironmentVariableStopsIngestion(): void
    {
        $this->setEnvironment('off');
        $loader = $this->loader(['collection_profile' => 'standard']);

        self::assertTrue($loader->hasLoadError());
        self::assertFalse((new PrivacyPolicy($loader))->isAnonymousTrackingEnabled());
        self::assertTrue((new CollectionProfile($loader))->isStrict());
    }

    public function testSavingTheProfilePreservesUnrelatedSettings(): void
    {
        $loader = $this->loader(['brand_name' => 'Example', 'anonymous_geo_enabled' => true]);

        $loader->setMany([CollectionProfile::KEY => 'strict']);

        $saved = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame(['brand_name' => 'Example', 'anonymous_geo_enabled' => true, 'collection_profile' => 'strict'], $saved);
        self::assertTrue((new CollectionProfile(new AggregateConfigLoader($this->projectDir, 'prod')))->isStrict());
    }

    private function loader(array $config): AggregateConfigLoader
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($config, 4, 2));

        return new AggregateConfigLoader($this->projectDir, 'prod');
    }

    private function setEnvironment(string $value): void
    {
        $this->savedEnvironment ??= [
            'env_exists' => array_key_exists('COLLECTION_PROFILE', $_ENV),
            'env' => $_ENV['COLLECTION_PROFILE'] ?? null,
            'server_exists' => array_key_exists('COLLECTION_PROFILE', $_SERVER),
            'server' => $_SERVER['COLLECTION_PROFILE'] ?? null,
        ];
        $_ENV['COLLECTION_PROFILE'] = $value;
        $_SERVER['COLLECTION_PROFILE'] = $value;
    }
}
