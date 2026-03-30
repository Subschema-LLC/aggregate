<?php

namespace App\Message;

class TrackEventMessage
{
    public function __construct(
        public string $websiteToken,
        public string $url,
        public ?string $referrer,
        public ?int $screenWidth,
        public ?string $eventName,
        public ?string $goalEvent,
        public ?array $eventData,
        public string $ip,
        public string $userAgent,
        public ?string $visitorId,
        public ?string $sessionId,
        public ?string $consentState,
    ) {}
}
