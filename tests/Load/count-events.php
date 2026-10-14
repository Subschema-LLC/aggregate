<?php

/*
 * Prints the number of stored events by privacy mode as JSON, for the load
 * test report. Run from the installation's root after a load test:
 *
 *     php tests/Load/count-events.php > stored.json
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
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

echo json_encode(['total' => array_sum($byMode), 'by_mode' => $byMode], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";

$kernel->shutdown();
