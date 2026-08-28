<?php

namespace App\Entity;

use App\Repository\EventRepository;
use App\Service\GeoIp\GeoArea;
use App\Service\PrivacySanitizer;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'events')]
#[ORM\Index(name: 'IDX_EVENTS_WEBSITE_TOKEN', columns: ['website_token'])]
#[ORM\Index(name: 'IDX_EVENTS_SESSION_ID', columns: ['session_id'])]
#[ORM\Index(name: 'IDX_EVENTS_VISITOR_ID', columns: ['visitor_id'])]
#[ORM\Index(name: 'IDX_EVENTS_CONSENT_STATE', columns: ['consent_state'])]
#[ORM\Index(name: 'IDX_EVENTS_PRIVACY_SITE_CREATED', columns: ['privacy_mode', 'website_token', 'created_at'])]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 191)]
    private string $websiteToken;

    #[ORM\Column(type: 'string', length: 191)]
    private string $eventName = 'view';

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $referrer = null;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $generalizedUserAgent = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'anonymous'])]
    private string $privacyMode = 'anonymous';

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'unknown'])]
    private string $deviceClass = 'unknown';

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'unknown'])]
    private string $viewportBucket = 'unknown';

    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $geoArea = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $screenWidth = null;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $visitorId = null;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $sessionId = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $consentState = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $customData = null;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $goalEvent = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }

    public function getWebsiteToken(): string { return $this->websiteToken; }
    public function setWebsiteToken(string $token): self { $this->websiteToken = $token; return $this; }

    public function getEventName(): string { return $this->eventName; }
    public function setEventName(string $name): self { $this->eventName = $name; return $this; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): self { $this->url = $url; return $this; }

    public function getReferrer(): ?string { return $this->referrer; }
    public function setReferrer(?string $referrer): self { $this->referrer = $referrer; return $this; }

    public function getGeneralizedUserAgent(): ?string { return $this->generalizedUserAgent; }
    public function setGeneralizedUserAgent(?string $ua): self { $this->generalizedUserAgent = $ua; return $this; }

    public function getPrivacyMode(): string { return $this->privacyMode; }
    public function setPrivacyMode(string $privacyMode): self
    {
        if (!in_array($privacyMode, ['anonymous', 'enhanced'], true)) {
            throw new \InvalidArgumentException('Privacy mode must be anonymous or enhanced.');
        }

        $this->privacyMode = $privacyMode;

        return $this;
    }

    public function getDeviceClass(): string { return $this->deviceClass; }
    public function setDeviceClass(string $deviceClass): self
    {
        if (!in_array($deviceClass, ['mobile', 'tablet', 'desktop', 'bot', 'unknown'], true)) {
            throw new \InvalidArgumentException('Invalid device class.');
        }

        $this->deviceClass = $deviceClass;

        return $this;
    }

    public function getViewportBucket(): string { return $this->viewportBucket; }
    public function setViewportBucket(string $viewportBucket): self
    {
        if (!in_array($viewportBucket, ['small', 'medium', 'large', 'unknown'], true)) {
            throw new \InvalidArgumentException('Invalid viewport bucket.');
        }

        $this->viewportBucket = $viewportBucket;

        return $this;
    }

    public function getGeoArea(): ?string { return $this->geoArea; }
    public function setGeoArea(?string $geoArea): self
    {
        if (!self::isValidGeoArea($geoArea)) {
            throw new \InvalidArgumentException(
                'Geographic area must be a canonical country:XX or continent:XX code.',
            );
        }

        $this->geoArea = $geoArea;

        return $this;
    }

    public function getScreenWidth(): ?int { return $this->screenWidth; }
    public function setScreenWidth(?int $w): self { $this->screenWidth = $w; return $this; }

    public function getVisitorId(): ?string { return $this->visitorId; }
    public function setVisitorId(?string $visitorId): self { $this->visitorId = $visitorId; return $this; }

    public function getSessionId(): ?string { return $this->sessionId; }
    public function setSessionId(?string $sessionId): self { $this->sessionId = $sessionId; return $this; }

    public function getConsentState(): ?string { return $this->consentState; }
    public function setConsentState(?string $consentState): self
    {
        if ($consentState !== null && $consentState !== 'granted') {
            throw new \InvalidArgumentException('Consent state must be granted or null.');
        }

        $this->consentState = $consentState;

        return $this;
    }

    public function getCustomData(): ?array { return $this->customData; }
    public function setCustomData(?array $data): self { $this->customData = $data; return $this; }

    public function getGoalEvent(): ?string { return $this->goalEvent; }
    public function setGoalEvent(?string $goalEvent): self { $this->goalEvent = $goalEvent; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function enforcePrivacyInvariants(): void
    {
        if (!self::isValidGeoArea($this->geoArea)) {
            throw new \LogicException(
                'Event geographic areas must use canonical country:XX or continent:XX codes.',
            );
        }

        if ($this->goalEvent !== null && !PrivacySanitizer::isSafeEventName($this->goalEvent)) {
            throw new \LogicException('Goal event names must use the safe event-name format.');
        }

        if ($this->privacyMode === 'enhanced') {
            if ($this->consentState !== 'granted') {
                throw new \LogicException('Enhanced events require granted consent.');
            }

            return;
        }

        if ($this->privacyMode !== 'anonymous') {
            throw new \LogicException('Invalid privacy mode.');
        }

        if (strlen($this->url) > 512 || !str_starts_with($this->url, '/') || strpbrk($this->url, '?#') !== false) {
            throw new \LogicException('Anonymous event URLs must be sanitized paths.');
        }

        if (!in_array($this->referrer, ['direct', 'internal', 'search', 'social', 'email', 'referral', 'unknown'], true)) {
            throw new \LogicException('Anonymous event referrers must be coarse channels.');
        }

        if (!in_array($this->deviceClass, ['mobile', 'tablet', 'desktop', 'bot', 'unknown'], true)
            || !in_array($this->viewportBucket, ['small', 'medium', 'large', 'unknown'], true)) {
            throw new \LogicException('Anonymous device dimensions must use coarse buckets.');
        }

        if (!PrivacySanitizer::isSafeEventName($this->eventName)) {
            throw new \LogicException('Anonymous event names must use the safe event-name format.');
        }

        $utc = $this->createdAt->setTimezone(new \DateTimeZone('UTC'));
        $this->createdAt = $utc->setTime((int) $utc->format('G'), 0, 0);
        $this->generalizedUserAgent = null;
        $this->screenWidth = null;
        $this->visitorId = null;
        $this->sessionId = null;
        $this->consentState = null;
        $this->customData = null;
    }

    private static function isValidGeoArea(?string $geoArea): bool
    {
        if ($geoArea === null) {
            return true;
        }

        if (str_starts_with($geoArea, 'country:')) {
            return GeoArea::country(substr($geoArea, strlen('country:')))?->value() === $geoArea;
        }

        if (str_starts_with($geoArea, 'continent:')) {
            return GeoArea::continent(substr($geoArea, strlen('continent:')))?->value() === $geoArea;
        }

        return false;
    }
}
