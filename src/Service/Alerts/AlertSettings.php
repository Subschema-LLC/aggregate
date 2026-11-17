<?php

declare(strict_types=1);

namespace App\Service\Alerts;

use App\Integrations\Alerts\AlertsYamlStore;
use App\Integrations\Alerts\CoveredWebsites;
use App\Service\AggregateConfigLoader;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;

/**
 * Alert rules and destinations stored under `alerts` in config/aggregate.yaml
 * (AlertsYamlStore) and the websites they cover (CoveredWebsites). Shared by
 * the Alerts dashboard page, app:alerts:* CLI commands, and AlertEvaluator.
 *
 * @phpstan-import-type DestinationConfig from AlertRuleValidator
 * @phpstan-import-type RuleConfig from AlertRuleValidator
 */
class AlertSettings
{
    public const KEY = AlertsYamlStore::KEY;

    private readonly AlertsYamlStore $store;
    private readonly CoveredWebsites $covered;

    public function __construct(
        AggregateConfigLoader $config,
        private readonly AlertRuleValidator $validator,
        WebsiteConfigManager $websites,
        WebsiteScopeCatalog $scopes,
    ) {
        $this->store = new AlertsYamlStore($config, $validator->mapping(), $validator->references());
        $this->covered = new CoveredWebsites($websites, $scopes);
    }

    public function validator(): AlertRuleValidator
    {
        return $this->validator;
    }

    /**
     * The configured destinations and rules, checked for shape only. A rule
     * whose website, group or retention no longer matches still loads, so it
     * can be shown, edited and deleted; referenceProblem() says why it is
     * skipped.
     *
     * @return array{destinations: array<string, DestinationConfig>, rules: array<string, RuleConfig>}
     */
    public function toArray(): array
    {
        return $this->store->load();
    }

    /**
     * Why a configured rule is skipped, or null when it can be checked.
     *
     * @param RuleConfig $rule
     */
    public function referenceProblem(array $rule): ?string
    {
        return $this->validator->referenceProblem($rule);
    }

    /** @return array<string, DestinationConfig> */
    public function destinations(): array
    {
        return $this->toArray()['destinations'];
    }

    /** @return DestinationConfig|null */
    public function destination(string $id): ?array
    {
        return $this->destinations()[$id] ?? null;
    }

    /** @return array<string, RuleConfig> */
    public function rules(): array
    {
        return $this->toArray()['rules'];
    }

    /** @return RuleConfig|null */
    public function rule(string $id): ?array
    {
        return $this->rules()[$id] ?? null;
    }

    /**
     * Saves or updates a destination and returns the validated destination.
     *
     * @param array<string, mixed> $candidate
     *
     * @return DestinationConfig
     */
    public function saveDestination(array $candidate): array
    {
        return $this->store->saveDestination($candidate);
    }

    /**
     * Deletes a destination when no rule references it.
     */
    public function deleteDestination(string $id): void
    {
        $this->store->deleteDestination($id);
    }

    /**
     * Saves or updates a rule and returns the validated rule.
     *
     * @param array<string, mixed> $candidate
     *
     * @return RuleConfig
     */
    public function saveRule(array $candidate): array
    {
        return $this->store->saveRule($candidate);
    }

    /**
     * Deletes a rule by ID.
     */
    public function deleteRule(string $id): void
    {
        $this->store->deleteRule($id);
    }

    /**
     * Resolves a rule's `covers` specification to the list of registered
     * websites it watches: `[['token' => ..., 'name' => ..., 'domain' => ...], ...]`.
     *
     * @param RuleConfig $rule
     *
     * @return list<array{token: string, name: string, domain: string}>
     */
    public function coveredWebsites(array $rule): array
    {
        return $this->covered->of($rule);
    }

    /**
     * A registered website by token, or null when it is not in config/websites.yaml.
     *
     * @return array{token: string, name: string, domain: string}|null
     */
    public function website(string $token): ?array
    {
        return $this->covered->website($token);
    }

    /**
     * Human-readable description of what a rule covers.
     *
     * @param RuleConfig $rule
     */
    public function describeCovers(array $rule): string
    {
        return $this->covered->describeCovers($rule);
    }

    /**
     * Plain-language description of a rule's condition and window.
     *
     * @param RuleConfig $rule
     */
    public static function describeCondition(array $rule): string
    {
        $windowLabel = match ($rule['window']) {
            AlertRuleValidator::WINDOW_HOUR => '1 hour (1 h)',
            AlertRuleValidator::WINDOW_DAY => '1 day (24 h)',
            AlertRuleValidator::WINDOW_WEEK => '1 week (168 h)',
        };

        if ($rule['source'] === AlertRuleValidator::SOURCE_TRACKING_FAILURES) {
            return sprintf('More than %d tracking failures in %s', $rule['threshold'], $windowLabel);
        }

        $modeLabel = match ($rule['privacy_mode']) {
            AlertRuleValidator::PRIVACY_ENHANCED => 'enhanced ',
            AlertRuleValidator::PRIVACY_ANONYMOUS => 'anonymous ',
            default => '',
        };
        $eventLabel = $rule['event_name'] !== '' ? sprintf('"%s" ', $rule['event_name']) : '';
        $modeSuffix = $rule['privacy_mode'] === AlertRuleValidator::PRIVACY_ALL && $rule['event_name'] === '' ? ' (all modes)' : '';

        if ($rule['condition'] === AlertRuleValidator::CONDITION_NO_EVENTS) {
            return sprintf('No %s%sevents%s in %s', $modeLabel, $eventLabel, $modeSuffix, $windowLabel);
        }

        return sprintf('Fewer than %d %s%sevents%s in %s', $rule['threshold'], $modeLabel, $eventLabel, $modeSuffix, $windowLabel);
    }

    public static function bareHost(string $domain): string
    {
        return CoveredWebsites::bareHost($domain);
    }

    /**
     * Normalizes the installation URL from app_host: lower-case scheme and
     * host, no trailing slash, preserving any configured sub-folder path.
     */
    public static function normalizeInstallationUrl(mixed $appHost): string
    {
        if (!is_string($appHost) || trim($appHost) === '') {
            return 'http://localhost';
        }
        $parts = parse_url(trim($appHost));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return 'http://localhost';
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';

        return $scheme.'://'.$host.$port.$path;
    }
}
