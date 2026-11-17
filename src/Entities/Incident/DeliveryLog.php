<?php

declare(strict_types=1);

namespace App\Entities\Incident;

/**
 * Which alert each destination last received, and the record of every
 * delivery attempt. Together with the stored incident state this is the
 * outbox: each hourly check derives the current alert from the state again
 * and sends it to every destination whose last delivered alert differs.
 */
interface DeliveryLog
{
    public const OP_FIRING = 'alert_firing';
    public const OP_RECOVERED = 'alert_recovered';
    public const OP_TEST = 'alert_test';

    public const OUTCOME_SENT = 'sent';
    public const OUTCOME_SUPPRESSED = 'suppressed';
    public const OUTCOME_FAILED = 'failed';

    /** The ID of the last alert delivered under $subject, or null. */
    public function lastDelivered(string $subject): ?string;

    /** Records that $subject has the alert, so it is neither retried nor sent again. */
    public function markDelivered(string $subject, string $alertId, string $details): void;

    /** Records an attempt (sent, suppressed or failed) in the audit trail. */
    public function record(
        string $operation,
        string $outcome,
        string $ruleId,
        string $websiteHost,
        string $destinationLabel,
        string $details,
    ): void;
}
