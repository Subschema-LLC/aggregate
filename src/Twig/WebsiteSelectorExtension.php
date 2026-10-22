<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Security\Access\WebsiteAccess;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class WebsiteSelectorExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly ?WebsiteConfigManager $websiteManager = null,
        private readonly ?WebsiteScopeCatalog $scopes = null,
        private readonly ?WebsiteAccess $websiteAccess = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('global_website_selector_options', [$this, 'options']),
        ];
    }

    /**
     * @return array{
     *     groups: list<array{id: string, name: string, value: string, members: list<string>}>,
     *     websites: list<array{token: string, id: string, name: string, domain: string, group_id: ?string, group_name: ?string, value: string}>,
     *     group_label: string
     * }|null
     */
    public function options(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || $this->websiteManager === null) {
            return null;
        }

        $allWebsites = $this->websiteManager->getWebsites();
        $visibleWebsites = $this->websiteAccess !== null ? $this->websiteAccess->filter($allWebsites) : $allWebsites;
        if ($visibleWebsites === []) {
            return null;
        }

        $label = ($this->scopes !== null && !$this->scopes->hasLoadError())
            ? $this->scopes->label()
            : ['singular' => WebsiteScopeCatalog::DEFAULT_SINGULAR, 'plural' => WebsiteScopeCatalog::DEFAULT_PLURAL];

        $groups = [];
        if ($this->scopes !== null && !$this->scopes->hasLoadError()) {
            foreach ($this->scopes->groups() as $id => $group) {
                $isAllowed = $this->websiteAccess === null
                    || $this->websiteAccess->allowsGroup($id)
                    || count(array_filter($group['members'], fn (string $t): bool => $this->websiteAccess->allowsToken($t))) > 0;

                if (!$isAllowed) {
                    continue;
                }

                $groups[] = [
                    'id' => $id,
                    'name' => $group['name'],
                    'value' => 'group:' . $id,
                    'members' => $group['members'],
                ];
            }
        }

        $websites = [];
        foreach ($visibleWebsites as $site) {
            $token = (string) ($site['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $siteId = WebsiteConfigManager::idForToken($token);
            $groupId = ($this->scopes !== null && !$this->scopes->hasLoadError())
                ? $this->scopes->groupIdForToken($token)
                : null;
            $groupName = $groupId !== null ? $this->scopes->groupName($groupId) : null;

            $websites[] = [
                'token' => $token,
                'id' => $siteId,
                'name' => (string) ($site['name'] ?? 'Unnamed'),
                'domain' => (string) ($site['domain'] ?? ''),
                'group_id' => $groupId,
                'group_name' => $groupName,
                'value' => 'website:' . $siteId,
            ];
        }

        return [
            'groups' => $groups,
            'websites' => $websites,
            'group_label' => $label['plural'],
        ];
    }
}
