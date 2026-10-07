<?php

namespace App\Entity;

use App\Repository\EventRepository;
use App\Service\CustomDataSettings;
use App\Service\GeoIp\GeoArea;
use App\Service\InternalTrafficSettings;
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
#[ORM\Index(name: 'IDX_EVENTS_ARCHIVED_CREATED_ID', columns: ['archived_at', 'created_at', 'id'])]
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

    // Captured at ingestion, not mapped to new database columns. These values
    // have already passed the deployment's consent-free property policy.
    private array $approvedAnonymousCustomData = [];

    // The organization-traffic flag recorded by setInternalTraffic() (or read
    // back from a stored row), kept apart so a later setCustomData() can
    // neither forge nor drop it on an anonymous event.
    private ?bool $internalTraffic = null;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $goalEvent = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

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

    /** Only call with data approved by the deployment's consent-free policy. */
    public function setApprovedAnonymousCustomData(?array $data): self
    {
        // Keep the approved values, not merely their names: an accidental
        // later setCustomData() must not enrich an anonymous event.
        $this->approvedAnonymousCustomData = (new PrivacySanitizer())->sanitizeEventData($data) ?? [];
        if (array_key_exists(CustomDataSettings::PAGE_SEQUENCE_PROPERTY, $this->approvedAnonymousCustomData)) {
            $pageSequence = CustomDataSettings::sanitizePageSequence($this->approvedAnonymousCustomData[CustomDataSettings::PAGE_SEQUENCE_PROPERTY]);
            if ($pageSequence === null) {
                unset($this->approvedAnonymousCustomData[CustomDataSettings::PAGE_SEQUENCE_PROPERTY]);
            } else {
                $this->approvedAnonymousCustomData[CustomDataSettings::PAGE_SEQUENCE_PROPERTY] = $pageSequence;
            }
        }
        $this->customData = $this->approvedAnonymousCustomData ?: null;

        return $this;
    }

    /** Whether the event came from a browser marked as organization traffic. */
    public function isInternalTraffic(): bool
    {
        return $this->internalTraffic === true;
    }

    /**
     * Records the organization-traffic flag under its fixed key,
     * custom_data.org_internal_traffic: true or false, or no key when the
     * browser was not asked (the strict profile reads no browser storage).
     * The flag is controlled only here, never by submitted properties.
     */
    public function setInternalTraffic(?bool $internalTraffic): self
    {
        $this->internalTraffic = $internalTraffic;
        unset($this->approvedAnonymousCustomData[InternalTrafficSettings::JSON_KEY]);
        if ($internalTraffic !== null) {
            $this->customData ??= [];
            $this->customData[InternalTrafficSettings::JSON_KEY] = $internalTraffic;
        } elseif ($this->customData !== null) {
            unset($this->customData[InternalTrafficSettings::JSON_KEY]);
            if ($this->customData === []) {
                $this->customData = null;
            }
        }

        return $this;
    }

    #[ORM\PostLoad]
    public function restoreApprovedAnonymousCustomData(): void
    {
        // Persisted values were approved at ingestion. Preserve that exact
        // historical snapshot on updates without authorizing arbitrary
        // properties assigned after hydration.
        if ($this->privacyMode === 'anonymous') {
            $this->approvedAnonymousCustomData = $this->customData ?? [];
        }
        $marker = $this->customData[InternalTrafficSettings::JSON_KEY] ?? null;
        $this->internalTraffic = is_bool($marker) ? $marker : null;
    }

    public function getGoalEvent(): ?string { return $this->goalEvent; }
    public function setGoalEvent(?string $goalEvent): self { $this->goalEvent = $goalEvent; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function setArchivedAt(?\DateTimeImmutable $archivedAt): self
    {
        $this->archivedAt = $archivedAt;

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
        $data = $this->approvedAnonymousCustomData;
        unset($data[InternalTrafficSettings::JSON_KEY]);
        if ($this->internalTraffic !== null) {
            $data[InternalTrafficSettings::JSON_KEY] = $this->internalTraffic;
        }
        $this->customData = $data ?: null;
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
