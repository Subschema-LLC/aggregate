<?php

namespace App\Controller;

use App\Message\TrackEventMessage;
use App\Service\WebsiteConfigManager;
use App\Security\IpRateLimiter;
use App\Service\AnonymousEventRecorder;
use App\Service\GeoIp\GeoIpResolverInterface;
use App\Service\GoalEventRegistry;
use App\Service\InternalTrafficSettings;
use App\Service\PrivacyPolicy;
use App\Service\PrivacySanitizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

class ReceiveController
{
    #[Route('/api/receive', name: 'api_receive', methods: ['POST'])]
    public function __invoke(
        Request $request,
        WebsiteConfigManager $websiteManager,
        MessageBusInterface $bus,
        IpRateLimiter $rateLimiter,
        PrivacySanitizer $sanitizer,
        GoalEventRegistry $goalEvents,
        PrivacyPolicy $privacyPolicy,
        GeoIpResolverInterface $geoIpResolver,
        AnonymousEventRecorder $anonymousRecorder,
        LoggerInterface $logger,
        InternalTrafficSettings $internalTrafficSettings,
    ): Response
    {
        try {
            if (strlen($request->getContent()) > 65_536) {
                return $this->jsonWithCors($request, ['error' => 'Payload is too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }

            try {
                $payload = $this->decodePayload($request);
            } catch (\InvalidArgumentException) {
                return $this->jsonWithCors($request, ['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
            }

            // Despite its legacy name, this is the deployment-wide ingestion
            // kill switch. Check it before deriving any request-based bucket.
            if (!$privacyPolicy->isAnonymousTrackingEnabled()) {
                return $this->jsonWithCors($request, ['status' => 'ignored'], Response::HTTP_ACCEPTED);
            }

            // New clients send pagePath. url remains a compatibility input,
            // but its host, query and fragment are always discarded.
            $pagePath = $sanitizer->sanitizePagePath($payload['pagePath'] ?? $payload['url'] ?? null);

            // Exclusions are a server-side kill switch for sensitive routes,
            // including when a client claims enhanced consent. Valid excluded
            // paths are ignored before a local IP rate-limit bucket is made.
            if ($pagePath !== null && $privacyPolicy->isExcludedPath($pagePath)) {
                return $this->jsonWithCors($request, ['status' => 'ignored'], Response::HTTP_ACCEPTED);
            }

            // Apply the rate limiter only after the zero-collection controls.
            // Excluded/disabled requests therefore create no local IP bucket.
            // Other valid and invalid requests use a short-lived rotating HMAC
            // bucket; the raw address never enters Messenger or analytics.
            $ip = $request->getClientIp() ?? '0.0.0.0';
            if (!$rateLimiter->allow($ip)) {
                return $this->jsonWithCors($request, ['error' => 'Too Many Requests'], Response::HTTP_TOO_MANY_REQUESTS);
            }

            // Find website by public token
            $websiteToken = is_string($payload['websiteToken'] ?? null)
                ? trim($payload['websiteToken'])
                : '';
            if ($websiteToken === '' || strlen($websiteToken) > 191) {
                return $this->jsonWithCors($request, ['error' => 'websiteToken is required'], Response::HTTP_BAD_REQUEST);
            }

            $website = $websiteManager->findOneByToken($websiteToken);
            if (!$website) {
                return $this->jsonWithCors($request, ['error' => 'Invalid websiteToken'], Response::HTTP_BAD_REQUEST);
            }

            // Domain whitelisting using Origin or Referer
            $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');
            if (!$this->isOriginAllowed($origin, $website['domain'])) {
                return $this->jsonWithCors($request, ['error' => 'Forbidden origin'], Response::HTTP_FORBIDDEN);
            }

            if ($pagePath === null) {
                return $this->jsonWithCors($request, ['error' => 'pagePath is required'], Response::HTTP_BAD_REQUEST);
            }

            $referrerChannel = $sanitizer->sanitizeReferrerChannel(
                $payload['referrerChannel'] ?? null,
                $payload['referrer'] ?? null,
                (string) $website['domain'],
            );
            $userAgent = (string) $request->headers->get('User-Agent', '');
            $eventName = $sanitizer->sanitizeEventName($payload['eventName'] ?? null);
            if ($eventName === null) {
                return $this->jsonWithCors($request, ['error' => 'eventName is invalid'], Response::HTTP_BAD_REQUEST);
            }
            $enhancedConsent = $privacyPolicy->hasEnhancedConsent($payload['consentState'] ?? null);
            // Accept only the coarse boolean, never a client-supplied marker
            // name/value or truthy strings that could misclassify traffic.
            $internalTraffic = ($payload['internalTraffic'] ?? false) === true;
            // The deployment chooses the JSON key, and queued events keep this
            // name even if settings change before the worker handles them.
            $internalTrafficName = $internalTrafficSettings->toBrowserConfig()['name'];
            $submittedGoal = $payload['goalEvent'] ?? null;
            $goalEvent = $goalEvents->resolve($submittedGoal, anonymousMode: !$enhancedConsent);
            $goalWasRejected = $goalEvents->wasSubmitted($submittedGoal) && $goalEvent === null;
            unset($submittedGoal);
            $deviceClass = $sanitizer->sanitizeDeviceClass($payload['deviceClass'] ?? null, $userAgent);
            $viewportBucket = $sanitizer->sanitizeViewportBucket(
                $payload['viewportBucket'] ?? null,
                $payload['screenWidth'] ?? null,
            );
            $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            // The resolver returns only a canonical country/continent code. It
            // performs no network calls and never exposes the source address or
            // detailed MMDB record to events, Messenger or logs.
            $geoArea = $geoIpResolver->resolve($ip)?->value();
            unset($ip);

            if (!$enhancedConsent) {
                // Every safe named event is accepted anonymously, but attached
                // identifiers and custom properties are never copied. Goals
                // must be explicitly approved for anonymous collection.
                $anonymousRecorder->record(
                    websiteToken: $websiteToken,
                    eventName: $eventName,
                    pagePath: $pagePath,
                    referrerChannel: $referrerChannel,
                    deviceClass: $deviceClass,
                    viewportBucket: $viewportBucket,
                    geoArea: $geoArea,
                    occurredAt: $occurredAt,
                    goalEvent: $goalEvent,
                    internalTraffic: $internalTraffic,
                    internalTrafficName: $internalTrafficName,
                );

                $responsePayload = [
                    'status' => 'recorded',
                    'mode' => 'anonymous',
                ];
                if ($goalWasRejected) {
                    $responsePayload['warnings'] = ['goal_not_allowed'];
                }

                return $this->jsonWithCors($request, $responsePayload, Response::HTTP_ACCEPTED);
            }

            $eventData = $sanitizer->sanitizeEventData($payload['eventData'] ?? null);
            // The reserved reporting key is derived only from the top-level
            // boolean, including before enhanced events enter the queue.
            unset($eventData[$internalTrafficName]);

            $bus->dispatch(new TrackEventMessage(
                websiteToken: $websiteToken,
                eventName: $eventName,
                pagePath: $pagePath,
                referrerChannel: $referrerChannel,
                deviceClass: $deviceClass,
                viewportBucket: $viewportBucket,
                screenWidth: $sanitizer->sanitizeScreenWidth($payload['screenWidth'] ?? null),
                goalEvent: $goalEvent,
                eventData: $eventData ?: null,
                generalizedUserAgent: $sanitizer->generalizeUserAgent($userAgent),
                visitorId: $sanitizer->sanitizeIdentifier($payload['visitorId'] ?? null),
                sessionId: $sanitizer->sanitizeIdentifier($payload['sessionId'] ?? null),
                occurredAt: $occurredAt,
                geoArea: $geoArea,
                internalTraffic: $internalTraffic,
                internalTrafficName: $internalTrafficName,
            ));

            $responsePayload = [
                'status' => 'accepted',
                'mode' => 'enhanced',
            ];
            if ($goalWasRejected) {
                $responsePayload['warnings'] = ['goal_not_allowed'];
            }

            return $this->jsonWithCors($request, $responsePayload, Response::HTTP_ACCEPTED);
        } catch (\Throwable $e) {
            $logger->error('Failed to ingest analytics event', [
                // Do not hand the exception or request metadata to a logger:
                // transport/SQL exceptions can retain payload values.
                'exception_class' => $e::class,
            ]);

            return $this->jsonWithCors($request, ['error' => 'Ingestion failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/receive', name: 'api_receive_options', methods: ['OPTIONS'])]
    public function options(Request $request): Response
    {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        $this->applyCorsHeaders($response, $request);

        return $response;
    }

    private function jsonWithCors(Request $request, array $payload, int $status): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $this->applyCorsHeaders($response, $request);

        return $response;
    }

    private function applyCorsHeaders(Response $response, Request $request): void
    {
        $origin = $request->headers->get('Origin');
        if (!$origin) {
            return;
        }

        $requestHeaders = $request->headers->get('Access-Control-Request-Headers');
        $allowHeaders = $requestHeaders ?: 'Content-Type';

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', $allowHeaders);
        $response->headers->set('Access-Control-Max-Age', '86400');
    }

    private function decodePayload(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invalid JSON payload');
        }

        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload');
        }

        return $payload;
    }

    private function isOriginAllowed(?string $originHeader, string $expectedDomain): bool
    {
        if (!$originHeader) { return false; }
        $domain = $this->extractDomain($originHeader);
        if (!$domain) { return false; }
        $expectedDomain = strtolower(trim($expectedDomain, " \t\n\r\0\x0B."));
        if ($expectedDomain === '') { return false; }
        // Allow subdomains of expectedDomain
        return $domain === $expectedDomain || str_ends_with($domain, '.' . $expectedDomain);
    }

    private function extractDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) { return null; }
        return strtolower($host);
    }
}
