<?php

declare(strict_types=1);

namespace App\Service\ViewSync;

/**
 * One reporting view as ViewExporter wrote it: a local newline-delimited JSON
 * file with every value in its catalog type, the view's columns, and a
 * fingerprint of the content. BigQuery loads the file as it is; the reporting
 * database reads the rows back from it (records()).
 */
final readonly class ViewSnapshot
{
    /**
     * @param list<array{name: string, type: string}> $fields      the view's columns, in row order
     * @param string                                  $fingerprint the same for the same columns and rows, in any order
     */
    public function __construct(
        public string $view,
        public string $file,
        public array $fields,
        public int $rows,
        public int $bytes,
        public string $fingerprint,
    ) {
    }

    /**
     * The rows, read back from the file, each a list of values in the
     * columns' order.
     *
     * @return \Generator<int, list<mixed>>
     *
     * @throws ViewSyncException when the file cannot be read
     */
    public function records(): \Generator
    {
        $handle = @fopen($this->file, 'rb');
        if ($handle === false) {
            throw new ViewSyncException('The export of '.$this->view.' could not be read back.');
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                if (!is_array($record)) {
                    throw new ViewSyncException('The export of '.$this->view.' could not be read back.');
                }
                $row = [];
                foreach ($this->fields as $field) {
                    $row[] = $record[$field['name']] ?? null;
                }
                yield $row;
            }
        } catch (\JsonException $e) {
            throw new ViewSyncException('The export of '.$this->view.' could not be read back.', previous: $e);
        } finally {
            fclose($handle);
        }
    }

    public function delete(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }
}
