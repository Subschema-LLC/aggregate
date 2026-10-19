<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Service\Email\Provider\DsnProvider;
use App\Service\Email\Provider\MailgunProvider;
use App\Service\Email\Provider\PostmarkProvider;
use App\Service\Email\Provider\SendgridProvider;
use App\Service\Email\Provider\SmtpProvider;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The email providers this installation offers, by code, in menu order. */
final class EmailProviders
{
    /** @var array<string, EmailProvider> */
    private array $providers = [];

    /** @param iterable<EmailProvider> $providers */
    public function __construct(#[AutowireIterator(EmailProvider::TAG)] iterable $providers)
    {
        foreach ($providers as $provider) {
            $code = $provider->code();
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $code) !== 1 || isset($this->providers[$code])) {
                throw new \LogicException(sprintf('Email provider code "%s" is invalid or used twice.', $code));
            }
            $this->providers[$code] = $provider;
        }
    }

    /** The providers that ship with the application, for tests and tools that run without the container. */
    public static function builtIn(): self
    {
        return new self([new PostmarkProvider(), new SendgridProvider(), new MailgunProvider(), new SmtpProvider(), new DsnProvider()]);
    }

    /** @return array<string, EmailProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    public function has(string $code): bool
    {
        return isset($this->providers[$code]);
    }

    public function get(string $code): EmailProvider
    {
        return $this->providers[$code] ?? throw new \InvalidArgumentException(sprintf('Unknown email provider "%s".', $code));
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->providers);
    }
}
