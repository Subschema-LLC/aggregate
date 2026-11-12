<?php

declare(strict_types=1);

/*
 * Several servers sharing one database, set up from the command line the way
 * the server roles guide describes (docs/CONFIGURATION.md#server-roles):
 *
 *   admin      the administration server, http://127.0.0.1:8100
 *   collect-1  a collection server, http://127.0.0.1:8101
 *   collect-2  a collection server, http://127.0.0.1:8102
 *   worker-1   a worker server (doctrine:// only), no web server
 *   balancer   Nginx at http://127.0.0.2:8180, from app:server:balancer-config
 *
 * It checks what each role serves and refuses, the configuration pull, that
 * events sent to the balancer are recorded once, that a paused collection
 * server's traffic goes to the other, the balancer's rate limit, a files-only
 * deployment on a collection server, and the queue retention purge.
 *
 * It copies this checkout into sibling folders and writes their configuration
 * and the database, so it refuses to run unless AGGREGATE_E2E_DISPOSABLE=1:
 *
 *   AGGREGATE_E2E_DISPOSABLE=1 AGGREGATE_E2E_DRIVER=mysql|pgsql \
 *   AGGREGATE_E2E_QUEUE=sync|doctrine AGGREGATE_E2E_DB_HOST=127.0.0.1 \
 *   AGGREGATE_E2E_DB_PORT=3306 AGGREGATE_E2E_DB_NAME=aggregate \
 *   AGGREGATE_E2E_DB_USER=... AGGREGATE_E2E_DB_PASSWORD=... \
 *   php tests/Integration/cluster.php
 *
 * The database must be empty, and nginx must be installed.
 */

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

if (getenv('AGGREGATE_E2E_DISPOSABLE') !== '1') {
    fwrite(STDERR, "This test writes server copies beside the checkout and fills the database. Run it only in a disposable environment with AGGREGATE_E2E_DISPOSABLE=1.\n");
    exit(2);
}

$driver = (string) getenv('AGGREGATE_E2E_DRIVER');
$queue = (string) (getenv('AGGREGATE_E2E_QUEUE') ?: 'sync');
$db = [
    'host' => (string) (getenv('AGGREGATE_E2E_DB_HOST') ?: '127.0.0.1'),
    'port' => (string) (getenv('AGGREGATE_E2E_DB_PORT') ?: ''),
    'name' => (string) getenv('AGGREGATE_E2E_DB_NAME'),
    'user' => (string) getenv('AGGREGATE_E2E_DB_USER'),
    'password' => (string) getenv('AGGREGATE_E2E_DB_PASSWORD'),
];
const ADMIN = 'http://127.0.0.1:8100';
const BALANCER = 'http://127.0.0.2:8180';
const ORIGIN = 'https://example.test';

final class Cluster
{
    public static string $root = '';
    public static string $work = '';
    /** @var array<string, Process> web servers and Nginx, by name */
    public static array $processes = [];

    public static function step(string $name, callable $body): mixed
    {
        $started = microtime(true);
        try {
            $result = $body();
            printf("  ok  %s (%.1fs)\n", $name, microtime(true) - $started);

            return $result;
        } catch (\Throwable $e) {
            $message = get_class($e).': '.$e->getMessage();
            printf("FAIL  %s\n      %s\n", $name, str_replace("\n", "\n      ", $message));
            $context = self::context();
            if ($context !== '') {
                echo "\n--- context ---\n".$context."\n";
            }
            if (getenv('GITHUB_ACTIONS') === 'true') {
                echo '::error title='.self::escape('Cluster ('.getenv('AGGREGATE_E2E_DRIVER').', '.getenv('AGGREGATE_E2E_QUEUE').'): '.$name, true).'::'.self::escape(substr($message."\n\n".$context, 0, 60000))."\n";
            }
            self::stop();
            exit(1);
        }
    }

    public static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public static function dir(string $server): string
    {
        return self::$work.'/'.$server;
    }

    public static function stop(): void
    {
        foreach (self::$processes as $process) {
            $process->stop(1);
        }
        self::$processes = [];
    }

