<?php

declare(strict_types=1);

namespace App\Service\Email\Provider;

use App\Service\Email\AbstractEmailProvider;
use App\Service\Email\EmailCredential;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Mailer\Transport\Dsn;

/** SendGrid's v3 Mail Send API, through symfony/sendgrid-mailer. */
#[AsTaggedItem(priority: 40)]
final class SendgridProvider extends AbstractEmailProvider
{
    public const KEY_REGION = 'email_sendgrid_region';
    public const REGIONS = ['global', 'eu'];

    public function code(): string
    {
        return 'sendgrid';
    }

    public function label(): string
    {
        return 'SendGrid';
    }

    public function description(): string
    {
        return 'SendGrid’s Mail Send API. Use an API key with the Mail Send permission; an EU-pinned subuser sends through the EU region.';
    }

    public function settingDefaults(): array
    {
        return [self::KEY_REGION => 'global'];
    }

    public function validate(array $values, bool $selected): array
    {
        return [self::KEY_REGION => self::choice($values, self::KEY_REGION, self::REGIONS)];
    }

    public function credential(): EmailCredential
    {
        return new EmailCredential('sendgrid_api_key', 'API key', 'Settings → API Keys in SendGrid. Restricted access with only Mail Send is enough.');
    }

    protected function dsn(array $settings, #[\SensitiveParameter] ?string $credential): Dsn
    {
        $region = $settings[self::KEY_REGION];

        return new Dsn('sendgrid+api', 'default', $credential, null, null, $region === 'global' ? [] : ['region' => $region]);
    }
}
