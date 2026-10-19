<?php

declare(strict_types=1);

namespace App\Service\Email\Provider;

use App\Service\Email\EmailCredential;
use App\Service\Email\EmailProvider;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Mailer\Exception\ExceptionInterface as MailerException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * Any transport Symfony Mailer can build from a DSN: another service whose
 * bridge package is installed (Amazon SES, Brevo, Resend and others), or
 * failover(…) and roundrobin(…) between several. The whole DSN is the
 * credential, since it carries the key.
 */
#[AsTaggedItem(priority: 10)]
final class DsnProvider implements EmailProvider
{
    public function code(): string
    {
        return 'dsn';
    }

    public function label(): string
    {
        return 'Another service (mailer DSN)';
    }

    public function description(): string
    {
        return 'Any service Symfony Mailer supports through an installed bridge package, or failover between two services, written as one mailer DSN.';
    }

    public function settingDefaults(): array
    {
        return [];
    }

    public function validate(array $values, bool $selected): array
    {
        return [];
    }

    public function credential(): EmailCredential
    {
        return new EmailCredential('dsn', 'Mailer DSN', 'For example ses+api://KEY:SECRET@default?region=eu-west-1, or failover(postmark+api://TOKEN@default sendgrid+api://KEY@default).');
    }

    public function transport(Transport $factory, array $settings, #[\SensitiveParameter] ?string $credential): TransportInterface
    {
        try {
            return $factory->fromString((string) $credential);
        } catch (MailerException $e) {
            // The factory's messages name schemes and packages, never the DSN's key.
            throw new \InvalidArgumentException('The mailer DSN cannot be used: '.$e->getMessage(), 0, $e);
        }
    }

    public function prepare(Email $email, array $settings): void
    {
    }
}
