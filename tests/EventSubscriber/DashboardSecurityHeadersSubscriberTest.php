<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\DashboardSecurityHeadersSubscriber;
use App\EventSubscriber\InternalTrafficResponseSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class DashboardSecurityHeadersSubscriberTest extends TestCase
{
    #[DataProvider('protectedPaths')]
    public function testProtectedPathsReceiveNonceBasedCspHstsAndHardeningHeaders(string $path): void
    {
        $subscriber = new DashboardSecurityHeadersSubscriber();
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('https://analytics.example.test'.$path);
        $response = new Response('<html></html>');

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $nonce = $request->attributes->get(DashboardSecurityHeadersSubscriber::NONCE_ATTRIBUTE);
        self::assertIsString($nonce);
        self::assertNotSame('', $nonce);

        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString("script-src 'self' 'nonce-{$nonce}'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("form-action 'self'", $csp);
        self::assertStringContainsString("base-uri 'none'", $csp);
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
        self::assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=(), payment=(), usb=()', $response->headers->get('Permissions-Policy'));
    }

    public function testPlainHttpResponsesGetCspButNoHsts(): void
    {
        $subscriber = new DashboardSecurityHeadersSubscriber();
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('http://localhost:8000/install');
        $response = new Response('<html></html>');

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public static function protectedPaths(): iterable
    {
        yield 'dashboard root' => ['/dashboard'];
        yield 'dashboard nested page' => ['/dashboard/settings/privacy'];
        yield 'mcp authorize page' => ['/dashboard/oauth/authorize'];
        yield 'login page' => ['/login'];
        yield 'login code page' => ['/login/code'];
        yield 'install page' => ['/install'];
        yield 'install execute' => ['/install/execute'];
    }

    public function testInternalTrafficDashboardRouteKeepsNoReferrerPolicy(): void
    {
        $subscriber = new DashboardSecurityHeadersSubscriber();
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/dashboard/internal-traffic');
        $response = InternalTrafficResponseSubscriber::protect(new Response('<html></html>'));

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    #[DataProvider('unprotectedPaths')]
    public function testPublicAndIngestionPathsAreNotModifiedByDashboardSubscriber(string $path): void
    {
        $subscriber = new DashboardSecurityHeadersSubscriber();
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create($path);
        $response = new Response('ok');

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertNull($request->attributes->get(DashboardSecurityHeadersSubscriber::NONCE_ATTRIBUTE));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public static function unprotectedPaths(): iterable
    {
        yield 'collection endpoint' => ['/api/receive'];
        yield 'tracker script' => ['/aggregate.js'];
        yield 'public home' => ['/'];
        yield 'public internal traffic share' => ['/internal-traffic/token'];
    }

    public function testTemplatesCarryNoInlineEventHandlersAndServerConfigsOmitDeprecatedXssHeader(): void
    {
        $templatesDir = dirname(__DIR__, 2).'/templates';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templatesDir));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression(
                '/\bon(?:click|submit|change|load|error|focus|blur|keydown|keyup|input)\s*=/i',
                $contents,
                sprintf('Template %s contains an inline event handler incompatible with CSP.', $file->getPathname()),
            );
        }

        foreach (['docs/nginx/aggregate-analytics.conf', 'docs/apache/aggregate-analytics.conf'] as $config) {
            $contents = (string) file_get_contents(dirname(__DIR__, 2).'/'.$config);
            self::assertStringNotContainsString('X-XSS-Protection', $contents, $config);
        }
    }
}
