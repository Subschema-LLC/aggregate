<?php

declare(strict_types=1);

namespace App\Service\Email\Provider;

use App\Service\Email\AbstractEmailProvider;
use App\Service\Email\EmailCredential;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Any SMTP server, such as a provider's SMTP relay or a local mail server.
 * Encryption is required unless turned off for a relay on the same machine:
 * "starttls" refuses a server that does not offer STARTTLS, rather than
 * sending a password and a message in the clear.
 */
#[AsTaggedItem(priority: 20)]
final class SmtpProvider extends AbstractEmailProvider
{
    public const KEY_HOST = 'email_smtp_host';
    public const KEY_PORT = 'email_smtp_port';
    public const KEY_USERNAME = 'email_smtp_username';
    public const KEY_ENCRYPTION = 'email_smtp_encryption';
    public const ENCRYPTION = [
        'starttls' => 'STARTTLS, required (usually port 587)',
        'tls' => 'TLS from the start (usually port 465)',
        'none' => 'None, for a relay on this server only',
    ];

    public function code(): string
    {
        return 'smtp';
    }

    public function label(): string
    {
        return 'SMTP server';
    }

    public function description(): string
    {
        return 'Any SMTP server: an email service’s SMTP relay, your organization’s mail server or a relay on this server.';
    }

    public function settingDefaults(): array
    {
        return [self::KEY_HOST => '', self::KEY_PORT => 587, self::KEY_USERNAME => '', self::KEY_ENCRYPTION => 'starttls'];
    }

    public function validate(array $values, bool $selected): array
    {
        $host = strtolower(self::text($values, self::KEY_HOST, 253));
        if ($host !== '' && !self::isHostName($host) && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException(self::KEY_HOST.' must be a host name or IP address, such as smtp.example.com.');
        }
        if ($selected && $host === '') {
            throw new \InvalidArgumentException('Enter the SMTP server’s host name, such as smtp.example.com.');
        }

        $port = $values[self::KEY_PORT] ?? 587;
        if (is_string($port) && preg_match('/^[0-9]{1,5}$/D', trim($port)) === 1) {
            $port = (int) trim($port);
        }
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(self::KEY_PORT.' must be a port number from 1 to 65535.');
        }

        return [
            self::KEY_HOST => $host,
            self::KEY_PORT => $port,
            self::KEY_USERNAME => self::text($values, self::KEY_USERNAME, 255),
            self::KEY_ENCRYPTION => self::choice($values, self::KEY_ENCRYPTION, array_keys(self::ENCRYPTION)),
        ];
    }

    public function credential(): EmailCredential
    {
        return new EmailCredential('smtp_password', 'Password', 'The SMTP password or API key used as the password. Leave it unset for a relay that needs none.', false);
    }

    public function transport(Transport $factory, array $settings, #[\SensitiveParameter] ?string $credential): TransportInterface
    {
        $transport = parent::transport($factory, $settings, $credential);
        if ($settings[self::KEY_ENCRYPTION] === 'starttls' && $transport instanceof EsmtpTransport) {
            $transport->setRequireTls(true);
        }

        return $transport;
    }

    protected function dsn(array $settings, #[\SensitiveParameter] ?string $credential): Dsn
    {
        $encryption = $settings[self::KEY_ENCRYPTION];
        $username = $settings[self::KEY_USERNAME];

        return new Dsn(
            $encryption === 'tls' ? 'smtps' : 'smtp',
            $settings[self::KEY_HOST],
            $username === '' ? null : $username,
            $credential,
            $settings[self::KEY_PORT],
            $encryption === 'none' ? ['auto_tls' => 'false'] : [],
        );
    }
}
