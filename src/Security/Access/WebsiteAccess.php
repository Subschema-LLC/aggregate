<?php

declare(strict_types=1);

namespace App\Security\Access;

use App\Entity\User;
use App\Service\WebsiteConfigManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The signed-in user's access, for controllers and templates that list or
 * change websites. Website pages filter their website and scope choices
 * through this and refuse anything outside it.
 *
 * Without an HTTP request (console commands, workers) access is unrestricted:
 * the operator running them already controls the installation. An HTTP request
 * without a signed-in user gets nothing.
 */
final class WebsiteAccess
{
    public function __construct(
        private readonly Security $security,
        private readonly UserAccessResolver $resolver,
        private readonly RequestStack $requests,
        private readonly WebsiteConfigManager $websites,
    ) {
    }

    public function current(): EffectiveAccess
    {
        if ($this->requests->getMainRequest() === null) {
            return EffectiveAccess::administrator();
        }
        $user = $this->security->getUser();

        return $user instanceof User ? $this->resolver->forUser($user) : EffectiveAccess::none();
    }

    public function allowsEveryWebsite(): bool
    {
        return $this->current()->allowsEveryWebsite();
    }

    public function allowsToken(string $token): bool
    {
        return $token !== '' && $this->current()->allowsToken($token);
    }

    /** A website by its 24-character script id; ids shared by several websites are refused. */
    public function allowsSiteId(string $siteId): bool
    {
        $access = $this->current();
        if ($access->allowsEveryWebsite()) {
            return true;
        }
        $token = $this->websites->findOneById($siteId)['token'] ?? null;

        return is_string($token) && $access->allowsToken($token);
    }

    public function allowsGroup(string $groupId): bool
    {
        return $this->current()->allowsGroup($groupId);
    }

    /**
     * The websites in a list the user can use, in the same order.
     *
     * @template T of array
     *
     * @param list<T> $websites each with a 'token'
     *
     * @return list<T>
     */
    public function filter(array $websites): array
    {
        $access = $this->current();
        if ($access->allowsEveryWebsite()) {
            return $websites;
        }

        return array_values(array_filter(
            $websites,
            static fn (array $website): bool => is_string($website['token'] ?? null) && $website['token'] !== '' && $access->allowsToken($website['token']),
        ));
    }
}
