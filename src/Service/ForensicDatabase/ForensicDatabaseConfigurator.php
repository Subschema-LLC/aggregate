<?php

declare(strict_types=1);

namespace App\Service\ForensicDatabase;

use App\Service\LocalEnvironmentFile;
use App\Service\ReportingDatabase\ReportingDatabaseConfigurator;

/**
 * Saves the forensic database connection, FORENSIC_DATABASE_URL, to
 * .env.local for the admin page and app:forensic-database:configure, the way
 * the reporting database's is saved: tested first (connect, create and drop a
 * table, refuse the application and reporting databases, check that the
 * database may receive forensic copies), never shown back.
 */
class ForensicDatabaseConfigurator extends ReportingDatabaseConfigurator
{
    public const VARIABLE = ForensicDatabaseConnection::VARIABLE;
    protected const COMMENT = 'Forensic database for investigating problems; saved by Aggregate after a successful connection test.';

    public function __construct(LocalEnvironmentFile $environment, ForensicDatabaseConnection $current)
    {
        parent::__construct($environment, $current);
    }
}
