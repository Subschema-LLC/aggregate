<?php

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * @param string[] $tokens
     * @return array<string, \DateTimeImmutable> Map of websiteToken => latest createdAt
     */
    public function findLastEventDatesByTokens(array $tokens): array
    {
        $cleanTokens = array_values(array_filter(array_unique($tokens), static fn (mixed $t): bool => is_string($t) && trim($t) !== ""));
        if ($cleanTokens === []) {
            return [];
        }

        try {
            $rows = $this->createQueryBuilder("e")
                ->select("e.websiteToken AS token, MAX(e.createdAt) AS lastEventAt")
                ->where("e.websiteToken IN (:tokens)")
                ->setParameter("tokens", $cleanTokens)
                ->groupBy("e.websiteToken")
                ->getQuery()
                ->getArrayResult();

            $results = [];
            foreach ($rows as $row) {
                $token = (string) ($row["token"] ?? "");
                $date = $row["lastEventAt"] ?? null;
                if ($token === "" || $date === null) {
                    continue;
                }
                if ($date instanceof \DateTimeInterface) {
                    $results[$token] = \DateTimeImmutable::createFromInterface($date);
                } elseif (is_string($date)) {
                    $results[$token] = new \DateTimeImmutable($date, new \DateTimeZone("UTC"));
                }
            }

            return $results;
        } catch (\Throwable) {
            return [];
        }
    }
}
