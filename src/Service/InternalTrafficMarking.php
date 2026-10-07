<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Marking links: how a team member's browser gets the organization-traffic
 * marker on each tracked website.
 *
 * A browser keeps every website's cookies and local storage separate, so the
 * analytics host cannot write the marker for another website. The
 * Organization traffic pages therefore link to each website with a
 * short-lived code in the address fragment (#aggregate-org-traffic=<code>).
 * The tracker on that website asks this server whether the code is valid
 * (verify()), writes or removes its configured marker in the website's own
 * cookie or local storage, and returns the browser to the continue page.
 *
 * The code is signed with the application secret and the current share
 * token, so rotating or revoking the share link stops outstanding codes, and
 * a link passed around by someone else cannot mark visitors' browsers once
 * it expires. It carries no identifier: every team member gets the same
 * marker.
 */
final class InternalTrafficMarking
{
    public const FRAGMENT_KEY = 'aggregate-org-traffic';
    public const LIFETIME_SECONDS = 1800;
    public const ACTIONS = ['mark', 'remove'];

    public function __construct(
        private readonly InternalTrafficSettings $settings,
        private readonly WebsiteConfigManager $websites,
        private readonly AggregateConfigLoader $config,
        private readonly string $secret,
    ) {
    }

    /** A code for one action that stays valid for LIFETIME_SECONDS. */
    public function code(string $action, ?int $now = null): string
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Marking codes are for mark or remove.');
        }
        $expires = ($now ?? time()) + self::LIFETIME_SECONDS;

        return 'v1.'.$expires.'.'.$action.'.'.$this->signature($expires, $action);
    }

    /** The action a valid, unexpired code allows, or null. */
    public function verify(mixed $code, ?int $now = null): ?string
    {
        if (!is_string($code) || preg_match('/^v1\.([0-9]{1,12})\.(mark|remove)\.([A-Za-z0-9_-]{43})$/D', $code, $parts) !== 1) {
            return null;
        }
        $expires = (int) $parts[1];
        if ($expires < ($now ?? time()) || $expires > ($now ?? time()) + self::LIFETIME_SECONDS) {
            return null;
        }
        try {
            $expected = $this->signature($expires, $parts[2]);
        } catch (\Throwable) {
            return null;
        }

        return hash_equals($expected, $parts[3]) ? $parts[2] : null;
    }

    /**
     * What the Organization traffic pages show: the links and when they expire.
     *
     * @return array{strict: bool, expires: int, websites: list<array{name: string, domain: string, mark_url: string, remove_url: string}>}
     */
    public function page(?int $now = null): array
    {
        $now ??= time();

        return [
            'strict' => (new CollectionProfile($this->config))->isStrict(),
            'expires' => $now + self::LIFETIME_SECONDS,
            'websites' => $this->websites($now),
        ];
    }

    /**
     * The tracked websites with their marking and removal links.
     *
     * @return list<array{name: string, domain: string, mark_url: string, remove_url: string}>
     */
    public function websites(?int $now = null): array
    {
        $mark = $this->code('mark', $now);
        $remove = $this->code('remove', $now);
        $links = [];
        foreach ($this->websites->getWebsites() as $website) {
            $base = self::homeUrl((string) ($website['domain'] ?? ''));
            if ($base === null) {
                continue;
            }
            $links[] = [
                'name' => (string) ($website['name'] ?? ''),
                'domain' => (string) $website['domain'],
                'mark_url' => $base.'#'.self::FRAGMENT_KEY.'='.$mark,
                'remove_url' => $base.'#'.self::FRAGMENT_KEY.'='.$remove,
            ];
        }

        return $links;
    }

    /**
     * The cookie domain for the marker the tracker writes on a website: the
     * operator's cookie domain when it contains the page, otherwise the
     * website's configured domain when the page is on it (covering its
     * subdomains), otherwise none (a host-only cookie). Local storage always
     * belongs to the page's exact origin, so it has no domain.
     */
    public function cookieDomainFor(?string $origin, ?array $website): string
    {
        $marker = $this->settings->toBrowserConfig();
        $host = is_string($origin) ? strtolower((string) parse_url($origin, PHP_URL_HOST)) : '';
        if ($marker['storage'] !== 'cookie' || $host === '' || str_starts_with($marker['name'], '__Host-')) {
            return '';
        }
        foreach ([ltrim($marker['cookieDomain'], '.'), strtolower((string) ($website['domain'] ?? ''))] as $candidate) {
            if ($candidate !== '' && preg_match('/^[a-z0-9.-]+$/D', $candidate) === 1 && str_contains($candidate, '.')
                && ($host === $candidate || str_ends_with($host, '.'.$candidate))) {
                return $candidate;
            }
        }

        return '';
    }

    /** Where the tracker sends the browser after marking: this server's continue page. */
    public function continueUrl(string $fallback): string
    {
        $host = DropInScripts::normalizeAppHost($this->config->getWithEnvFallback('app_host', ''));

        return $host !== null ? $host.'/internal-traffic/continue' : $fallback;
    }

    /** A website's home page: https, or http for a local development host. */
    public static function homeUrl(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/D', $domain) !== 1) {
            return null;
        }
        $host = explode(':', $domain)[0];
        $local = $host === 'localhost' || str_ends_with($host, '.localhost') || $host === '127.0.0.1';

        return ($local ? 'http://' : 'https://').$domain.'/';
    }

    private function signature(int $expires, string $action): string
    {
        $key = hash_hmac('sha256', 'aggregate-org-traffic-marking|'.$this->settings->getShareToken(), $this->secret, true);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'v1|'.$expires.'|'.$action, $key, true)), '+/', '-_'), '=');
    }
}
