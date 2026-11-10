<?php

declare(strict_types=1);

namespace App\Service\Alerts;

use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\PrivacySanitizer;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;

/**
 * Shared validation for alert rules and destinations across YAML, the Alerts
 * dashboard page, and app:alerts:* commands.
 *
 * @phpstan-type DestinationConfig array{
 *     id: string,
 *     label: string,
 *     type: 'webhook'|'email',
 *     url?: string,
 *     host?: string,
 *     recipients?: list<string>
 * }
 * @phpstan-type RuleConfig array{
 *     id: string,
 *     enabled: bool,
 *     covers: string,
 *     source: 'events'|'tracking_failures',
 *     privacy_mode: 'all'|'enhanced'|'anonymous',
 *     event_name: string,
 *     condition: 'no_events'|'fewer_than'|'more_than',
 *     threshold: int,
 *     window: 'hour'|'day'|'week',
 *     destinations: list<string>
 * }
 */
class AlertRuleValidator
{
    public const SOURCE_EVENTS = 'events';
    public const SOURCE_TRACKING_FAILURES = 'tracking_failures';
    public const SOURCES = [self::SOURCE_EVENTS, self::SOURCE_TRACKING_FAILURES];

    public const PRIVACY_ALL = 'all';
    public const PRIVACY_ENHANCED = 'enhanced';
    public const PRIVACY_ANONYMOUS = 'anonymous';
    public const PRIVACY_MODES = [self::PRIVACY_ALL, self::PRIVACY_ENHANCED, self::PRIVACY_ANONYMOUS];

    public const CONDITION_NO_EVENTS = 'no_events';
    public const CONDITION_FEWER_THAN = 'fewer_than';
    public const CONDITION_MORE_THAN = 'more_than';
    public const CONDITIONS = [self::CONDITION_NO_EVENTS, self::CONDITION_FEWER_THAN, self::CONDITION_MORE_THAN];

    public const WINDOW_HOUR = 'hour';
    public const WINDOW_DAY = 'day';
    public const WINDOW_WEEK = 'week';
    public const WINDOWS = [
        self::WINDOW_HOUR => 1,
        self::WINDOW_DAY => 24,
        self::WINDOW_WEEK => 168,
    ];

    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_EMAIL = 'email';
    public const DESTINATION_TYPES = [self::TYPE_WEBHOOK, self::TYPE_EMAIL];

    public const MAX_LABEL = 80;
    public const MAX_URL = 2000;
    public const MAX_ADDRESS = 254;
    public const MAX_RECIPIENTS = 50;
    public const MAX_THRESHOLD = 1_000_000_000;

    public function __construct(
        private readonly ?WebsiteConfigManager $websites = null,
        private readonly ?WebsiteScopeCatalog $scopes = null,
        private readonly ?AnalyticsDataLifecyclePolicy $lifecycle = null,
    ) {
    }

