<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Service\AggregateConfigLoader;

/**
 * Shared checks for sections: unknown keys, targets and environment
 * overrides read the way the pages read them.
 */
abstract class AbstractSection implements SettingsSection
{
    public function target(): ?string
    {
        return self::TARGET_NONE;
    }

    public function environmentOverrides(?string $target = null): array
    {
        $this->assertTarget($target);

        return array_values(array_filter($this->keys(), fn (string $key): bool => $this->config()->hasEnvironmentOverride($key)));
    }

    /** The configuration the section's environment overrides are read from. */
    abstract protected function config(): AggregateConfigLoader;

    /**
     * Refuses keys the section does not have.
     *
     * @param array<string, mixed> $changes
     */
    protected function assertKnown(array $changes): void
    {
        $unknown = array_diff(array_keys($changes), $this->keys());
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                '%s %s not in the %s settings, which are: %s.',
                implode(', ', $unknown),
                count($unknown) === 1 ? 'is' : 'are',
                $this->name(),
                implode(', ', $this->keys()),
            ));
        }
        if ($changes === []) {
            throw new \InvalidArgumentException('Name at least one setting to change.');
        }
    }

    /** Refuses a target the section does not take, and requires one it needs. */
    protected function assertTarget(?string $target): void
    {
        if ($this->target() === self::TARGET_NONE && $target !== null) {
            throw new \InvalidArgumentException(sprintf('The %s settings belong to the whole installation; leave out --scope and --site.', $this->name()));
        }
        if ($this->target() === self::TARGET_SITE && ($target === null || $target === '')) {
            throw new \InvalidArgumentException(sprintf('The %s settings belong to one website: name it with --site=<script id> (app:tag-manager:sites lists them).', $this->name()));
        }
    }

    /**
     * The note the pages show for values an environment variable keeps.
     *
     * @param list<string> $keys
     */
    protected static function overriddenNote(array $keys): ?string
    {
        if ($keys === []) {
            return null;
        }

        return sprintf(
            '%s %s set by %s, which %s precedence, so %s left unchanged.',
            implode(', ', $keys),
            count($keys) === 1 ? 'is' : 'are',
            count($keys) === 1 ? 'an environment variable' : 'environment variables',
            count($keys) === 1 ? 'takes' : 'take',
            count($keys) === 1 ? 'it was' : 'they were',
        );
    }

    /** "Checked; nothing was saved." for --check, or the message for a save. */
    protected static function done(bool $check, string $saved): string
    {
        return $check ? 'The values are valid. Nothing was saved (--check).' : $saved;
    }
}
