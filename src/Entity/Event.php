<?php

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Website::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Website $website;

    #[ORM\Column(type: 'string', length: 191)]
    private string $eventName = 'view';

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $referrer = null;

    #[ORM\Column(type: 'string', length: 191)]
    private string $dailyIpHash;

    #[ORM\Column(type: 'string', length: 191)]
    private string $generalizedUserAgent;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $screenWidth = null;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $sessionId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $customData = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getWebsite(): Website { return $this->website; }
    public function setWebsite(Website $website): self { $this->website = $website; return $this; }

    public function getEventName(): string { return $this->eventName; }
    public function setEventName(string $name): self { $this->eventName = $name; return $this; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): self { $this->url = $url; return $this; }

    public function getReferrer(): ?string { return $this->referrer; }
    public function setReferrer(?string $referrer): self { $this->referrer = $referrer; return $this; }

    public function getDailyIpHash(): string { return $this->dailyIpHash; }
    public function setDailyIpHash(string $hash): self { $this->dailyIpHash = $hash; return $this; }

    public function getGeneralizedUserAgent(): string { return $this->generalizedUserAgent; }
    public function setGeneralizedUserAgent(string $ua): self { $this->generalizedUserAgent = $ua; return $this; }

    public function getScreenWidth(): ?int { return $this->screenWidth; }
    public function setScreenWidth(?int $w): self { $this->screenWidth = $w; return $this; }

    public function getSessionId(): ?string { return $this->sessionId; }
    public function setSessionId(?string $sessionId): self { $this->sessionId = $sessionId; return $this; }

    public function getCustomData(): ?array { return $this->customData; }
    public function setCustomData(?array $data): self { $this->customData = $data; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
