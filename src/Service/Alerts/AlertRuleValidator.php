<?php

declare(strict_types=1);

namespace App\Service\Alerts;

use App\Entities\AlertRule\AlertRule;
use App\Entities\AlertRule\RuleReferences;
use App\Entities\Destination\Destination;
use App\Entities\Destination\Recipients;
use App\Entities\Destination\WebhookUrl;
use App\Integrations\Alerts\PrivacySanitizerEventNames;
use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;

/**
 * Reads the alerts mapping from YAML, the Alerts dashboard page and the
 * app:alerts:* commands into the AlertRule and Destination entities, and
 * checks what a rule refers to: the websites and groups it covers and the
 * retention its window must fit.
 *
 * @phpstan-import-type DestinationArray from Destination as DestinationConfig
 * @phpstan-import-type RuleArray from AlertRule as RuleConfig
 */
class AlertRuleValidator implements RuleReferences
{
    public const SOURCE_EVENTS = AlertRule::SOURCE_EVENTS;
    public const SOURCE_TRACKING_FAILURES = AlertRule::SOURCE_TRACKING_FAILURES;
    public const SOURCES = AlertRule::SOURCES;

    public const PRIVACY_ALL = AlertRule::PRIVACY_ALL;
    public const PRIVACY_ENHANCED = AlertRule::PRIVACY_ENHANCED;
    public const PRIVACY_ANONYMOUS = AlertRule::PRIVACY_ANONYMOUS;
    public const PRIVACY_MODES = AlertRule::PRIVACY_MODES;

    public const CONDITION_NO_EVENTS = AlertRule::CONDITION_NO_EVENTS;
    public const CONDITION_FEWER_THAN = AlertRule::CONDITION_FEWER_THAN;
    public const CONDITION_MORE_THAN = AlertRule::CONDITION_MORE_THAN;
    public const CONDITIONS = AlertRule::CONDITIONS;

    public const WINDOW_HOUR = AlertRule::WINDOW_HOUR;
    public const WINDOW_DAY = AlertRule::WINDOW_DAY;
    public const WINDOW_WEEK = AlertRule::WINDOW_WEEK;
    public const WINDOWS = AlertRule::WINDOWS;

    public const TYPE_WEBHOOK = Destination::TYPE_WEBHOOK;
    public const TYPE_EMAIL = Destination::TYPE_EMAIL;
    public const DESTINATION_TYPES = Destination::TYPES;

    public const MAX_LABEL = Destination::MAX_LABEL;
    public const MAX_URL = WebhookUrl::MAX_LENGTH;
    public const MAX_ADDRESS = Recipients::MAX_ADDRESS;
    public const MAX_RECIPIENTS = Recipients::MAX_RECIPIENTS;
    public const MAX_THRESHOLD = AlertRule::MAX_THRESHOLD;

    public function __construct(
        private readonly ?WebsiteConfigManager $websites = null,
        private readonly ?WebsiteScopeCatalog $scopes = null,
        private readonly ?AnalyticsDataLifecyclePolicy $lifecycle = null,
    ) {
    }

    /**
     * Validates a complete alerts mapping (destinations and rules).
     *
     * Loading checks the shape of every rule but not whether the websites,
     * groups and retention it depends on still match: a website removed from
     * config/websites.yaml must not make every other rule stop. Use
     * referenceProblem() to find rules that can no longer be checked; saving a
     * rule checks its references (validateRule() with $checkReferences).
     *
     * @param mixed $config
     *
     * @return array{destinations: array<string, DestinationConfig>, rules: array<string, RuleConfig>}
     */
    public function validateAll(mixed $config, bool $checkReferences = false): array
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
            self::assertSecretNameIsFree($validated['id'], array_keys($destinations));
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
            $validated = $this->validateRule($rawRule, array_keys($destinations), $checkReferences);
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
        return Destination::fromInput($candidate)->toArray();
    }

    /**
     * Validates a webhook URL: must be HTTPS, no credentials or fragment, and
     * must not target localhost, private, loopback, link-local or reserved addresses.
     */
    public function validateWebhookUrl(mixed $url): string
    {
        return WebhookUrl::check($url);
    }

    public static function isPrivateOrLocalHost(string $host): bool
    {
        return WebhookUrl::isPrivateOrLocalHost($host);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param list<string>|null    $knownDestinationIds when provided, each destination on the rule must be in this list
     * @param bool                 $checkReferences     also require the covered website or group to exist and the window to fit retention
     *
     * @return RuleConfig
     */
    public function validateRule(array $candidate, ?array $knownDestinationIds = null, bool $checkReferences = true): array
    {
        return AlertRule::fromInput(
            $candidate,
            new PrivacySanitizerEventNames(),
            $checkReferences ? $this : null,
            $knownDestinationIds,
        )->toArray();
    }

    /**
     * Why a loaded rule cannot be checked any more, or null when it can: the
     * website or group it covers was removed, or its window became longer than
     * the live retention. Such a rule is skipped and shown with this reason
     * while every other rule keeps running.
     *
     * @param RuleConfig $rule
     */
    public function referenceProblem(array $rule): ?string
    {
        try {
            $this->assertCoversExist($rule['covers']);
            $this->assertWindowFitsRetention($rule['source'], $rule['privacy_mode'], $rule['window']);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Builds the webhook payload v1 alert_condition display string.
     *
     * @param RuleConfig $rule
     */
    public static function formatAlertCondition(array $rule): string
    {
        return AlertRule::conditionText($rule);
    }

    public static function windowHours(string $window): int
    {
        return AlertRule::windowHours($window);
    }

    public static function slugify(string $text): string
    {
        return Destination::slugify($text);
    }

    public function assertCoversExist(string $covers): void
    {
        if (str_starts_with($covers, 'group:')) {
            $groupId = substr($covers, 6);
            if ($this->scopes !== null && !$this->scopes->hasGroup($groupId)) {
                throw new \InvalidArgumentException(sprintf('Website group "%s" does not exist.', $groupId));
            }

            return;
        }
        if (str_starts_with($covers, 'website:')) {
            $token = substr($covers, 8);
            if ($this->websites !== null && $this->websites->findOneByToken($token) === null) {
                throw new \InvalidArgumentException(sprintf('Website "%s" is not registered in config/websites.yaml.', $token));
            }
        }
    }

    /**
     * Destination IDs that differ only by "-" and "_" would share one secret
     * key in config/secrets/alerts.yaml and one environment variable name.
     *
     * @param list<string> $existingIds
     */
    public static function assertSecretNameIsFree(string $id, array $existingIds): void
    {
        foreach ($existingIds as $existing) {
            if ($existing !== $id && AlertSecrets::envNameFor($existing) === AlertSecrets::envNameFor($id)) {
                throw new \InvalidArgumentException(sprintf(
                    'Destination ID "%s" is too close to "%s": IDs that differ only by "-" and "_" would share a signing secret. Choose another ID.',
                    $id,
                    $existing,
                ));
            }
        }
    }

    public function assertWindowFitsRetention(string $source, string $privacyMode, string $window): void
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
}
