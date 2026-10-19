<?php

namespace App\Entity;

use App\Repository\UserRepository;
use App\Security\Access\AccessGrant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
/**
 * A dashboard account. Administrators (ROLE_ADMIN) can do everything; a
 * standard user can use what their permission groups and their own grants in
 * $access allow (see docs/USERS-AND-PERMISSIONS.md).
 */
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const MAX_DISPLAY_NAME = 120;
    public const MAX_EMAIL = 254;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private string $username;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'string')]
    private string $password;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'display_name', type: 'string', length: 120, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(type: 'string', length: 254, nullable: true)]
    private ?string $email = null;

    /**
     * Permission groups and the user's own grants (AccessGrant::normalizeUser()).
     * Administrators ignore it.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $access = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void
    {
        // Clear temporary sensitive data if needed
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isAdmin(): bool
    {
        return in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    /** Makes the account an administrator or a standard user. */
    public function setAdmin(bool $admin): self
    {
        $roles = array_values(array_filter($this->roles, static fn (mixed $role): bool => $role !== self::ROLE_ADMIN && $role !== 'ROLE_USER'));
        if ($admin) {
            array_unshift($roles, self::ROLE_ADMIN);
        }
        $this->roles = $roles;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $displayName = $displayName === null ? '' : trim($displayName);
        if (mb_strlen($displayName) > self::MAX_DISPLAY_NAME || preg_match('/\p{C}/u', $displayName) === 1) {
            throw new \InvalidArgumentException('Enter a name of at most '.self::MAX_DISPLAY_NAME.' characters, without control characters.');
        }
        $this->displayName = $displayName === '' ? null : $displayName;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $email = $email === null ? '' : trim($email);
        if ($email !== '' && (strlen($email) > self::MAX_EMAIL || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new \InvalidArgumentException('Enter a valid email address, or leave it empty.');
        }
        $this->email = $email === '' ? null : $email;

        return $this;
    }

    /** The name the dashboard shows: the display name when set, otherwise the username. */
    public function getLabel(): string
    {
        return $this->displayName ?? $this->username;
    }

    /**
     * The stored access, or none when it cannot be read: a damaged value
     * grants nothing rather than something unintended.
     *
     * @return array{permission_groups: list<string>, websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>}
     */
    public function getAccess(): array
    {
        try {
            return AccessGrant::normalizeUser($this->access);
        } catch (\InvalidArgumentException) {
            return AccessGrant::normalizeUser(null);
        }
    }

    /**
     * @param array<string, mixed> $access permission_groups plus an AccessGrant document
     *
     * @throws \InvalidArgumentException when the access is not valid
     */
    public function setAccess(array $access): self
    {
        $access = AccessGrant::normalizeUser($access);
        $this->access = $access['permission_groups'] === [] && AccessGrant::isEmpty($access) ? null : $access;

        return $this;
    }
}
