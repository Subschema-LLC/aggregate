<?php

/*
 * Prints the number of stored events by privacy mode, and the disk space the
 * events table uses with its indexes, as JSON for the load test report. On
 * SQLite the space is the whole database file's used pages. Pass the
 * installation's root, or run it from inside one:
 *
 *     php tests/Load/count-events.php [/srv/aggregate] > stored.json
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

$root = rtrim($argv[1] ?? dirname(__DIR__, 2), '/');
require $root.'/vendor/autoload.php';

(new Dotenv())->bootEnv($root.'/.env');

$kernel = new Kernel((string) ($_SERVER['APP_ENV'] ?? 'prod'), (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();

/** @var Doctrine\DBAL\Connection $connection */
$connection = $kernel->getContainer()->get('doctrine')->getConnection();

$byMode = [];
foreach ($connection->fetchAllAssociative('SELECT privacy_mode, COUNT(*) AS events FROM events GROUP BY privacy_mode') as $row) {
    $byMode[(string) $row['privacy_mode']] = (int) $row['events'];
}

$platform = $connection->getDatabasePlatform();
$bytes = null;
try {
    if ($platform instanceof Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
        $bytes = (int) $connection->fetchOne("SELECT pg_total_relation_size('events')");
    } elseif ($platform instanceof Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
        // InnoDB's sizes are estimates that ANALYZE TABLE refreshes.
        $connection->executeQuery('ANALYZE TABLE events')->free();
        $bytes = (int) $connection->fetchOne("SELECT data_length + index_length FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'events'");
    } elseif ($platform instanceof Doctrine\DBAL\Platforms\SQLitePlatform) {
        $bytes = ((int) $connection->fetchOne('PRAGMA page_count') - (int) $connection->fetchOne('PRAGMA freelist_count')) * (int) $connection->fetchOne('PRAGMA page_size');
    }
} catch (Doctrine\DBAL\Exception) {
    $bytes = null;
}

echo json_encode(['total' => array_sum($byMode), 'by_mode' => $byMode, 'bytes' => $bytes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";

$kernel->shutdown();
