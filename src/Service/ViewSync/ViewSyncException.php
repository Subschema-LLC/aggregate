<?php

declare(strict_types=1);

namespace App\Service\ViewSync;

/**
 * A view sync failure with a message that is safe to store and show an
 * administrator: it names the view, column or setting, never a credential or
 * a value from the data. ViewReader throws it when a view cannot be read; the
 * destinations' own exceptions, BigQueryException and
 * ReportingDatabaseException, extend it.
 */
class ViewSyncException extends \RuntimeException
{
}
