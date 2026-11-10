<?php

declare(strict_types=1);

namespace App\Service\Alerts;

use App\Service\AggregateConfigLoader;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;

/**
 * Alert rules and destinations stored under `alerts` in config/aggregate.yaml.
 * Shared by the Alerts dashboard page, app:alerts:* CLI commands, and
 * AlertEvaluator.
 *
 * @phpstan-import-type DestinationConfig from AlertRuleValidator
 * @phpstan-import-type RuleConfig from AlertRuleValidator
 */
class AlertSettings
{
    public const KEY = 'alerts';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AlertRuleValidator $validator,
        private readonly WebsiteConfigManager $websites,
        private readonly WebsiteScopeCatalog $scopes,
    ) {
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
        $this->config->assertHealthy();

        return $this->validator->validateAll($this->config->get(self::KEY, []));
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
        $validated = $this->validator->validateDestination($candidate);
        $this->config->updateMany(function (array $current) use ($validated): array {
            $state = $this->validator->validateAll($current[self::KEY] ?? []);
            AlertRuleValidator::assertSecretNameIsFree($validated['id'], array_keys($state['destinations']));
            $state['destinations'][$validated['id']] = $validated;

            return [self::KEY => self::serialize($state)];
        });

        return $validated;
    }

    /**
     * Deletes a destination when no rule references it.
     */
    public function deleteDestination(string $id): void
    {
        $this->config->updateMany(function (array $current) use ($id): array {
            $state = $this->validator->validateAll($current[self::KEY] ?? []);
            if (!isset($state['destinations'][$id])) {
                throw new \InvalidArgumentException(sprintf('Alert destination "%s" does not exist.', $id));
            }
            foreach ($state['rules'] as $rule) {
                if (in_array($id, $rule['destinations'], true)) {
                    throw new \InvalidArgumentException(sprintf('Destination "%s" is used by rule "%s". Remove it from the rule before deleting it.', $id, $rule['id']));
                }
            }
            unset($state['destinations'][$id]);

            return [self::KEY => self::serialize($state)];
        });
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
        $current = $this->toArray();
        $validated = $this->validator->validateRule($candidate, array_keys($current['destinations']));
        $this->config->updateMany(function (array $rawConfig) use ($validated): array {
            $state = $this->validator->validateAll($rawConfig[self::KEY] ?? []);
            $validatedWithLatest = $this->validator->validateRule($validated, array_keys($state['destinations']));
            $state['rules'][$validatedWithLatest['id']] = $validatedWithLatest;

            return [self::KEY => self::serialize($state)];
        });

        return $validated;
    }

    /**
     * Deletes a rule by ID.
     */
    public function deleteRule(string $id): void
    {
        $this->config->updateMany(function (array $current) use ($id): array {
            $state = $this->validator->validateAll($current[self::KEY] ?? []);
            if (!isset($state['rules'][$id])) {
                throw new \InvalidArgumentException(sprintf('Alert rule "%s" does not exist.', $id));
            }
            unset($state['rules'][$id]);

            return [self::KEY => self::serialize($state)];
        });
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
        $covers = $rule['covers'];
        $allSites = [];
        foreach ($this->websites->getWebsites() as $site) {
            $token = (string) ($site['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $allSites[$token] = [
                'token' => $token,
                'name' => (string) ($site['name'] ?? 'Unnamed'),
                'domain' => self::bareHost((string) ($site['domain'] ?? '')),
            ];
        }

        if ($covers === 'all') {
            return array_values($allSites);
        }

        if (str_starts_with($covers, 'group:')) {
            $groupId = substr($covers, 6);
            $groups = $this->scopes->groups();
            $members = $groups[$groupId]['members'] ?? [];
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
     * @return array{token: string, name: string, domain: string}|null
     */
    public function website(string $token): ?array
    {
        $site = $this->websites->findOneByToken($token);
        if ($site === null) {
            return null;
        }

        return [
            'token' => $token,
            'name' => (string) ($site['name'] ?? 'Unnamed'),
            'domain' => self::bareHost((string) ($site['domain'] ?? '')),
        ];
    }

    /**
     * Human-readable description of what a rule covers.
     *
     * @param RuleConfig $rule
     */
    public function describeCovers(array $rule): string
    {
        $covers = $rule['covers'];
        if ($covers === 'all') {
            $count = count($this->coveredWebsites($rule));

            return sprintf('All websites (%d)', $count);
        }
        if (str_starts_with($covers, 'group:')) {
            $groupId = substr($covers, 6);
            $name = $this->scopes->groupName($groupId) ?? $groupId;
            $count = count($this->coveredWebsites($rule));

            return sprintf('Group: %s (%d website%s)', $name, $count, $count === 1 ? '' : 's');
        }
        $token = str_starts_with($covers, 'website:') ? substr($covers, 8) : $covers;
        $site = $this->websites->findOneByToken($token);
        if ($site !== null) {
            $domain = self::bareHost((string) ($site['domain'] ?? ''));

            return $domain !== '' ? sprintf('%s (%s)', $site['name'], $domain) : (string) $site['name'];
        }

        return $token;
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
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        if (str_contains($domain, '://')) {
            $host = parse_url($domain, PHP_URL_HOST);

            return is_string($host) ? strtolower($host) : '';
        }
        $host = explode('/', $domain, 2)[0];

        return strtolower(trim($host));
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

    /**
     * @param array{destinations: array<string, DestinationConfig>, rules: array<string, RuleConfig>} $state
     *
     * @return array{destinations: list<array<string, mixed>>, rules: list<array<string, mixed>>}
     */
    private static function serialize(array $state): array
    {
        $destinations = [];
        foreach ($state['destinations'] as $dest) {
            if ($dest['type'] === AlertRuleValidator::TYPE_WEBHOOK) {
                $destinations[] = [
                    'id' => $dest['id'],
                    'label' => $dest['label'],
                    'type' => $dest['type'],
                    'url' => $dest['url'],
                ];
            } else {
                $destinations[] = [
                    'id' => $dest['id'],
                    'label' => $dest['label'],
                    'type' => $dest['type'],
                    'recipients' => $dest['recipients'],
                ];
            }
        }

        $rules = [];
        foreach ($state['rules'] as $rule) {
            $entry = [
                'id' => $rule['id'],
                'enabled' => $rule['enabled'],
                'covers' => $rule['covers'],
                'source' => $rule['source'],
            ];
            if ($rule['source'] === AlertRuleValidator::SOURCE_EVENTS) {
                $entry['privacy_mode'] = $rule['privacy_mode'];
                if ($rule['event_name'] !== '') {
                    $entry['event_name'] = $rule['event_name'];
                }
            }
            $entry['condition'] = $rule['condition'];
            $entry['threshold'] = $rule['threshold'];
            $entry['window'] = $rule['window'];
            $entry['destinations'] = $rule['destinations'];
            $rules[] = $entry;
        }

        return [
            'destinations' => $destinations,
            'rules' => $rules,
        ];
    }
}
