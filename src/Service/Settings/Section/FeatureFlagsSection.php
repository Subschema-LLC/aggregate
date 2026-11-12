<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Service\Settings\AbstractSection;

/**
 * Feature flags, as the Feature flags page saves them: each flag is a key,
 * with enabled and hide_from_navigation, for example
 * app:config set feature-flags.custom_scripts '{enabled: true}'.
 */
final class FeatureFlagsSection extends AbstractSection
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly FeatureFlags $flags,
    ) {
    }

    public function name(): string
    {
        return 'feature-flags';
    }

    public function page(): string
    {
        return 'Administration > Feature flags';
    }

    public function keys(): array
    {
        return array_keys($this->flags->definitions());
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return $this->flags->all();
    }

    public function environmentOverrides(?string $target = null): array
    {
        $this->assertTarget($target);

        return [];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        if ($check) {
            $this->flags->validate($changes);
        } else {
            $this->flags->save($changes);
        }

        return [self::done($check, 'Feature flags saved. Changes apply to the active configuration.')];
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
