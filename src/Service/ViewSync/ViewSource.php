<?php

declare(strict_types=1);

namespace App\Service\ViewSync;

/**
 * Where ViewExporter reads what it copies: the reporting views (ViewReader),
 * or the forensic database's tables and private views
 * (App\Service\ForensicDatabase\ForensicReader).
 */
interface ViewSource
{
    /**
     * The columns, with their catalog types, and the rows in that column
     * order, read lazily.
     *
     * @return array{fields: list<array{name: string, type: string}>, rows: iterable<list<mixed>>}
     *
     * @throws ViewSyncException when the source is unknown or cannot be read
     */
    public function read(string $view): array;
}
