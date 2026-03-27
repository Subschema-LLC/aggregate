<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class TrackEventDto
{
    #[Assert\NotBlank]
    #[Assert\Url]
    public string $url;

    #[Assert\Url]
    public ?string $referrer = null;

    #[Assert\Positive]
    public ?int $screenWidth = null;

    #[Assert\Length(max: 191)]
    public ?string $eventName = null;

    #[Assert\Length(max: 191)]
    public ?string $goalEvent = null;

    public ?array $eventData = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 191)]
    public string $websiteToken;

    // Optional in Tier 2
    #[Assert\Length(max: 255)]
    public ?string $visitorId = null;

    #[Assert\Length(max: 255)]
    public ?string $sessionId = null;
}
