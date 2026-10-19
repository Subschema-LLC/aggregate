<?php

declare(strict_types=1);

namespace App\Service\Email;

/**
 * The outcome of one send. $messageId is the provider's ID for the message,
 * which finds it in the provider's activity log; $error is the redacted reason
 * a send failed, safe to show an administrator.
 */
final readonly class EmailResult
{
    private function __construct(
        public bool $sent,
        public ?int $taskId,
        public ?string $messageId,
        public ?string $error,
    ) {
    }

    public static function sent(?int $taskId, ?string $messageId): self
    {
        return new self(true, $taskId, $messageId, null);
    }

    public static function failed(?int $taskId, string $error): self
    {
        return new self(false, $taskId, null, $error);
    }
}
