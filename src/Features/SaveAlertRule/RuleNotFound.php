<?php

declare(strict_types=1);

namespace App\Features\SaveAlertRule;

/** Editing a rule that does not exist, for example one deleted meanwhile. */
final class RuleNotFound extends \InvalidArgumentException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct(sprintf('Alert rule "%s" does not exist.', $id));
    }
}
