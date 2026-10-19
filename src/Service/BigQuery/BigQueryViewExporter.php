<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use App\Service\ViewSync\ViewReader;
use App\Service\ViewSync\ViewSyncException;

/**
 * Writes one reporting view, read through ViewReader, to a temporary
 * newline-delimited JSON file for a BigQuery load job, with every value in
 * the view's catalog type. Rows are streamed, so large private views do not
 * have to fit in memory. Errors name the view and column, never a value.
 */
class BigQueryViewExporter
{
    public function __construct(
        private readonly ViewReader $reader,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{file: string, rows: int, bytes: int, fields: list<array{name: string, type: string}>}
     *
     * @throws ViewSyncException when the view cannot be read or a value does not fit its type
     * @throws BigQueryException when the export file cannot be written
     */
    public function export(string $view): array
    {
        $source = $this->reader->read($view);
        $fields = $source['fields'];
        $file = $this->temporaryFile($view);
        $handle = fopen($file, 'wb');
        if ($handle === false) {
            throw new BigQueryException('The export file could not be created in var/bigquery. Check that the folder is writable.');
        }
        $rows = 0;
        try {
            foreach ($source['rows'] as $row) {
                $record = [];
                foreach ($fields as $index => $field) {
                    $record[$field['name']] = ViewReader::convert($row[$index] ?? null, $field['type'], $view, $field['name']);
                }
                $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION)."\n";
                if (fwrite($handle, $line) !== strlen($line)) {
                    throw new BigQueryException('The export file could not be written. Check the free space in var/bigquery.');
                }
                ++$rows;
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($file);
            throw $e;
        }
        fclose($handle);

        return ['file' => $file, 'rows' => $rows, 'bytes' => (int) filesize($file), 'fields' => $fields];
    }

    private function temporaryFile(string $view): string
    {
        $directory = $this->projectDir.'/var/bigquery';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new BigQueryException('var/bigquery could not be created. Check that var is writable.');
        }
        // Exports left by an interrupted run are removed after a day.
        foreach (glob($directory.'/export-*.ndjson') ?: [] as $stale) {
            if (filemtime($stale) < time() - 86400) {
                @unlink($stale);
            }
        }
        $file = $directory.'/export-'.$view.'-'.bin2hex(random_bytes(6)).'.ndjson';
        if (@touch($file) === false || !@chmod($file, 0600)) {
            throw new BigQueryException('The export file could not be created in var/bigquery. Check that the folder is writable.');
        }

        return $file;
    }
}
