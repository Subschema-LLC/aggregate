<?php

declare(strict_types=1);

namespace App\Features\Alerts\SendTestAlert;

/**
 * What a test alert takes from outside: a random ID, unlike a real alert's
 * derived one, the completed hour it reports, and the installation it names.
 */
final class TestAlertId
{
    public function __construct(
        public readonly string $id,
        public readonly \DateTimeImmutable $windowEnd,
        public readonly string $installation,
    ) {
    }
}
