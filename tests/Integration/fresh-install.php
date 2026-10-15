<?php

declare(strict_types=1);

/*
 * End-to-end fresh install on a real database, done the way an operator does it:
 * the browser setup page, /install, the dashboard, the collection API and the
 * maintenance commands, followed by checks on every reporting view.
 *
 * It changes this checkout (.env, .env.local, config/, var/), so it refuses to
 * run unless AGGREGATE_E2E_DISPOSABLE=1. CI runs it once per database engine:
 *
 *   AGGREGATE_E2E_DISPOSABLE=1 AGGREGATE_E2E_DRIVER=mysql|pgsql|sqlsrv|sqlite \
 *   AGGREGATE_E2E_DB_HOST=127.0.0.1 AGGREGATE_E2E_DB_PORT=3306 \
 *   AGGREGATE_E2E_DB_NAME=aggregate AGGREGATE_E2E_DB_USER=... AGGREGATE_E2E_DB_PASSWORD=... \
 *   [AGGREGATE_E2E_TRUST_CERTIFICATE=1] php tests/Integration/fresh-install.php
 *
 * On a database server, the reporting database sync step also needs an empty
 * database named like AGGREGATE_E2E_DB_NAME plus "_reporting", on the same
 * server, in which the same user can create, fill and drop tables.
 */

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

if (getenv('AGGREGATE_E2E_DISPOSABLE') !== '1') {
    fwrite(STDERR, "This test rewrites the checkout's configuration and data. Run it only in a disposable copy with AGGREGATE_E2E_DISPOSABLE=1.\n");
    exit(2);
}

$driver = (string) getenv('AGGREGATE_E2E_DRIVER');
$database = [
    'host' => (string) (getenv('AGGREGATE_E2E_DB_HOST') ?: '127.0.0.1'),
    'port' => (string) (getenv('AGGREGATE_E2E_DB_PORT') ?: ''),
    'name' => (string) getenv('AGGREGATE_E2E_DB_NAME'),
    'user' => (string) getenv('AGGREGATE_E2E_DB_USER'),
    'password' => (string) getenv('AGGREGATE_E2E_DB_PASSWORD'),
];
$port = (int) (getenv('AGGREGATE_E2E_HTTP_PORT') ?: 8765);
$base = 'http://127.0.0.1:'.$port;

final class E2E
{
    public static int $failures = 0;
    /** @var array<string, int|string> */
    public static array $summary = [];
    public static ?Process $server = null;
    public static string $root = '';

    public static function step(string $name, callable $body): mixed
    {
        $started = microtime(true);
        try {
            $result = $body();
            printf("  ok  %s (%.1fs)\n", $name, microtime(true) - $started);

            return $result;
        } catch (\Throwable $e) {
            self::fail($name, $e);
        }
    }

    public static function fail(string $name, \Throwable|string $problem): never
    {
        $message = $problem instanceof \Throwable ? get_class($problem).': '.$problem->getMessage() : $problem;
        printf("FAIL  %s\n      %s\n", $name, str_replace("\n", "\n      ", $message));
        $context = self::context();
        if ($context !== '') {
            echo "\n--- context ---\n".$context."\n";
        }
        if (getenv('GITHUB_ACTIONS') === 'true') {
            $annotation = $name.': '.$message.($context !== '' ? "\n\n".$context : '');
            // Annotations carry the failure to the checks API, where it can be read without the log.
            echo '::error title='.self::escape('Fresh install ('.getenv('AGGREGATE_E2E_DRIVER').'): '.$name, true).'::'.self::escape(substr($annotation, 0, 60000))."\n";
        }
        self::stop();
        exit(1);
    }

    public static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public static function stop(): void
    {
        self::$server?->stop(1);
    }

    private static function context(): string
    {
        $parts = [];
        if (self::$server !== null) {
            $log = array_filter(explode("\n", self::$server->getOutput().self::$server->getErrorOutput()), static fn (string $line): bool => $line !== '' && !preg_match('/ (Accepted|Closing)$/', $line) && !str_contains($line, ' [200]: '));
            if ($log !== []) {
                $parts[] = "web server:\n".implode("\n", array_slice($log, -30));
            }
        }
        foreach (glob(self::$root.'/var/log/*.log') ?: [] as $file) {
            $lines = array_slice(file($file, FILE_IGNORE_NEW_LINES) ?: [], -200);
            $errors = array_values(array_filter($lines, static fn (string $line): bool => (bool) preg_match('/"level_name":"(?:ERROR|CRITICAL|ALERT|EMERGENCY)"|PHP (?:Fatal|Warning)|Uncaught/', $line)));
            if ($errors !== []) {
                $parts[] = basename($file).":\n".implode("\n", array_map(static fn (string $line): string => substr($line, 0, 2000), array_slice($errors, -5)));
            }
        }

        return implode("\n\n", $parts);
    }

    private static function escape(string $value, bool $property = false): string
    {
        $value = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);

        return $property ? str_replace([':', ','], ['%3A', '%2C'], $value) : $value;
    }
}

E2E::$root = $root;
register_shutdown_function(static fn () => E2E::stop());

/** Run a console command the way an operator would, with the installation's own configuration. */
function console(string $root, array $arguments, ?string $input = null): string
{
    $process = new Process(['php', 'bin/console', ...$arguments, '--no-ansi'], $root, cleanEnvironment(), $input, 600);
    $process->run();
    if (!$process->isSuccessful()) {
        throw new \RuntimeException(sprintf("bin/console %s exited with %d:\n%s%s", implode(' ', $arguments), $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()));
    }

    return $process->getOutput();
}

/** Runs a console command that may fail; returns its exit code, output and error output. */
function consoleResult(string $root, array $arguments, ?string $input = null): array
{
    $process = new Process(['php', 'bin/console', ...$arguments, '--no-ansi'], $root, cleanEnvironment(), $input, 600);
    $process->run();

    return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
}

/** Without real variables, the installation reads .env and .env.local as it would on a web host. */
function cleanEnvironment(): array
{
    return ['APP_ENV' => false, 'APP_DEBUG' => false, 'APP_SECRET' => false, 'DATABASE_URL' => false, 'MESSENGER_TRANSPORT_DSN' => false, 'MAILER_DSN' => false, 'DASHBOARD_ENABLED' => false];
}

function pageProblem(HttpBrowser $browser): string
{
    $response = $browser->getInternalResponse();
    $text = preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $response->getContent())));

    return sprintf('HTTP %d at %s: %s', $response->getStatusCode(), $browser->getHistory()->current()->getUri(), substr(trim((string) $text), 0, 1500));
}

function connection(string $root): Connection
{
    $environment = (new Dotenv())->parse((string) file_get_contents($root.'/.env.local'));
    $url = str_replace('%kernel.project_dir%', $root, $environment['DATABASE_URL']);
    $scheme = ['mssql' => 'pdo_sqlsrv', 'mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite'];

    return DriverManager::getConnection((new DsnParser($scheme))->parse($url));
}

function hourAgo(int $hours): \DateTimeImmutable
{
    $time = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    return $time->setTime((int) $time->format('H'), 0)->modify(sprintf('-%d hours', $hours));
}

