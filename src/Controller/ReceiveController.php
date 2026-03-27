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
            return new JsonResponse(['error' => 'Invalid websiteToken'], Response::HTTP_BAD_REQUEST);
        }

        // Domain whitelisting using Origin or Referer
        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        if (!$this->isOriginAllowed($origin, $website['domain'])) {
            return new JsonResponse(['error' => 'Forbidden origin'], Response::HTTP_FORBIDDEN);
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

        return new JsonResponse(['status' => 'accepted'], Response::HTTP_ACCEPTED);
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
