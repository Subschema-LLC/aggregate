<?php

namespace App\Entity;

use App\Repository\WebsiteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebsiteRepository::class)]
#[ORM\Table(name: 'websites')]
class Website
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 191)]
    private string $name;

    #[ORM\Column(type: 'string', length: 191)]
    private string $domain;

    #[ORM\Column(type: 'string', length: 191, unique: true)]
    private string $publicToken;

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getDomain(): string { return $this->domain; }
    public function setDomain(string $domain): self { $this->domain = strtolower($domain); return $this; }

    public function getPublicToken(): string { return $this->publicToken; }
    public function setPublicToken(string $token): self { $this->publicToken = $token; return $this; }
}
