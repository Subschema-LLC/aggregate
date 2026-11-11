<?php

declare(strict_types=1);

/*
 * Sends one collection request through the production kernel in this fresh
 * process and prints, as JSON, the classes, interfaces and traits it loaded
 * after the kernel booted. CollectionRequestFootprintTest runs it to check that
 * collection never builds the dashboard, mailer or ORM services again.
 *
 *   php tests/Integration/collection-request-classes.php /tmp/cache-dir
 *
 * The container is compiled into the given directory, not var/cache. Compiling
 * loads nearly every class, so run it once with --warm-up first: the second,
 * fresh process then loads only what the request needs, like a worker.
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$cacheDir = $argv[1] ?? throw new InvalidArgumentException('Pass a cache directory.');

final class CollectionFootprintKernel extends Kernel
{
    public static string $root = '';
    public static string $cache = '';

    public function getProjectDir(): string
    {
        return self::$root;
    }

    public function getCacheDir(): string
    {
        return self::$cache.'/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return self::$cache.'/log';
    }
}

CollectionFootprintKernel::$root = $root;
CollectionFootprintKernel::$cache = $cacheDir;
(new Dotenv())->bootEnv($root.'/.env');

$kernel = new CollectionFootprintKernel('prod', false);
$kernel->boot();
if (in_array('--warm-up', $argv, true)) {
    exit(0);
}

$known = static fn (): array => array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
$before = $known();

// An unconfigured token: the request still passes every listener, builds the
// controller and its services, and ends at the website check.
$request = Request::create('/api/receive', 'POST', server: [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ORIGIN' => 'https://footprint.example',
    'REMOTE_ADDR' => '198.19.255.254',
], content: json_encode(['websiteToken' => str_repeat('0', 32), 'eventName' => 'view', 'pagePath' => '/', 'consentState' => 'denied'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
$kernel->terminate($request, $response);

$loaded = array_values(array_diff($known(), $before));
sort($loaded);
echo json_encode(['status' => $response->getStatusCode(), 'body' => (string) $response->getContent(), 'classes' => $loaded], JSON_THROW_ON_ERROR), "\n";
