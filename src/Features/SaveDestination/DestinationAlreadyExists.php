<?php

declare(strict_types=1);

namespace App\Features\SaveDestination;

final class DestinationAlreadyExists extends \InvalidArgumentException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct(sprintf('Alert destination "%s" already exists.', $id));
    }
}