    private static function context(): string
    {
        $parts = [];
        foreach (self::$processes as $name => $process) {
            $log = array_filter(explode("\n", $process->getOutput().$process->getErrorOutput()), static fn (string $line): bool => $line !== '' && !preg_match('/ (Accepted|Closing)$/', $line));
            if ($log !== []) {
                $parts[] = $name.":\n".implode("\n", array_slice($log, -15));
            }
        }
        foreach (glob(self::$work.'/*/var/log/*.log') ?: [] as $file) {
            $lines = array_slice(file($file, FILE_IGNORE_NEW_LINES) ?: [], -300);
            $errors = array_values(array_filter($lines, static fn (string $line): bool => (bool) preg_match('/"level_name":"(?:ERROR|CRITICAL|ALERT|EMERGENCY)"|PHP (?:Fatal|Warning)|Uncaught/', $line)));
            if ($errors !== []) {
                $parts[] = substr($file, strlen(self::$work) + 1).":\n".implode("\n", array_map(static fn (string $line): string => substr($line, 0, 1500), array_slice($errors, -5)));
            }
        }
        $error = self::$work.'/balancer/aggregate-balancer-error.log';
        if (is_file($error)) {
            $parts[] = "balancer error log:\n".implode("\n", array_slice(file($error, FILE_IGNORE_NEW_LINES) ?: [], -10));
        }

        return implode("\n\n", $parts);
    }

    private static function escape(string $value, bool $property = false): string
    {
        $value = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);

        return $property ? str_replace([':', ','], ['%3A', '%2C'], $value) : $value;
    }
}

Cluster::$root = $root;
Cluster::$work = dirname($root).'/aggregate-cluster-e2e';
register_shutdown_function(static fn () => Cluster::stop());

/** Without real variables, each server reads its own .env and .env.local, as on a web host. */
function cleanEnvironment(): array
{
    return ['APP_ENV' => false, 'APP_DEBUG' => false, 'APP_SECRET' => false, 'DATABASE_URL' => false, 'MESSENGER_TRANSPORT_DSN' => false,
        'MAILER_DSN' => false, 'SERVER_ROLE' => false, 'CLUSTER_KEY' => false, 'ADMINISTRATION_URL' => false, 'CLUSTER_SERVER_NAME' => false,
        'TRUSTED_PROXIES' => false, 'COLLECTION_BASE_URL' => false, 'DASHBOARD_ENABLED' => false];
}

