<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\Access\DashboardAccessVoter;
use App\Service\AggregateConfigLoader;
use App\Service\ViewSync\ViewSyncDestinations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Sync menu entry: opens the destination that is on (BigQuery first), or
 * BigQuery when none is. Each destination keeps its own page, shown as a tab
 * of Sync, so the Google sign-in callback address stays the same.
 */
final class ViewSyncController extends AbstractController
{
    use DashboardAccess;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ViewSyncDestinations $destinations,
    ) {
    }

    #[Route('/dashboard/sync', name: 'app_sync', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessPermitted();

        // A destination the user may not open (the forensic database, for a
        // standard user) is never the landing page.
        return $this->redirectToRoute($this->destinations->landingRoute(fn (string $route): bool => $this->isGranted(DashboardAccessVoter::ROUTE, $route)));
    }
}
