<?php

declare(strict_types=1);

namespace App\Service\Email\Provider;

use App\Service\Email\EmailCredential;
use App\Service\Email\EmailProvider;
use App\Service\Email\EmailSettingValue;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Resend's Emails API, through symfony/resend-mailer. */
#[AsTaggedItem(priority: 25)]
final class ResendProvider implements EmailProvider
{
    public const KEY_DOMAIN = 'email_resend_domain';

    public function code(): string
    {
        return 'resend';
    }

    public function label(): string
    {
        return 'Resend';
    }

    public function description(): string
    {
        return 'Resend’s Emails API for one verified sending domain. Use a sending API key scoped to that domain, or a full-access API key.';
    }

    public function settingDefaults(): array
    {
        return [self::KEY_DOMAIN => ''];
    }

    public function validate(array $values, bool $selected): array
    {
        $domain = strtolower(EmailSettingValue::text($values, self::KEY_DOMAIN, 253));
        if ($domain !== '' && !EmailSettingValue::isHostName($domain)) {
            throw new \InvalidArgumentException(self::KEY_DOMAIN.' must be a sending domain, such as send.example.com.');
        }
        if ($selected && $domain === '') {
            throw new \InvalidArgumentException('Enter the Resend sending domain, such as send.example.com.');
        }

        return [self::KEY_DOMAIN => $domain];
    }

    public function credential(): EmailCredential
    {
        return new EmailCredential('resend_api_key', 'API key', 'API Keys in Resend. Sending access for this domain is enough.');
    }

    public function transport(Transport $factory, array $settings, #[\SensitiveParameter] ?string $credential): TransportInterface
    {
        return $factory->fromDsnObject($this->dsn($settings, $credential));
    }

    /** @param array<string, mixed> $settings validated email settings */
    private function dsn(array $settings, #[\SensitiveParameter] ?string $credential): Dsn
    {
        return new Dsn('resend+api', 'default', $credential);
    }

    /**
     * Sends from the verified Resend sending domain while keeping the
     * configured sender local-part and display name, and leaves out the
     * message's purpose tag.
     *
     * symfony/resend-mailer sends a tag as {"X-Tag": "<value>"}, while Resend's
     * Emails API takes each tag as {"name": …, "value": …}. Rather than risk a
     * refused message, Resend messages go without the tag; the purpose is still
     * recorded with the task, and the X-Metadata-aggregate_task header still
     * ties Resend's events to it.
     */
    public function prepare(Email $email, array $settings): void
    {
        $headers = $email->getHeaders();
        foreach ($headers->all() as $header) {
            if ($header instanceof TagHeader) {
                $headers->remove($header->getName());
            }
        }

        $domain = $settings[self::KEY_DOMAIN] ?? '';
        if (!is_string($domain) || $domain === '') {
            return;
        }
        $from = $email->getFrom()[0] ?? null;
        $localPart = 'no-reply';
        $name = '';
        if ($from !== null) {
            $name = $from->getName();
            $at = strrpos($from->getAddress(), '@');
            if ($at !== false && $at > 0) {
                $localPart = substr($from->getAddress(), 0, $at);
            }
        }
        $email->from(new Address($localPart.'@'.$domain, $name));
    }
}
