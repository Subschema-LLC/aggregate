<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\AlertRule\AlertRule;
use App\Entities\AlertRule\EventNames;
use App\Entities\AlertRule\RuleReferences;
use App\Entities\Destination\Destination;
use App\Service\Alerts\AlertSecrets;

/**
 * Reads the `alerts` mapping of config/aggregate.yaml: destinations and rules,
 * each a list or a mapping keyed by ID.
 *
 * Loading checks the shape of every rule. References are checked only when
 * given: a website removed from config/websites.yaml must not stop every other
 * rule from loading. RuleReferenceChecks::problemWith() says which rules can no
 * longer be checked.
 *
 * @phpstan-import-type DestinationArray from Destination
 * @phpstan-import-type RuleArray from AlertRule
 *
 * @phpstan-type AlertsState array{
 *     destinations: array<string, DestinationArray>,
 *     rules: array<string, RuleArray>
 * }
 */
final class AlertsMapping
{
    public function __construct(private readonly EventNames $eventNames)
    {
    }

    /** @return AlertsState */
    public function parse(mixed $config, ?RuleReferences $references = null): array
    {
        if ($config === null || $config === []) {
            return ['destinations' => [], 'rules' => []];
        }
        if (!is_array($config) || array_is_list($config)) {
            throw new \InvalidArgumentException(
                'alerts must be a mapping with destinations and rules.',
            );
        }
        $unknownTop = array_diff(array_keys($config), ['destinations', 'rules']);
        if ($unknownTop !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown alerts setting "%s". Use destinations and rules.',
                (string) reset($unknownTop),
            ));
        }

        $destinations = [];
        foreach (self::entries($config, 'destinations') as $key => $raw) {
            $raw = self::mapping($raw, 'destination');
            if (is_string($key) && !isset($raw['id'])) {
                $raw['id'] = $key;
            }
            $destination = Destination::fromInput($raw)->toArray();
            if (isset($destinations[$destination['id']])) {
                throw new \InvalidArgumentException(
                    sprintf('Duplicate alert destination ID "%s".', $destination['id']),
                );
            }
            self::assertSecretNameIsFree($destination['id'], array_keys($destinations));
            $destinations[$destination['id']] = $destination;
        }

        $rules = [];
        foreach (self::entries($config, 'rules') as $key => $raw) {
            $raw = self::mapping($raw, 'rule');
            if (is_string($key) && !isset($raw['id']) && !isset($raw['rule'])) {
                $raw['id'] = $key;
            }
            $rule = $this->rule($raw, array_keys($destinations), $references);
            if (isset($rules[$rule['id']])) {
                throw new \InvalidArgumentException(
                    sprintf('Duplicate alert rule ID "%s".', $rule['id']),
                );
            }
            $rules[$rule['id']] = $rule;
        }

        return ['destinations' => $destinations, 'rules' => $rules];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param list<string>|null    $knownDestinationIds
     *
     * @return RuleArray
     */
    public function rule(
        array $candidate,
        ?array $knownDestinationIds,
        ?RuleReferences $references,
    ): array {
        return AlertRule::fromInput(
            $candidate,
            $this->eventNames,
            $references,
            $knownDestinationIds,
        )->toArray();
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
            if ($existing !== $id
                && AlertSecrets::envNameFor($existing) === AlertSecrets::envNameFor($id)) {
                throw new \InvalidArgumentException(sprintf(
                    'Destination ID "%s" is too close to "%s": IDs that differ only by "-" '
                    .'and "_" would share a signing secret. Choose another ID.',
                    $id,
                    $existing,
                ));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function entries(array $config, string $key): array
    {
        $raw = $config[$key] ?? [];
        if (!is_array($raw)) {
            throw new \InvalidArgumentException(
                sprintf('alerts.%s must be a list or mapping of %s.', $key, $key),
            );
        }

        return $raw;
    }

    /** @return array<string, mixed> */
    private static function mapping(mixed $entry, string $noun): array
    {
        if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
            throw new \InvalidArgumentException(sprintf('Each alert %s must be a mapping.', $noun));
        }

        /** @var array<string, mixed> $entry */
        return $entry;
    }
}
