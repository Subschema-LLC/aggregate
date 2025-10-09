<?php

namespace App\Controller;

use App\Dto\TrackEventDto;
use App\Message\TrackEventMessage;
use App\Repository\WebsiteRepository;
use App\Security\IpRateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ReceiveController
{
    public function __construct(
        private readonly WebsiteRepository $websites,
        private readonly ValidatorInterface $validator,
        private readonly MessageBusInterface $bus,
        private readonly IpRateLimiter $rateLimiter,
        private readonly SerializerInterface $serializer,
    ) {}

    #[Route('/api/receive', name: 'api_receive', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        // Naive rate limit per IP
        $ip = $request->getClientIp() ?? '0.0.0.0';
        if (!$this->rateLimiter->allow($ip)) {
            return new JsonResponse(['error' => 'Too Many Requests'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $dto = new TrackEventDto();
        $dto->url = $data['url'] ?? '';
        $dto->referrer = $data['referrer'] ?? null;
        $dto->screenWidth = isset($data['screenWidth']) ? (int) $data['screenWidth'] : null;
        $dto->eventName = $data['eventName'] ?? null;
        $dto->eventData = isset($data['eventData']) && is_array($data['eventData']) ? $data['eventData'] : null;
        $dto->websiteToken = $data['websiteToken'] ?? '';
        $dto->visitorId = $data['visitorId'] ?? null;
        $dto->sessionId = $data['sessionId'] ?? null;

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return new JsonResponse(['error' => 'Validation failed', 'details' => (string) $errors], Response::HTTP_BAD_REQUEST);
        }

        // Find website by public token
        $website = $this->websites->findOneByPublicToken($dto->websiteToken);
        if (!$website) {
            return new JsonResponse(['error' => 'Invalid websiteToken'], Response::HTTP_BAD_REQUEST);
        }

        // Domain whitelisting using Origin or Referer
        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        if (!$this->isOriginAllowed($origin, $website->getDomain())) {
            return new JsonResponse(['error' => 'Forbidden origin'], Response::HTTP_FORBIDDEN);
        }

        $ua = $request->headers->get('User-Agent', '');

        $this->bus->dispatch(new TrackEventMessage(
            websiteToken: $dto->websiteToken,
            url: $dto->url,
            referrer: $dto->referrer,
            screenWidth: $dto->screenWidth,
            eventName: $dto->eventName,
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
