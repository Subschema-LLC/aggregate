<?php

namespace App\Controller;

use App\Dto\TrackEventDto;
use App\Message\TrackEventMessage;
use App\Service\WebsiteConfigManager;
use App\Security\IpRateLimiter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

class ReceiveController
{
    public function __construct(
        private readonly WebsiteConfigManager $websiteManager,
        private readonly MessageBusInterface $bus,
        private readonly IpRateLimiter $rateLimiter,
    ) {}

    #[Route('/api/receive', name: 'api_receive', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload] TrackEventDto $dto
    ): Response
    {
        // Naive rate limit per IP
        $ip = $request->getClientIp() ?? '0.0.0.0';
        if (!$this->rateLimiter->allow($ip)) {
            return new JsonResponse(['error' => 'Too Many Requests'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Find website by public token
        $website = $this->websiteManager->findOneByToken($dto->websiteToken);
        if (!$website) {
            return $this->jsonWithCors($request, ['error' => 'Invalid websiteToken'], Response::HTTP_BAD_REQUEST);
        }

        // Domain whitelisting using Origin or Referer
        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        if (!$this->isOriginAllowed($origin, $website['domain'])) {
            return $this->jsonWithCors($request, ['error' => 'Forbidden origin'], Response::HTTP_FORBIDDEN);
        }

        $ua = $request->headers->get('User-Agent', '');

        $this->bus->dispatch(new TrackEventMessage(
            websiteToken: $dto->websiteToken,
            url: $dto->url,
            referrer: $dto->referrer,
            screenWidth: $dto->screenWidth,
            eventName: $dto->eventName,
            goalEvent: $dto->goalEvent,
            eventData: $dto->eventData,
            ip: $ip,
            userAgent: $ua,
            visitorId: $dto->visitorId,
            sessionId: $dto->sessionId
        ));

        return $this->jsonWithCors($request, ['status' => 'accepted'], Response::HTTP_ACCEPTED, $website['domain']);
    }

    #[Route('/api/receive', name: 'api_receive_options', methods: ['OPTIONS'])]
    public function options(Request $request): Response
    {
        $origin = $request->headers->get('Origin');
        if (!$this->isOriginAllowedForConfiguredWebsites($origin)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $this->applyCorsHeaders($response, $request);

        return $response;
    }

    private function jsonWithCors(Request $request, array $payload, int $status, ?string $expectedDomain = null): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $this->applyCorsHeaders($response, $request, $expectedDomain);

        return $response;
    }

    private function applyCorsHeaders(Response $response, Request $request, ?string $expectedDomain = null): void
    {
        $origin = $request->headers->get('Origin');
        if (!$origin) {
            return;
        }

        $originAllowed = $expectedDomain !== null
            ? $this->isOriginAllowed($origin, $expectedDomain)
            : $this->isOriginAllowedForConfiguredWebsites($origin);

        if (!$originAllowed) {
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

    private function isOriginAllowedForConfiguredWebsites(?string $originHeader): bool
    {
        if (!$originHeader) {
            return false;
        }

        foreach ($this->websiteManager->getWebsites() as $website) {
            $domain = $website['domain'] ?? '';
            if ($domain !== '' && $this->isOriginAllowed($originHeader, $domain)) {
                return true;
            }
        }

        return false;
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
