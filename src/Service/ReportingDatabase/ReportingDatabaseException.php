<?php

declare(strict_types=1);

namespace App\Service\ReportingDatabase;

use App\Service\ViewSync\ViewSyncException;

/**
 * A reporting database problem with a message that is safe to show to an
 * administrator: it names the view, table or setting, never a password or a
 * value from the data.
 */
final class ReportingDatabaseException extends ViewSyncException
{
}
