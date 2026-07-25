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
