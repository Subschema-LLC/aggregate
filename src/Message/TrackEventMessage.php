<?php

namespace App\Message;

class TrackEventMessage
{
    public function __construct(
        public string $websiteToken,
        public string $eventName,
        public string $pagePath,
        public string $referrerChannel,
        public string $deviceClass,
        public string $viewportBucket,
        public ?int $screenWidth,
        public ?string $goalEvent,
        public ?array $eventData,
        public string $generalizedUserAgent,
        public ?string $visitorId,
        public ?string $sessionId,
        public \DateTimeImmutable $occurredAt,
        public ?string $geoArea = null,
        public ?bool $internalTraffic = null,
    ) {}
}