/** @return array{0: int, 1: string} exit code and output */
function run(string $server, array $arguments, ?string $input = null, int $timeout = 600): array
{
    $process = new Process(['php', 'bin/console', ...$arguments, '--no-ansi'], Cluster::dir($server), cleanEnvironment(), $input, $timeout);
    $process->run();

    return [(int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

function console(string $server, array $arguments, ?string $input = null, int $timeout = 600): string
{
    [$code, $output] = run($server, $arguments, $input, $timeout);
    Cluster::check($code === 0, sprintf("%s: bin/console %s exited with %d:\n%s", $server, implode(' ', $arguments), $code, $output));

    return $output;
}

function refused(string $server, array $arguments, string $expected): void
{
    [$code, $output] = run($server, $arguments);
    Cluster::check($code !== 0 && str_contains(preg_replace('/\s+/', ' ', $output), $expected), sprintf('%s should refuse %s with "%s", but it exited %d: %s', $server, implode(' ', $arguments), $expected, $code, $output));
}

function connection(): Connection
{
    $environment = (new Dotenv())->parse((string) file_get_contents(Cluster::dir('admin').'/.env.local'));

    return DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($environment['DATABASE_URL']));
}

function events(): int
{
    return (int) connection()->fetchOne('SELECT COUNT(*) FROM events');
}

/** @return int the HTTP status */
function collect(array $event, string $base = BALANCER): int
{
    $token = (string) (Yaml::parseFile(Cluster::dir('admin').'/config/websites.yaml')['websites'][0]['token'] ?? '');
    $response = HttpClient::create()->request('POST', $base.'/api/receive', [
        'headers' => ['Origin' => ORIGIN, 'Content-Type' => 'application/json'],
        'body' => json_encode(['websiteToken' => $token] + $event + ['consentState' => 'denied'], \JSON_THROW_ON_ERROR),
    ]);

    return $response->getStatusCode();
}

function status(string $url): int
{
    return HttpClient::create(['max_redirects' => 0])->request('GET', $url)->getStatusCode();
}

function serve(string $server, int $port): void
{
    $process = new Process(['php', '-S', '127.0.0.1:'.$port, '-t', 'public', Cluster::$root.'/tests/Integration/router.php'], Cluster::dir($server), cleanEnvironment() + ['PHP_CLI_SERVER_WORKERS' => '2'], null, null);
    $process->start();
    Cluster::$processes[$server] = $process;
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        if (@fsockopen('127.0.0.1', $port) !== false) {
            return;
        }
        usleep(100000);
    }
    throw new \RuntimeException($server.'\'s web server did not start.');
}

/**
 * Requests a web server has taken so far. PHP's server logs no request line
 * for the application's responses, but it opens one connection per request.
 */
function requests(string $server): int
{
    $log = Cluster::$processes[$server]->getErrorOutput();

    return preg_match_all('/ Accepted$/m', $log) - preg_match_all('/ Closed without sending a request/', $log);
}

function sync(string ...$servers): void
{
    foreach ($servers as $server) {
        console($server, ['app:cluster:sync']);
    }
}

function nginx(): string
{
    return (new ExecutableFinder())->find('nginx', null, ['/usr/sbin', '/usr/local/sbin']) ?? throw new \RuntimeException('Install nginx first.');
}

/**
 * Writes the balancer's configuration from the administration server and
 * starts Nginx, or restarts it: a reload keeps each address's rate-limit
 * state from the previous limit.
 */
function balancer(): void
{
    if (isset(Cluster::$processes['balancer'])) {
        Cluster::$processes['balancer']->stop(5);
        unset(Cluster::$processes['balancer']);
    }
    $directory = Cluster::dir('balancer');
    @mkdir($directory.'/temp', 0777, true);
    // Standard output only: the command's messages go to standard error.
    $generate = new Process(['php', 'bin/console', 'app:server:balancer-config', '--nginx', '--no-ansi'], Cluster::dir('admin'), cleanEnvironment());
    $generate->run();
    Cluster::check($generate->isSuccessful(), 'app:server:balancer-config failed: '.$generate->getErrorOutput());
    $site = $generate->getOutput();
    if (!is_file('/proc/net/if_inet6') || trim((string) file_get_contents('/proc/net/if_inet6')) === '') {
        $site = (string) preg_replace('/^\s*listen \[::\].*\n/m', '', $site);
    }
    file_put_contents($directory.'/site.conf', str_replace('/var/log/nginx/', $directory.'/', $site));
    $temp = $directory.'/temp';
    file_put_contents($directory.'/nginx.conf', <<<CONF
        pid {$directory}/nginx.pid;
        error_log {$directory}/error.log;
        events {}
        http {
            client_body_temp_path {$temp}/body;
            proxy_temp_path {$temp}/proxy;
            fastcgi_temp_path {$temp}/fastcgi;
            uwsgi_temp_path {$temp}/uwsgi;
            scgi_temp_path {$temp}/scgi;
            include {$directory}/site.conf;
        }
        CONF);
    $command = [nginx(), '-p', $directory, '-c', $directory.'/nginx.conf', '-e', $directory.'/error.log'];
    $test = new Process([...$command, '-t']);
    $test->run();
    Cluster::check($test->isSuccessful(), "Nginx refused the balancer configuration:\n".$test->getErrorOutput()."\n".$site);
    $process = new Process([...$command, '-g', 'daemon off;']);
    $process->start();
    Cluster::$processes['balancer'] = $process;
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        if (@fsockopen('127.0.0.2', 8180) !== false) {
            return;
        }
        usleep(100000);
    }
    throw new \RuntimeException('Nginx did not start: '.$process->getErrorOutput());
}

