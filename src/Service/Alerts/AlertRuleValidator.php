<?php

declare(strict_types=1);

namespace App\Service\Alerts;

use App\Entities\AlertRule\AlertRule;
use App\Entities\Destination\Destination;
use App\Entities\Destination\Recipients;
use App\Entities\Destination\WebhookUrl;
use App\Integrations\Alerts\AlertsMapping;
use App\Integrations\Alerts\PrivacySanitizerEventNames;
use App\Integrations\Alerts\RuleReferenceChecks;
use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;

/**
 * The alert checks used by the Alerts dashboard page, the app:alerts:*
 * commands and the evaluator: the AlertRule and Destination entities, the
 * alerts mapping (AlertsMapping) and rule references (RuleReferenceChecks).
 *
 * @phpstan-import-type DestinationArray from Destination as DestinationConfig
 * @phpstan-import-type RuleArray from AlertRule as RuleConfig
 */
class AlertRuleValidator
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

    private readonly AlertsMapping $mapping;
    private readonly RuleReferenceChecks $references;

    public function __construct(
        ?WebsiteConfigManager $websites = null,
        ?WebsiteScopeCatalog $scopes = null,
        ?AnalyticsDataLifecyclePolicy $lifecycle = null,
    ) {
        $this->mapping = new AlertsMapping(new PrivacySanitizerEventNames());
        $this->references = new RuleReferenceChecks($websites, $scopes, $lifecycle);
    }

    public function mapping(): AlertsMapping
    {
        return $this->mapping;
    }

    public function references(): RuleReferenceChecks
    {
        return $this->references;
    }

    /**
     * Validates a complete alerts mapping (destinations and rules); see
     * AlertsMapping::parse().
     *
     * @param mixed $config
     *
     * @return array{destinations: array<string, DestinationConfig>, rules: array<string, RuleConfig>}
     */
    public function validateAll(mixed $config, bool $checkReferences = false): array
    {
        return $this->mapping->parse($config, $checkReferences ? $this->references : null);
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
        return $this->mapping->rule(
            $candidate,
            $knownDestinationIds,
            $checkReferences ? $this->references : null,
        );
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
        return $this->references->problemWith($rule);
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
        $this->references->assertCoversExist($covers);
    }

    /** @param list<string> $existingIds */
    public static function assertSecretNameIsFree(string $id, array $existingIds): void
    {
        AlertsMapping::assertSecretNameIsFree($id, $existingIds);
    }

    public function assertWindowFitsRetention(string $source, string $privacyMode, string $window): void
    {
        $this->references->assertWindowFitsRetention($source, $privacyMode, $window);
    }
}
