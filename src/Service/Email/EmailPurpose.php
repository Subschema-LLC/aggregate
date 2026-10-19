<?php

declare(strict_types=1);

namespace App\Service\Email;

/**
 * Why a message is sent. The value is the subject of its processing task and
 * audit entry and the tag the provider files it under, so a purpose code is
 * all those records say about a message: never its recipient or content.
 * Each purpose has a text and an HTML template, templates/email/<value>.*.twig.
 */
enum EmailPurpose: string
{
    case Test = 'test';

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Test email',
        };
    }

    public function subject(string $brand): string
    {
        return match ($this) {
            self::Test => sprintf('Test email from %s', $brand),
        };
    }

    /** Whether it may be sent while email_enabled is off: a test checks the settings before email is turned on. */
    public function sendsWhileDisabled(): bool
    {
        return match ($this) {
            self::Test => true,
        };
    }
}
