<?php

declare(strict_types=1);

namespace App\Security\Access;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creating, changing and deleting accounts, shared by the Users page, the
 * profile page and the user commands so they apply the same rules:
 *
 * - usernames are unique and at most 180 characters; passwords at least 8;
 * - an installation always keeps at least one administrator;
 * - an administrator cannot remove their own administrator access or delete
 *   their own account from the dashboard, so nobody locks themselves out;
 * - assigned permission groups must exist.
 */
final class UserAdministration
{
    public const MIN_PASSWORD = 8;
    public const MAX_USERNAME = 180;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PermissionGroupCatalog $permissionGroups,
    ) {
    }

    /**
     * @param array<string, mixed> $access permission_groups plus an AccessGrant document
     */
    public function create(string $username, string $password, bool $admin, array $access = [], ?string $displayName = null, ?string $email = null): User
    {
        $username = $this->username($username);
        $this->password($password);
        if ($this->users->findOneBy(['username' => $username]) instanceof User) {
            throw new \InvalidArgumentException(sprintf('User "%s" already exists.', $username));
        }
        $user = (new User())->setUsername($username)->setRoles([])->setAdmin($admin);
        $user->setDisplayName($displayName)->setEmail($email);
        $user->setAccess($this->validAccess($access));
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->flush($username);

        return $user;
    }

    /**
     * Saves an administrator's changes to an account.
     *
     * @param array<string, mixed> $access permission_groups plus an AccessGrant document
     */
    public function update(User $user, string $username, ?string $displayName, ?string $email, bool $admin, array $access, ?User $actor = null): void
    {
        $username = $this->username($username);
        $existing = $this->users->findOneBy(['username' => $username]);
        if ($existing instanceof User && $existing->getId() !== $user->getId()) {
            throw new \InvalidArgumentException(sprintf('User "%s" already exists.', $username));
        }
        if ($user->isAdmin() && !$admin) {
            if ($actor !== null && $actor->getId() === $user->getId()) {
                throw new \InvalidArgumentException('You cannot remove your own administrator access. Ask another administrator.');
            }
            if ($this->administratorCount() <= 1) {
                throw new \InvalidArgumentException('Keep at least one administrator. Make another user an administrator first.');
            }
        }
        $access = $this->validAccess($access);
        $user->setUsername($username)->setDisplayName($displayName)->setEmail($email)->setAdmin($admin)->setAccess($access);
        $this->flush($username);
    }

    /** Saves a user's own profile: their display name and email. */
    public function updateProfile(User $user, ?string $displayName, ?string $email): void
    {
        $user->setDisplayName($displayName)->setEmail($email);
        $this->flush($user->getUsername());
    }

    public function changePassword(User $user, string $password): void
    {
        $this->password($password);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->flush($user->getUsername());
    }

    public function isCurrentPassword(User $user, string $password): bool
    {
        return $password !== '' && $this->hasher->isPasswordValid($user, $password);
    }

    public function delete(User $user, ?User $actor = null): void
    {
        if ($actor !== null && $actor->getId() === $user->getId()) {
            throw new \InvalidArgumentException('You cannot delete your own account. Ask another administrator.');
        }
        if ($user->isAdmin() && $this->administratorCount() <= 1) {
            throw new \InvalidArgumentException('Keep at least one administrator. Make another user an administrator first.');
        }
        $this->em->remove($user);
        $this->em->flush();
    }

    /** Takes a deleted permission group off every user. Returns how many users had it. */
    public function forgetPermissionGroup(string $id): int
    {
        $changed = 0;
        foreach ($this->users->findAll() as $user) {
            $access = $user->getAccess();
            if (!in_array($id, $access['permission_groups'], true)) {
                continue;
            }
            $access['permission_groups'] = array_values(array_diff($access['permission_groups'], [$id]));
            $user->setAccess($access);
            ++$changed;
        }
        if ($changed > 0) {
            $this->em->flush();
        }

        return $changed;
    }

    /** @return array<string, int> permission group id => users assigned to it */
    public function permissionGroupMembers(): array
    {
        $counts = [];
        foreach ($this->users->findAll() as $user) {
            if ($user->isAdmin()) {
                continue;
            }
            foreach ($user->getAccess()['permission_groups'] as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function administratorCount(): int
    {
        return count(array_filter($this->users->findAll(), static fn (User $user): bool => $user->isAdmin()));
    }

    public function username(string $username): string
    {
        $username = trim($username);
        if ($username === '') {
            throw new \InvalidArgumentException('Enter a username.');
        }
        if (strlen($username) > self::MAX_USERNAME || preg_match('/\p{C}/u', $username) === 1) {
            throw new \InvalidArgumentException('Usernames are at most '.self::MAX_USERNAME.' characters, without control characters.');
        }

        return $username;
    }

    public function password(string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new \InvalidArgumentException('Passwords must be at least '.self::MIN_PASSWORD.' characters.');
        }
    }

    /**
     * @param array<string, mixed> $access
     *
     * @return array{permission_groups: list<string>, websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>}
     */
    private function validAccess(array $access): array
    {
        $access = AccessGrant::normalizeUser($access);
        foreach ($access['permission_groups'] as $id) {
            if (!$this->permissionGroups->has($id)) {
                throw new \InvalidArgumentException(sprintf('Permission group "%s" does not exist.', $id));
            }
        }

        return $access;
    }

    private function flush(string $username): void
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \InvalidArgumentException(sprintf('User "%s" already exists.', $username));
        }
    }
}