$servers = ['admin' => 8100, 'collect-1' => 8101, 'collect-2' => 8102] + ($queue === 'doctrine' ? ['worker-1' => null] : []);
$secret = bin2hex(random_bytes(16));
$databaseUrl = match ($driver) {
    'mysql' => sprintf('mysql://%s:%s@%s:%s/%s?serverVersion=%s', rawurlencode($db['user']), rawurlencode($db['password']), $db['host'], $db['port'] ?: '3306', $db['name'], getenv('AGGREGATE_E2E_DB_VERSION') ?: '10.11.0-MariaDB'),
    'pgsql' => sprintf('postgresql://%s:%s@%s:%s/%s?serverVersion=%s', rawurlencode($db['user']), rawurlencode($db['password']), $db['host'], $db['port'] ?: '5432', $db['name'], getenv('AGGREGATE_E2E_DB_VERSION') ?: '16'),
    default => throw new \RuntimeException('Set AGGREGATE_E2E_DRIVER to mysql or pgsql: a cluster needs a database every server can reach.'),
};
$transport = $queue === 'doctrine' ? 'doctrine://default' : 'sync://';
echo "Cluster on {$driver} with {$transport}\n";

Cluster::step('copy the release to each server', static function () use ($root, $servers): void {
    (new Process(['rm', '-rf', Cluster::$work]))->run();
    foreach (array_keys($servers) as $server) {
        $directory = Cluster::dir($server);
        mkdir($directory, 0775, true);
        $copy = new Process(['rsync', '-a', '--exclude=/.git', '--exclude=/vendor', '--exclude=/var', '--exclude=/node_modules', '--exclude=/website', '--exclude=/.env.local', $root.'/', $directory.'/']);
        $copy->run();
        Cluster::check($copy->isSuccessful(), 'rsync: '.$copy->getErrorOutput());
        // Hard links share the dependencies without copying them; nothing writes to vendor/.
        $link = new Process(['cp', '-al', $root.'/vendor', $directory.'/vendor']);
        $link->run();
        if (!$link->isSuccessful()) {
            (new Process(['cp', '-a', $root.'/vendor', $directory.'/vendor']))->mustRun();
        }
        mkdir($directory.'/var', 0775, true);
        file_put_contents($directory.'/.env', "APP_ENV=prod\nAPP_DEBUG=0\nAPP_SECRET=\nTRUSTED_PROXIES=\nDATABASE_URL=\"sqlite:///%kernel.project_dir%/var/data.db\"\nMESSENGER_TRANSPORT_DSN=sync://\nMAILER_DSN=null://null\n");
        foreach (['config/aggregate.yaml', 'config/websites.yaml', 'release.json'] as $file) {
            @unlink($directory.'/'.$file);
        }
    }
});

$key = Cluster::step('install the administration server', static function () use ($secret, $databaseUrl, $transport, $queue): string {
    file_put_contents(Cluster::dir('admin').'/.env.local', "APP_SECRET={$secret}\nDATABASE_URL=\"{$databaseUrl}\"\nMESSENGER_TRANSPORT_DSN={$transport}\n");
    file_put_contents(Cluster::dir('admin').'/config/aggregate.yaml', Yaml::dump(['app_host' => ADMIN, 'rate_limit_per_minute' => 1000, 'cluster_config_max_age_minutes' => 1]));
    console('admin', ['doctrine:migrations:migrate', '-n', '--allow-no-migration'], null, 1200);
    console('admin', ['app:install', '--username=admin', '--password=Cluster-e2e-password-1']);
    console('admin', ['app:create-website'], "Cluster site\nexample.test\n");
    console('admin', ['app:collection-address', BALANCER]);
    console('admin', ['app:cluster:key']);
    preg_match('/[A-Za-z0-9_\-+\/=]{32,}/', console('admin', ['app:cluster:key', '--show']), $match);
    Cluster::check(isset($match[0]), 'app:cluster:key --show printed no key.');
    console('admin', ['app:cluster:join', '--stdin'], json_encode(['role' => 'administration', 'name' => 'admin', 'cluster_key' => $match[0], 'queue' => $queue], \JSON_THROW_ON_ERROR));
    console('admin', ['app:cluster:server', 'add', 'collect-1', '127.0.0.1', '--role=collection', '--port=8101']);
    console('admin', ['app:cluster:server', 'add', 'collect-2', '127.0.0.1', '--role=collection', '--port=8102']);
    if ($queue === 'doctrine') {
        console('admin', ['app:cluster:server', 'add', 'worker-1', '127.0.0.1', '--role=worker']);
        console('admin', ['messenger:setup-transports']);
    }
    // app:cluster:join removed the cache built for the role all; no cache:clear is needed.
    $role = json_decode(console('admin', ['app:server:role', '--json']), true, flags: \JSON_THROW_ON_ERROR);
    Cluster::check($role['role'] === 'administration' && $role['compiled_role'] === 'administration', 'The administration server reports '.json_encode([$role['role'], $role['compiled_role'], $role['problem']]));
    serve('admin', 8100);
    sync('admin');

    return $match[0];
});

