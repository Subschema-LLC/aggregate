<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

/**
 * A BigQuery or Google sign-in failure with a message safe to show an
 * administrator: it never contains credentials, tokens or exported values.
 */
final class BigQueryException extends \RuntimeException
{
}
