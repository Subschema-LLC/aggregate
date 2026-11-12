<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\InternalTrafficSettings;
use App\Service\Settings\AbstractSection;

/**
 * The organization-traffic marker, as the Organization traffic page saves
 * it. The page's share link is rotated or revoked with
 * app:internal-traffic:share-link.
 */
final class OrganizationTrafficSection extends AbstractSection
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly InternalTrafficSettings $settings,
    ) {
    }

    public function name(): string
    {
        return 'organization-traffic';
    }

    public function page(): string
    {
        return 'Collection > Organization traffic';
    }

    public function keys(): array
    {
        return array_keys(InternalTrafficSettings::DEFAULTS);
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return $this->settings->markerSettings();
    }

    public function environmentOverrides(?string $target = null): array
    {
        $this->assertTarget($target);

        return array_values(array_intersect($this->keys(), array_keys(array_filter($this->settings->getEnvironmentOverrides()))));
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $overridden = array_values(array_intersect(array_keys($changes), $this->environmentOverrides()));
        $candidate = [...$this->settings->markerSettings(), ...array_diff_key($changes, array_flip($overridden))];
        if ($check) {
            InternalTrafficSettings::validateMarker($candidate);
        } else {
            $this->settings->saveMarker(array_diff_key($candidate, array_flip($this->environmentOverrides())));
        }

        return array_values(array_filter([
            self::overriddenNote($overridden),
            self::done($check, 'Organization traffic marker settings were saved.'),
        ]));
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
