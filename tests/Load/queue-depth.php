<?php

/*
 * Prints how many messages wait in the Messenger queue (messenger_messages,
 * queue "default"), so a load test can let consumers finish before counting
 * stored events. Prints 0 when the queue table does not exist (sync://).
 *
 *     php tests/Load/queue-depth.php /srv/head
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
try {
    echo (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'default'"), "\n";
} catch (Doctrine\DBAL\Exception\TableNotFoundException) {
    echo "0\n";
}
