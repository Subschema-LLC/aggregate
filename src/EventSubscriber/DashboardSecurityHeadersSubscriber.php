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
    public const FORM_ACTION_ATTRIBUTE = 'csp_form_action';
    private const PROTECTED_PATH = '#^/(?:dashboard|login|install)(?:/|$)#';
    /** scheme://host[:port] only: nothing that could end the directive or widen it to a whole scheme. */
    private const FORM_ACTION_SOURCE = '#^https?://[a-z0-9-]+(?:\.[a-z0-9-]+)*(?::\d{1,5})?$#';

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

        self::apply($event->getResponse(), self::nonceFor($request), $request->isSecure(), self::formActionsFor($request));
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
     * Lets this response's forms lead to one more origin. Browsers apply
     * form-action to every redirect that follows a form post, and report the
     * page's own address when they block one, so a form answered with a
     * redirect elsewhere (the assistant sign-in page returns the browser to
     * the assistant) fails with a confusing "form-action 'self'" error unless
     * that origin is listed here.
     *
     * @throws \InvalidArgumentException when $source is not scheme://host[:port]
     */
    public static function allowFormAction(Request $request, string $source): void
    {
        if (preg_match(self::FORM_ACTION_SOURCE, $source) !== 1) {
            throw new \InvalidArgumentException('A form-action source must be http(s)://host[:port].');
        }
        $sources = self::formActionsFor($request);
        if (!in_array($source, $sources, true)) {
            $sources[] = $source;
        }
        $request->attributes->set(self::FORM_ACTION_ATTRIBUTE, $sources);
    }

    /** @return list<string> */
    public static function formActionsFor(Request $request): array
    {
        $sources = $request->attributes->get(self::FORM_ACTION_ATTRIBUTE);

        return is_array($sources) ? array_values(array_filter($sources, 'is_string')) : [];
    }

    /**
     * HSTS goes only on HTTPS responses, and without includeSubDomains: an
     * installation served under a path of a company's main domain must not
     * force HTTPS on every other subdomain. Operators can widen it in the web
     * server.
     */
    /** @param list<string> $formActions see allowFormAction() */
    public static function apply(Response $response, string $nonce, bool $secure = true, array $formActions = []): Response
    {
        $response->headers->set('Content-Security-Policy', self::csp($nonce, $formActions));
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

    /** @param list<string> $formActions see allowFormAction(); anything else is dropped */
    public static function csp(string $nonce, array $formActions = []): string
    {
        $formAction = implode(' ', array_unique([
            "'self'",
            ...array_filter($formActions, static fn (mixed $source): bool => is_string($source) && preg_match(self::FORM_ACTION_SOURCE, $source) === 1),
        ]));

        return "default-src 'none'; "
            ."script-src 'self' 'nonce-{$nonce}'; "
            ."style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
            ."img-src 'self' data:; "
            ."font-src 'self' https://cdnjs.cloudflare.com data:; "
            ."connect-src 'self'; "
            ."form-action {$formAction}; "
            ."frame-ancestors 'none'; "
            ."base-uri 'none'";
    }
}
