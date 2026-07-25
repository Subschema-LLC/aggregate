<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reduces ingestion data before it can reach Messenger or an analytics table.
 *
 * Client-side sanitization is useful for data minimization in transit, but it
 * is not a security boundary. Every value used by the backend is normalized
 * again here.
 */
final class PrivacySanitizer
{
    private const REFERRER_CHANNELS = [
        'direct',
        'internal',
        'search',
        'social',
        'email',
        'referral',
        'unknown',
    ];

    private const DEVICE_CLASSES = ['mobile', 'tablet', 'desktop', 'bot', 'unknown'];
    private const VIEWPORT_BUCKETS = ['small', 'medium', 'large', 'unknown'];

    public function sanitizePagePath(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim(self::validUtf8($value));
        if ($value === '') {
            return null;
        }

        // Absolute URLs are supported for older SDKs, but host, query and
        // fragment data are deliberately discarded.
        if (preg_match('#^https?://#i', $value) === 1 || str_starts_with($value, '//')) {
            $path = parse_url(str_starts_with($value, '//') ? 'https:'.$value : $value, PHP_URL_PATH);
            if (!is_string($path)) {
                return null;
            }
        } else {
            $path = preg_split('/[?#]/', $value, 2)[0] ?? '';
        }

        // Decode twice before splitting so encoded separators and dot segments
        // cannot hide a sensitive route from server-side exclusions.
        for ($pass = 0; $pass < 2; ++$pass) {
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                break;
            }
            $path = $decoded;
        }
        $path = str_replace('\\', '/', $this->stripControlCharacters(self::validUtf8($path)));
        if ($path === '') {
            $path = '/';
        }
        if (!str_starts_with($path, '/')) {
            return null;
        }

        $canonicalSegments = [];
        foreach (explode('/', $path) as $segment) {
            // Matrix parameters can contain the same identifiers as a query.
            $segment = explode(';', $segment, 2)[0];
            $segment = $this->stripControlCharacters(self::validUtf8($segment));
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($canonicalSegments);
                continue;
            }

            // Residual percent escapes after two decode passes are ambiguous
            // and could conceal another identifier or path separator.
            $stillEncoded = preg_match('/%[0-9A-Fa-f]{2}/', $segment) === 1;

