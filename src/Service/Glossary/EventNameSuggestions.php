<?php

declare(strict_types=1);

namespace App\Service\Glossary;

use App\Service\PrivacySanitizer;
use Doctrine\DBAL\Connection;

/** Explicit administrator discovery only; never used by the resolver or sync. */
class EventNameSuggestions
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param list<string> $declaredCodes @return list<string> */
    public function find(array $declaredCodes): array
    {
        $sample = $this->connection->createQueryBuilder()
            ->select('event_name')->from('events')->orderBy('id', 'DESC')
            ->setMaxResults(1000)->executeQuery()->fetchFirstColumn();
        $names = [];
        foreach ($sample as $name) {
            if (is_string($name) && PrivacySanitizer::isSafeEventName($name)
                && !in_array($name, $declaredCodes, true)) {
                $names[$name] = true;
            }
        }
        $names = array_keys($names);
        sort($names, SORT_STRING);

        return $names;
    }
}
