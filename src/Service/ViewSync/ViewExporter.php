<?php

declare(strict_types=1);

namespace App\Service\ViewSync;

/**
 * Writes one reporting view, read through ViewReader (or another ViewSource,
 * such as the forensic database's reader), to a temporary
 * newline-delimited JSON file with every value in the view's catalog type,
 * and fingerprints its content. Both syncs copy from this snapshot: BigQuery
 * loads the file, and the reporting database reads its rows back. Rows are
 * streamed, so large private views do not have to fit in memory, and the
 * view is read once per copy. Errors name the view and column, never a value.
 *
 * The fingerprint combines the columns, the row count and the sum of every
 * row's hash, so it does not depend on the order the database returns rows
 * in, which a view does not define: the same rows give the same fingerprint.
 * It tells a scheduled sync whether a copy would change anything; it is not a
 * security check.
 */
class ViewExporter
{
    /** Changes when the fingerprint is computed differently, so old ones never match. */
    private const FINGERPRINT_FORMAT = 'v1';

    public function __construct(
        private readonly ViewSource $reader,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param string $directory where to write the file, relative to the application folder, such as var/bigquery
     *
     * @throws ViewSyncException when the view cannot be read, a value does not fit its type, or the file cannot be written
     */
    public function export(string $view, string $directory): ViewSnapshot
    {
        $source = $this->reader->read($view);
        $fields = $source['fields'];
        $file = $this->temporaryFile($view, $directory);
        $handle = fopen($file, 'wb');
        if ($handle === false) {
            throw new ViewSyncException('The export file could not be created in '.$directory.'. Check that the folder is writable.');
        }
        $rows = 0;
        // Four 32-bit sums of the rows' 128-bit hashes, each kept below 2^32.
        $sums = [0, 0, 0, 0];
        try {
            foreach ($source['rows'] as $row) {
                $record = [];
                foreach ($fields as $index => $field) {
                    $record[$field['name']] = ViewReader::convert($row[$index] ?? null, $field['type'], $view, $field['name']);
                }
                $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION)."\n";
                if (fwrite($handle, $line) !== strlen($line)) {
                    throw new ViewSyncException('The export file could not be written. Check the free space in '.$directory.'.');
                }
                foreach (array_values((array) unpack('N4', hash('xxh128', $line, true))) as $part => $value) {
                    $sums[$part] = ($sums[$part] + (int) $value) & 0xFFFFFFFF;
                }
                ++$rows;
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($file);
            throw $e;
        }
        fclose($handle);
        $fingerprint = hash('sha256', self::FINGERPRINT_FORMAT."\n".json_encode($fields, JSON_THROW_ON_ERROR)."\n".$rows."\n".implode(',', $sums));

        return new ViewSnapshot($view, $file, $fields, $rows, (int) filesize($file), $fingerprint);
    }

    private function temporaryFile(string $view, string $directory): string
    {
        $path = $this->projectDir.'/'.$directory;
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new ViewSyncException($directory.' could not be created. Check that var is writable.');
        }
        // Exports left by an interrupted run are removed after a day.
        foreach (glob($path.'/export-*.ndjson') ?: [] as $stale) {
            if (filemtime($stale) < time() - 86400) {
                @unlink($stale);
            }
        }
        $file = $path.'/export-'.$view.'-'.bin2hex(random_bytes(6)).'.ndjson';
        if (@touch($file) === false || !@chmod($file, 0600)) {
            throw new ViewSyncException('The export file could not be created in '.$directory.'. Check that the folder is writable.');
        }

        return $file;
    }
}
