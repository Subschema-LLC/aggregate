<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\Settings\AbstractSection;
use App\Service\SiteScriptConfig;

/**
 * A website's built-in consent banner, as the Consent manager page saves it:
 * each key replaces its saved value, null removes an optional one, and the
 * result is checked as a whole. Name the website with --site=<script id>.
 */
final class ConsentManagerSection extends AbstractSection
{
    public const KEYS = ['enabled', 'name', 'privacy_policy_url', 'consent_type', 'regions', 'default_region', 'default_granted',
        'precheck_categories', 'categories', 'respect_gpc', 'external_tags', 'text', 'theme', 'buttons', 'layout', 'close_button'];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly SiteScriptConfig $sites,
    ) {
    }

    public function name(): string
    {
        return 'consent-manager';
    }

    public function page(): string
    {
        return 'Collection > Consent manager lite';
    }

    public function target(): string
    {
        return self::TARGET_SITE;
    }

    public function keys(): array
    {
        return self::KEYS;
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return $this->sites->consent((string) $target);
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
            $this->sites->previewConsent((string) $target, $changes);

            return [self::done(true, '')];
        }
        $this->sites->saveConsent((string) $target, $changes);

        return ['Consent manager settings saved to YAML. The hosted banner changes for visitors within five minutes; download a self-hosted copy again.'];
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
