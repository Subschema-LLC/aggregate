<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\GeneralSettings;
use App\Service\Settings\AbstractSection;
use App\Service\WebsiteActivityService;

/** The General settings page's application settings, through the same rules. */
final class GeneralSection extends AbstractSection
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly GeneralSettings $settings,
        private readonly ?WebsiteActivityService $activity = null,
    ) {
    }

    public function name(): string
    {
        return 'general';
    }

    public function page(): string
    {
        return 'Administration > General settings';
    }

    public function keys(): array
    {
        return GeneralSettings::KEYS;
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return $this->settings->values();
    }

    public function environmentOverrides(?string $target = null): array
    {
        $this->assertTarget($target);

        return array_values(array_unique([...parent::environmentOverrides(), ...$this->settings->environmentOverrides()]));
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $current = $this->settings->values();
        $saving = $this->settings->changes([...$current, ...$changes]);
        // Keys the operator did not name keep their saved YAML: the page
        // writes every field it shows, the command only what it was given.
        $saving = array_intersect_key($saving, $changes);
        $overridden = array_values(array_intersect(array_keys($changes), $this->environmentOverrides()));
        if (!$check && $saving !== []) {
            $this->settings->save($saving);
            $this->activity?->forget();
        }

        return array_values(array_filter([
            self::overriddenNote($overridden),
            $saving === [] && !$check ? 'Nothing was changed.' : self::done($check, 'Settings updated in config/aggregate.yaml.'),
        ]));
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
