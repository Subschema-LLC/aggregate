<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HealthController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AggregateConfigLoader $config,
    ) {}

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): Response
    {
        $checks = [];
        $allHealthy = true;

        // Check database connectivity
        try {
            $this->connection->executeQuery('SELECT 1');
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
            $allHealthy = false;
        }

        // Check daily_salt_secret configuration
        $salt = $this->config->getWithEnvFallback('daily_salt_secret', '');
        if (empty($salt) || $salt === 'dev-salt' || strlen($salt) < 16) {
            $checks['config'] = [
                'status' => 'error',
                'message' => 'daily_salt_secret not properly configured in aggregate.yaml'
            ];
            $allHealthy = false;
        } else {
            $checks['config'] = ['status' => 'ok'];
        }

        // Check messenger transport configuration
        $transportDsn = $this->config->getWithEnvFallback('messenger_transport_dsn', '');
        if (empty($transportDsn)) {
            $checks['messenger'] = [
                'status' => 'warning',
                'message' => 'messenger_transport_dsn not configured in aggregate.yaml'
            ];
        } else {
            $checks['messenger'] = ['status' => 'ok'];
        }

        $statusCode = $allHealthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse([
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'checks' => $checks,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $statusCode);
    }
}
