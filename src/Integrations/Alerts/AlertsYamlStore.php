<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\AlertRule\AlertRule;
use App\Entities\Destination\Destination;
use App\Service\AggregateConfigLoader;

/**
 * Alert rules and destinations stored under `alerts` in config/aggregate.yaml.
 * Each write re-reads the file inside the loader's lock, so a change made by
 * another entry point since the page loaded is kept.
 *
 * @phpstan-import-type DestinationArray from Destination
 * @phpstan-import-type RuleArray from AlertRule
 * @phpstan-import-type AlertsState from AlertsMapping
 */
final class AlertsYamlStore
{
    public const KEY = 'alerts';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AlertsMapping $mapping,
        private readonly RuleReferenceChecks $references,
    ) {
    }

    /**
     * The configured destinations and rules, checked for shape only. A rule
     * whose website, group or retention no longer matches still loads, so it
     * can be shown, edited and deleted.
     *
     * @return AlertsState
     */
    public function load(): array
    {
        $this->config->assertHealthy();

        return $this->mapping->parse($this->config->get(self::KEY, []));
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @return DestinationArray
     */
    public function saveDestination(array $candidate): array
    {
        $saved = Destination::fromInput($candidate)->toArray();
        $this->update(static function (array $state) use ($saved): array {
            AlertsMapping::assertSecretNameIsFree($saved['id'], array_keys($state['destinations']));
            $state['destinations'][$saved['id']] = $saved;

            return $state;
        });

        return $saved;
    }

    public function deleteDestination(string $id): void
    {
        $this->update(static function (array $state) use ($id): array {
            if (!isset($state['destinations'][$id])) {
                throw new \InvalidArgumentException(
                    sprintf('Alert destination "%s" does not exist.', $id),
                );
            }
            foreach ($state['rules'] as $rule) {
                if (in_array($id, $rule['destinations'], true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Destination "%s" is used by rule "%s". Remove it from the rule '
                        .'before deleting it.',
                        $id,
                        $rule['id'],
                    ));
                }
            }
            unset($state['destinations'][$id]);

            return $state;
        });
    }

    /**
     * Checks the rule, with its references, against the destinations on the
     * page and again against those in the file at the moment of writing.
     *
     * @param array<string, mixed> $candidate
     *
     * @return RuleArray
     */
    public function saveRule(array $candidate): array
    {
        $known = array_keys($this->load()['destinations']);
        $saved = $this->mapping->rule($candidate, $known, $this->references);
        $this->update(function (array $state) use ($saved): array {
            $latest = array_keys($state['destinations']);
            $rule = $this->mapping->rule($saved, $latest, $this->references);
            $state['rules'][$rule['id']] = $rule;

            return $state;
        });

        return $saved;
    }

    public function deleteRule(string $id): void
    {
        $this->update(static function (array $state) use ($id): array {
            if (!isset($state['rules'][$id])) {
                throw new \InvalidArgumentException(
                    sprintf('Alert rule "%s" does not exist.', $id),
                );
            }
            unset($state['rules'][$id]);

            return $state;
        });
    }

    /** @param callable(AlertsState): AlertsState $change */
    private function update(callable $change): void
    {
        $this->config->updateMany(function (array $current) use ($change): array {
            $state = $change($this->mapping->parse($current[self::KEY] ?? []));

            return [self::KEY => AlertsYaml::write($state)];
        });
    }
}
