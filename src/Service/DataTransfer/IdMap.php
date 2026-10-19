<?php

declare(strict_types=1);

namespace App\Service\DataTransfer;

/**
 * Source id to target id, one JSON line per row. The file is mode 0600 and
 * holds ids only, never the rest of the row.
 */
final class IdMap
{
    /** @var resource */
    private $handle;

    /** @param resource $handle */
    private function __construct(private readonly string $path, $handle)
    {
        $this->handle = $handle;
    }

    public static function open(string $directory): self
    {
        if (is_link($directory)) {
            throw new DataTransferException('The id-map directory is not a real directory, so it was not used.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new DataTransferException('The id-map directory could not be created.');
        }
        chmod($directory, 0700);
        $path = $directory.'/id-map-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4)).'.jsonl';
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new DataTransferException('The id map could not be created.');
        }
        chmod($path, 0600);

        return new self($path, $handle);
    }

    public function remember(string $table, string $sourceId, string $targetId): void
    {
        $line = json_encode(
            ['table' => $table, 'source_id' => $sourceId, 'target_id' => $targetId],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (fwrite($this->handle, $line."\n") === false) {
            throw new DataTransferException('The id map could not be written.');
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function close(): void
    {
        fflush($this->handle);
        fclose($this->handle);
    }
}
