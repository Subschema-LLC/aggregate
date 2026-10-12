<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * Access checks and private responses shared by the dashboard controllers.
 * The using controller extends AbstractController and has an
 * AggregateConfigLoader in $this->config.
 */
trait DashboardAccess
{
    /** Not found while the dashboard is disabled, so headless installations expose no page. */
    private function denyIfDashboardDisabled(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
    }

    private function denyIfNotAdmin(): void
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Only administrators can perform this action.');
        }
    }

    /** The checks most administration pages need, in order: dashboard enabled, then an administrator. */
    private function denyUnlessAvailableToAdmin(): void
    {
        $this->denyIfDashboardDisabled();
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }

    /** @param array<string, mixed> $parameters */
    private function renderDashboardPage(string $template, array $parameters = []): Response
    {
        return $this->render($template, $parameters, new Response(headers: [
            'Cache-Control' => 'private, no-store, max-age=0',
        ]));
    }
}
