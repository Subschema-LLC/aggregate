<?php

declare(strict_types=1);

namespace App\Service;

/** Named-zone conversion failed because MySQL's time zone tables are not loaded. */
final class MysqlTimeZoneTablesMissing extends \RuntimeException
{
    public function __construct(private readonly string $command, string $message)
    {
        parent::__construct($message);
    }

    public function command(): string
    {
        return $this->command;
    }
}
