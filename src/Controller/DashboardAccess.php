<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\Access\DashboardAccessVoter;
use App\Security\Access\WebsiteAccess;
use App\Service\WebsiteScopeEditor;
use Symfony\Component\HttpFoundation\Response;

/**
 * Access checks and private responses shared by the dashboard controllers.
 * The using controller extends AbstractController and has an
 * AggregateConfigLoader in $this->config.
 *
 * Website pages list and change only the websites the signed-in user can
 * use: visibleWebsites() filters a list, and the canUse*() checks guard a
 * website or scope taken from the request.
 */
trait DashboardAccess
{
    public static function getSubscribedServices(): array
    {
        return [...parent::getSubscribedServices(), WebsiteAccess::class => '?'.WebsiteAccess::class];
    }

    /** Not found while the dashboard is disabled, so headless installations expose no page. */
    private function denyIfDashboardDisabled(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
    }

    /** For users and permission groups, which only administrators manage. */
    private function denyIfNotAdmin(): void
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Only administrators can perform this action.');
        }
    }

    /**
     * The checks every dashboard page needs, in order: dashboard enabled, then
     * the page and level App\Security\Access\DashboardPages lists for the
     * current route and method. DashboardAccessSubscriber makes the same check
     * before any controller runs; this keeps it when a controller is used directly.
     */
    private function denyUnlessPermitted(): void
    {
        $this->denyIfDashboardDisabled();
        $request = $this->container->has('request_stack') ? $this->container->get('request_stack')->getCurrentRequest() : null;
        $this->denyAccessUnlessGranted(DashboardAccessVoter::ROUTE, $request, 'You do not have access to this page. Ask an administrator if you need it.');
    }

    /** May the user edit this page (not only view it)? */
    private function canEditPage(string $page): bool
    {
        return $this->isGranted(DashboardAccessVoter::PAGE_EDIT, $page);
    }

    /**
     * The websites in a list the user can use, in order.
     *
     * @template T of array
     *
     * @param list<T> $websites each with a 'token'
     *
     * @return list<T>
     */
    private function visibleWebsites(array $websites): array
    {
        $access = $this->websiteAccess();
        if ($access !== null) {
            return $access->filter($websites);
        }

        return $this->canUseEveryWebsite() ? $websites : [];
    }

    /** Every website, every group, and the settings shared by all of them. */
    private function canUseEveryWebsite(): bool
    {
        $access = $this->websiteAccess();

        return $access !== null ? $access->allowsEveryWebsite() : $this->isGranted(DashboardAccessVoter::EVERY_WEBSITE);
    }

    private function canUseWebsiteToken(string $token): bool
    {
        $access = $this->websiteAccess();

        return $access !== null ? $access->allowsToken($token) : $this->canUseEveryWebsite();
    }

    /** A website by its 24-character script id. */
    private function canUseSiteId(string $siteId): bool
    {
        $access = $this->websiteAccess();

        return $access !== null ? $access->allowsSiteId($siteId) : $this->canUseEveryWebsite();
    }

    /** A website scope value: deployment, group:<id> or website:<site id>. */
    private function canUseScope(string $scope): bool
    {
        if ($scope === '' || $scope === 'deployment') {
            return $this->canUseEveryWebsite();
        }
        if (str_starts_with($scope, 'group:')) {
            $access = $this->websiteAccess();

            return $access !== null ? $access->allowsGroup(substr($scope, 6)) : $this->canUseEveryWebsite();
        }

        return str_starts_with($scope, 'website:') && $this->canUseSiteId(substr($scope, 8));
    }

    /**
     * A scope selection (WebsiteScopeEditor::selection()) limited to what the
     * user can use: its choices are filtered, a user without every website
     * starts on their first group or website when none is chosen, and a scope
     * they cannot use, including the deployment, is not found.
     *
     * @return array<string, mixed>
     */
    private function permittedScopeSelection(WebsiteScopeEditor $scopes, mixed $raw, string $route): array
    {
        $raw = is_string($raw) ? $raw : null;
        if (($raw === null || $raw === '') && !$this->canUseEveryWebsite()) {
            $first = null;
            foreach ($scopes->selection(null, $route)['choices'] as $choice) {
                if ($this->canUseScope($choice['value'])) {
                    $first = $choice['value'];
                    break;
                }
            }
            if ($first === null) {
                throw $this->createAccessDeniedException('No websites are assigned to you yet. Ask an administrator for access.');
            }
            $raw = $first;
        } elseif ($raw !== null && !$this->canUseScope($raw)) {
            throw $this->createNotFoundException('That website scope is not available.');
        }
        $selection = $scopes->selection($raw, $route);
        if (!$this->canUseScope((string) $selection['value'])) {
            throw $this->createNotFoundException('That website scope is not available.');
        }
        $selection['choices'] = array_values(array_filter(
            $selection['choices'],
            fn (array $choice): bool => $this->canUseScope((string) $choice['value']),
        ));

        return $selection;
    }

    private function websiteAccess(): ?WebsiteAccess
    {
        return $this->container->has(WebsiteAccess::class) ? $this->container->get(WebsiteAccess::class) : null;
    }

    /** @param array<string, mixed> $parameters */
    private function renderDashboardPage(string $template, array $parameters = []): Response
    {
        return $this->render($template, $parameters, new Response(headers: [
            'Cache-Control' => 'private, no-store, max-age=0',
        ]));
    }
}
