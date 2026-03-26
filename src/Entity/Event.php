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

    #[ORM\ManyToOne(targetEntity: View::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?View $view = null;

    #[ORM\Column(type: 'string', length: 191)]
    private string $eventName;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $customData = null;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $sessionId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getWebsite(): Website { return $this->website; }
    public function setWebsite(Website $website): self { $this->website = $website; return $this; }

    public function getView(): ?View { return $this->view; }
    public function setView(?View $pv): self { $this->view = $pv; return $this; }

    public function getEventName(): string { return $this->eventName; }
    public function setEventName(string $name): self { $this->eventName = $name; return $this; }

    public function getCustomData(): ?array { return $this->customData; }
    public function setCustomData(?array $data): self { $this->customData = $data; return $this; }

    public function getSessionId(): ?string { return $this->sessionId; }
    public function setSessionId(?string $sessionId): self { $this->sessionId = $sessionId; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
