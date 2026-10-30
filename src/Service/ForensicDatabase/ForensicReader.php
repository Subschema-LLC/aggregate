<?php

declare(strict_types=1);

namespace App\Service\ForensicDatabase;

use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewReader;
use App\Service\ViewSync\ViewSource;
use App\Service\ViewSync\ViewSyncException;
use Doctrine\DBAL\Connection;

/**
 * Reads what the forensic database copies (ForensicCatalog): a raw table with
 * the columns the catalog lists, or a private reporting view through
 * ViewReader. Rows are streamed in batches, as for every view sync, and the
 * application database only serves a read.
 *
 * Identifier columns are hashed here, in the application, before a row is
 * written anywhere: each read() makes a new ForensicPseudonyms, whose random
 * key exists only while that table is read and is wiped when the last row has
 * been read or the read stops. No browser-generated identifier reaches the
 * snapshot file or the forensic database. Errors name the table and column,
 * never a value.
 */
class ForensicReader implements ViewSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ViewReader $views,
    ) {
    }

    public function read(string $view): array
    {
        if (ViewCatalog::isPrivate($view)) {
            return $this->views->read($view);
        }
        if (!ForensicCatalog::isTable($view)) {
            throw new ViewSyncException($view.' is not a table or private view that the forensic database copies.');
        }
        $table = ForensicCatalog::TABLES[$view];
        $platform = $this->connection->getDatabasePlatform();
        $select = [];
        $fields = [];
        $hashed = [];
        foreach ($table['columns'] as $name => $column) {
            $select[] = $platform->quoteSingleIdentifier($column[0]);
            $fields[] = ['name' => $name, 'type' => $column[1]];
            if (isset($column[2])) {
                $hashed[count($fields) - 1] = $column[2];
            }
        }
        $sql = 'SELECT '.implode(', ', $select).' FROM '.$platform->quoteSingleIdentifier($table['source']);
        try {
            $this->connection->executeQuery($sql.' WHERE 1 = 0')->free();
        } catch (\Doctrine\DBAL\Exception $e) {
            throw new ViewSyncException('The table '.$table['source'].' cannot be read for '.$view.'. Run the database migrations (php bin/console doctrine:migrations:migrate).', previous: $e);
        }

        return ['fields' => $fields, 'rows' => $this->rows($sql, $hashed)];
    }

    /**
     * @param array<int, string> $hashed column index => hash domain
     *
     * @return \Generator<int, list<mixed>>
     */
    private function rows(string $sql, array $hashed): \Generator
    {
        $pseudonyms = $hashed === [] ? null : new ForensicPseudonyms();
        try {
            foreach ($this->views->select($sql) as $row) {
                foreach ($hashed as $index => $domain) {
                    $row[$index] = $pseudonyms?->hash($domain, $row[$index] ?? null);
                }
                yield $row;
            }
        } finally {
            $pseudonyms?->forget();
        }
    }
}
