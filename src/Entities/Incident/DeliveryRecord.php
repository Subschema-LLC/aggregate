<?php

declare(strict_types=1);

namespace App\Entities\Incident;

/** The key of one destination's delivery record for a rule and website. */
final class DeliveryRecord
{
    /** Long website tokens are hashed so the key stays within 191 characters. */
    public static function subject(
        string $ruleId,
        string $websiteToken,
        string $destinationId,
    ): string
    {
        $subject = $ruleId.':'.$destinationId.':'.$websiteToken;
        if (strlen($subject) <= 191) {
            return $subject;
        }

        return $ruleId.':'.$destinationId.':#'.hash('sha256', $websiteToken);
    }
}
