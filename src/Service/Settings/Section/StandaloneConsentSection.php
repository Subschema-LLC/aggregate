<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\Settings\AbstractSection;
use App\Service\StandaloneConsentSettings;

/**
 * A website's standalone consent banner, as Setup's standalone banner page
 * saves it, checked as a whole. Name the website with --site=<script id>.
 */
final class StandaloneConsentSection extends AbstractSection
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly StandaloneConsentSettings $settings,
    ) {
    }

    public function name(): string
    {
        return 'standalone-consent';
    }

    public function page(): string
    {
        return 'Setup > Standalone consent banner';
    }

    public function target(): string
    {
        return self::TARGET_SITE;
    }

    public function keys(): array
    {
        return array_keys(StandaloneConsentSettings::validate([]));
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return $this->settings->get((string) $target);
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
        $candidate = [...$this->settings->get((string) $target), ...$changes];
        if ($check) {
            StandaloneConsentSettings::validate($candidate);

            return [self::done(true, '')];
        }
        $this->settings->save((string) $target, $candidate);

        return ['Standalone consent settings saved. Hosted scripts update for visitors within five minutes; replace downloaded snapshots separately.'];
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
