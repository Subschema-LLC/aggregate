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
        $cleanTokens = array_values(array_filter(array_unique($tokens), static fn (string $t): bool => trim($t) !== ""));
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

    /**
     * Counts events for one website in [from, to) up to $cap rows, so a busy
     * website stops at the rule's threshold instead of scanning the full window.
     *
     * Uses the (website_token, created_at) or (privacy_mode, website_token, created_at)
     * index on all five supported database engines. SQL Server requires a column
     * alias on TOP subqueries and a table alias on derived tables.
     */
    public function countEventsUpTo(
        string $token,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $cap,
        string $privacyMode = 'all',
        ?string $eventName = null,
    ): int {
        $token = trim($token);
        if ($token === '' || $cap < 1 || $to <= $from) {
            return 0;
        }

        $connection = $this->getEntityManager()->getConnection();
        $platform = $connection->getDatabasePlatform();
        $type = Type::getType(Types::DATETIME_IMMUTABLE);
        $utc = new \DateTimeZone('UTC');

        $where = 'website_token = ? AND created_at >= ? AND created_at < ?';
        $params = [
            $token,
            $type->convertToDatabaseValue($from->setTimezone($utc), $platform),
            $type->convertToDatabaseValue($to->setTimezone($utc), $platform),
        ];

        if ($privacyMode === 'anonymous' || $privacyMode === 'enhanced') {
            $where .= ' AND privacy_mode = ?';
            $params[] = $privacyMode;
        }
        if ($eventName !== null && trim($eventName) !== '') {
            $where .= ' AND event_name = ?';
            $params[] = trim($eventName);
        }

        $inner = $platform->modifyLimitQuery('SELECT 1 AS c FROM events WHERE '.$where, $cap);
        $sql = 'SELECT COUNT(*) FROM ('.$inner.') AS t';

        return (int) $connection->fetchOne($sql, $params);
    }
}
