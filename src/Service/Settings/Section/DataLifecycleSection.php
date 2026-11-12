<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\Settings\AbstractSection;

/**
 * Archiving, retention, record retention and the queue's retention, as the
 * Data lifecycle page saves them: every setting is checked together, so a
 * retention period can never fall below the archive age.
 */
final class DataLifecycleSection extends AbstractSection
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AnalyticsDataLifecyclePolicy $policy,
    ) {
    }

    public function name(): string
    {
        return 'data-lifecycle';
    }

    public function page(): string
    {
        return 'Administration > Data lifecycle';
    }

    public function keys(): array
    {
        return array_keys(AnalyticsDataLifecyclePolicy::DEFAULTS);
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return AnalyticsDataLifecyclePolicy::validate($this->policy->toArray());
    }

    public function environmentOverrides(?string $target = null): array
    {
        $this->assertTarget($target);

        return array_keys(array_filter($this->policy->getEnvironmentOverrides()));
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $overridden = array_values(array_intersect(array_keys($changes), $this->environmentOverrides()));
        $changes = array_diff_key($changes, array_flip($overridden));
        $validated = AnalyticsDataLifecyclePolicy::validate([...$this->policy->toArray(), ...$changes]);
        if (!$check && $changes !== []) {
            $this->config->setMany(array_intersect_key($validated, $changes));
        }

        return array_values(array_filter([
            self::overriddenNote($overridden),
            $changes === [] ? 'Nothing was changed.' : self::done($check, 'Data lifecycle settings were saved.'),
        ]));
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
