<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * A section whose page also takes secrets, such as an email service's API
 * key. app:config reads a secret from standard input or a file, never from
 * the command line, stores it where the page stores it, and never prints it.
 */
interface SettingsWithSecrets
{
    /**
     * The secrets the section takes, by name, each with what it is.
     *
     * @return array<string, string>
     */
    public function secrets(): array;

    /**
     * Whether each secret is set, by name, never its value.
     *
     * @return array<string, bool>
     */
    public function secretsSet(?string $target = null): array;

    /**
     * Checks a secret as the page does and, unless $check, stores it; null
     * removes it.
     *
     * @return string what happened, without the secret
     *
     * @throws \InvalidArgumentException when the page would refuse it
     */
    public function setSecret(string $name, #[\SensitiveParameter] ?string $value, ?string $target = null, bool $check = false): string;
}