echo "Fresh install on {$driver}\n";

E2E::step('prepare a release-like checkout', static function () use ($root, $driver): void {
    E2E::check(in_array($driver, ['mysql', 'pgsql', 'sqlsrv', 'sqlite'], true), 'Set AGGREGATE_E2E_DRIVER to mysql, pgsql, sqlsrv or sqlite.');
    E2E::check(!is_file($root.'/.env.local'), '.env.local already exists; use a fresh checkout.');
    // The same defaults a release ZIP ships: no secret, so the setup page appears.
    file_put_contents($root.'/.env', "APP_ENV=prod\nAPP_DEBUG=0\nAPP_SECRET=\nTRUSTED_PROXIES=\nDATABASE_URL=\"sqlite:///%kernel.project_dir%/var/data.db\"\nMESSENGER_TRANSPORT_DSN=sync://\nMAILER_DSN=null://null\n");
    foreach (['config/aggregate.yaml', 'config/websites.yaml', 'SETUP-CODE.txt', 'var/data.db'] as $file) {
        @unlink($root.'/'.$file);
    }
    (new Process(['rm', '-rf', $root.'/var/cache/prod', $root.'/var/log']))->run();
});

E2E::$server = E2E::step('start the web server', static function () use ($root, $port, $base): Process {
    $server = new Process(['php', '-S', '127.0.0.1:'.$port, '-t', 'public', 'tests/Integration/router.php'], $root, cleanEnvironment() + ['PHP_CLI_SERVER_WORKERS' => '2'], null, null);
    $server->start();
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        if (@file_get_contents($base.'/api/health') !== false || isset($http_response_header)) {
            return $server;
        }
        usleep(100000);
    }
    throw new \RuntimeException('The PHP web server did not start.');
});

$browser = new HttpBrowser(HttpClient::create(['timeout' => 300, 'max_duration' => 600]));

E2E::step('setup page: connect the database', static function () use ($browser, $base, $root, $driver, $database): void {
    $crawler = $browser->request('GET', $base.'/');
    E2E::check($browser->getInternalResponse()->getStatusCode() === 200 && str_contains($crawler->filter('title')->text(), 'Set up'), 'Expected the setup page. '.pageProblem($browser));
    E2E::check($crawler->filter('fieldset[disabled]')->count() === 0, 'The server check blocked setup: '.$crawler->filter('.checks')->text(''));
    preg_match('/[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/', (string) @file_get_contents($root.'/SETUP-CODE.txt'), $code);
    E2E::check($code !== [], 'SETUP-CODE.txt was not created.');

    $form = $crawler->selectButton('Save and continue')->form();
    $form['setup_code'] = strtolower($code[0]);
    $form['driver']->select($driver);
    if ($driver !== 'sqlite') {
        $form['host'] = $database['host'];
        $form['port'] = $database['port'];
        $form['name'] = $database['name'];
        $form['user'] = $database['user'];
        $form['password'] = $database['password'];
        if ($driver === 'sqlsrv' && getenv('AGGREGATE_E2E_TRUST_CERTIFICATE') === '1') {
            $form['trust_certificate']->tick();
        }
    }
    $browser->submit($form);
    E2E::check(is_file($root.'/.env.local'), 'The setup page did not write .env.local. '.pageProblem($browser));
    // PostgreSQL keeps connections open between requests unless unticked.
    E2E::check(($driver === 'pgsql') === str_contains((string) file_get_contents($root.'/.env.local'), '&persistent=true'), 'Persistent connections in .env.local do not match the default for '.$driver.'.');
    E2E::check(str_ends_with((string) $browser->getHistory()->current()->getUri(), '/install'), 'Expected /install next. '.pageProblem($browser));
});

if (getenv('AGGREGATE_E2E_SQLSRV_NATIVE') === '1') {
    E2E::step('switch to the native sqlsrv driver', static function () use ($root): void {
        // The setup page writes mssql:// (pdo_sqlsrv); sqlsrv:// selects Doctrine's
        // driver for Microsoft's sqlsrv extension, which the docs also describe.
        $environment = (string) file_get_contents($root.'/.env.local');
        $native = str_replace("DATABASE_URL='mssql://", "DATABASE_URL='sqlsrv://", $environment);
        E2E::check($native !== $environment, 'The setup page did not write an mssql:// URL.');
        file_put_contents($root.'/.env.local', $native);
    });
}

E2E::step('/install: create the administrator (runs the migrations)', static function () use ($browser, $root): void {
    $crawler = $browser->getCrawler();
    E2E::check($crawler->filter('input[name=admin_username]')->count() === 1, 'Expected the administrator form. '.pageProblem($browser));
    $form = $crawler->selectButton('Install Now')->form();
    $form['admin_username'] = 'admin';
    $form['admin_password'] = 'e2e-admin-password';
    $browser->submit($form);
    $config = Yaml::parseFile($root.'/config/aggregate.yaml');
    E2E::check(($config['installed'] ?? null) === true, 'installed: true was not saved. '.pageProblem($browser));
    E2E::check(!is_file($root.'/SETUP-CODE.txt'), 'SETUP-CODE.txt was not removed.');
    E2E::check(str_ends_with((string) $browser->getHistory()->current()->getUri(), '/login'), 'Expected the login page. '.pageProblem($browser));
});

E2E::step('migrations are complete', static function () use ($root): void {
    console($root, ['doctrine:migrations:up-to-date']);
});

E2E::step('log in', static function () use ($browser): void {
    $form = $browser->getCrawler()->filter('form')->form();
    $form['_username'] = 'admin';
    $form['_password'] = 'e2e-admin-password';
    $browser->submit($form);
    E2E::check(str_contains((string) $browser->getHistory()->current()->getUri(), '/dashboard'), 'Login did not reach the dashboard. '.pageProblem($browser));
});

E2E::step('register a website and declare typed properties', static function () use ($root): void {
    console($root, ['app:create-website'], "E2E site\nexample.test\n");
    // Exercises each engine's JSON functions in the custom reporting views.
    $config = Yaml::parseFile($root.'/config/aggregate.yaml');
    $config['custom_data_properties'] = [
        'plan' => ['type' => 'string', 'consent_required' => false, 'column' => 'plan_name'],
        'total_minor' => ['type' => 'integer', 'consent_required' => false, 'column' => 'total_minor_text', 'numeric_column' => 'total_minor_number'],
        'discount_rate' => ['type' => 'double', 'consent_required' => false, 'numeric_column' => 'discount_rate_number'],
    ];
    $config['query_parameter_mappings'] = [];
    file_put_contents($root.'/config/aggregate.yaml', Yaml::dump($config, 4, 2));
});

$token = (string) (Yaml::parseFile($root.'/config/websites.yaml')['websites'][0]['token'] ?? '');

$collect = static function (array $event) use ($base, $token): void {
    $client = HttpClient::create();
    $response = $client->request('POST', $base.'/api/receive', [
        'headers' => ['Origin' => 'https://example.test', 'Content-Type' => 'application/json'],
        'body' => json_encode(['websiteToken' => $token] + $event + ['consentState' => 'denied'], JSON_THROW_ON_ERROR),
    ]);
    $status = $response->getStatusCode();
    if ($status >= 300) {
        throw new \RuntimeException('POST /api/receive answered '.$status.': '.$response->getContent(false));
    }
};

