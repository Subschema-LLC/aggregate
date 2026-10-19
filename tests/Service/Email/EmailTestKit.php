<?php

declare(strict_types=1);

namespace App\Tests\Service\Email;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridTransportFactory;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\NullTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

/** Symfony Mailer's transport factory with the bridges this application ships, over a mock HTTP client. */
final class EmailTestKit
{
    public static function transports(MockHttpClient $client, ?EventDispatcherInterface $dispatcher = null): Transport
    {
        return new Transport([
            new PostmarkTransportFactory($dispatcher, $client),
            new SendgridTransportFactory($dispatcher, $client),
            new MailgunTransportFactory($dispatcher, $client),
            new NullTransportFactory($dispatcher, $client),
            new EsmtpTransportFactory($dispatcher, $client),
        ]);
    }
}
