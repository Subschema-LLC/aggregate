<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\ApplicationUpdateService;
use App\Service\FeatureFlags;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class UpdatesController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'application_updates_refresh';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ApplicationUpdateService $updates,
        private readonly FeatureFlags $features,
    ) {
    }

    #[Route('/dashboard/updates', name: 'app_updates', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAvailableToAdmin();

        return $this->render('dashboard/updates.html.twig', [
            'update_status' => $this->updates->check(),
            'update_repository' => ApplicationUpdateService::REPOSITORY,
            'update_repository_url' => ApplicationUpdateService::REPOSITORY_URL,
        ]);
    }

    #[Route('/dashboard/updates/refresh', name: 'app_updates_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();

        $submitted = $request->request->all();
        $csrfToken = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_updates');
        }

        $this->updates->check(true);

        return $this->redirectToRoute('app_updates');
    }

    private function denyUnlessAvailableToAdmin(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }

        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->features->isEnabled('updates')) {
            throw $this->createNotFoundException('Updates are disabled.');
        }
    }
}
