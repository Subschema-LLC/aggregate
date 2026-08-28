<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AggregateConfigLoaderTest extends TestCase
{
    private string $projectDir;

    /** @var array<string, array{env_exists: bool, env: mixed, server_exists: bool, server: mixed}> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-config-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => $saved) {
            if ($saved['env_exists']) {
                $_ENV[$key] = $saved['env'];
            } else {
                unset($_ENV[$key]);
            }

            if ($saved['server_exists']) {
                $_SERVER[$key] = $saved['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }

        foreach (glob($this->projectDir.'/config/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);
    }

    public function testEnvironmentStringZeroOverridesYamlBoolean(): void
    {
        $this->writeConfig([
            'anonymous_tracking_enabled' => true,
        ]);
        $this->setEnvironment('ANONYMOUS_TRACKING_ENABLED', '0');

        $loader = new AggregateConfigLoader($this->projectDir, 'prod');

        self::assertSame('0', $loader->getWithEnvFallback('anonymous_tracking_enabled', true));
        self::assertFalse($loader->getBoolWithEnvFallback('anonymous_tracking_enabled', true));
    }

    public function testServerStringZeroIsUsedWhenEnvEntryIsEmpty(): void
    {
        $this->writeConfig([
            'anonymous_tracking_enabled' => true,
        ]);
        $this->setEnvironment('ANONYMOUS_TRACKING_ENABLED', '', '0');

        $loader = new AggregateConfigLoader($this->projectDir, 'prod');

        self::assertFalse($loader->getBoolWithEnvFallback('anonymous_tracking_enabled', true));
    }

    public function testEnvironmentValueOverridesNestedYamlEnvironment(): void
    {
        $this->writeConfig([
            'rate_limit_per_minute' => 100,
            'environments' => [
                'prod' => ['rate_limit_per_minute' => 200],
                'test' => ['rate_limit_per_minute' => 300],
            ],
        ]);
        $this->setEnvironment('RATE_LIMIT_PER_MINUTE', '400');

        $loader = new AggregateConfigLoader($this->projectDir, 'prod');

        self::assertSame('400', $loader->getWithEnvFallback('rate_limit_per_minute', 100));
        self::assertSame(200, $loader->get('rate_limit_per_minute'));
    }

    public function testEnvironmentSpecificFileTakesPrecedenceOverMainFile(): void
    {
        $this->writeConfig(['rate_limit_per_minute' => 100]);
        file_put_contents(
            $this->projectDir.'/config/aggregate_prod.yaml',
            Yaml::dump(['rate_limit_per_minute' => 250]),
        );

        $loader = new AggregateConfigLoader($this->projectDir, 'prod');

        self::assertSame(250, $loader->get('rate_limit_per_minute'));
    }

    public function testSetManyPersistsRelatedValuesInOneLockedUpdate(): void
    {
        $this->writeConfig([
            'brand_name' => 'Global name',
            'environments' => [
                'prod' => ['rate_limit_per_minute' => 100],
                'test' => ['rate_limit_per_minute' => 1000],
            ],
        ]);
        $loader = new AggregateConfigLoader($this->projectDir, 'prod');

        $loader->setMany([
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => 'Example',
            'brand_logo_path' => 'var/branding/prod/logo-0123456789abcdef0123456789abcdef.png',
        ]);

        self::assertSame('Example Analytics', $loader->get('brand_name'));
        self::assertSame('Example', $loader->get('brand_logo_text'));

        $written = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('Global name', $written['brand_name']);
        self::assertSame('Example Analytics', $written['environments']['prod']['brand_name']);
        self::assertSame('Example', $written['environments']['prod']['brand_logo_text']);
        self::assertSame(
            'var/branding/prod/logo-0123456789abcdef0123456789abcdef.png',
            $written['environments']['prod']['brand_logo_path'],
        );
        self::assertSame(['rate_limit_per_minute' => 1000], $written['environments']['test']);
    }

    public function testSetManyWritesToTheEnvironmentSpecificSourceFile(): void
    {
        $this->writeConfig(['brand_name' => 'Main']);
        file_put_contents(
            $this->projectDir.'/config/aggregate_prod.yaml',
            Yaml::dump(['brand_name' => 'Production']),
        );
        $loader = new AggregateConfigLoader($this->projectDir, 'prod');

        $loader->setMany([
            'brand_name' => 'Production White Label',
            'brand_logo_text' => 'PWL',
        ]);

        self::assertSame(
            ['brand_name' => 'Production White Label', 'brand_logo_text' => 'PWL'],
            Yaml::parseFile($this->projectDir.'/config/aggregate_prod.yaml'),
        );
        self::assertSame(['brand_name' => 'Main'], Yaml::parseFile($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testSetManyPreservesASymlinkAndDoesNotRequireTheConfigDirectoryToBeWritable(): void
    {
        $this->writeConfig(['brand_name' => 'Before']);
        $sharedConfig = $this->projectDir.'/shared.yaml';
        self::assertTrue(rename($this->projectDir.'/config/aggregate.yaml', $sharedConfig));
        self::assertTrue(symlink('../shared.yaml', $this->projectDir.'/config/aggregate.yaml'));
        self::assertTrue(chmod($this->projectDir.'/config', 0500));

        try {
            $loader = new AggregateConfigLoader($this->projectDir, 'prod');
            $loader->setMany([
                'brand_name' => 'After',
                'brand_logo_text' => 'Wordmark',
            ]);
        } finally {
            chmod($this->projectDir.'/config', 0700);
        }

        self::assertTrue(is_link($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame([
            'brand_name' => 'After',
            'brand_logo_text' => 'Wordmark',
        ], Yaml::parseFile($sharedConfig));

        unlink($this->projectDir.'/config/aggregate.yaml');
        unlink($sharedConfig);
    }

    public function testResetReloadsConfigurationForLongRunningWorkers(): void
    {
        $this->writeConfig(['brand_name' => 'Before']);
        $loader = new AggregateConfigLoader($this->projectDir, 'prod');
        self::assertSame('Before', $loader->get('brand_name'));
        $this->writeConfig(['brand_name' => 'After']);

        $loader->reset();

        self::assertSame('After', $loader->get('brand_name'));
    }

    public function testEmptyEnvironmentOverrideCanBeMeaningfulWhenRequested(): void
    {
        $this->writeConfig(['brand_logo_text' => 'YAML wordmark']);
        $this->setEnvironment('BRAND_LOGO_TEXT', '');
        $loader = new AggregateConfigLoader($this->projectDir, 'prod');

        self::assertSame('YAML wordmark', $loader->getWithEnvFallback('brand_logo_text'));
        self::assertSame('', $loader->getWithEnvFallback('brand_logo_text', null, allowEmpty: true));
        self::assertTrue($loader->hasEnvironmentOverride('brand_logo_text', allowEmpty: true));
        self::assertFalse($loader->hasEnvironmentOverride('brand_logo_text'));
    }

    private function writeConfig(array $config): void
    {
        file_put_contents(
            $this->projectDir.'/config/aggregate.yaml',
            Yaml::dump($config, 4, 2),
        );
    }

    private function setEnvironment(string $key, mixed $envValue, mixed $serverValue = null): void
    {
        if (!array_key_exists($key, $this->savedEnvironment)) {
            $this->savedEnvironment[$key] = [
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        $_ENV[$key] = $envValue;
        if ($serverValue === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $serverValue;
        }
    }
}