Cluster::step('join the other servers and pull their configuration', static function () use ($servers, $key, $secret, $databaseUrl, $queue): void {
    foreach (array_keys($servers) as $server) {
        if ($server === 'admin') {
            continue;
        }
        console($server, ['app:cluster:join', '--stdin'], json_encode([
            'role' => str_starts_with($server, 'worker') ? 'worker' : 'collection',
            'name' => $server,
            'administration_url' => ADMIN,
            'cluster_key' => $key,
            'database_url' => $databaseUrl,
            'trusted_proxies' => '127.0.0.1',
            'app_secret' => $secret,
            'queue' => $queue,
        ], \JSON_THROW_ON_ERROR));
        Cluster::check(!is_file(Cluster::dir($server).'/config/websites.yaml'), $server.' has a website list before its first pull.');
        Cluster::check(!is_dir(Cluster::dir($server).'/var/cache/prod'), $server.'\'s cache, built for the role all by the join itself, was not removed.');
        $output = console($server, ['app:cluster:sync']);
        Cluster::check(str_contains($output, 'Pulled configuration'), $server.' did not pull: '.$output);
        Cluster::check(is_file(Cluster::dir($server).'/config/websites.yaml'), $server.' pulled no website list.');
        $own = Yaml::parseFile(Cluster::dir($server).'/config/aggregate.yaml');
        Cluster::check(!isset($own['server_role']) && ($own['collection_base_url'] ?? null) === BALANCER, $server.'\'s pulled configuration: '.json_encode($own));
        $role = json_decode(console($server, ['app:server:role', '--json']), true, flags: \JSON_THROW_ON_ERROR);
        Cluster::check($role['compiled_role'] === $role['role'] && in_array($role['role'], ['collection', 'worker'], true), $server.' reports '.json_encode($role));
    }
    serve('collect-1', 8101);
    serve('collect-2', 8102);
    $list = json_decode(console('admin', ['app:cluster:server', '--json']), true, flags: \JSON_THROW_ON_ERROR);
    foreach ($list['servers'] as $server) {
        Cluster::check($server['newest_configuration'] === true && $server['reported_role'] === $server['role'], 'The administration server lists '.json_encode($server));
    }
});

Cluster::step('each role serves and runs only its own part', static function (): void {
    Cluster::check(status(ADMIN.'/api/health') === 200, 'The administration server is not healthy.');
    // Pages need the importmap packages, which CI installs before the copies are made.
    if (is_dir(Cluster::dir('admin').'/assets/vendor/@hotwired')) {
        Cluster::check(status(ADMIN.'/login') === 200, 'The administration server has no sign-in page.');
    } else {
        echo "      (sign-in page not checked: run importmap:install first)\n";
    }
    Cluster::check(collect(['eventName' => 'view', 'pagePath' => '/'], ADMIN) === 404, 'The administration server accepts events.');
    Cluster::check(status('http://127.0.0.1:8101/login') === 404 && status('http://127.0.0.1:8101/dashboard') === 404, 'A collection server serves the dashboard.');
    Cluster::check(status('http://127.0.0.1:8101/api/health') === 200, 'A collection server is not healthy.');
    refused('collect-1', ['doctrine:migrations:migrate', '-n'], 'runs on the administration server (http://127.0.0.1:8100)');
    refused('collect-1', ['app:create-website'], 'runs on the administration server');
    refused('collect-1', ['app:collection-address', 'https://other.example.com'], 'runs on the administration server');
    refused('collect-1', ['app:updates:apply', '--yes', '--database-backup-confirmed'], 'then update this server with --files-only');
    refused('admin', ['app:updates:apply', '--yes', '--files-only'], 'so it updates without --files-only');
});