$ids = static function () use ($root): int {
    return (int) connection($root)->fetchOne('SELECT MAX(id) FROM events');
};

$batches = E2E::step('collect anonymous, goal and consented events', static function () use ($collect, $ids): array {
    $batches = [];
    $record = static function (string $name, callable $send) use ($ids, &$batches): void {
        $before = $ids();
        $send();
        $batches[$name] = [$before, $ids()];
    };
    $record('recent views', static function () use ($collect): void {
        for ($i = 0; $i < 6; ++$i) {
            $collect(['eventName' => 'view', 'pagePath' => '/pricing', 'referrerChannel' => 'search', 'deviceClass' => 'desktop', 'viewportBucket' => 'large']);
        }
    });
    $record('recent goals', static function () use ($collect): void {
        for ($i = 0; $i < 6; ++$i) {
            $collect(['eventName' => 'purchase', 'goalEvent' => 'purchase', 'pagePath' => '/checkout/complete', 'customData' => ['plan' => 'pro', 'total_minor' => 4999, 'discount_rate' => 0.1]]);
        }
    });
    $record('old events', static function () use ($collect): void {
        for ($i = 0; $i < 6; ++$i) {
            $collect(['eventName' => 'view', 'pagePath' => '/old-page']);
            $collect(['eventName' => 'lead', 'goalEvent' => 'lead', 'pagePath' => '/contact']);
        }
    });
    $record('consented', static function () use ($collect): void {
        for ($i = 0; $i < 2; ++$i) {
            $collect(['eventName' => 'view', 'pagePath' => '/account', 'consentState' => 'granted', 'visitorId' => 'e2e-visitor', 'sessionId' => 'e2e-session', 'screenWidth' => 1440]);
        }
    });
    foreach ($batches as $name => [$from, $to]) {
        E2E::check($to - $from === ($name === 'old events' ? 12 : ($name === 'consented' ? 2 : 6)), sprintf('The %s were not all stored (%d rows).', $name, $to - $from));
    }
    $modes = connection(dirname(__DIR__, 2))->fetchAllKeyValue('SELECT privacy_mode, COUNT(*) FROM events GROUP BY privacy_mode');
    E2E::check((int) ($modes['anonymous'] ?? 0) === 24 && (int) ($modes['enhanced'] ?? 0) === 2, 'Unexpected privacy modes: '.json_encode($modes));

    return $batches;
});

E2E::step('age the events: completed hours, older days and past the archive period', static function () use ($root, $batches): void {
    $db = connection($root);
    $age = static function (array $range, \DateTimeImmutable $at) use ($db): void {
        $db->executeStatement('UPDATE events SET created_at = :at WHERE id > :from AND id <= :to', ['at' => $at, 'from' => $range[0], 'to' => $range[1]], ['at' => Types::DATETIME_IMMUTABLE, 'from' => Types::INTEGER, 'to' => Types::INTEGER]);
    };
    $age($batches['recent views'], hourAgo(3));
    $age($batches['recent goals'], hourAgo(48));
    $age($batches['old events'], hourAgo(120 * 24));
    $age($batches['consented'], hourAgo(72));
});

E2E::step('tracker script and health check', static function () use ($base): void {
    $client = HttpClient::create();
    $script = $client->request('GET', $base.'/aggregate.js');
    E2E::check($script->getStatusCode() === 200 && str_contains($script->getContent(), 'Aggregate'), '/aggregate.js failed: '.$script->getStatusCode());
    $health = $client->request('GET', $base.'/api/health');
    $body = $health->toArray(false);
    E2E::check($health->getStatusCode() === 200 && ($body['checks']['database']['status'] ?? null) === 'ok', '/api/health: '.$health->getContent(false));
});

E2E::step('every dashboard page renders', static function () use ($browser, $base): void {
    $crawler = $browser->request('GET', $base.'/dashboard');
    $links = [];
    foreach ($crawler->filter('a[href^="/dashboard"], a[href^="'.$base.'/dashboard"]') as $anchor) {
        $href = (string) parse_url((string) $anchor->getAttribute('href'), PHP_URL_PATH);
        if (!preg_match('#logout|download|export|/branding/logo#', $href)) {
            $links[$href] = true;
        }
    }
    E2E::check(count($links) >= 5, 'Too few dashboard links found: '.implode(', ', array_keys($links)));
    $broken = [];
    foreach (array_keys($links) as $path) {
        $browser->request('GET', $base.$path);
        $status = $browser->getInternalResponse()->getStatusCode();
        if ($status >= 400) {
            $broken[] = pageProblem($browser);
        }
    }
    E2E::check($broken === [], "Broken pages:\n".implode("\n", $broken));
    printf("      (%d pages)\n", count($links));
    E2E::$summary['pages'] = count($links);
});

E2E::step('save the BI thresholds from the dashboard', static function () use ($browser, $base, $root): void {
    $crawler = $browser->request('GET', $base.'/dashboard/privacy');
    $form = $crawler->filter('form[action$="/dashboard/settings/analytics-privacy"]')->form();
    $form['anonymous_min_cell_count'] = '6';
    $browser->submit($form);
    $config = Yaml::parseFile($root.'/config/aggregate.yaml');
    E2E::check((int) ($config['anonymous_min_cell_count'] ?? 0) === 6, 'The thresholds were not saved to configuration. '.pageProblem($browser));
});

E2E::step('glossary sync, reporting view regeneration and archiving', static function () use ($root): void {
    $config = Yaml::parseFile($root.'/config/aggregate.yaml');
    file_put_contents($root.'/config/aggregate.yaml', Yaml::dump(['analytics_archiving_enabled' => true, 'analytics_archive_after_days' => 90,
        'bi_page_groups' => [['pattern' => '/group/**', 'group' => 'grouped']]] + $config, 4, 2));
    console($root, ['app:analytics:glossary:sync']);
    console($root, ['app:analytics:views:regenerate']);
    // The headless path replaces the BI views again with the saved thresholds.
    E2E::check(str_contains(console($root, ['app:analytics:bi-views:regenerate', '--dry-run']), 'event_counts.event_count >= 6'), 'app:analytics:bi-views:regenerate did not use the saved threshold.');
    console($root, ['app:analytics:bi-views:regenerate']);
    console($root, ['app:analytics:maintain']);
    $db = connection($root);
    E2E::check((int) $db->fetchOne('SELECT COUNT(*) FROM analytics_archive_events') > 0, 'No events were archived.');
    E2E::check((int) $db->fetchOne('SELECT COUNT(*) FROM analytics_archive_goals') > 0, 'No goals were archived.');
    E2E::check((int) $db->fetchOne('SELECT COUNT(*) FROM events WHERE archived_at IS NOT NULL') === 12, 'Archived rows were not marked.');
    // A second run must not count the same rows twice.
    console($root, ['app:analytics:maintain']);
    E2E::check((int) $db->fetchOne('SELECT SUM(event_count) FROM analytics_archive_events') === 12, 'Archive totals changed on a second run.');
});

