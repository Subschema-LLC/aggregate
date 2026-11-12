<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Service\AggregateConfigLoader;
use App\Service\ViewSync\ViewSyncSettings;

/**
 * A view sync's settings (BigQuery, the reporting database, the forensic
 * database), as its page saves them: the switch and interval, the views,
 * and the destination's own settings. Adding a private view needs the same
 * confirmation the page asks for (--confirm).
 */
abstract class AbstractViewSyncSection extends AbstractSection
{
    public function __construct(protected readonly AggregateConfigLoader $config)
    {
    }

    /** The sync's settings service. */
    abstract protected function settings(): ViewSyncSettings;

    public function keys(): array
    {
        return array_keys($this->settings()::defaults());
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return $this->settings()->toArray();
    }

    public function environmentOverrides(?string $target = null): array
    {
        $this->assertTarget($target);

        return array_keys(array_filter($this->settings()->getEnvironmentOverrides()));
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $settings = $this->settings();
        $current = $settings->toArray();
        $this->assertConfirmed($changes, $current, $confirmed);
        $overridden = array_values(array_intersect(array_keys($changes), $this->environmentOverrides()));
        if ($check) {
            $settings::validate([...$current, ...array_diff_key($changes, array_flip($overridden))]);
        } else {
            $settings->save($changes);
        }

        return array_values(array_filter([
            self::overriddenNote($overridden),
            self::done($check, 'The '.$settings::definition()->settingsName.' settings were saved.'),
        ]));
    }

    /**
     * The page's confirmation: adding a private view, which holds row-level
     * or unsuppressed data, must be confirmed.
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $current
     */
    protected function assertConfirmed(array $changes, array $current, bool $confirmed): void
    {
        $key = $this->settings()::keys()['private_views'];
        if ($confirmed || !array_key_exists($key, $changes) || !is_array($changes[$key])) {
            return;
        }
        if (array_diff(array_map('strval', $changes[$key]), $current[$key]) !== []) {
            throw new \InvalidArgumentException('Confirm that the private views you added may be copied to '.$this->settings()::definition()->destination.' with --confirm. They hold row-level or unsuppressed data.');
        }
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
