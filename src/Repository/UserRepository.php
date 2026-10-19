<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * The one user with this email address, ignoring case; null when no user
     * or several share it.
     */
    public function findOnlyByEmail(string $email): ?User
    {
        $matches = $this->createQueryBuilder('u')
            ->where('LOWER(u.email) = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        return count($matches) === 1 && $matches[0] instanceof User ? $matches[0] : null;
    }

    /**
     * Usernames of users with no email address, who cannot finish two-step
     * sign-in.
     *
     * @return list<string>
     */
    public function usernamesWithoutEmail(): array
    {
        return array_map('strval', $this->createQueryBuilder('u')
            ->select('u.username')
            ->where('u.email IS NULL')
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getSingleColumnResult());
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
