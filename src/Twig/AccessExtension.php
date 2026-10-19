<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Security\Access\DashboardAccessVoter;
use App\Security\Access\DashboardPages;
use App\Security\Access\UserAccessResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Permission checks for templates: links and buttons show only what the
 * signed-in user can use. Presentation only; routes enforce access.
 */
final class AccessExtension extends AbstractExtension
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly RequestStack $requests,
        private readonly TokenStorageInterface $tokens,
        private readonly UserAccessResolver $resolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('dashboard_allowed', $this->allowed(...)),
            new TwigFunction('dashboard_can_edit', $this->canEdit(...)),
            new TwigFunction('dashboard_page_access', $this->pageAccess(...)),
            new TwigFunction('dashboard_access_summary', $this->summary(...)),
        ];
    }

    /** May the user open this route (checked as a link, so view level)? */
    public function allowed(string $route): bool
    {
        return $this->authorization->isGranted(DashboardAccessVoter::ROUTE, $route);
    }

    public function canEdit(string $page): bool
    {
        return $this->authorization->isGranted(DashboardAccessVoter::PAGE_EDIT, $page);
    }

    /**
     * The current page and the user's level on it, or null outside a grantable page.
     *
     * @return array{page: string, label: string, level: string, scope: string}|null
     */
    public function pageAccess(): ?array
    {
        $route = $this->requests->getMainRequest()?->attributes->get('_route');
        if (!is_string($route) || !array_key_exists($route, DashboardPages::ROUTES)) {
            return null;
        }
        $page = DashboardPages::requirement($route)['page'];
        if ($page === null) {
            return null;
        }

        return [
            'page' => $page,
            'label' => DashboardPages::label($page),
            'level' => $this->canEdit($page) ? DashboardPages::EDIT : DashboardPages::VIEW,
            'scope' => DashboardPages::PAGES[$page]['scope'],
        ];
    }

    /**
     * A user's effective access for display (EffectiveAccess::summary()); the
     * signed-in user when none is given.
     *
     * @return array<string, mixed>|null
     */
    public function summary(?User $user = null): ?array
    {
        $user ??= $this->tokens->getToken()?->getUser();

        return $user instanceof User ? $this->resolver->forUser($user)->summary() : null;
    }
}
