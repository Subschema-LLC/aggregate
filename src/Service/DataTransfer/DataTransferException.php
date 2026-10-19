<?php

declare(strict_types=1);

namespace App\Service\DataTransfer;

/**
 * A transfer problem the operator can act on. The message never includes row
 * values, identifiers, or database URLs.
 */
final class DataTransferException extends \RuntimeException
{
}