E2E::step('reporting views return the expected cells', static function () use ($root, $token): void {
    $db = connection($root);
    $views = $db->createSchemaManager()->introspectViews();
    $names = [];
    foreach ($views as $view) {
        $name = $view->getObjectName()->getUnqualifiedName()->getValue();
        $names[] = $name;
        $db->fetchOne('SELECT COUNT(*) FROM '.$name);
    }
    foreach (['bi_anonymous_events_v1', 'bi_anonymous_goals_v1', 'bi_anonymous_geo_events_v1', 'bi_anonymous_daily_v1', 'bi_anonymous_daily_rollup_v1', 'bi_anonymous_coverage_v1', 'bi_dim_website_token_v1', 'bi_glossary_values_v1', 'bi_glossary_columns_v1', 'analytics_archived_events_v1'] as $required) {
        E2E::check(in_array($required, $names, true), $required.' is missing; views: '.implode(', ', $names));
    }
    $pages = $db->fetchAllKeyValue("SELECT page_path, SUM(event_count) FROM bi_anonymous_events_v1 WHERE event_name = 'view' GROUP BY page_path");
    E2E::check((int) ($pages['/pricing'] ?? 0) === 6 && (int) ($pages['/old-page'] ?? 0) === 6, 'bi_anonymous_events_v1 views: '.json_encode($pages));
    E2E::check(!isset($pages['/account']), 'Consented events leaked into the anonymous view.');
    $goals = $db->fetchAllKeyValue('SELECT goal_event, SUM(event_count) FROM bi_anonymous_goals_v1 GROUP BY goal_event');
    E2E::check((int) ($goals['purchase'] ?? 0) === 6 && (int) ($goals['lead'] ?? 0) === 6, 'bi_anonymous_goals_v1: '.json_encode($goals));
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM bi_glossary_values_v1 WHERE dimension = 'goal_event' AND code = 'purchase'") === 1, 'The glossary lacks the purchase goal.');
    $sites = $db->fetchAllKeyValue('SELECT f.website_token, d.website_token_label FROM bi_anonymous_goals_v1 f LEFT JOIN bi_dim_website_token_v1 d ON d.website_token = f.website_token GROUP BY f.website_token, d.website_token_label');
    E2E::check($sites === [$token => 'E2E site'], 'Website labels do not join to the facts: '.json_encode($sites));
    $custom = $db->fetchAllAssociative("SELECT plan_name, total_minor_text, total_minor_number, discount_rate_number FROM analytics_custom_goals_v1 WHERE goal_event = 'purchase'");
    E2E::check(count($custom) === 6, 'analytics_custom_goals_v1 rows: '.json_encode($custom));
    foreach ($custom as $row) {
        E2E::check($row['plan_name'] === 'pro' && (string) $row['total_minor_text'] === '4999' && (int) $row['total_minor_number'] === 4999 && abs((float) $row['discount_rate_number'] - 0.1) < 1e-9, 'Unexpected custom values: '.json_encode($row));
    }
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM analytics_custom_pageviews_v1 WHERE page_path = '/pricing'") === 6, 'analytics_custom_pageviews_v1 lacks the /pricing views.');
    printf("      (%d views)\n", count($names));
    E2E::$summary['views'] = count($names);
});

