<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Controller\HealthController;
use App\Controller\ScriptController;
use App\Service\CustomDataSettings;
use App\Service\InternalTrafficSettings;
use App\Service\PrivacyPolicy;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PrivacyConfigurationFailureTest extends TestCase
{
    private string $projectDir;

    /** @var array<string, array{env_exists: bool, env: mixed, server_exists: bool, server: mixed}> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-privacy-config-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));

        foreach (['ANONYMOUS_TRACKING_ENABLED'] as $key) {
            $this->savedEnvironment[$key] = [
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
            unset($_ENV[$key], $_SERVER[$key]);
        }
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

    public function testMalformedYamlDisablesIngestion(): void
    {
        file_put_contents(
            $this->projectDir.'/config/aggregate.yaml',
            "anonymous_tracking_enabled: true\nanonymous_excluded_paths: [\n",
        );
        $config = new AggregateConfigLoader($this->projectDir, 'prod');

        self::assertTrue($config->hasLoadError());
        self::assertFalse((new PrivacyPolicy($config))->isAnonymousTrackingEnabled());
    }

    #[DataProvider('invalidGlossaries')]
    public function testInvalidGlossaryDoesNotAffectCollectionOrHealth(mixed $glossary): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'bi_glossary' => $glossary,
            'anonymous_tracking_enabled' => true,
        ], 8));
        $config = new AggregateConfigLoader($this->projectDir, 'prod');
        self::assertFalse($config->hasLoadError());
        self::assertTrue((new PrivacyPolicy($config))->isAnonymousTrackingEnabled());
        $model = new CustomDataSettings($config);
        self::assertSame([], $model->toBrowserConfig()['consentFreeProperties']);
        $tracker = new ScriptController($config, new InternalTrafficSettings($config), $model, dirname(__DIR__, 2));
        $response = $tracker();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('bi_glossary', (string) $response->getContent());
        self::assertStringNotContainsString('private-glossary-text', (string) $response->getContent());

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeQuery')->with('SELECT 1');
        $health = (new HealthController($connection, $config))();
        self::assertSame(200, $health->getStatusCode());
        self::assertSame(['status' => 'ok'], json_decode((string) $health->getContent(), true)['checks']['configuration']);
    }

    public static function invalidGlossaries(): iterable
    {
        yield 'null' => [null];
        yield 'not mapping' => ['private-glossary-text'];
        yield 'invalid locale' => [['locales' => ['es_MX']]];
        yield 'invalid dimension' => [['values' => ['private-glossary-text' => ['code' => ['label' => 'Private']]]]];
        yield 'invalid text' => [['values' => ['device_class' => ['tablet' => ['label' => "private-glossary-text\0"]]]]];
    }
}
