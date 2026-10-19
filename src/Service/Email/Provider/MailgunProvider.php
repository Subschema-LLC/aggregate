<?php

declare(strict_types=1);

namespace App\Service\Email\Provider;

use App\Service\Email\AbstractEmailProvider;
use App\Service\Email\EmailCredential;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;

/** Mailgun's Messages API, through symfony/mailgun-mailer. */
#[AsTaggedItem(priority: 30)]
final class MailgunProvider extends AbstractEmailProvider
{
    public const KEY_DOMAIN = 'email_mailgun_domain';
    public const KEY_REGION = 'email_mailgun_region';
    public const REGIONS = ['us', 'eu'];

    public function code(): string
    {
        return 'mailgun';
    }

    public function label(): string
    {
        return 'Mailgun';
    }

    public function description(): string
    {
        return 'Mailgun’s Messages API for one sending domain. A domain created in Mailgun’s EU region sends through the EU region.';
    }

    public function settingDefaults(): array
    {
        return [self::KEY_DOMAIN => '', self::KEY_REGION => 'us'];
    }

    public function validate(array $values, bool $selected): array
    {
        $domain = strtolower(self::text($values, self::KEY_DOMAIN, 253));
        if ($domain !== '' && !self::isHostName($domain)) {
            throw new \InvalidArgumentException(self::KEY_DOMAIN.' must be a sending domain, such as mg.example.com.');
        }
        if ($selected && $domain === '') {
            throw new \InvalidArgumentException('Enter the Mailgun sending domain, such as mg.example.com.');
        }

        return [self::KEY_DOMAIN => $domain, self::KEY_REGION => self::choice($values, self::KEY_REGION, self::REGIONS)];
    }

    public function credential(): EmailCredential
    {
        return new EmailCredential('mailgun_api_key', 'API key', 'A Mailgun sending API key for this domain, or the account’s private API key.');
    }

    protected function dsn(array $settings, #[\SensitiveParameter] ?string $credential): Dsn
    {
        return new Dsn('mailgun+api', 'default', $credential, $settings[self::KEY_DOMAIN], null, ['region' => $settings[self::KEY_REGION]]);
    }

    /** Sign-in messages carry no open pixels or rewritten links, whatever the domain's tracking settings. */
    public function prepare(Email $email, array $settings): void
    {
        $email->getHeaders()->addTextHeader('o:tracking', 'no');
    }
}
