<?php

declare(strict_types=1);

namespace App\Service\Operations;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Records administrator actions in the audit trail, under their area
 * (AuditTrailSettings::AREAS). The subscribers that watch dashboard requests,
 * sign-ins and console commands call this; see AdminActions for what each
 * route and command records.
 *
 * Recording never stops or changes the action: the action has already
 * happened, so a failed write is logged and the response goes on.
 */
class AdminAudit
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    /** Signed in, but not allowed to do this. */
    public const DENIED = 'denied';
    /** Recorded when it began, because the outcome is decided by another process (an update). */
    public const STARTED = 'started';

    public function __construct(
        private readonly AuditTrail $trail,
        private readonly LoggerInterface $logger,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    /**
     * @param string      $area    an AuditTrailSettings area
     * @param string      $action  a short code, such as user_created
     * @param string|null $actor   the username, or who ran a command
     * @param string|null $subject what was acted on: a user, website or group
     * @param string|null $details setting names and summaries, never values
     * @param bool        $always  record even when the area is switched off
     */
    public function record(string $area, string $action, string $outcome, ?string $actor, ?string $subject = null, ?string $details = null, bool $always = false): void
    {
        try {
            $this->trail->record($area, $action, $outcome, $this->now(), $subject, $actor, null, $details, $always);
        } catch (\Throwable $e) {
            $this->logger->warning('An administrator action could not be recorded in the audit trail.', ['area' => $area, 'action' => $action, 'exception' => $e]);
        }
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock !== null
            ? \DateTimeImmutable::createFromInterface($this->clock->now())
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