    /**
     * Validates a complete alerts mapping (destinations and rules).
     *
     * @param mixed $config
     *
     * @return array{destinations: array<string, DestinationConfig>, rules: array<string, RuleConfig>}
     */
    public function validateAll(mixed $config): array
    {
        if ($config === null || $config === []) {
            return ['destinations' => [], 'rules' => []];
        }
        if (!is_array($config) || array_is_list($config)) {
            throw new \InvalidArgumentException('alerts must be a mapping with destinations and rules.');
        }
        $unknownTop = array_diff(array_keys($config), ['destinations', 'rules']);
        if ($unknownTop !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown alerts setting "%s". Use destinations and rules.', (string) reset($unknownTop)));
        }

        $rawDestinations = $config['destinations'] ?? [];
        if (!is_array($rawDestinations)) {
            throw new \InvalidArgumentException('alerts.destinations must be a list or mapping of destinations.');
        }
        $destinations = [];
        foreach ($rawDestinations as $key => $rawDestination) {
            if (!is_array($rawDestination) || ($rawDestination !== [] && array_is_list($rawDestination))) {
                throw new \InvalidArgumentException('Each alert destination must be a mapping.');
            }
            if (is_string($key) && !isset($rawDestination['id'])) {
                $rawDestination['id'] = $key;
            }
            $validated = $this->validateDestination($rawDestination);
            if (isset($destinations[$validated['id']])) {
                throw new \InvalidArgumentException(sprintf('Duplicate alert destination ID "%s".', $validated['id']));
            }
            $destinations[$validated['id']] = $validated;
        }

        $rawRules = $config['rules'] ?? [];
        if (!is_array($rawRules)) {
            throw new \InvalidArgumentException('alerts.rules must be a list or mapping of rules.');
        }
        $rules = [];
        foreach ($rawRules as $key => $rawRule) {
            if (!is_array($rawRule) || ($rawRule !== [] && array_is_list($rawRule))) {
                throw new \InvalidArgumentException('Each alert rule must be a mapping.');
            }
            if (is_string($key) && !isset($rawRule['id']) && !isset($rawRule['rule'])) {
                $rawRule['id'] = $key;
            }
            $validated = $this->validateRule($rawRule, array_keys($destinations));
            if (isset($rules[$validated['id']])) {
                throw new \InvalidArgumentException(sprintf('Duplicate alert rule ID "%s".', $validated['id']));
            }
            $rules[$validated['id']] = $validated;
        }

        return ['destinations' => $destinations, 'rules' => $rules];
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @return DestinationConfig
     */
    public function validateDestination(array $candidate): array
    {
        $allowedKeys = ['id', 'label', 'type', 'url', 'recipients', 'emails', 'addresses'];
        $unknown = array_diff(array_keys($candidate), $allowedKeys);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown alert destination field "%s".', (string) reset($unknown)));
        }

        $label = $candidate['label'] ?? null;
        if (!is_string($label) || trim($label) === '' || mb_strlen(trim($label)) > self::MAX_LABEL || preg_match('/[\x00-\x1F\x7F]/u', $label) === 1 || !mb_check_encoding($label, 'UTF-8')) {
            throw new \InvalidArgumentException(sprintf('A destination label is required and must be at most %d characters on one line.', self::MAX_LABEL));
        }
        $label = trim($label);

        $id = $candidate['id'] ?? self::slugify($label);
        if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', trim($id)) !== 1) {
            throw new \InvalidArgumentException('A destination ID must be 1 to 63 lowercase letters, numbers, hyphens or underscores.');
        }
        $id = trim($id);

        $type = $candidate['type'] ?? null;
        $type = is_string($type) ? strtolower(trim($type)) : null;
        if (!in_array($type, self::DESTINATION_TYPES, true)) {
            throw new \InvalidArgumentException('Destination type must be webhook or email.');
        }

        if ($type === self::TYPE_WEBHOOK) {
            if (isset($candidate['recipients']) && $candidate['recipients'] !== [] && $candidate['recipients'] !== '') {
                throw new \InvalidArgumentException('A webhook destination cannot list email recipients.');
            }
            $url = $this->validateWebhookUrl($candidate['url'] ?? null);
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            return [
                'id' => $id,
                'label' => $label,
                'type' => self::TYPE_WEBHOOK,
                'url' => $url,
                'host' => $host,
            ];
        }

        if (isset($candidate['url']) && trim((string) $candidate['url']) !== '') {
            throw new \InvalidArgumentException('An email destination cannot have a webhook URL.');
        }
        $rawRecipients = $candidate['recipients'] ?? $candidate['emails'] ?? $candidate['addresses'] ?? [];
        $recipients = $this->validateRecipients($rawRecipients);