$sent = Cluster::step('the balancer spreads events over the collection servers', static function () use ($queue): int {
    balancer();
    Cluster::check(status(BALANCER.'/api/health') === 200, 'The balancer does not reach a healthy collection server.');
    $before = events();
    $marks = [requests('collect-1'), requests('collect-2')];
    for ($i = 0; $i < 8; ++$i) {
        Cluster::check(($status = collect(['eventName' => 'view', 'pagePath' => '/pricing'])) < 300, 'POST /api/receive through the balancer answered '.$status);
    }
    for ($i = 0; $i < 2; ++$i) {
        Cluster::check(collect(['eventName' => 'view', 'pagePath' => '/account', 'consentState' => 'granted', 'visitorId' => 'cluster-visitor', 'sessionId' => 'cluster-session']) < 300, 'An enhanced event was refused.');
    }
    $anonymous = (int) connection()->fetchOne("SELECT COUNT(*) FROM events WHERE privacy_mode = 'anonymous'");
    if ($queue === 'doctrine') {
        $queued = (int) connection()->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'default'");
        Cluster::check($queued === 2, sprintf('%d enhanced events were queued, not 2.', $queued));
        console('worker-1', ['messenger:consume', 'async', '--limit=2', '--time-limit=60']);
    }
    $enhanced = (int) connection()->fetchOne("SELECT COUNT(*) FROM events WHERE privacy_mode = 'enhanced'");
    Cluster::check([$anonymous, $enhanced] === [8, 2] && events() - $before === 10, sprintf('Recorded %d anonymous and %d enhanced events, not 8 and 2.', $anonymous, $enhanced));
    [$one, $two] = [requests('collect-1') - $marks[0], requests('collect-2') - $marks[1]];
    Cluster::check($one > 0 && $two > 0 && $one + $two === 10, sprintf('collect-1 took %d events and collect-2 %d; both should have some, 10 in all.', $one, $two));

    return events();
});

Cluster::step('a configuration change reaches every collection server', static function () use ($sent): void {
    $config = Yaml::parseFile(Cluster::dir('admin').'/config/aggregate.yaml');
    $config['anonymous_excluded_paths'] = ['/private/*'];
    file_put_contents(Cluster::dir('admin').'/config/aggregate.yaml', Yaml::dump($config, 4, 2));
    sync('admin');
    sync('collect-1', 'collect-2');
    foreach (['collect-1', 'collect-2'] as $server) {
        Cluster::check((Yaml::parseFile(Cluster::dir($server).'/config/aggregate.yaml')['anonymous_excluded_paths'] ?? null) === ['/private/*'], $server.' did not get the exclusion.');
    }
    for ($i = 0; $i < 4; ++$i) {
        collect(['eventName' => 'view', 'pagePath' => '/private/report']);
    }
    Cluster::check(events() === $sent, 'Events on an excluded path were recorded: '.(events() - $sent));
});

Cluster::step('a paused collection server hands its traffic to the other', static function (): void {
    $state = Cluster::dir('collect-2').'/var/cluster_state.json';
    $current = json_decode((string) file_get_contents($state), true, flags: \JSON_THROW_ON_ERROR);
    file_put_contents($state, json_encode(['verified_at' => time() - 600] + $current, \JSON_THROW_ON_ERROR));
    Cluster::check(status('http://127.0.0.1:8102/api/health') === 503, 'collect-2 is not paused after 10 minutes without verifying.');
    Cluster::check(collect(['eventName' => 'view', 'pagePath' => '/'], 'http://127.0.0.1:8102') === 503, 'A paused server accepted an event.');
    $before = events();
    $refused = requests('collect-2');
    for ($i = 0; $i < 6; ++$i) {
        Cluster::check(($status = collect(['eventName' => 'view', 'pagePath' => '/failover'])) < 300, 'The balancer answered '.$status.' while one collection server was paused.');
    }
    Cluster::check(events() - $before === 6, sprintf('%d of 6 events were recorded while collect-2 was paused.', events() - $before));
    $refused = requests('collect-2') - $refused;
    Cluster::check($refused >= 1, 'The balancer never tried the paused server, so its 503 was not tested.');
    sync('collect-2');
    Cluster::check(status('http://127.0.0.1:8102/api/health') === 200, 'collect-2 did not resume after a good pull.');
    printf("      collect-2 refused %d event(s), which the balancer sent to collect-1\n", $refused);
});

