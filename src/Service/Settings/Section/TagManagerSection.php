<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\Settings\AbstractSection;
use App\Service\TagManagerSettings;

/**
 * Tag manager settings, as the Tag manager page saves them: whether it is
 * on, its variables and its tags, checked as a whole. With --site=<script id>
 * they are the website's own; without, the installation's defaults. While
 * custom JavaScript tags are turned off, none can be added or changed.
 */
final class TagManagerSection extends AbstractSection
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly TagManagerSettings $settings,
    ) {
    }

    public function name(): string
    {
        return 'tag-manager';
    }

    public function page(): string
    {
        return 'Collection > Tag manager lite';
    }

    public function target(): string
    {
        return self::TARGET_SITE;
    }

    public function keys(): array
    {
        return array_keys(TagManagerSettings::DEFAULTS);
    }

    public function values(?string $target = null): array
    {
        return $this->settings->all(self::site($target));
    }

    public function environmentOverrides(?string $target = null): array
    {
        return [];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertKnown($changes);
        $site = self::site($target);
        $validated = TagManagerSettings::validate([...$this->settings->all($site), ...$changes]);
        $this->settings->assertNoNewCustomCode($validated, $site);
        if ($check) {
            return [self::done(true, '')];
        }
        $this->settings->save($validated, $site);

        return ['Script settings saved to YAML. Changes reach visitors within five minutes.', ...$this->settings->emitWarnings($validated)];
    }

    /** The website's script id, or null for the installation's defaults, as the page's website menu allows. */
    private static function site(?string $target): ?string
    {
        return $target === null || $target === '' ? null : $target;
    }

    protected function assertTarget(?string $target): void
    {
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
