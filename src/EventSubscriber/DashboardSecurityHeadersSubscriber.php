<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Browser hardening headers for /dashboard, /login and /install responses.
 *
 * A per-request nonce is generated before the controller renders templates so
 * base.html.twig, ImportMapRenderer and inline dashboard scripts carry the
 * matching nonce attribute while un-nonced inline scripts and framing are
 * blocked.
 */
final class DashboardSecurityHeadersSubscriber implements EventSubscriberInterface
{
    public const NONCE_ATTRIBUTE = 'csp_nonce';
    private const PROTECTED_PATH = '#^/(?:dashboard|login|install)(?:/|$)#';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 64],
            KernelEvents::RESPONSE => ['onResponse', -1024],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!self::appliesTo($request)) {
            return;
        }

        self::nonceFor($request);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!self::appliesTo($request)) {
            return;
        }

        self::apply($event->getResponse(), self::nonceFor($request), $request->isSecure());
    }

    public static function appliesTo(Request $request): bool
    {
        return preg_match(self::PROTECTED_PATH, $request->getPathInfo()) === 1;
    }

    public static function nonceFor(Request $request): string
    {
        $existing = $request->attributes->get(self::NONCE_ATTRIBUTE);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set(self::NONCE_ATTRIBUTE, $nonce);

        return $nonce;
    }

    /**
     * HSTS goes only on HTTPS responses, and without includeSubDomains: an
     * installation served under a path of a company's main domain must not
     * force HTTPS on every other subdomain. Operators can widen it in the web
     * server.
     */
    public static function apply(Response $response, string $nonce, bool $secure = true): Response
    {
        $response->headers->set('Content-Security-Policy', self::csp($nonce));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($secure) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if (!$response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'same-origin');
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        return $response;
    }

    public static function csp(string $nonce): string
    {
        return "default-src 'none'; "
            ."script-src 'self' 'nonce-{$nonce}'; "
            ."style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
            ."img-src 'self' data:; "
            ."font-src 'self' https://cdnjs.cloudflare.com data:; "
            ."connect-src 'self'; "
            ."form-action 'self'; "
            ."frame-ancestors 'none'; "
            ."base-uri 'none'";
    }
}
