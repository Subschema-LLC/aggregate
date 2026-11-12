<?php

declare(strict_types=1);

namespace App\Service\Settings;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One group of settings that a dashboard page edits, as app:config reaches
 * them: the same YAML keys, checked by the same services the page uses and
 * saved the way the page saves them, so the dashboard, YAML and the command
 * line can never disagree about what a setting accepts.
 *
 * A section names its keys as they appear in YAML. Settings that live in a
 * website's or group's own file take a target: a scope (website or group,
 * as on the Collection controls and Data model pages) or a website (its
 * script id, as on the Tag manager and Consent manager pages).
 */
#[AutoconfigureTag(self::TAG)]
interface SettingsSection
{
    public const TAG = 'app.settings_section';

    /** No target: the deployment's settings. */
    public const TARGET_NONE = null;
    /** website:<script id>, group:<id> or deployment, as the scope menu of the page. */
    public const TARGET_SCOPE = 'scope';
    /** A website's script id, as the website menu of the page. */
    public const TARGET_SITE = 'site';

    /** The name on the command line: lower-case words joined by hyphens. */
    public function name(): string;

    /** The dashboard page, and its section, that changes the same settings. */
    public function page(): string;

    /** The kind of target the section takes, one of the TARGET_ constants. */
    public function target(): ?string;

    /**
     * The settings' names, as the keys they are saved under.
     *
     * @return list<string>
     */
    public function keys(): array;

    /**
     * The values in effect, as the page shows them: environment variables,
     * then YAML, then defaults.
     *
     * @return array<string, mixed>
     */
    public function values(?string $target = null): array;

    /**
     * Keys whose environment variable takes precedence now. The page locks
     * those fields, and app:config leaves them unchanged the same way.
     *
     * @return list<string>
     */
    public function environmentOverrides(?string $target = null): array;

    /**
     * Checks the changes the way the page checks a submission and, unless
     * $check, saves them as the page saves them. Keys left out keep their
     * values.
     *
     * @param array<string, mixed> $changes values by key, from keys()
     * @param bool $confirmed whether the operator confirmed a change the page asks to confirm
     *
     * @return list<string> what happened, including the page's warnings
     *
     * @throws \InvalidArgumentException with the page's message when a value is
     *                                   not accepted; then nothing is saved
     */
    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array;
}
