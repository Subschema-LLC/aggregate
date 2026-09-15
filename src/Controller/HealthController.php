<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
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
        } catch (\Throwable) {
            $checks['database'] = ['status' => 'error', 'message' => 'Database check failed.'];
            $allHealthy = false;
        }

        try {
            // The tracker and ingestion also depend on a valid custom-data
            // model, even when an event submits no custom properties.
            (new CustomDataSettings($this->config))->toArray();
            $checks['configuration'] = ['status' => 'ok'];
        } catch (\Throwable) {
            $checks['configuration'] = [
                'status' => 'error',
                'message' => 'Application configuration is invalid.',
            ];
            $allHealthy = false;
        }

        // Check messenger transport configuration
        $transportDsn = $_ENV['MESSENGER_TRANSPORT_DSN'] ?? $_SERVER['MESSENGER_TRANSPORT_DSN'] ?? '';
        if (empty($transportDsn)) {
            $checks['messenger'] = [
                'status' => 'warning',
                'message' => 'MESSENGER_TRANSPORT_DSN not configured in .env/.env.local'
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