        return [
            'id' => $id,
            'label' => $label,
            'type' => self::TYPE_EMAIL,
            'recipients' => $recipients,
        ];
    }

    /**
     * Validates a webhook URL: must be HTTPS, no credentials or fragment, and
     * must not target localhost, private, loopback, link-local or reserved addresses.
     */
    public function validateWebhookUrl(mixed $url): string
    {
        if (!is_string($url)) {
            throw new \InvalidArgumentException('Webhook URL must be an HTTPS web address.');
        }
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_URL || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new \InvalidArgumentException(sprintf('Webhook URL must be a valid HTTPS address of at most %d characters.', self::MAX_URL));
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Webhook URL must be a full HTTPS web address.');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw new \InvalidArgumentException('Webhook URLs must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Webhook URLs cannot contain a user name or password.');
        }
        if (isset($parts['fragment']) || str_contains($url, '#')) {
            throw new \InvalidArgumentException('Webhook URLs cannot contain a fragment (#).');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '' || self::isPrivateOrLocalHost($host)) {
            throw new \InvalidArgumentException('Webhook URLs cannot target localhost, loopback, link-local, or private network addresses.');
        }

        return $url;
    }

    public static function isPrivateOrLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B.[]"));
        if ($host === '' || $host === 'localhost') {
            return true;
        }
        foreach (['.localhost', '.local', '.internal', '.home.arpa', '.localdomain'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // Check IPv4-mapped IPv6 literals such as ::ffff:127.0.0.1 or ::ffff:10.0.0.1.
        if (str_starts_with($host, '::ffff:')) {
            $mapped = substr($host, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return self::isPrivateOrReservedIpv4($mapped);
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::isPrivateOrReservedIpv4($host);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            if (str_starts_with($host, 'fe8') || str_starts_with($host, 'fe9') || str_starts_with($host, 'fea') || str_starts_with($host, 'feb')) {
                return true;
            }

            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        // Reject numeric/octal/shorthand IPv4 forms such as 127.1 or 2130706433.
        if (preg_match('/^[0-9.]+$/D', $host) === 1 || preg_match('/^0x[0-9a-f.]+$/Di', $host) === 1) {
            return true;
        }

        return false;
    }

    private static function isPrivateOrReservedIpv4(string $ipv4): bool
    {
        if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        $long = ip2long($ipv4);
        // RFC 6598 Carrier-Grade NAT (100.64.0.0/10: 100.64.0.0 - 100.127.255.255).
        if ($long !== false && ($long & 0xFFC00000) === 0x64400000) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param list<string>|null    $knownDestinationIds when provided, each destination on the rule must be in this list
     *
     * @return RuleConfig
     */
    public function validateRule(array $candidate, ?array $knownDestinationIds = null): array
    {
        $allowedKeys = [
            'id', 'rule', 'enabled', 'covers', 'website', 'websites', 'group', 'website_group',
            'source', 'filter', 'privacy_mode', 'event_name', 'condition', 'threshold', 'n',
            'window', 'destinations',
        ];
        $unknown = array_diff(array_keys($candidate), $allowedKeys);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown alert rule field "%s".', (string) reset($unknown)));
        }

        $id = $candidate['id'] ?? $candidate['rule'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', trim($id)) !== 1) {
            throw new \InvalidArgumentException('Rule ID (rule) must be 1 to 63 lowercase letters, numbers, hyphens or underscores.');
        }
        $id = trim($id);

        $enabled = self::parseBoolean($candidate['enabled'] ?? true, 'enabled');
        $covers = $this->validateCovers($candidate);

        $source = $candidate['source'] ?? self::SOURCE_EVENTS;
        $source = is_string($source) ? strtolower(trim($source)) : null;
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Rule source must be events or tracking_failures.');
        }

        $filter = $candidate['filter'] ?? [];
        if (!is_array($filter) || ($filter !== [] && array_is_list($filter))) {
            throw new \InvalidArgumentException('Rule filter must be a mapping.');
        }
        $unknownFilter = array_diff(array_keys($filter), ['privacy_mode', 'event_name']);
        if ($unknownFilter !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown alert rule filter "%s". Use privacy_mode and event_name.', (string) reset($unknownFilter)));
        }

        $rawPrivacyMode = $candidate['privacy_mode'] ?? $filter['privacy_mode'] ?? self::PRIVACY_ALL;
        $privacyMode = is_string($rawPrivacyMode) ? strtolower(trim($rawPrivacyMode)) : null;
        if ($privacyMode === '') {
            $privacyMode = self::PRIVACY_ALL;
        }
        if (!in_array($privacyMode, self::PRIVACY_MODES, true)) {
            throw new \InvalidArgumentException('Privacy mode filter must be all, enhanced or anonymous.');
        }

        $rawEventName = $candidate['event_name'] ?? $filter['event_name'] ?? '';
        if ($rawEventName === null) {
            $rawEventName = '';
        }
        if (!is_string($rawEventName)) {
            throw new \InvalidArgumentException('Event name filter must be text.');
        }
        $eventName = trim($rawEventName);
        if ($eventName !== '' && !PrivacySanitizer::isSafeEventName($eventName)) {
            throw new \InvalidArgumentException('Event name filter must use the safe event-name format (letters, numbers, underscores, dots, colons or hyphens, starting with a letter).');
        }

        if ($source === self::SOURCE_TRACKING_FAILURES && ($privacyMode !== self::PRIVACY_ALL || $eventName !== '')) {
            throw new \InvalidArgumentException('Privacy mode and event name filters apply only to the events source; tracking_failures counts all ingest and storage failures.');
        }

        $rawCondition = $candidate['condition'] ?? ($source === self::SOURCE_TRACKING_FAILURES ? self::CONDITION_MORE_THAN : null);
        $condition = is_string($rawCondition) ? strtolower(trim($rawCondition)) : null;
        if ($source === self::SOURCE_EVENTS && !in_array($condition, [self::CONDITION_NO_EVENTS, self::CONDITION_FEWER_THAN], true)) {
            throw new \InvalidArgumentException('Condition for the events source must be no_events or fewer_than.');
        }
        if ($source === self::SOURCE_TRACKING_FAILURES && $condition !== self::CONDITION_MORE_THAN) {
            throw new \InvalidArgumentException('Condition for the tracking_failures source must be more_than.');
        }

        $rawThreshold = $candidate['threshold'] ?? $candidate['n'] ?? ($condition === self::CONDITION_NO_EVENTS ? 0 : null);
        if ($condition === self::CONDITION_NO_EVENTS) {
            if ($rawThreshold !== null && $rawThreshold !== '' && $rawThreshold !== 0 && $rawThreshold !== '0') {
                throw new \InvalidArgumentException('Threshold must be 0 or omitted when condition is no_events.');
            }
            $threshold = 0;
        } elseif ($condition === self::CONDITION_FEWER_THAN) {
            $threshold = self::parseInteger($rawThreshold, 'threshold', 1, self::MAX_THRESHOLD);
        } else {
            $threshold = self::parseInteger($rawThreshold, 'threshold', 0, self::MAX_THRESHOLD);
        }

        $window = self::normalizeWindow($candidate['window'] ?? null);
        $this->assertWindowFitsRetention($source, $privacyMode, $window);

        $rawDestinations = $candidate['destinations'] ?? [];
        if (is_string($rawDestinations) && trim($rawDestinations) !== '') {
            $rawDestinations = array_map('trim', explode(',', $rawDestinations));
        }
        if (!is_array($rawDestinations) || $rawDestinations === [] || !array_is_list($rawDestinations)) {
            throw new \InvalidArgumentException('Choose at least one destination for the alert rule.');
        }
        $destinations = [];
        foreach ($rawDestinations as $destId) {
            if (!is_string($destId) || trim($destId) === '') {
                throw new \InvalidArgumentException('Each rule destination must be a destination ID.');
            }
            $destId = trim($destId);
            if ($knownDestinationIds !== null && !in_array($destId, $knownDestinationIds, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown alert destination "%s". Add the destination first.', $destId));
            }
            if (!in_array($destId, $destinations, true)) {
                $destinations[] = $destId;
            }
        }

        return [
            'id' => $id,
            'enabled' => $enabled,
            'covers' => $covers,
            'source' => $source,
            'privacy_mode' => $privacyMode,
            'event_name' => $eventName,
            'condition' => $condition,
            'threshold' => $threshold,
            'window' => $window,
            'destinations' => $destinations,
        ];
    }

    /**
     * Builds the webhook payload v1 alert_condition display string.
     *
     * @param RuleConfig $rule
     */
    public static function formatAlertCondition(array $rule): string
    {
        return match ($rule['condition']) {
            self::CONDITION_NO_EVENTS => 'no_events_per_'.$rule['window'],
            self::CONDITION_FEWER_THAN => sprintf('fewer_than_%d_per_%s', $rule['threshold'], $rule['window']),
            self::CONDITION_MORE_THAN => sprintf('more_than_%d_tracking_failures_per_%s', $rule['threshold'], $rule['window']),
        };
    }

    public static function windowHours(string $window): int
    {
        return self::WINDOWS[$window] ?? 1;
    }

    public static function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug === '' ? 'destination' : substr($slug, 0, 63);
    }

    /**
     * @param mixed $rawRecipients
     *
     * @return list<string>
     */
    private function validateRecipients(mixed $rawRecipients): array
    {
        if (is_string($rawRecipients)) {
            $rawRecipients = preg_split('/[\r\n,;]+/', $rawRecipients) ?: [];
        }
        if (!is_array($rawRecipients) || !array_is_list($rawRecipients)) {
            throw new \InvalidArgumentException('Email destination recipients must be a list of email addresses.');
        }
        $recipients = [];
        foreach ($rawRecipients as $address) {
            if (!is_string($address)) {
                throw new \InvalidArgumentException('Each recipient must be an email address.');
            }
            $address = trim($address);
            if ($address === '') {
                continue;
            }
            if (strlen($address) > self::MAX_ADDRESS || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('Each recipient must be a valid email address, such as ops@example.com.');
            }
            $lower = strtolower($address);
            if (!in_array($lower, array_map('strtolower', $recipients), true)) {
                $recipients[] = $address;
            }
        }
        if ($recipients === []) {
            throw new \InvalidArgumentException('Enter at least one recipient email address for an email destination.');
        }
        if (count($recipients) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException(sprintf('An email destination can list at most %d recipients.', self::MAX_RECIPIENTS));
        }

        return $recipients;
    }

    /** @param array<string, mixed> $candidate */
    private function validateCovers(array $candidate): string
    {
        $covers = $candidate['covers'] ?? null;
        if ($covers === null) {
            if (isset($candidate['group']) || isset($candidate['website_group'])) {
                $group = $candidate['group'] ?? $candidate['website_group'];
                $covers = is_string($group) ? 'group:'.trim($group) : null;
            } elseif (isset($candidate['website'])) {
                $covers = is_string($candidate['website']) ? 'website:'.trim($candidate['website']) : null;
            } elseif (isset($candidate['websites'])) {
                if (is_array($candidate['websites']) && count($candidate['websites']) === 1 && is_string($candidate['websites'][0] ?? null)) {
                    $covers = 'website:'.trim($candidate['websites'][0]);
                } elseif (is_string($candidate['websites'])) {
                    $covers = 'website:'.trim($candidate['websites']);
                }
            }
        }

        if (!is_string($covers) || trim($covers) === '') {
            throw new \InvalidArgumentException('Choose a website, a website group, or all websites for the rule to cover.');
        }
        $covers = trim($covers);
        if ($covers === 'all' || $covers === '*') {
            return 'all';
        }
        if (str_starts_with($covers, 'group:')) {
            $groupId = substr($covers, 6);
            if ($groupId === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $groupId) !== 1) {
                throw new \InvalidArgumentException('Choose a valid website group.');
            }
            if ($this->scopes !== null && !$this->scopes->hasGroup($groupId)) {
                throw new \InvalidArgumentException(sprintf('Website group "%s" does not exist.', $groupId));
            }

            return 'group:'.$groupId;
        }

        $token = str_starts_with($covers, 'website:') ? substr($covers, 8) : $covers;
        if ($token === '' || strlen($token) > 191 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new \InvalidArgumentException('Choose a valid website token.');
        }
        if ($this->websites !== null && $this->websites->findOneByToken($token) === null) {
            throw new \InvalidArgumentException(sprintf('Website "%s" is not registered in config/websites.yaml.', $token));
        }

        return 'website:'.$token;
    }

    /** @return 'hour'|'day'|'week' */
    private static function normalizeWindow(mixed $window): string
    {
        if (is_int($window)) {
            $window = (string) $window;
        }
        if (!is_string($window)) {
            throw new \InvalidArgumentException('Window must be hour (1h), day (24h) or week (168h).');
        }

        return match (strtolower(trim($window))) {
            'hour', '1h', '1' => self::WINDOW_HOUR,
            'day', '24h', '24' => self::WINDOW_DAY,
            'week', '168h', '168' => self::WINDOW_WEEK,
            default => throw new \InvalidArgumentException('Window must be hour (1 completed UTC hour), day (24 completed UTC hours) or week (168 completed UTC hours).'),
        };
    }

    private function assertWindowFitsRetention(string $source, string $privacyMode, string $window): void
    {
        if ($this->lifecycle === null) {
            return;
        }
        try {
            $policy = $this->lifecycle->getPolicy();
        } catch (\Throwable) {
            return;
        }

        $windowDays = (int) ceil(self::windowHours($window) / 24);
        if ($source === self::SOURCE_EVENTS && $policy['retention_enabled']) {
            $liveDays = match ($privacyMode) {
                self::PRIVACY_ANONYMOUS => $policy['anonymous_retention_days'],
                self::PRIVACY_ENHANCED => $policy['enhanced_retention_days'],
                default => min($policy['anonymous_retention_days'], $policy['enhanced_retention_days']),
            };
            if ($windowDays > $liveDays) {
                throw new \InvalidArgumentException(sprintf(
                    'The %s window (%d days) is longer than the live event retention period (%d days). Shorten the window or increase retention on Data lifecycle.',
                    $window,
                    $windowDays,
                    $liveDays,
                ));
            }
        }

        if ($source === self::SOURCE_TRACKING_FAILURES && $policy['processing_tasks_retention_days'] > 0) {
            if ($windowDays > $policy['processing_tasks_retention_days']) {
                throw new \InvalidArgumentException(sprintf(
                    'The %s window (%d days) is longer than processing_tasks_retention_days (%d days).',
                    $window,
                    $windowDays,
                    $policy['processing_tasks_retention_days'],
                ));
            }
        }
    }

    private static function parseBoolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => throw new \InvalidArgumentException(sprintf('%s must be true or false.', $field)),
            };
        }

        throw new \InvalidArgumentException(sprintf('%s must be true or false.', $field));
    }

    private static function parseInteger(mixed $value, string $field, int $min, int $max): int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', trim($value)) === 1) {
            $int = (int) trim($value);
        } else {
            throw new \InvalidArgumentException(sprintf('Threshold (N) must be a whole number of at least %d.', $min));
        }

        if ($int < $min || $int > $max) {
            throw new \InvalidArgumentException(sprintf('Threshold (N) must be between %d and %d.', $min, $max));
        }

        return $int;
    }
}
