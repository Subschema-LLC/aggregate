<?php

/*
 * Fills the events table with synthetic rows for the database headroom test,
 * so indexes, inserts and the BI views work against a table the size of a
 * busy installation's. Rows go in with engine-native bulk inserts of up to a
 * million at a time, then the planner statistics are refreshed (and, on
 * PostgreSQL, the table vacuumed).
 *
 *     php tests/Load/preload-events.php /srv/head 10000000
 *
 * The rows belong to the load test website, spread over the last 90 days in
 * whole hours; every fifth is enhanced, with a visitor and session from a
 * fixed pool. PostgreSQL and MySQL only. Never point it at real data.
 */

use App\Kernel;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Dotenv\Dotenv;

$root = rtrim($argv[1] ?? '', '/');
$rows = (int) ($argv[2] ?? 0);
if ($root === '' || $rows < 1) {
    fwrite(STDERR, "Usage: php tests/Load/preload-events.php APP_ROOT ROWS\n");
    exit(2);
}

require $root.'/vendor/autoload.php';
(new Dotenv())->bootEnv($root.'/.env');
$kernel = new Kernel((string) ($_SERVER['APP_ENV'] ?? 'prod'), (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();

/** @var Doctrine\DBAL\Connection $connection */
$connection = $kernel->getContainer()->get('doctrine')->getConnection();
$platform = $connection->getDatabasePlatform();
$token = trim((string) file_get_contents($root.'/var/load-test-token'));

$pages = ['/', '/pricing', '/about', '/contact', '/blog/', '/blog/getting-started', '/blog/release-notes',
    '/docs/install', '/docs/configuration', '/products/widget', '/products/gadget', '/checkout', '/checkout/complete'];
$quoted = implode(', ', array_map(static fn (string $page): string => $connection->quote($page), $pages));
$count = count($pages);
$columns = 'website_token, event_name, url, privacy_mode, device_class, viewport_bucket, consent_state, visitor_id, session_id, screen_width, created_at';

if ($platform instanceof PostgreSQLPlatform) {
    $select = static fn (): string => <<<SQL
        SELECT :token, 'view', (ARRAY[{$quoted}])[1 + g % {$count}],
            CASE WHEN g % 5 = 0 THEN 'enhanced' ELSE 'anonymous' END,
            (ARRAY['desktop', 'mobile', 'tablet'])[1 + g % 3],
            (ARRAY['large', 'small', 'medium'])[1 + g % 3],
            CASE WHEN g % 5 = 0 THEN 'granted' ELSE 'denied' END,
            CASE WHEN g % 5 = 0 THEN 'preload-visitor-' || (g % 200000) END,
            CASE WHEN g % 5 = 0 THEN 'preload-session-' || (g % 1000000) END,
            CASE WHEN g % 5 = 0 THEN 1440 END,
            date_trunc('hour', now() AT TIME ZONE 'UTC') - make_interval(hours => (g % 2160)::int)
        FROM generate_series(CAST(:first AS bigint), CAST(:last AS bigint)) AS g
        SQL;
    // VACUUM also marks the pages all-visible, so autovacuum does not
    // start on the new rows in the middle of a measurement.
    $analyze = 'VACUUM (ANALYZE) events';
} elseif ($platform instanceof AbstractMySQLPlatform) {
    $connection->executeStatement('SET SESSION cte_max_recursion_depth = 1000001');
    $select = static fn (): string => <<<SQL
        WITH RECURSIVE seq (g) AS (SELECT CAST(:first AS UNSIGNED) UNION ALL SELECT g + 1 FROM seq WHERE g < :last)
        SELECT :token, 'view', ELT(1 + g % {$count}, {$quoted}),
            IF(g % 5 = 0, 'enhanced', 'anonymous'),
            ELT(1 + g % 3, 'desktop', 'mobile', 'tablet'),
            ELT(1 + g % 3, 'large', 'small', 'medium'),
            IF(g % 5 = 0, 'granted', 'denied'),
            IF(g % 5 = 0, CONCAT('preload-visitor-', g % 200000), NULL),
            IF(g % 5 = 0, CONCAT('preload-session-', g % 1000000), NULL),
            IF(g % 5 = 0, 1440, NULL),
            DATE_SUB(DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:00:00'), INTERVAL (g % 2160) HOUR)
        FROM seq
        SQL;
    $analyze = 'ANALYZE TABLE events';
} else {
    fwrite(STDERR, "The headroom test runs on PostgreSQL and MySQL only.\n");
    exit(2);
}

$started = microtime(true);
for ($first = 1; $first <= $rows; $first += 1_000_000) {
    $last = min($rows, $first + 999_999);
    $connection->executeStatement("INSERT INTO events ({$columns}) ".$select(), ['token' => $token, 'first' => $first, 'last' => $last]);
    printf("%d of %d rows, %.0f s\n", $last, $rows, microtime(true) - $started);
}
$connection->executeQuery($analyze)->free();
printf("Preloaded %d rows in %.0f s\n", $rows, microtime(true) - $started);