Cluster::step('collection servers update files only, after the administration server', static function (): void {
    console('admin', ['app:updates:method', 'deployment']);
    sync('admin');
    sync('collect-1');
    refused('collect-1', ['app:updates:deployed', '--commit='.str_repeat('a', 40), '--database-backup-confirmed'], 'then update this server with --files-only');
    if (!is_dir(Cluster::dir('collect-1').'/assets/vendor/@hotwired')) {
        // The deployment steps install missing importmap packages, which needs the network.
        echo "      (files-only deployment not run: run importmap:install first)\n";

        return;
    }
    $output = console('collect-1', ['app:updates:deployed', '--files-only', '--commit='.str_repeat('a', 40)], null, 1800);
    Cluster::check(str_contains($output, 'The database already has this release\'s migrations') && str_contains($output, 'post-deployment steps are complete'), "The files-only deployment did not finish:\n".$output);
    Cluster::check(!str_contains($output, 'doctrine:migrations:migrate'), 'A collection server ran the migrations.');
    Cluster::check(status('http://127.0.0.1:8101/api/health') === 200, 'collect-1 is not healthy after its update.');
});

Cluster::step('the balancer limits events per address', static function (): void {
    $config = Yaml::parseFile(Cluster::dir('admin').'/config/aggregate.yaml');
    $config['rate_limit_per_minute'] = 3;
    file_put_contents(Cluster::dir('admin').'/config/aggregate.yaml', Yaml::dump($config, 4, 2));
    balancer();
    $statuses = [];
    for ($i = 0; $i < 10; ++$i) {
        $statuses[] = collect(['eventName' => 'view', 'pagePath' => '/limited']);
    }
    $limited = count(array_filter($statuses, static fn (int $status): bool => $status === 429));
    Cluster::check($limited >= 5 && $limited <= 7, 'The balancer limited '.$limited.' of 10 requests at 3 a minute (with a burst of 3): '.json_encode($statuses));
    Cluster::check(!str_contains((string) @file_get_contents(Cluster::dir('balancer').'/aggregate-balancer-error.log'), 'limiting requests'), 'The balancer logged a rate-limited address.');
});

Cluster::step('queued and failed messages older than the retention are deleted', static function (): void {
    $db = connection();
    if (!$db->createSchemaManager()->tablesExist(['messenger_messages'])) {
        console('admin', ['messenger:setup-transports']);
    }
    foreach (['30 days ago' => 'failed', '1 day ago' => 'default'] as $age => $queue) {
        $at = (new \DateTimeImmutable($age, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $db->insert('messenger_messages', ['body' => 'cluster-e2e', 'headers' => '[]', 'queue_name' => $queue, 'created_at' => $at, 'available_at' => $at]);
    }
    $before = json_decode(console('admin', ['app:queue:retention', '--json']), true, flags: \JSON_THROW_ON_ERROR);
    Cluster::check(($before['queues']['failed']['messages'] ?? 0) >= 1, 'app:queue:retention does not show the failed message: '.json_encode($before));
    console('admin', ['app:analytics:maintain']);
    $left = $db->fetchFirstColumn("SELECT queue_name FROM messenger_messages WHERE body = 'cluster-e2e'");
    Cluster::check($left === ['default'], 'After maintenance these test messages are left: '.json_encode($left));
    $db->executeStatement("DELETE FROM messenger_messages WHERE body = 'cluster-e2e'");
});

Cluster::stop();
$version = (string) connection()->fetchOne($driver === 'mysql' ? 'SELECT VERSION()' : 'SHOW server_version');
$summary = sprintf('All steps passed on %s %s with %s: three servers%s, one database, an Nginx balancer.', $driver, $version, $transport, $queue === 'doctrine' ? ' and a worker' : '');
echo "Cluster: {$summary}\n";
if (getenv('GITHUB_ACTIONS') === 'true') {
    echo '::notice title=Cluster ('.$driver.', '.$queue.')::'.$summary."\n";
}
