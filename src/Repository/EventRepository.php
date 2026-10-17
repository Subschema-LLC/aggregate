<?php

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * The newest event of each website, by asking for one website at a time:
     * with the (website_token, created_at) index, each MAX() reads a single
     * index entry, where one grouped query over several websites reads every
     * one of their events. Database errors are not caught here, so a failed
     * lookup is never cached or shown as "no events".
     *
     * @param string[] $tokens
     * @return array<string, \DateTimeImmutable> Map of websiteToken => latest createdAt
     */
    public function findLastEventDatesByTokens(array $tokens): array
    {
        $cleanTokens = array_values(array_filter(array_unique($tokens), static fn (mixed $t): bool => is_string($t) && trim($t) !== ""));
        if ($cleanTokens === []) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();
        $platform = $connection->getDatabasePlatform();
        $type = Type::getType(Types::DATETIME_IMMUTABLE);
        $statement = $connection->prepare("SELECT MAX(created_at) FROM events WHERE website_token = ?");

        $results = [];
        foreach ($cleanTokens as $token) {
            $statement->bindValue(1, $token);
            $latest = $statement->executeQuery()->fetchOne();
            if ($latest === null || $latest === false) {
                continue;
            }
            $date = $type->convertToPHPValue($latest, $platform);
            if ($date instanceof \DateTimeInterface) {
                $results[$token] = \DateTimeImmutable::createFromInterface($date);
            }
        }

        return $results;
    }
}
