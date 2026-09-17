<?php

declare(strict_types=1);

namespace App\Service;

/** Shared validation and matching for website ingestion domain restrictions. */
final class WebsiteDomainPolicy
{
    public const MAX_DOMAINS = 32;

    /** @return array{mode: 'restricted'|'all', domains: list<string>} */
    public function normalize(mixed $policy): array
    {
        if (!is_array($policy)
            || array_diff(array_keys($policy), ['mode', 'domains']) !== []
            || !in_array($policy['mode'] ?? null, ['restricted', 'all'], true)) {
            throw new \InvalidArgumentException('Domain policy must specify mode "restricted" or "all", with an optional domains list.');
        }

        $domains = $policy['domains'] ?? [];
        if ((array_key_exists('domains', $policy) && $policy['domains'] === null)
            || !is_array($domains) || !array_is_list($domains)
            || count($domains) > self::MAX_DOMAINS) {
            throw new \InvalidArgumentException('Allowed domains must be a list of at most 32 hostnames or wildcard subdomains.');
        }
        if ($policy['mode'] === 'restricted' && $domains === []) {
            throw new \InvalidArgumentException('Restricted websites require at least one allowed hostname or wildcard subdomain.');
        }

        $normalized = [];
        foreach ($domains as $domain) {
            if (!is_string($domain) || preg_match('/[\x00-\x1f\x7f]/', $domain) === 1) {
                throw new \InvalidArgumentException('Each allowed domain must be a hostname or a wildcard such as *.example.com.');
            }
            $domain = trim($domain);
            $wildcard = str_starts_with($domain, '*.');
            $host = $this->normalizeHost($wildcard ? substr($domain, 2) : $domain);
            if ($wildcard && $this->isIpAddress($host)) {
                throw new \InvalidArgumentException('Wildcards may only prefix DNS hostnames, not IP addresses.');
            }
            $normalized[] = ($wildcard ? '*.' : '').$host;
        }

        return ['mode' => $policy['mode'], 'domains' => array_values(array_unique($normalized))];
    }

    /**
     * An omitted policy retains the original registered host and its descendants.
     * An explicitly invalid policy must never fall back to this legacy behavior.
     *
     * @return array{mode: 'restricted'|'all', domains: list<string>}
     */
    public function resolve(array $website): array
    {
        if (array_key_exists('domain_policy', $website)) {
            return $this->normalize($website['domain_policy']);
        }
        if (!is_string($website['domain'] ?? null)) {
            throw new \InvalidArgumentException('A website requires a valid registered domain.');
        }

        $domain = $this->normalizePrimaryDomain($website['domain']);

        return [
            'mode' => 'restricted',
            'domains' => $this->isIpAddress($domain) ? [$domain] : [$domain, '*.'.$domain],
        ];
    }

    public function allows(array $website, ?string $origin, ?string $referer = null): bool
    {
        try {
            $policy = $this->resolve($website);
            if ($policy['mode'] === 'all') {
                return true;
            }

            // Presence matters: null/invalid/multiple Origin values cannot fall
            // back to an otherwise allowed Referer supplied by the same client.
            $host = $origin !== null
                ? $this->hostFromUrl($origin, false)
                : $this->hostFromUrl($referer ?? '', true);
            foreach ($policy['domains'] as $domain) {
                if (str_starts_with($domain, '*.')) {
                    if (str_ends_with($host, substr($domain, 1))) {
                        return true;
                    }
                } elseif ($host === $domain) {
                    return true;
                }
            }
        } catch (\InvalidArgumentException) {
            return false;
        }

        return false;
    }

    /** Accept ordinary hostnames and the root HTTP(S) URLs used by old forms. */
    public function normalizePrimaryDomain(string $domain): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $domain) === 1) {
            throw new \InvalidArgumentException('A registered domain may not contain control characters.');
        }
        $domain = trim($domain);
        if (preg_match('~\Ahttps?://~i', $domain) === 1) {
            return $this->hostFromUrl($domain, false, true);
        }

        return $this->normalizeHost(str_ends_with($domain, '/') ? substr($domain, 0, -1) : $domain);
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException('Bracketed hosts must be valid IPv6 addresses.');
            }
        }
        if ($this->isIpAddress($host)) {
            return (string) inet_ntop((string) inet_pton($host));
        }
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        $labels = explode('.', $host);
        if ($host === '' || strlen($host) > 253
            || preg_match('/\A[a-z0-9.-]+\z/D', $host) !== 1
            || preg_match('/[a-z]/', $labels[array_key_last($labels)]) !== 1) {
            throw new \InvalidArgumentException('Use a valid ASCII hostname (punycode for international domains) or an exact IP address, without a protocol, port, or path.');
        }
        foreach ($labels as $label) {
            if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $label) !== 1) {
                throw new \InvalidArgumentException('Hostname labels must contain 1–63 letters, digits, or hyphens and cannot start or end with a hyphen.');
            }
        }

        return $host;
    }

    private function isIpAddress(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private function hostFromUrl(string $url, bool $allowPath, bool $allowRootPath = false): string
    {
        if ($url === '' || strlen($url) > 8192
            || preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $url) === 1
            || preg_match('~\Ahttps?://([^/?#]+)(.*)\z~iD', $url, $parts) !== 1) {
            throw new \InvalidArgumentException('The request must supply one valid HTTP(S) origin or referrer.');
        }

        if (!$allowPath && $parts[2] !== '' && !($allowRootPath && $parts[2] === '/')) {
            throw new \InvalidArgumentException('An origin may not include a path, query, or fragment.');
        }
        if (preg_match('/\A(\[[a-f0-9:.]+\]|[^:@\[\]]+)(?::([0-9]{1,5}))?\z/iD', $parts[1], $authority) !== 1
            || (isset($authority[2]) && (int) $authority[2] > 65535)) {
            throw new \InvalidArgumentException('The origin or referrer authority is invalid.');
        }

        return $this->normalizeHost($authority[1]);
    }
}
