<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\Cluster\ClusterConfigPublisher;
use App\Service\Cluster\ClusterServers;
use App\Service\Cluster\ClusterSettings;
use App\Service\Cluster\ClusterSignature;
use App\Service\Cluster\ClusterSync;
use App\Service\Cluster\ServerRole;
use App\Service\Operations\TaskTrigger;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * The configuration the other servers of a cluster pull every minute, from
 * the administration server only. A request must be signed with CLUSTER_KEY
 * by a server in the `cluster` list, from that server's address, within a
 * minute; any other request gets the same 404 as an unknown address.
 */
final class ClusterConfigController
{
    public function __construct(
        #[Autowire('%aggregate.server_role.compiled%')]
        private readonly string $role,
        private readonly AggregateConfigLoader $config,
        private readonly ClusterConfigPublisher $publisher,
        private readonly ClusterServers $servers,
        private ?ClockInterface $clock = null,
    ) {
        $this->clock ??= Clock::get();
    }

    #[Route(ClusterSync::PATH, name: 'api_cluster_config', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $now = $this->clock->now()->getTimestamp();
        $settings = new ClusterSettings($this->config);
        $name = $this->role === ServerRole::ADMINISTRATION ? ClusterSignature::verify($settings->key(), $request, $now) : null;
        $server = $name !== null ? $settings->serverAt($name, $request->getClientIp()) : null;
        if ($server === null) {
            throw new NotFoundHttpException();
        }

        $published = $this->publisher->publish(TaskTrigger::worker());
        $have = $request->query->get('have');
        $role = $request->headers->get(ClusterSync::ROLE_HEADER);
        $release = $request->headers->get(ClusterSync::RELEASE_HEADER);
        $this->servers->recordPull($server['name'], $now, $published['version'], is_string($release) ? $release : null, is_string($role) ? $role : null, $request->getClientIp());

        if (is_string($have) && hash_equals($published['version'], $have)) {
            $response = new Response('', Response::HTTP_NOT_MODIFIED);
        } else {
            $response = new JsonResponse($published['bundle']);
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
