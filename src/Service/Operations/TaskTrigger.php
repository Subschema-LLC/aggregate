<?php

declare(strict_types=1);

namespace App\Service\Operations;

/**
 * What started a processing task: its scheduled command doing whatever is
 * due ("schedule"), someone running a command for a specific job ("command"),
 * or an administrator in the dashboard ("dashboard", with their username).
 */
final readonly class TaskTrigger
{
    public const SCHEDULE = 'schedule';
    public const COMMAND = 'command';
    public const DASHBOARD = 'dashboard';

    private function __construct(
        public string $source,
        public ?string $requestedBy,
    ) {
    }

    public static function schedule(): self
    {
        return new self(self::SCHEDULE, null);
    }

    public static function command(): self
    {
        return new self(self::COMMAND, null);
    }

    public static function dashboard(string $username): self
    {
        $username = AuditTrail::clean($username, 191);
        if ($username === null) {
            throw new \InvalidArgumentException('A dashboard task needs the administrator’s username.');
        }

        return new self(self::DASHBOARD, $username);
    }
}
