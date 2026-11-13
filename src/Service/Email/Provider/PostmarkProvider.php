<?php

declare(strict_types=1);

namespace App\Service\Email\Provider;

use App\Service\Email\EmailCredential;
use App\Service\Email\EmailProvider;
use App\Service\Email\EmailSettingValue;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/** Postmark's Email API, through symfony/postmark-mailer. */
#[AsTaggedItem(priority: 50)]
final class PostmarkProvider implements EmailProvider
{
    public const KEY_MESSAGE_STREAM = 'email_postmark_message_stream';

    public function code(): string
    {
        return 'postmark';
    }

    public function label(): string
    {
        return 'Postmark';
    }

    public function description(): string
    {
        return 'Postmark’s Email API. Use a Server API token from the API Tokens tab of the Postmark server that sends these messages.';
    }

    public function settingDefaults(): array
    {
        return [self::KEY_MESSAGE_STREAM => 'outbound'];
    }

    public function validate(array $values, bool $selected): array
    {
        $stream = EmailSettingValue::text($values, self::KEY_MESSAGE_STREAM, 100);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D', $stream) !== 1) {
            throw new \InvalidArgumentException(self::KEY_MESSAGE_STREAM.' must be the ID of a transactional message stream, such as outbound.');
        }

        return [self::KEY_MESSAGE_STREAM => $stream];
    }

    public function credential(): EmailCredential
    {
        return new EmailCredential('postmark_server_token', 'Server API token', 'From the Postmark server’s API Tokens tab. The account token does not send email.');
    }

    public function transport(Transport $factory, array $settings, #[\SensitiveParameter] ?string $credential): TransportInterface
    {
        return $factory->fromDsnObject($this->dsn($settings, $credential));
    }

    /** @param array<string, mixed> $settings validated email settings */
    private function dsn(array $settings, #[\SensitiveParameter] ?string $credential): Dsn
    {
        return new Dsn('postmark+api', 'default', $credential, null, null, ['message_stream' => $settings[self::KEY_MESSAGE_STREAM]]);
    }

    public function prepare(Email $email, array $settings): void
    {
    }
}
