<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\HealthController;
use App\Service\AggregateConfigLoader;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class HealthControllerConfigTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-health-config-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
    }

    protected function tearDown(): void
    {
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
}