E2E::step('BigQuery dry run exports every syncable view with its schema', static function () use ($root): void {
    // Reads each view the way a sync would, converting values to BigQuery
    // types on this engine, without credentials or uploads.
    $output = $root.'/var/bigquery-dry-run';
    E2E::check(is_dir($output) || mkdir($output, 0700, true), 'The dry-run folder could not be created.');
    $views = ['bi_anonymous_events_v1', 'bi_anonymous_goals_v1', 'bi_anonymous_geo_events_v1', 'bi_anonymous_daily_v1', 'bi_anonymous_daily_rollup_v1', 'bi_anonymous_coverage_v1', 'bi_dim_website_token_v1', 'bi_dim_event_name_v1',
        'bi_dim_goal_event_v1', 'bi_dim_referrer_channel_v1', 'bi_dim_device_class_v1', 'bi_dim_viewport_bucket_v1', 'bi_dim_geo_area_v1',
        'bi_glossary_values_v1', 'bi_glossary_columns_v1', 'analytics_custom_events_v1', 'analytics_custom_pageviews_v1', 'analytics_custom_goals_v1',
        'analytics_archived_events_v1', 'analytics_archived_pageviews_v1', 'analytics_archived_goals_v1'];
    $results = json_decode(console($root, ['app:bigquery:sync', '--dry-run', '--json', '--output='.$output, ...array_map(static fn (string $view): string => '--view='.$view, $views)]), true);
    E2E::check(is_array($results) && array_column($results, 'view') === $views, 'The dry run did not export every view: '.json_encode($results));
    $rows = static function (string $view) use ($output): array {
        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($output.'/'.$view.'.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    };
    $goals = array_values(array_filter($rows('bi_anonymous_goals_v1'), static fn (array $row): bool => $row['goal_event'] === 'purchase'));
    E2E::check(array_sum(array_map('intval', array_column($goals, 'event_count'))) === 6, 'Exported goal counts: '.json_encode($goals));
    foreach ($goals as $goal) {
        E2E::check(preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', (string) $goal['event_day']) === 1 && is_string($goal['event_count']), 'Exported goal types: '.json_encode($goal));
    }
    foreach ($rows('bi_anonymous_events_v1') as $event) {
        E2E::check(preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:00:00\.000000$/D', (string) $event['event_hour']) === 1, 'Exported event hour: '.json_encode($event));
    }
    $purchases = array_values(array_filter($rows('analytics_custom_goals_v1'), static fn (array $row): bool => $row['goal_event'] === 'purchase'));
    E2E::check(count($purchases) === 6, 'Exported custom goals: '.count($purchases));
    foreach ($purchases as $row) {
        E2E::check($row['total_minor_number'] === '4999' && is_float($row['discount_rate_number']) && abs($row['discount_rate_number'] - 0.1) < 1e-9
            && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}$/D', (string) $row['created_at']) === 1, 'Exported custom values: '.json_encode($row));
    }
    $glossary = $rows('bi_glossary_values_v1');
    E2E::check($glossary !== [] && is_bool($glossary[0]['is_default_locale']) && is_bool($glossary[0]['is_fallback']), 'Exported glossary flags: '.json_encode($glossary[0] ?? null));
    $schema = json_decode((string) file_get_contents($output.'/bi_anonymous_events_v1.schema.json'), true);
    E2E::check(array_column($schema, 'type', 'name')['event_count'] === 'INT64', 'Exported schema: '.json_encode($schema));
    // The task tables exist on this engine and the check command reads them.
    E2E::check(str_contains(console($root, ['app:bigquery:check', '--no-connect']), 'off'), 'app:bigquery:check did not report the settings.');
});

E2E::step('reporting database sync copies every syncable view to a second database', static function () use ($root, $driver, $database, $browser, $base): void {
    // The same server and user, another database: CI creates aggregate_reporting
    // beforehand; SQLite needs only a file.
    $environment = rtrim((string) file_get_contents($root.'/.env.local'))."\n";
    $applicationUrl = (new Dotenv())->parse($environment)['DATABASE_URL'];
    $config = Yaml::parseFile($root.'/config/aggregate.yaml');
    $views = ['bi_anonymous_events_v1', 'bi_anonymous_goals_v1', 'bi_anonymous_geo_events_v1', 'bi_anonymous_daily_v1', 'bi_anonymous_daily_rollup_v1', 'bi_anonymous_coverage_v1', 'bi_dim_website_token_v1', 'bi_dim_event_name_v1',
        'bi_dim_goal_event_v1', 'bi_dim_referrer_channel_v1', 'bi_dim_device_class_v1', 'bi_dim_viewport_bucket_v1', 'bi_dim_geo_area_v1', 'bi_glossary_values_v1', 'bi_glossary_columns_v1'];
    $private = ['analytics_custom_events_v1', 'analytics_custom_pageviews_v1', 'analytics_custom_goals_v1', 'analytics_archived_events_v1', 'analytics_archived_pageviews_v1', 'analytics_archived_goals_v1'];
    file_put_contents($root.'/config/aggregate.yaml', Yaml::dump(['reporting_database_enabled' => true, 'reporting_database_views' => $views, 'reporting_database_private_views' => $private] + $config, 4, 2));

    // A URL that leads back to the application database is refused.
    file_put_contents($root.'/.env.local', $environment."REPORTING_DATABASE_URL='".str_replace('%kernel.project_dir%/var/data.db', '%kernel.project_dir%/var/./data.db', $applicationUrl)."'\n");
    [$status, $output, $errors] = consoleResult($root, ['app:reporting-database:check']);
    E2E::check($status === 1 && str_contains((string) preg_replace('/\s+/', ' ', $output.$errors), 'names the application database'), 'The application database was accepted as the reporting database: '.$output.$errors);

    file_put_contents($root.'/.env.local', $environment);

    // The command line tests a connection before saving it, and refuses the application database.
    $fields = $driver === 'sqlite'
        ? ['type' => 'sqlite', 'database' => 'var/reporting.db']
        : ['type' => $driver, 'host' => $database['host'], 'port' => $database['port'], 'database' => $database['name'].'_reporting', 'user' => $database['user'], 'password' => $database['password'],
            'trust_certificate' => getenv('AGGREGATE_E2E_TRUST_CERTIFICATE') === '1' ? '1' : ''];
    $options = static function (array $fields): array {
        $options = ['--type='.$fields['type'], '--database='.$fields['database']];
        foreach (['host', 'port', 'user'] as $key) {
            if (($fields[$key] ?? '') !== '') {
                $options[] = '--'.$key.'='.$fields[$key];
            }
        }

        return [...$options, ...(($fields['trust_certificate'] ?? '') === '1' ? ['--trust-certificate'] : []), ...($fields['type'] === 'sqlite' ? [] : ['--password-stdin'])];
    };
    $refused = ['database' => $driver === 'sqlite' ? 'var/./data.db' : $database['name']] + $fields;
    [$status, $output, $errors] = consoleResult($root, ['app:reporting-database:configure', ...$options($refused), '--no-interaction'], $database['password']."\n");
    E2E::check($status === 1 && str_contains((string) preg_replace('/\s+/', ' ', $output.$errors), 'application database') && file_get_contents($root.'/.env.local') === $environment, 'app:reporting-database:configure accepted the application database: '.$output.$errors);

    // The dashboard form refuses it too, then tests and saves the reporting database to .env.local.
    $submit = static function (array $values) use ($browser, $base): string {
        $form = $browser->request('GET', $base.'/dashboard/reporting-database')->filter('form#reporting-database-connection')->form();
        $form['type']->select($values['type']);
        foreach (['host', 'port', 'database', 'user', 'password'] as $key) {
            $form[$key] = (string) ($values[$key] ?? '');
        }
        ($values['trust_certificate'] ?? '') === '1' ? $form['trust_certificate']->tick() : $form['trust_certificate']->untick();

        return (string) preg_replace('/\s+/', ' ', implode(' ', $browser->submit($form)->filter('.notification')->each(static fn ($notice): string => $notice->text())));
    };
    $message = $submit($refused);
    E2E::check(str_contains($message, 'The connection was not saved') && str_contains($message, 'application database') && file_get_contents($root.'/.env.local') === $environment, 'The dashboard accepted the application database: '.$message.' '.pageProblem($browser));
    $message = $submit($fields);
    $saved = (new Dotenv())->parse((string) file_get_contents($root.'/.env.local'));
    E2E::check(str_contains($message, 'saved to .env.local') && isset($saved['REPORTING_DATABASE_URL']) && $saved['DATABASE_URL'] === $applicationUrl, 'The dashboard did not save the connection: '.$message.' '.pageProblem($browser));
    $reportingUrl = $saved['REPORTING_DATABASE_URL'];
    if ($driver !== 'sqlite') {
        // The form never shows the password; leaving it empty keeps the saved one.
        E2E::check(!str_contains((string) $browser->getInternalResponse()->getContent(), rawurlencode($database['password'])) && !str_contains((string) $browser->getInternalResponse()->getContent(), $database['password']), 'The page shows the saved password.');
        $message = $submit(['password' => ''] + $fields);
        E2E::check(str_contains($message, 'saved to .env.local') && (new Dotenv())->parse((string) file_get_contents($root.'/.env.local'))['REPORTING_DATABASE_URL'] === $reportingUrl, 'Saving with an empty password: '.$message);
    }
    // SymfonyStyle wraps long lines.
    $check = (string) preg_replace('/\s+/', ' ', console($root, ['app:reporting-database:check']));
    E2E::check(str_contains($check, 'can create and drop tables') && str_contains($check, 'No views have been copied yet'), 'app:reporting-database:check: '.$check);

    $reporting = static function () use ($root, $reportingUrl): Connection {
        $scheme = ['mssql' => 'pdo_sqlsrv', 'mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite'];

        return DriverManager::getConnection((new DsnParser($scheme))->parse(str_replace('%kernel.project_dir%', $root, $reportingUrl)));
    };
    $application = connection($root);
    // Twice: the first run creates each table, the second replaces it.
    foreach (['create', 'replace'] as $round) {
        $result = json_decode(console($root, ['app:reporting-database:sync', '--force', '--json']), true);
        $statuses = array_column($result['results'] ?? [], 'status', 'view');
        E2E::check($statuses === array_fill_keys([...$views, ...$private], 'synced'), $round.': '.json_encode($result));
        $copy = $reporting();
        foreach ([...$views, ...$private] as $view) {
            $expected = (int) $application->fetchOne('SELECT COUNT(*) FROM '.$view);
            $copied = (int) $copy->fetchOne('SELECT COUNT(*) FROM '.$view);
            E2E::check($copied === $expected && ($statuses[$view] ?? null) === 'synced' && ($result['results'][array_search($view, array_column($result['results'], 'view'), true)]['rows'] ?? null) === $expected, sprintf('%s %s: %d rows copied, %d in the view.', $round, $view, $copied, $expected));
        }
        $copy->close();
    }

    $copy = $reporting();
    // Staging and previous tables are gone; only the copies remain.
    $tables = array_map(static fn ($name): string => strtolower($name->getUnqualifiedName()->getValue()), $copy->createSchemaManager()->introspectTableNames());
    sort($tables);
    $expectedTables = [...$views, ...$private];
    sort($expectedTables);
    E2E::check($tables === $expectedTables, 'Tables in the reporting database: '.implode(', ', $tables));
    // Values keep their types on this engine.
    E2E::check((int) $copy->fetchOne("SELECT SUM(event_count) FROM bi_anonymous_goals_v1 WHERE goal_event = 'purchase'") === 6, 'Copied goal counts differ.');
    $purchases = $copy->fetchAllAssociative("SELECT plan_name, total_minor_number, discount_rate_number, created_at FROM analytics_custom_goals_v1 WHERE goal_event = 'purchase'");
    E2E::check(count($purchases) === 6, 'Copied custom goals: '.json_encode($purchases));
    foreach ($purchases as $row) {
        E2E::check($row['plan_name'] === 'pro' && (int) $row['total_minor_number'] === 4999 && abs((float) $row['discount_rate_number'] - 0.1) < 1e-12
            && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}/', (string) $row['created_at']) === 1, 'Copied custom values: '.json_encode($row));
    }
    // Sorted here: the two databases may collate text differently.
    $labels = static function (Connection $db): array {
        $rows = array_map(static fn (array $row): string => json_encode(array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $db->fetchAllNumeric('SELECT dimension, code, locale, label FROM bi_glossary_values_v1'));
        sort($rows);

        return $rows;
    };
    E2E::check($labels($copy) === $labels($application), 'Copied glossary labels differ from the view.');
    $flags = $copy->fetchAssociative("SELECT is_fallback, is_default_locale FROM bi_glossary_values_v1 WHERE dimension = 'goal_event' AND code = 'purchase'");
    E2E::check(is_array($flags) && in_array($flags['is_default_locale'], [true, 1, '1'], true), 'Copied glossary flags: '.json_encode($flags));
    $copy->close();

    E2E::check((int) $application->fetchOne("SELECT COUNT(*) FROM processing_tasks WHERE task_type = 'reporting_database_sync' AND status = 'succeeded'") === 2 * (count($views) + count($private)), 'Reporting database syncs were not recorded.');
    $check = (string) preg_replace('/\s+/', ' ', console($root, ['app:reporting-database:check']));
    E2E::check(str_contains($check, (count($views) + count($private)).' copied views found'), 'app:reporting-database:check after the sync: '.$check);
    $page = $browser->request('GET', $base.'/dashboard/reporting-database');
    E2E::check($page->filter('.reporting-database-status .tag.is-success')->count() === count($views) + count($private) && str_contains($page->text(), $driver === 'sqlite' ? 'var/reporting.db' : 'aggregate_reporting'), 'The reporting database page: '.pageProblem($browser));

    // Remove connection deletes only that line from .env.local.
    $remove = $page->filter('form#reporting-database-remove-connection')->form();
    $browser->submit($remove);
    E2E::check(file_get_contents($root.'/.env.local') === $environment, 'Removing the connection left .env.local as: '.file_get_contents($root.'/.env.local'));

    // The command line saves the same connection, reading the password from standard input.
    [$status, $output, $errors] = consoleResult($root, ['app:reporting-database:configure', ...$options($fields), '--no-interaction'], $database['password']."\n");
    E2E::check($status === 0 && (new Dotenv())->parse((string) file_get_contents($root.'/.env.local'))['REPORTING_DATABASE_URL'] === $reportingUrl, 'app:reporting-database:configure: '.$output.$errors);
    console($root, ['app:reporting-database:configure', '--remove']);
    E2E::check(file_get_contents($root.'/.env.local') === $environment, 'app:reporting-database:configure --remove left: '.file_get_contents($root.'/.env.local'));

    file_put_contents($root.'/config/aggregate.yaml', Yaml::dump($config, 4, 2));
});

E2E::step('small cells are suppressed', static function () use ($root, $collect): void {
    $db = connection($root);
    $before = (int) $db->fetchOne('SELECT MAX(id) FROM events');
    for ($i = 0; $i < 4; ++$i) {
        $collect(['eventName' => 'view', 'pagePath' => '/rare']);
    }
    $db->executeStatement('UPDATE events SET created_at = :at WHERE id > :from', ['at' => hourAgo(5), 'from' => $before], ['at' => Types::DATETIME_IMMUTABLE, 'from' => Types::INTEGER]);
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM bi_anonymous_events_v1 WHERE page_path = '/rare'") === 0, 'A cell below the threshold was published.');
});

E2E::step('the daily view groups pages and withholds cells that would reveal hourly counts', static function () use ($root, $collect): void {
    $db = connection($root);
    // Completed hours of yesterday (UTC); the saved threshold is 6.
    $yesterday = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-1 day');
    $send = static function (string $path, int $count, int $hour) use ($db, $collect, $yesterday): void {
        $before = (int) $db->fetchOne('SELECT MAX(id) FROM events');
        for ($i = 0; $i < $count; ++$i) {
            $collect(['eventName' => 'view', 'pagePath' => $path, 'referrerChannel' => 'search', 'deviceClass' => 'desktop', 'viewportBucket' => 'large']);
        }
        $db->executeStatement('UPDATE events SET created_at = :at WHERE id > :from', ['at' => $yesterday->setTime($hour, 0), 'from' => $before], ['at' => Types::DATETIME_IMMUTABLE, 'from' => Types::INTEGER]);
    };
    $send('/group/a', 3, 10);
    $send('/group/b', 3, 10);
    $send('/guard', 7, 11);
    $send('/guard', 2, 12);
    foreach ([13, 14, 15] as $hour) {
        $send('/spread', 2, $hour);
    }
    $day = $yesterday;
    $daily = static function (string $group) use ($db, $day): ?int {
        $count = $db->fetchOne('SELECT SUM(event_count) FROM bi_anonymous_daily_v1 WHERE page_group = :group AND event_day = :day', ['group' => $group, 'day' => $day], ['day' => Types::DATE_IMMUTABLE]);

        return $count === null || $count === false ? null : (int) $count;
    };
    $hourly = static fn (string $prefix): int => (int) $db->fetchOne('SELECT COUNT(*) FROM bi_anonymous_events_v1 WHERE page_path LIKE :prefix', ['prefix' => $prefix.'%']);
    E2E::check($hourly('/group/') === 0 && $daily('grouped') === 6, 'Grouped pages: hourly rows '.$hourly('/group/').', daily '.json_encode($daily('grouped')));
    E2E::check($hourly('/spread') === 0 && $daily('/spread') === 6, 'Hours combined: hourly rows '.$hourly('/spread').', daily '.json_encode($daily('/spread')));
    E2E::check($hourly('/guard') === 1 && $daily('/guard') === null, 'A daily cell that would reveal a withheld hourly count of 2 was published: '.json_encode($daily('/guard')));
});

E2E::step('the roll-up view pools withheld cells and the coverage view bands them', static function () use ($root, $collect): void {
    $db = connection($root);
    $yesterday = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-1 day');
    $send = static function (string $path, int $count, int $hour, string $event, string $channel, string $device) use ($db, $collect, $yesterday): void {
        $before = (int) $db->fetchOne('SELECT MAX(id) FROM events');
        for ($i = 0; $i < $count; ++$i) {
            $collect(['eventName' => $event, 'pagePath' => $path, 'referrerChannel' => $channel, 'deviceClass' => $device, 'viewportBucket' => 'large']);
        }
        $db->executeStatement('UPDATE events SET created_at = :at WHERE id > :from', ['at' => $yesterday->setTime($hour, 0), 'from' => $before], ['at' => Types::DATETIME_IMMUTABLE, 'from' => Types::INTEGER]);
    };
    // Each cell is below the threshold of 6; /pool/a reaches it by page, b-d by channel, e never.
    $send('/pool/a', 3, 9, 'pool_check', 'search', 'desktop');
    $send('/pool/a', 3, 9, 'pool_check', 'social', 'desktop');
    $send('/pool/b', 2, 9, 'pool_check', 'search', 'desktop');
    $send('/pool/c', 2, 9, 'pool_check', 'search', 'mobile');
    $send('/pool/d', 2, 9, 'pool_check', 'search', 'tablet');
    $send('/pool/e', 1, 9, 'pool_check', 'social', 'mobile');
    $rows = $db->fetchAllAssociative("SELECT page_group, referrer_channel, device_class, detail_level, event_count FROM bi_anonymous_daily_rollup_v1 WHERE event_name = 'pool_check' AND event_day = :day ORDER BY detail_level", ['day' => $yesterday], ['day' => Types::DATE_IMMUTABLE]);
    $rows = array_map(static fn (array $row): string => implode('|', [$row['page_group'], $row['referrer_channel'], $row['device_class'], $row['detail_level'], (int) $row['event_count']]), $rows);
    E2E::check($rows === ['(other)|search|(other)|channel|6', '/pool/a|(other)|(other)|page|6'], 'Roll-up rows for pool_check: '.json_encode($rows));
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM bi_anonymous_daily_rollup_v1 WHERE page_group = '/guard'") === 0, 'The roll-up published a row that would reveal the withheld /guard hour.');
    $columns = 'website_token, event_day, event_name, page_group, referrer_channel, device_class, event_count';
    $full = $db->fetchAllAssociative('SELECT '.$columns." FROM bi_anonymous_daily_rollup_v1 WHERE detail_level = 'page_channel_device' ORDER BY event_day, event_name, page_group, referrer_channel, device_class");
    $daily = $db->fetchAllAssociative('SELECT '.$columns.' FROM bi_anonymous_daily_v1 ORDER BY event_day, event_name, page_group, referrer_channel, device_class');
    E2E::check(json_encode($full) === json_encode($daily), 'Full-detail roll-up rows differ from the daily view.');
    $rank = ['0-10' => 0, '10-50' => 1, '50-90' => 2, '90-100' => 3];
    $bands = $db->fetchAllKeyValue('SELECT view_name, coverage_band FROM bi_anonymous_coverage_v1 WHERE event_day = :day', ['day' => $yesterday], ['day' => Types::DATE_IMMUTABLE]);
    ksort($bands);
    // Filtering literal-only columns works whatever the client's collation.
    $filtered = (int) $db->fetchOne("SELECT COUNT(*) FROM bi_anonymous_coverage_v1 WHERE view_name = 'bi_anonymous_daily_rollup_v1' AND coverage_band IN ('0-10', '10-50', '50-90', '90-100') AND event_day = :day", ['day' => $yesterday], ['day' => Types::DATE_IMMUTABLE]);
    E2E::check($filtered === 1, 'Filtering the coverage view by view_name and coverage_band returned '.$filtered.' rows.');
    E2E::check(array_keys($bands) === ['bi_anonymous_daily_rollup_v1', 'bi_anonymous_daily_v1', 'bi_anonymous_events_v1'] && array_diff($bands, array_keys($rank)) === []
        && $rank[$bands['bi_anonymous_daily_rollup_v1']] >= $rank[$bands['bi_anonymous_daily_v1']], 'Coverage bands: '.json_encode($bands));
});

E2E::step('retention deletes expired data', static function () use ($root): void {
    $config = Yaml::parseFile($root.'/config/aggregate.yaml');
    // Raw retention may not be shorter than the archive period, so archive after one day.
    $config = ['analytics_retention_enabled' => true, 'analytics_archive_after_days' => 1, 'analytics_enhanced_retention_days' => 1, 'analytics_anonymous_retention_days' => 100, 'analytics_archive_retention_days' => 100] + $config;
    file_put_contents($root.'/config/aggregate.yaml', Yaml::dump($config, 4, 2));
    console($root, ['app:analytics:maintain']);
    $db = connection($root);
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM events WHERE privacy_mode = 'enhanced'") === 0, 'Expired consented events were kept.');
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM analytics_archive_events WHERE page_path = '/old-page'") === 0, 'Expired archive cells were kept.');
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM analytics_archive_goals WHERE goal_event = 'purchase'") > 0, 'Goals from two days ago were not archived.');
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM events WHERE url = '/pricing'") === 6, 'Recent events were deleted.');
});

E2E::step('processing tasks and the audit trail record syncs and maintenance, and are purged', static function () use ($root): void {
    $db = connection($root);
    // Maintenance runs from the earlier steps were recorded.
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM processing_tasks WHERE task_type = 'analytics_maintenance' AND status = 'succeeded'") >= 3, 'Maintenance runs were not recorded.');
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM audit_trail WHERE operation = 'analytics_maintenance' AND outcome = 'succeeded'") >= 3, 'Maintenance runs were not audited.');

    // Sync is on but no key is installed: every due view fails with the reason.
    $config = Yaml::parseFile($root.'/config/aggregate.yaml');
    file_put_contents($root.'/config/aggregate.yaml', Yaml::dump(['bigquery_enabled' => true, 'bigquery_project_id' => 'aggregate-e2e', 'bigquery_views' => ['bi_anonymous_events_v1', 'bi_anonymous_goals_v1']] + $config, 4, 2));
    [$status, $output, $errors] = consoleResult($root, ['app:bigquery:sync', '--json']);
    E2E::check($status === 1 && str_contains($output, 'No service account key'), 'A sync without a key: '.$output.$errors);
    $failed = $db->fetchAllAssociative("SELECT subject, status, triggered_by, lock_key, details FROM processing_tasks WHERE task_type = 'bigquery_sync' ORDER BY id");
    E2E::check(count($failed) === 2 && $failed[0]['status'] === 'failed' && $failed[0]['triggered_by'] === 'schedule' && $failed[0]['lock_key'] === null && str_contains((string) $failed[0]['details'], 'No service account key'), 'Failed syncs: '.json_encode($failed));
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM audit_trail WHERE operation = 'bigquery_sync' AND outcome = 'failed'") === 2, 'Failed syncs were not audited.');

    // A view another process is syncing is skipped: the unique lock key works here.
    $db->insert('processing_tasks', ['task_type' => 'bigquery_sync', 'subject' => 'bi_anonymous_events_v1', 'status' => 'running', 'triggered_by' => 'command', 'lock_key' => 'bigquery_sync:bi_anonymous_events_v1', 'started_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))], ['started_at' => Types::DATETIME_IMMUTABLE]);
    [, $output, $errors] = consoleResult($root, ['app:bigquery:sync', '--force', '--json']);
    $results = array_column(json_decode($output, true)['results'] ?? [], 'status', 'view');
    E2E::check($results === ['bi_anonymous_events_v1' => 'busy', 'bi_anonymous_goals_v1' => 'failed'], 'Forced sync with one view locked: '.$output.$errors);
    E2E::check(str_contains(console($root, ['app:bigquery:check', '--no-connect']), 'running'), 'app:bigquery:check did not show the running sync.');

    // Records older than their periods are purged; each job's latest success
    // and failure stay. The purge is itself recorded.
    $old = new \DateTimeImmutable('-400 days', new \DateTimeZone('UTC'));
    $db->executeStatement("UPDATE processing_tasks SET started_at = :old, finished_at = :old WHERE status <> 'running'", ['old' => $old], ['old' => Types::DATETIME_IMMUTABLE]);
    $db->executeStatement('UPDATE audit_trail SET occurred_at = :old', ['old' => $old], ['old' => Types::DATETIME_IMMUTABLE]);
    $kept = (int) $db->fetchOne("SELECT COUNT(*) FROM (SELECT task_type, subject, status FROM processing_tasks WHERE status <> 'running' GROUP BY task_type, subject, status) kept");
    console($root, ['app:analytics:maintain']);
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM processing_tasks WHERE started_at < :recent", ['recent' => new \DateTimeImmutable('-1 day')], ['recent' => Types::DATETIME_IMMUTABLE]) === $kept, 'Old processing tasks were not purged down to the latest of each job.');
    $audit = $db->fetchAllAssociative('SELECT operation, outcome, details FROM audit_trail');
    E2E::check(count($audit) === 1 && $audit[0]['operation'] === 'analytics_maintenance' && str_contains((string) $audit[0]['details'], 'audit trail entr'), 'Audit trail after the purge: '.json_encode($audit));
    file_put_contents($root.'/config/aggregate.yaml', Yaml::dump($config, 4, 2));
});

E2E::step('one scheduled task runs the due jobs and the dashboard shows that it works', static function () use ($root, $browser, $base): void {
    [$status, $output] = consoleResult($root, ['app:scheduler:status', '--json']);
    $state = json_decode($output, true);
    E2E::check($status === 1 && ($state['state'] ?? null) === 'never' && str_contains((string) ($state['setup']['cron'] ?? ''), 'bin/console app:scheduler:run --no-interaction'), 'Status before the first run: '.$output);
    E2E::check(str_contains($browser->request('GET', $base.'/dashboard/scheduled-tasks')->filter('#scheduler-status-title')->text(''), 'Not set up'), 'The Scheduled tasks page before the first run: '.pageProblem($browser));

    // Maintenance ran in the previous step; make it due again.
    $db = connection($root);
    $earlier = new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC'));
    $db->executeStatement("UPDATE processing_tasks SET started_at = :at, finished_at = :at WHERE task_type = 'analytics_maintenance'", ['at' => $earlier], ['at' => Types::DATETIME_IMMUTABLE]);

    // Without a terminal, as cron and Plesk run it.
    $run = json_decode(console($root, ['app:scheduler:run', '--json']), true);
    E2E::check(($run['trigger'] ?? null) === 'schedule' && array_column($run['results'] ?? [], 'status', 'job') === ['analytics_maintenance' => 'ran', 'bigquery_sync' => 'off', 'reporting_database_sync' => 'off'], 'app:scheduler:run: '.json_encode($run));
    $latest = $db->fetchAssociative("SELECT status, triggered_by FROM processing_tasks WHERE task_type = 'analytics_maintenance' AND started_at > :earlier ORDER BY id DESC", ['earlier' => $earlier->modify('+1 day')], ['earlier' => Types::DATETIME_IMMUTABLE]);
    E2E::check($latest === ['status' => 'succeeded', 'triggered_by' => 'schedule'], 'The scheduled maintenance run was not recorded: '.json_encode($latest));
    // Nothing is due now, so the next run prints nothing for cron to mail.
    [$status, $output, $errors] = consoleResult($root, ['app:scheduler:run']);
    E2E::check($status === 0 && trim($output.$errors) === '', 'A run with nothing due printed: '.$output.$errors);

    [$status, $output] = consoleResult($root, ['app:scheduler:status']);
    E2E::check($status === 0 && str_contains((string) preg_replace('/\s+/', ' ', $output), 'The scheduled task last ran'), 'app:scheduler:status after a scheduled run: '.$output);
    $page = $browser->request('GET', $base.'/dashboard/scheduled-tasks');
    E2E::check(str_contains($page->filter('#scheduler-status-title')->text(''), 'Working') && str_contains($page->filter('.scheduled-tasks-results')->text(''), 'Analytics maintenance'), 'The Scheduled tasks page after a scheduled run: '.pageProblem($browser));

    // Run due jobs now starts the scheduler in the background as the administrator.
    $browser->submit($page->filter('form[action$="/dashboard/scheduled-tasks/run"]')->form());
    $record = [];
    for ($attempt = 0; $attempt < 100 && ($record['manual']['trigger'] ?? null) !== 'dashboard'; ++$attempt) {
        usleep(200000);
        $record = json_decode((string) @file_get_contents($root.'/var/scheduler/last-run.json'), true) ?: [];
    }
    E2E::check(($record['manual']['trigger'] ?? null) === 'dashboard', 'Run due jobs now did not run the scheduler: '.json_encode($record).' '.@file_get_contents($root.'/var/scheduler/last-output.log'));
    E2E::check(str_contains($browser->request('GET', $base.'/dashboard/scheduled-tasks')->text(''), 'Last run by hand'), 'The run by hand is not shown: '.pageProblem($browser));
});

$version = (string) connection($root)->fetchOne(match ($driver) {
    'mysql' => 'SELECT VERSION()',
    'pgsql' => 'SHOW server_version',
    'sqlsrv' => "SELECT CAST(SERVERPROPERTY('ProductVersion') AS NVARCHAR(128))",
    default => 'SELECT sqlite_version()',
});
E2E::stop();
$summary = sprintf('All steps passed on %s %s (%s): %d dashboard pages, %d views.', $driver, $version, connection($root)->getParams()['driver'] ?? '?', E2E::$summary['pages'] ?? 0, E2E::$summary['views'] ?? 0);
echo "Fresh install on {$driver}: {$summary}\n";
if (getenv('GITHUB_ACTIONS') === 'true') {
    echo '::notice title=Fresh install ('.$driver.')::'.$summary."\n";
}
