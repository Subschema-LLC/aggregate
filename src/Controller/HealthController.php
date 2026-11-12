<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\Cluster\ClusterGate;
use App\Service\Cluster\ClusterSettings;
use App\Service\Cluster\ClusterSignature;
use App\Service\Cluster\ClusterState;
use App\Service\InstalledRelease;
use App\Service\CustomDataSettings;
use App\Service\GoalDefinitions;
use App\Service\WebsiteScopeCatalog;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HealthController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AggregateConfigLoader $config,
        private readonly ?WebsiteScopeCatalog $scopes = null,
        private readonly ?ClusterGate $cluster = null,
        private readonly ?ClusterState $clusterState = null,
        private readonly ?InstalledRelease $release = null,
        private readonly ?GoalDefinitions $goals = null,
    ) {}

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(?Request $request = null): Response
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
            $this->goals?->all();
            if ($this->scopes?->hasInvalidOverlay()) {
                throw new \RuntimeException('Website or group configuration is invalid.');
            }
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

        // A paused cluster server answers 503, so the balancer takes it out
        // of rotation until its configuration is current again.
        $paused = $this->cluster?->pauseReason();
        if ($paused !== null) {
            $checks['cluster'] = ['status' => 'error', 'message' => 'Collection is paused: the configuration may be out of date.'];
            $allHealthy = false;
        }

        $statusCode = $allHealthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;
        $body = [
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'checks' => $checks,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        // The release and configuration version, and why a server paused, only
        // for the cluster's own servers: the anonymous response stays as it was.
        if ($request !== null && $this->cluster?->inCluster() && ClusterSignature::verify((new ClusterSettings($this->config))->key(), $request, time()) !== null) {
            $state = $this->clusterState?->read();
            try {
                $release = $this->release?->read()['version'] ?? null;
            } catch (\RuntimeException) {
                $release = null;
            }
            $body['cluster'] = [
                'release' => is_string($release) ? $release : null,
                'configuration_version' => $state['version'] ?? null,
                'verified_at' => isset($state['verified_at']) ? gmdate(\DATE_ATOM, $state['verified_at']) : null,
                'paused' => $paused,
                'problem' => $state['problem'] ?? null,
            ];
        }

        return new JsonResponse($body, $statusCode);
    }
}
