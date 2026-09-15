<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\HealthController;
use App\Service\AggregateConfigLoader;
use App\Service\InternalTrafficSettings;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class HealthControllerConfigTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-health-config-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(InternalTrafficSettings::DEFAULTS), 'anonymous_tracking_enabled', 'anonymous_excluded_paths'] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        @unlink($this->projectDir.'/config/aggregate.yaml');
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);
    }

    public function testMalformedConfigMakesHealthCheckFailWithoutParserDetails(): void
    {
        file_put_contents(
            $this->projectDir.'/config/aggregate.yaml',
            "anonymous_tracking_enabled: [\n",
        );
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeQuery')->with('SELECT 1');
        $controller = new HealthController(
            $connection,
            new AggregateConfigLoader($this->projectDir, 'prod'),
        );

        $response = $controller();
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('unhealthy', $payload['status']);
        self::assertSame([
            'status' => 'error',
            'message' => 'Application configuration is invalid.',
        ], $payload['checks']['configuration']);
        self::assertStringNotContainsString($this->projectDir, (string) $response->getContent());
        self::assertStringNotContainsString('ParseException', (string) $response->getContent());
    }

    #[DataProvider('invalidCustomDataModels')]
    public function testInvalidCustomDataModelMakesHealthUnavailableWithoutExposingItsValues(array $model): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($model, 5, 2));
        $config = new AggregateConfigLoader($this->projectDir, 'prod');
        // Parsing and the original ingestion controls remain valid. The model
        // must still make the endpoint report the same failure as ingestion.
        self::assertFalse($config->hasLoadError());
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeQuery')->with('SELECT 1');

        $response = (new HealthController($connection, $config))();
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('unhealthy', $payload['status']);
        self::assertSame(['status' => 'ok'], $payload['checks']['database']);
        self::assertSame([
            'status' => 'error',
            'message' => 'Application configuration is invalid.',
        ], $payload['checks']['configuration']);
        self::assertStringNotContainsString('private', (string) $response->getContent());
        self::assertStringNotContainsString($this->projectDir, (string) $response->getContent());
        self::assertStringNotContainsString('InvalidArgumentException', (string) $response->getContent());
    }

    public static function invalidCustomDataModels(): iterable
    {
        yield 'null properties' => [['custom_data_properties' => null]];
        yield 'null mappings' => [['query_parameter_mappings' => null]];
        yield 'invalid consent setting' => [['custom_data_properties' => ['private_property' => ['consent_required' => null]]]];
        yield 'mapping to undefined property' => [[
            'custom_data_properties' => ['plan' => []],
            'query_parameter_mappings' => ['private_parameter' => 'private_missing_property'],
        ]];
    }

    #[DataProvider('validCustomDataModels')]
    public function testDefaultEmptyAndValidCustomDataModelsRemainHealthy(array $model): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($model, 5, 2));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeQuery')->with('SELECT 1');

        $response = (new HealthController($connection, new AggregateConfigLoader($this->projectDir, 'prod')))();
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('healthy', $payload['status']);
        self::assertSame(['status' => 'ok'], $payload['checks']['configuration']);
    }

    public static function validCustomDataModels(): iterable
    {
        yield 'default model' => [[]];
        yield 'empty model' => [['custom_data_properties' => [], 'query_parameter_mappings' => []]];
        yield 'anonymous medium with query alias' => [[
            'custom_data_properties' => ['utm_medium' => ['consent_required' => false]],
            'query_parameter_mappings' => ['channel' => 'utm_medium'],
        ]];
    }
}
