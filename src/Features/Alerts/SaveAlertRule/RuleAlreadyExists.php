<?php

declare(strict_types=1);

namespace App\Features\Alerts\SaveAlertRule;

/** Creating a rule whose ID is taken. */
final class RuleAlreadyExists extends \InvalidArgumentException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct(sprintf(
            'Alert rule "%s" already exists. Choose another Rule ID or edit the existing rule.',
            $id,
        ));
    }
}
