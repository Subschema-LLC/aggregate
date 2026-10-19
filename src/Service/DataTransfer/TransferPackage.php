<?php

declare(strict_types=1);

namespace App\Service\DataTransfer;

/**
 * A private directory of JSONL table files plus a manifest. Files are created
 * mode 0600 and are deleted if the export does not finish.
 */
final class TransferPackage
{
    private const MAX_LINE_BYTES = 2_000_000;

    /** @var array<string, resource> */
    private array $handles = [];

    private bool $finished = false;

    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $row */
    public function writeRow(string $table, array $row): void
    {
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_LINE_BYTES) {
            throw new DataTransferException(sprintf('A %s row is larger than the transfer allows, so it was not copied.', $table));
        }
        if (fwrite($this->handle($table), $json."\n") === false) {
            throw new DataTransferException('The package could not be written.');
        }
    }

    /** @param array<string, mixed> $manifest */
    public function finish(array $manifest): void
    {
        $this->closeHandles();
        $path = $this->directory.'/manifest.json';
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json."\n") === false) {
            throw new DataTransferException('The package manifest could not be written.');
        }
        self::restrict($this->directory);
        $this->finished = true;
    }

    public function discard(): void
    {
        $this->closeHandles();
        if (!$this->finished) {
            self::discardDirectory($this->directory);
        }
    }

    public static function discardDirectory(string $directory): void
    {
        if (is_link($directory) || !is_dir($directory)) {
            return;
        }
        foreach (TransferCatalog::packageFiles() as $file) {
            $path = $directory.'/'.$file;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }
        @rmdir($directory);
    }

    /**
     * @return array<string, mixed>
     */
    public static function readManifest(string $directory): array
    {
        $path = $directory.'/manifest.json';
        if (is_link($path) || !is_file($path)) {
            throw new DataTransferException('The package manifest is missing, so the package was not imported.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new DataTransferException('The package manifest could not be read.');
        }
        try {
            $manifest = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DataTransferException('The package manifest is not valid, so the package was not imported.');
        }
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== TransferCatalog::FORMAT || ($manifest['product'] ?? null) !== 'aggregate' || !is_array($manifest['tables'] ?? null)) {
            throw new DataTransferException('The package was not written by this version of Aggregate, so it was not imported.');
        }
        foreach ($manifest['tables'] as $table => $info) {
            if (!is_string($table) || !in_array($table, TransferCatalog::tables(), true) || !is_array($info) || !is_int($info['rows'] ?? null) || $info['rows'] < 0) {
                throw new DataTransferException('The package manifest is not valid, so the package was not imported.');
            }
        }

        return $manifest;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public static function readRows(string $directory, string $table, int $expected): \Generator
    {
        $path = $directory.'/'.$table.'.jsonl';
        if ($expected === 0) {
            if (is_file($path) && self::countRows($path) !== 0) {
                throw new DataTransferException(sprintf('The %s package does not match its manifest, so nothing from that table was imported.', $table));
            }

            return;
        }
        if (is_link($path) || !is_file($path)) {
            throw new DataTransferException(sprintf('The %s package file is missing, so nothing from that table was imported.', $table));
        }
        $seen = 0;
        foreach (self::lines($path, $table) as $lineNumber => $line) {
            try {
                $decoded = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new DataTransferException(sprintf('%s row %d is not valid JSON, so it was not imported.', $table, $lineNumber));
            }
            if (!is_array($decoded)) {
                throw new DataTransferException(sprintf('%s row %d is not a row, so it was not imported.', $table, $lineNumber));
            }
            ++$seen;
            yield $lineNumber => $decoded;
        }
        if ($seen !== $expected) {
            throw new DataTransferException(sprintf('The %s package does not match its manifest, so nothing from that table was imported.', $table));
        }
    }

    private function handle(string $table): mixed
    {
        if (isset($this->handles[$table])) {
            return $this->handles[$table];
        }
        if (!in_array($table, TransferCatalog::tables(), true)) {
            throw new DataTransferException('Choose events, archives, or users.');
        }
        $path = $this->directory.'/'.$table.'.jsonl';
        if (is_link($path)) {
            throw new DataTransferException('The package path is not a real file, so it was not written.');
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new DataTransferException('The package could not be written.');
        }
        chmod($path, 0600);
        $this->handles[$table] = $handle;

        return $handle;
    }

    private function closeHandles(): void
    {
        foreach ($this->handles as $handle) {
            fflush($handle);
            fclose($handle);
        }
        $this->handles = [];
    }

    private static function restrict(string $directory): void
    {
        chmod($directory, 0700);
        foreach (TransferCatalog::packageFiles() as $file) {
            $path = $directory.'/'.$file;
            if (is_file($path) && !is_link($path)) {
                chmod($path, 0600);
            }
        }
    }

    private static function countRows(string $path): int
    {
        $count = 0;
        foreach (self::lines($path, 'package') as $unused) {
            ++$count;
        }

        return $count;
    }

    /** @return \Generator<int, string> */
    private static function lines(string $path, string $table): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new DataTransferException(sprintf('The %s package file could not be read.', $table));
        }
        try {
            $lineNumber = 0;
            while (!feof($handle)) {
                $chunk = fgets($handle, self::MAX_LINE_BYTES + 2);
                if ($chunk === false) {
                    break;
                }
                if (!str_ends_with($chunk, "\n") && !feof($handle)) {
                    throw new DataTransferException(sprintf('A %s row is larger than the transfer allows, so it was not imported.', $table));
                }
                $line = rtrim($chunk, "\r\n");
                if ($line === '') {
                    continue;
                }
                ++$lineNumber;
                yield $lineNumber => $line;
            }
        } finally {
            fclose($handle);
        }
    }
}
