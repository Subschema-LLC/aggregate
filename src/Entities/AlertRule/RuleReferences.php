<?php

declare(strict_types=1);

namespace App\Entities\AlertRule;

/**
 * What a rule refers to outside itself: the website or group it covers and the
 * retention its window must fit. Each method throws \InvalidArgumentException,
 * with a message for the person saving the rule, when the reference fails.
 */
interface RuleReferences
{
    public function assertCoversExist(string $covers): void;

    public function assertWindowFitsRetention(
        string $source,
        string $privacyMode,
        string $window,
    ): void;
}
