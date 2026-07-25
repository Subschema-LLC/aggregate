<?php

declare(strict_types=1);

namespace App\Service\GeoIp;

use MaxMind\Db\Reader;

/** Reads a local MMDB and immediately reduces its record to country/continent codes. */
final class MaxMindMmdbGeoCodeLookup implements MmdbGeoCodeLookupInterface
{
    private ?Reader $reader = null;
    private ?string $openPath = null;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function lookup(string $databasePath, string $ipAddress): ?GeoCodes
    {
        $databasePath = $this->resolveLocalDatabasePath($databasePath);
        if ($databasePath === null) {
            $this->closeReader();

            return null;
        }

        try {
            if ($this->reader === null || $this->openPath !== $databasePath) {
                $this->closeReader();
                $this->reader = new Reader($databasePath);
                $this->openPath = $databasePath;
            }

            $record = $this->reader->get($ipAddress);
            if (!is_array($record)) {
                return null;
            }

            // Never return, retain, persist or log the detailed MMDB record.
            $codes = new GeoCodes(
                countryCode: $record['country']['iso_code'] ?? null,
                continentCode: $record['continent']['code'] ?? null,
            );
            unset($record);

            return $codes;
        } catch (\Throwable) {
            // A missing, unreadable, corrupt or incompatible DB is an unknown
            // area. Deliberately do not attach the IP, record or exception to a
            // logger, because lookup metadata must remain transient.
            $this->closeReader();

            return null;
        }
    }

    public function __destruct()
    {
        $this->closeReader();
    }

    private function resolveLocalDatabasePath(string $databasePath): ?string
    {
        $databasePath = trim($databasePath);
        if ($databasePath === ''
            || str_contains($databasePath, "\0")
            || str_contains($databasePath, '://')
            // UNC and device paths can initiate outbound SMB access and leak
            // service credentials. The configured database must be local.
            || str_starts_with($databasePath, '\\\\')
            || str_starts_with($databasePath, '//')) {
            return null;
        }

        $isWindowsDrivePath = preg_match('/^[A-Za-z]:[\\\\\/]/D', $databasePath) === 1;
        $isAbsolute = str_starts_with($databasePath, '/')
            || $isWindowsDrivePath;

        // Reject every named PHP stream-wrapper/scheme form. The Windows drive
        // exception above is a local filesystem path, not a URI scheme.
        if (!$isWindowsDrivePath
            && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/D', $databasePath) === 1) {
            return null;
        }

        $projectRoot = realpath($this->projectDir);
        if ($projectRoot === false) {
            return null;
        }

        $candidate = $isAbsolute
            ? $databasePath
            : $projectRoot.DIRECTORY_SEPARATOR.$databasePath;
        $resolvedPath = realpath($candidate);
        if ($resolvedPath === false) {
            return null;
        }

        if (!$isAbsolute
            && $resolvedPath !== $projectRoot
            && !str_starts_with($resolvedPath, $projectRoot.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return str_ends_with(strtolower($resolvedPath), '.mmdb')
            && is_file($resolvedPath)
            && is_readable($resolvedPath)
            ? $resolvedPath
            : null;
    }

    private function closeReader(): void
    {
        if ($this->reader !== null) {
            try {
                $this->reader->close();
            } catch (\Throwable) {
                // Closing a reader must not turn optional geography into an
                // ingestion failure or disclose lookup state through logging.
            }
        }

        $this->reader = null;
        $this->openPath = null;
    }
}
