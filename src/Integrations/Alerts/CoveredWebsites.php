<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\AlertRule\AlertRule;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;

/**
 * The registered websites a rule's covers value resolves to, read from
 * config/websites.yaml and the website groups.
 *
 * @phpstan-import-type RuleArray from AlertRule
 *
 * @phpstan-type CoveredWebsite array{token: string, name: string, domain: string}
 */
final class CoveredWebsites
{
    public function __construct(
        private readonly WebsiteConfigManager $websites,
        private readonly WebsiteScopeCatalog $scopes,
    ) {
    }

    /**
     * @param RuleArray $rule
     *
     * @return list<CoveredWebsite>
     */
    public function of(array $rule): array
    {
        $covers = $rule['covers'];
        $allSites = [];
        foreach ($this->websites->getWebsites() as $site) {
            $token = (string) ($site['token'] ?? '');
            if ($token !== '') {
                $allSites[$token] = self::describe($token, $site);
            }
        }

        if ($covers === 'all') {
            return array_values($allSites);
        }
        if (str_starts_with($covers, 'group:')) {
            $members = $this->scopes->groups()[substr($covers, 6)]['members'] ?? [];
            $resolved = [];
            foreach ($members as $token) {
                if (isset($allSites[$token])) {
                    $resolved[] = $allSites[$token];
                }
            }

            return $resolved;
        }
        $token = str_starts_with($covers, 'website:') ? substr($covers, 8) : $covers;

        return isset($allSites[$token]) ? [$allSites[$token]] : [];
    }

    /**
     * A registered website by token, or null when it is not in config/websites.yaml.
     *
     * @return CoveredWebsite|null
     */
    public function website(string $token): ?array
    {
        $site = $this->websites->findOneByToken($token);

        return $site === null ? null : self::describe($token, $site);
    }

    /**
     * What a rule covers, for people: "All websites (3)", "Group: EU (2 websites)",
     * or a website's name and domain.
     *
     * @param RuleArray $rule
     */
    public function describeCovers(array $rule): string
    {
        $covers = $rule['covers'];
        if ($covers === 'all') {
            return sprintf('All websites (%d)', count($this->of($rule)));
        }
        if (str_starts_with($covers, 'group:')) {
            $groupId = substr($covers, 6);
            $name = $this->scopes->groupName($groupId) ?? $groupId;
            $count = count($this->of($rule));

            return sprintf('Group: %s (%d website%s)', $name, $count, $count === 1 ? '' : 's');
        }
        $token = str_starts_with($covers, 'website:') ? substr($covers, 8) : $covers;
        $site = $this->websites->findOneByToken($token);
        if ($site === null) {
            return $token;
        }
        $domain = self::bareHost((string) ($site['domain'] ?? ''));

        return $domain !== '' ? sprintf('%s (%s)', $site['name'], $domain) : (string) $site['name'];
    }

    public static function bareHost(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        if (str_contains($domain, '://')) {
            $host = parse_url($domain, PHP_URL_HOST);

            return is_string($host) ? strtolower($host) : '';
        }

        return strtolower(trim(explode('/', $domain, 2)[0]));
    }

    /**
     * @param array<string, mixed> $site
     *
     * @return CoveredWebsite
     */
    private static function describe(string $token, array $site): array
    {
        return [
            'token' => $token,
            'name' => (string) ($site['name'] ?? 'Unnamed'),
            'domain' => self::bareHost((string) ($site['domain'] ?? '')),
        ];
    }
}
