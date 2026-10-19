<?php

declare(strict_types=1);

namespace App\Service\DataTransfer;

/**
 * What a transfer did, without row values. Counts and the package path are
 * safe to show and to store on the processing task.
 */
final class TransferReport
{
    /**
     * @param array<string, array{read: int, written: int, skipped: int, replaced: int, filtered: int}> $tables
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $operation,
        public readonly bool $dryRun,
        public readonly array $tables,
        public readonly int $written,
        public readonly array $notes,
        public readonly ?string $packagePath,
        public readonly ?string $idMapPath,
    ) {
    }

    public function summary(): string
    {
        $parts = [];
        foreach ($this->tables as $table => $counts) {
            if ($this->dryRun) {
                $parts[] = sprintf(
                    '%s: %d would be copied, %d skipped, %d would be replaced',
                    $table,
                    $counts['read'] - $counts['filtered'],
                    $counts['skipped'],
                    $counts['replaced'],
                );
            } else {
                $parts[] = sprintf(
                    '%s: %d written, %d skipped, %d replaced',
                    $table,
                    $counts['written'],
                    $counts['skipped'],
                    $counts['replaced'],
                );
            }
        }
        $summary = $this->operation.($this->dryRun ? ' (dry run)' : '').': '.implode('; ', $parts);
        if ($this->packagePath !== null) {
            $summary .= '. Package: '.$this->packagePath;
        }
        if ($this->idMapPath !== null) {
            $summary .= '. Id map: '.$this->idMapPath;
        }
        if ($this->notes !== []) {
            $summary .= '. '.implode(' ', $this->notes);
        }

        return $summary;
    }
}
