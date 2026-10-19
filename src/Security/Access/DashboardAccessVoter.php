<?php

declare(strict_types=1);

namespace App\Security\Access;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Dashboard permissions:
 *
 * - ROUTE: may the user use this route? The subject is a Request (its route
 *   and method decide view or edit) or a route name (checked as a GET, for
 *   links). Unlisted routes are administrator-only.
 * - PAGE_VIEW / PAGE_EDIT: the subject is a page from DashboardPages.
 * - EVERY_WEBSITE: access to every website and deployment-wide settings.
 *
 * Administrators are granted everything.
 *
 * @extends Voter<string, mixed>
 */
final class DashboardAccessVoter extends Voter
{
    public const ROUTE = 'DASHBOARD_ROUTE';
    public const PAGE_VIEW = 'DASHBOARD_PAGE_VIEW';
    public const PAGE_EDIT = 'DASHBOARD_PAGE_EDIT';
    public const EVERY_WEBSITE = 'DASHBOARD_EVERY_WEBSITE';

    public function __construct(private readonly UserAccessResolver $resolver)
    {
    }

    public function supportsAttribute(string $attribute): bool
    {
        return in_array($attribute, [self::ROUTE, self::PAGE_VIEW, self::PAGE_EDIT, self::EVERY_WEBSITE], true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->supportsAttribute($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $access = $this->resolver->forUser($user);
        if ($access->isAdmin()) {
            return true;
        }

        return match ($attribute) {
            self::PAGE_VIEW => is_string($subject) && $access->allows($subject, DashboardPages::VIEW),
            self::PAGE_EDIT => is_string($subject) && $access->allows($subject, DashboardPages::EDIT),
            self::EVERY_WEBSITE => $access->allowsEveryWebsite(),
            default => $this->allowsRoute($access, $subject),
        };
    }

    private function allowsRoute(EffectiveAccess $access, mixed $subject): bool
    {
        if ($subject instanceof Request) {
            $route = $subject->attributes->get('_route');
            $method = $subject->getMethod();
        } else {
            $route = $subject;
            $method = 'GET';
        }
        if (!is_string($route) || $route === '') {
            return false;
        }

        return self::satisfies($access, DashboardPages::requirement($route, $method));
    }

    /** @param array{kind: string, page: ?string, level: string, every_website: bool} $requirement */
    public static function satisfies(EffectiveAccess $access, array $requirement): bool
    {
        if ($access->isAdmin()) {
            return true;
        }

        return match ($requirement['kind']) {
            DashboardPages::SIGNED_IN => true,
            DashboardPages::ADMIN => false,
            default => $requirement['page'] !== null
                && $access->allows($requirement['page'], $requirement['level'])
                && (!$requirement['every_website'] || $access->allowsEveryWebsite()),
        };
    }
}