            $canonicalSegments[] = $stillEncoded || self::looksLikeIdentifier($segment)
                ? '_redacted'
                : rawurlencode($segment);
        }

        $path = '/'.implode('/', $canonicalSegments);
        $path = substr($path, 0, 512);

        // Do not leave a truncated percent escape at the end of the dimension.
        return preg_replace('/%(?:[0-9A-F])?$/D', '', $path) ?? '/';
    }

    public function sanitizeReferrerChannel(
        mixed $channel,
        mixed $legacyReferrer,
        string $websiteDomain,
    ): string {
        if (is_string($channel)) {
            $normalized = strtolower(trim($channel));
            if (in_array($normalized, self::REFERRER_CHANNELS, true)) {
                return $normalized;
            }
        }

        if (!is_string($legacyReferrer) || trim($legacyReferrer) === '') {
            return 'direct';
        }

        $host = parse_url(trim($legacyReferrer), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return 'unknown';
        }

        $host = $this->normalizeHost($host);
        $siteHost = $this->normalizeHost($websiteDomain);
        if ($host === $siteHost || str_ends_with($host, '.'.$siteHost)) {
            return 'internal';
        }

        if (str_starts_with($host, 'google.')
            || str_starts_with($host, 'yandex.')
            || $this->hostMatches($host, [
            'google.com', 'google.co.uk', 'bing.com', 'duckduckgo.com',
            'search.yahoo.com', 'baidu.com', 'yandex.com', 'ecosia.org',
            'search.brave.com',
            ])) {
            return 'search';
        }

        if ($this->hostMatches($host, [
            'facebook.com', 'instagram.com', 'linkedin.com', 'x.com',
            'twitter.com', 't.co', 'reddit.com', 'pinterest.com',
            'youtube.com', 'youtu.be', 'tiktok.com', 'mastodon.social',
        ])) {
            return 'social';
        }

        if ($this->hostMatches($host, [
            'mail.google.com', 'outlook.live.com', 'outlook.office.com',
            'mail.yahoo.com', 'proton.me', 'protonmail.com',
        ])) {
            return 'email';
        }

        return 'referral';
    }

    public function sanitizeDeviceClass(mixed $value, string $userAgent): string
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, self::DEVICE_CLASSES, true)) {
                return $normalized;
            }
        }

        return $this->deviceClassFromUserAgent($userAgent);
    }

    public function sanitizeViewportBucket(mixed $value, mixed $screenWidth = null): string
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, self::VIEWPORT_BUCKETS, true)) {
                return $normalized;
            }
        }

        $width = $this->sanitizeScreenWidth($screenWidth);
        if ($width === null || $width === 0) {
            return 'unknown';
        }
        if ($width < 640) {
            return 'small';
        }
        if ($width < 1024) {
            return 'medium';
        }

        return 'large';
    }

    public function generalizeUserAgent(string $userAgent): string
    {
        $ua = strtolower($this->truncateUtf8($userAgent, 2048));
        $deviceClass = $this->deviceClassFromUserAgent($ua);

        $browser = match (true) {
            str_contains($ua, 'edg/'), str_contains($ua, 'edge/') => 'Edge',
            str_contains($ua, 'opr/'), str_contains($ua, 'opera') => 'Opera',
            str_contains($ua, 'firefox/'), str_contains($ua, 'fxios/') => 'Firefox',
            str_contains($ua, 'chrome/'), str_contains($ua, 'crios/') => 'Chrome',
            str_contains($ua, 'safari/') => 'Safari',
            default => 'Other',
        };

        return sprintf('%s / %s', $browser, $deviceClass);
    }

    public function sanitizeScreenWidth(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }

        $width = (int) $value;

        return $width >= 0 && $width <= 20_000 ? $width : null;
    }

    public function sanitizeEventName(mixed $value, string $default = 'view'): ?string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim(self::validUtf8($value));
        if ($value === '') {
            return $default;
        }

        // Event names are BI dimensions, not arbitrary user-provided text.
        // Keeping a compact ASCII vocabulary prevents names from becoming a
        // covert identifier in otherwise anonymous rows.
        if (!self::isSafeEventName($value)) {
            return null;
        }

        return $value;
    }

    public static function isSafeEventName(string $value): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,99}$/D', $value) === 1
            && !self::looksLikeIdentifier($value);
    }

    public function sanitizeGoalEvent(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $goal = $this->sanitizeEventName($value, '');

        return $goal === '' ? null : $goal;
    }

    public function sanitizeIdentifier(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $value) === 1 ? $value : null;
    }

    public function sanitizeOptionalString(mixed $value, int $maxLength): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim($this->stripControlCharacters(self::validUtf8((string) $value)));
        if ($value === '') {
            return null;
        }

        return $this->truncateUtf8($value, $maxLength);
    }

    public function sanitizeEventData(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            if (count($clean) >= 50) {
                break;
            }
            if (!is_string($key)) {
                continue;
            }

            $key = trim($key);
            if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $key) !== 1) {
                continue;
            }
            if (!is_scalar($item) && $item !== null) {
                continue;
            }

            $clean[$key] = is_string($item)
                ? $this->truncateUtf8($this->stripControlCharacters(self::validUtf8($item)), 500)
                : $item;
        }

        return $clean !== [] ? $clean : null;
    }

    private function deviceClassFromUserAgent(string $userAgent): string
    {
        $ua = strtolower($userAgent);

        if (preg_match('/bot|crawler|spider|slurp|headless/i', $ua) === 1) {
            return 'bot';
        }
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet') || (str_contains($ua, 'android') && !str_contains($ua, 'mobile'))) {
            return 'tablet';
        }
        if (str_contains($ua, 'mobile') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) {
            return 'mobile';
        }
        if ($ua === '') {
            return 'unknown';
        }

        return 'desktop';
    }

    private static function looksLikeIdentifier(string $segment): bool
    {
        $segment = trim(self::validUtf8($segment));
        if ($segment === '') {
            return false;
        }

        return str_contains($segment, '@')
            || preg_match('/^[0-9]+$/D', $segment) === 1
            || preg_match('/(?:^|[^a-f0-9])[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[89ab][a-f0-9]{3}-[a-f0-9]{12}(?:$|[^a-f0-9])/Di', $segment) === 1
            || preg_match('/(?:^|[-_])[0-9]{4,}(?:$|[-_])/D', $segment) === 1
            || preg_match('/^[a-f0-9]{16,}$/Di', $segment) === 1
            || (strlen($segment) >= 24
                && preg_match('/[a-z]/i', $segment) === 1
                && preg_match('/[0-9]/', $segment) === 1);
    }

    private function hostMatches(string $host, array $knownHosts): bool
    {
        foreach ($knownHosts as $knownHost) {
            if ($host === $knownHost || str_ends_with($host, '.'.$knownHost)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B."));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function stripControlCharacters(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    }

    private function truncateUtf8(string $value, int $maxBytes): string
    {
        return self::validUtf8(substr($value, 0, max(0, $maxBytes)));
    }

    private static function validUtf8(string $value): string
    {
        $clean = iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return $clean === false ? '' : $clean;
    }
}
