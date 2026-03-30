<?php

namespace App\Controller;

use App\Message\TrackEventMessage;
use App\Service\WebsiteConfigManager;
use App\Security\IpRateLimiter;
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
        LoggerInterface $logger,
    ): Response
    {
        try {
            $payload = $this->decodePayload($request);

            // Naive rate limit per IP
            $ip = $request->getClientIp() ?? '0.0.0.0';
            if (!$rateLimiter->allow($ip)) {
                return $this->jsonWithCors($request, ['error' => 'Too Many Requests'], Response::HTTP_TOO_MANY_REQUESTS);
            }

            // Find website by public token
            $websiteToken = trim((string) ($payload['websiteToken'] ?? ''));
            if ($websiteToken === '') {
                return $this->jsonWithCors($request, ['error' => 'websiteToken is required'], Response::HTTP_BAD_REQUEST);
            }

            $url = trim((string) ($payload['url'] ?? ''));
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                return $this->jsonWithCors($request, ['error' => 'url is required and must be valid'], Response::HTTP_BAD_REQUEST);
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

            $ua = $request->headers->get('User-Agent', '');

            $bus->dispatch(new TrackEventMessage(
                websiteToken: $websiteToken,
                url: $url,
                referrer: $this->normalizeOptionalUrl($payload['referrer'] ?? null),
                screenWidth: $this->normalizeOptionalInt($payload['screenWidth'] ?? null),
                eventName: $this->normalizeOptionalString($payload['eventName'] ?? null, 191),
                goalEvent: $this->normalizeOptionalString($payload['goalEvent'] ?? null, 191),
                eventData: $this->normalizeOptionalArray($payload['eventData'] ?? null),
                ip: $ip,
                userAgent: $ua,
                visitorId: $this->normalizeOptionalString($payload['visitorId'] ?? null, 255),
                sessionId: $this->normalizeOptionalString($payload['sessionId'] ?? null, 255),
                consentState: $this->normalizeConsentState($payload['consentState'] ?? null),
            ));

            return $this->jsonWithCors($request, ['status' => 'accepted'], Response::HTTP_ACCEPTED);
        } catch (\Throwable $e) {
            $logger->error('Failed to ingest analytics event', [
                'exception' => $e,
                'origin' => $request->headers->get('Origin'),
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

    private function normalizeOptionalString(mixed $value, int $maxLength): ?string
    {
        if (!is_scalar($value) || $value === '') {
            return null;
        }

        return substr((string) $value, 0, $maxLength);
    }

    private function normalizeOptionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function normalizeOptionalArray(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function normalizeConsentState(mixed $value): ?string
    {
        if (!is_scalar($value) || $value === '') {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'granted', 'denied', 'unknown' => $normalized,
            default => null,
        };
    }

    private function normalizeOptionalUrl(mixed $value): ?string
    {
        if (!is_scalar($value) || $value === '') {
            return null;
        }

        $url = trim((string) $value);

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function isOriginAllowed(?string $originHeader, string $expectedDomain): bool
    {
        if (!$originHeader) { return false; }
        $domain = $this->extractDomain($originHeader);
        if (!$domain) { return false; }
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
