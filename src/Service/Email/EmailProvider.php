<?php

declare(strict_types=1);

namespace App\Service\Email;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * One email service: how its YAML settings and its one credential become a
 * Symfony Mailer transport. Everything else (settings, credentials, sending,
 * recording) is shared, so a new provider is its Symfony Mailer bridge
 * package plus one class implementing this interface. Providers are found by
 * their service tag and listed in descending priority (#[AsTaggedItem]).
 *
 * The transport itself comes from the bridge's own factory: a provider builds
 * its DSN (AbstractEmailProvider) and never talks to the service itself.
 */
#[AutoconfigureTag(self::TAG)]
interface EmailProvider
{
    public const TAG = 'app.email_provider';

    /** The value of email_provider in YAML: a short lowercase code. */
    public function code(): string;

    public function label(): string;

    /** One line for the settings page: what the provider is and where to find its credential. */
    public function description(): string;

    /**
     * This provider's own YAML settings and their defaults. Keys start with
     * "email_<code>_" so they never collide with another provider's.
     *
     * @return array<string, mixed>
     */
    public function settingDefaults(): array;

    /**
     * Validates this provider's settings in any case, so a typo is caught
     * even while another provider is selected; $selected adds the checks
     * for values a send needs, such as a required domain.
     *
     * @param array<string, mixed> $values every email setting, defaults filled in
     *
     * @return array<string, mixed> this provider's settings, normalized
     *
     * @throws \InvalidArgumentException naming the first invalid setting
     */
    public function validate(array $values, bool $selected): array;

    /** The credential it needs, kept in config/secrets/email.yaml or the environment. */
    public function credential(): EmailCredential;

    /**
     * The transport for these settings, made by Symfony Mailer's factory
     * (which knows every installed bridge). $credential is null only when the
     * credential is optional (an SMTP relay without a password).
     *
     * @param array<string, mixed> $settings validated email settings
     *
     * @throws \InvalidArgumentException when the settings cannot make a transport
     */
    public function transport(Transport $factory, array $settings, #[\SensitiveParameter] ?string $credential): TransportInterface;

    /**
     * Adjusts a message for this service just before it is sent, for headers
     * only it understands. Most providers change nothing.
     *
     * @param array<string, mixed> $settings validated email settings
     */
    public function prepare(Email $email, array $settings): void;
}
