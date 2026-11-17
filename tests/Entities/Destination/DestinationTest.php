<?php

declare(strict_types=1);

namespace App\Tests\Entities\Destination;

use App\Entities\Destination\Destination;
use App\Entities\Destination\WebhookUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DestinationTest extends TestCase
{
    public function testAWebhookKeepsItsUrlAndHost(): void
    {
        $destination = Destination::fromInput([
            'label' => ' Ops Hub ',
            'type' => 'WEBHOOK',
            'url' => ' https://Hooks.Example.com/alerts ',
        ]);

        self::assertSame([
            'id' => 'ops-hub',
            'label' => 'Ops Hub',
            'type' => 'webhook',
            'url' => 'https://Hooks.Example.com/alerts',
            'host' => 'hooks.example.com',
        ], $destination->toArray());
    }

    public function testAnEmailDestinationAcceptsOlderRecipientKeys(): void
    {
        $destination = Destination::fromInput([
            'id' => 'team',
            'label' => 'Team',
            'type' => 'email',
            'emails' => "ops@example.com;\nOPS@example.com, dev@example.com",
        ]);

        self::assertSame([
            'id' => 'team',
            'label' => 'Team',
            'type' => 'email',
            'recipients' => ['ops@example.com', 'dev@example.com'],
        ], $destination->toArray());
    }

    /** @param array<string, mixed> $candidate */
    #[DataProvider('invalidDestinations')]
    public function testRefusesInvalidDestinations(array $candidate, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        Destination::fromInput($candidate);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidDestinations(): iterable
    {
        $hook = ['label' => 'Hook', 'type' => 'webhook'];
        $mail = ['label' => 'Mail', 'type' => 'email'];

        yield 'unknown field' => [[...$hook, 'secret' => 'x'], 'Unknown alert destination field'];
        yield 'no label' => [['type' => 'email'], 'A destination label is required'];
        yield 'two-line label' => [[...$hook, 'label' => "a\nb"], 'A destination label is required'];
        yield 'bad id' => [[...$hook, 'id' => 'Bad ID'], 'A destination ID must be 1 to 63'];
        yield 'bad type' => [[...$hook, 'type' => 'sms'], 'Destination type must be webhook or email.'];
        yield 'http' => [[...$hook, 'url' => 'http://example.com'], 'Webhook URLs must use HTTPS.'];
        yield 'credentials' => [[...$hook, 'url' => 'https://a:b@example.com'], 'user name or password'];
        yield 'fragment' => [[...$hook, 'url' => 'https://example.com/#x'], 'fragment (#)'];
        yield 'private' => [[...$hook, 'url' => 'https://10.0.0.5/x'], 'cannot target localhost'];
        yield 'webhook recipients' => [
            [...$hook, 'url' => 'https://example.com', 'recipients' => ['a@example.com']],
            'A webhook destination cannot list email recipients.',
        ];
        yield 'email url' => [
            [...$mail, 'url' => 'https://example.com', 'recipients' => ['a@example.com']],
            'An email destination cannot have a webhook URL.',
        ];
        yield 'no recipients' => [[...$mail, 'recipients' => ' , '], 'Enter at least one recipient'];
        yield 'bad recipient' => [[...$mail, 'recipients' => ['nope']], 'must be a valid email address'];
        yield 'too many' => [
            [...$mail, 'recipients' => array_map(static fn (int $i) => "u$i@example.com", range(1, 51))],
            'at most 50 recipients',
        ];
    }

    #[DataProvider('hosts')]
    public function testRecognisesPrivateAndLocalHosts(string $host, bool $private): void
    {
        self::assertSame($private, WebhookUrl::isPrivateOrLocalHost($host));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function hosts(): iterable
    {
        yield 'localhost' => ['localhost', true];
        yield 'local suffix' => ['printer.local', true];
        yield 'loopback' => ['127.0.0.1', true];
        yield 'mapped loopback' => ['::ffff:127.0.0.1', true];
        yield 'carrier-grade nat' => ['100.64.0.1', true];
        yield 'link-local ipv6' => ['fe80::1', true];
        yield 'shorthand ipv4' => ['127.1', true];
        yield 'hex ipv4' => ['0x7f000001', true];
        yield 'public ipv4' => ['93.184.216.34', false];
        yield 'public name' => ['hooks.example.com', false];
    }
}
