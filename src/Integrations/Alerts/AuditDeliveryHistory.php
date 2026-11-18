<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\Incident\DeliveryHistory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/** Alert delivery records in audit_trail, read for the status page and app:alerts:status. */
final class AuditDeliveryHistory implements DeliveryHistory
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function recent(array $operations, int $limit): ?array
    {
        $parameters = ['ops' => $operations];
        $types = ['ops' => ArrayParameterType::STRING];
        try {
            $total = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM audit_trail WHERE operation IN (:ops)',
                $parameters,
                $types,
            );
            $sql = $this->connection->getDatabasePlatform()->modifyLimitQuery(
                'SELECT id, occurred_at, operation, subject, outcome, details FROM audit_trail '
                .'WHERE operation IN (:ops) ORDER BY occurred_at DESC, id DESC',
                $limit,
            );
            $rows = $this->connection->fetchAllAssociative($sql, $parameters, $types);
        } catch (\Throwable) {
            return null;
        }

        $read = [];
        foreach ($rows as $row) {
            $type = Types::DATETIME_IMMUTABLE;
            $time = $this->connection->convertToPHPValue($row['occurred_at'], $type);
            $read[] = [
                'id' => (int) $row['id'],
                'occurred_at' => $time instanceof \DateTimeImmutable
                    ? new \DateTimeImmutable($time->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'))
                    : new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'operation' => (string) $row['operation'],
                'subject' => (string) ($row['subject'] ?? ''),
                'outcome' => (string) $row['outcome'],
                'details' => (string) ($row['details'] ?? ''),
            ];
        }

        return ['total' => $total, 'rows' => $read];
    }
}
