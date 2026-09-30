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
    E2E::check(str_ends_with((string) $browser->getHistory()->current()->getUri(), '/install'), 'Expected /install next. '.pageProblem($browser));
});

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

E2E::step('register a website', static function () use ($root): void {
    console($root, ['app:create-website'], "E2E site\nexample.test\n");
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
            $collect(['eventName' => 'purchase', 'goalEvent' => 'purchase', 'pagePath' => '/checkout/complete']);
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
});

E2E::step('save the BI thresholds from the dashboard', static function () use ($browser, $base, $root): void {
    $crawler = $browser->request('GET', $base.'/dashboard/privacy');
    $form = $crawler->filter('form[action$="/dashboard/settings/analytics-privacy"]')->form();
    $form['anonymous_min_cell_count'] = '6';
    $browser->submit($form);
    E2E::check((int) connection($root)->fetchOne('SELECT anonymous_min_cell_count FROM analytics_privacy_settings WHERE id = 1') === 6, 'The thresholds were not saved. '.pageProblem($browser));
});

E2E::step('glossary sync, reporting view regeneration and archiving', static function () use ($root): void {
    $config = Yaml::parseFile($root.'/config/aggregate.yaml');
    file_put_contents($root.'/config/aggregate.yaml', Yaml::dump(['analytics_archiving_enabled' => true, 'analytics_archive_after_days' => 90] + $config, 4, 2));
    console($root, ['app:analytics:glossary:sync']);
    console($root, ['app:analytics:views:regenerate']);
    console($root, ['app:analytics:maintain']);
    $db = connection($root);
    E2E::check((int) $db->fetchOne('SELECT COUNT(*) FROM analytics_archive_events') > 0, 'No events were archived.');
    E2E::check((int) $db->fetchOne('SELECT COUNT(*) FROM analytics_archive_goals') > 0, 'No goals were archived.');
    E2E::check((int) $db->fetchOne('SELECT COUNT(*) FROM events WHERE archived_at IS NOT NULL') === 12, 'Archived rows were not marked.');
    // A second run must not count the same rows twice.
    console($root, ['app:analytics:maintain']);
    E2E::check((int) $db->fetchOne('SELECT SUM(event_count) FROM analytics_archive_events') === 12, 'Archive totals changed on a second run.');
});

E2E::step('reporting views return the expected cells', static function () use ($root): void {
    $db = connection($root);
    $views = $db->createSchemaManager()->introspectViews();
    $names = [];
    foreach ($views as $view) {
        $name = $view->getObjectName()->getUnqualifiedName()->getValue();
        $names[] = $name;
        $db->fetchOne('SELECT COUNT(*) FROM '.$name);
    }
    foreach (['bi_anonymous_events_v1', 'bi_anonymous_goals_v1', 'bi_anonymous_geo_events_v1', 'bi_glossary_values_v1', 'bi_glossary_columns_v1', 'analytics_archived_events_v1'] as $required) {
        E2E::check(in_array($required, $names, true), $required.' is missing; views: '.implode(', ', $names));
    }
    $pages = $db->fetchAllKeyValue("SELECT page_path, SUM(event_count) FROM bi_anonymous_events_v1 WHERE event_name = 'view' GROUP BY page_path");
    E2E::check((int) ($pages['/pricing'] ?? 0) === 6 && (int) ($pages['/old-page'] ?? 0) === 6, 'bi_anonymous_events_v1 views: '.json_encode($pages));
    E2E::check(!isset($pages['/account']), 'Consented events leaked into the anonymous view.');
    $goals = $db->fetchAllKeyValue('SELECT goal_event, SUM(event_count) FROM bi_anonymous_goals_v1 GROUP BY goal_event');
    E2E::check((int) ($goals['purchase'] ?? 0) === 6 && (int) ($goals['lead'] ?? 0) === 6, 'bi_anonymous_goals_v1: '.json_encode($goals));
    E2E::check((int) $db->fetchOne("SELECT COUNT(*) FROM bi_glossary_values_v1 WHERE dimension = 'goal_event' AND code = 'purchase'") === 1, 'The glossary lacks the purchase goal.');
    printf("      (%d views)\n", count($names));
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

E2E::stop();
echo "Fresh install on {$driver}: all steps passed\n";
