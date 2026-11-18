<?php

declare(strict_types=1);

namespace App\Entities\Incident;

/** The most recent delivery records, newest first. */
interface DeliveryHistory
{
    /**
     * Null when the history cannot be read.
     *
     * @param list<string> $operations DeliveryLog::OP_* values
     *
     * @return array{
     *     total: int,
     *     rows: list<array{
     *         id: int,
     *         occurred_at: \DateTimeImmutable,
     *         operation: string,
     *         subject: string,
     *         outcome: string,
     *         details: string
     *     }>
     * }|null
     */
    public function recent(array $operations, int $limit): ?array;
}
