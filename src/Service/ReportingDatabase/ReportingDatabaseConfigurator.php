<?php

declare(strict_types=1);

namespace App\Service\ReportingDatabase;

use App\Service\LocalEnvironmentFile;

/**
 * Saves the reporting database connection, REPORTING_DATABASE_URL, to
 * .env.local for the admin page and app:reporting-database:configure. A
 * connection is tested before it is saved: Aggregate connects, creates and
 * drops a table, and refuses the application database. The URL stays out of
 * YAML because it holds a password; it is never shown back.
 */
class ReportingDatabaseConfigurator
{
    public const VARIABLE = 'REPORTING_DATABASE_URL';
    private const COMMENT = 'Reporting database for BI users; saved by Aggregate after a successful connection test.';

    public function __construct(
        private readonly LocalEnvironmentFile $environment,
        private readonly ReportingDatabaseConnection $current,
    ) {
    }

    /** Why the connection cannot be saved from Aggregate, or null when it can. */
    public function problem(): ?string
    {
        return $this->environment->problem(self::VARIABLE);
    }

    /** Whether .env.local holds the connection, so Aggregate can remove it. */
    public function isSavedHere(): bool
    {
        return $this->environment->get(self::VARIABLE) !== null;
    }

    /**
     * Tests and saves a connection from form fields. An empty password keeps
     * the saved one when the type, server and user are unchanged.
     *
     * @param array{type?: mixed, host?: mixed, port?: mixed, database?: mixed, user?: mixed, password?: mixed, trust_certificate?: mixed} $fields
     *
     * @return array{engine: string, host: ?string, port: ?int, database: string, server_version: string, tables: list<string>, mark: array{source: ?string}|null}
     *
     * @throws \InvalidArgumentException|ReportingDatabaseException with a message for the administrator
     */
    public function save(array $fields): array
    {
        $type = is_string($fields['type'] ?? null) ? $fields['type'] : '';
        $text = static fn (string $key): string => is_scalar($fields[$key] ?? null) ? trim((string) $fields[$key]) : '';
        $port = $text('port');
        if ($port !== '' && preg_match('/^[0-9]{1,5}$/D', $port) !== 1) {
            throw new \InvalidArgumentException('The port must be a number between 1 and 65535.');
        }
        $password = is_string($fields['password'] ?? null) ? $fields['password'] : '';
        $saved = $this->current->fields();
        if ($password === '' && $saved !== null && $saved['type'] === $type && $saved['host'] === $text('host') && $saved['user'] === $text('user')) {
            $password = $this->current->password();
        }
        $trust = in_array($fields['trust_certificate'] ?? null, [true, '1', 1, 'on'], true);

        return $this->saveUrl(ReportingDatabaseConnection::buildUrl($type, $text('host'), $port === '' ? null : (int) $port, $text('database'), $text('user'), $password, $trust));
    }

    /**
     * Tests and saves a complete URL, written like DATABASE_URL.
     *
     * @return array{engine: string, host: ?string, port: ?int, database: string, server_version: string, tables: list<string>, mark: array{source: ?string}|null}
     *
     * @throws \InvalidArgumentException|ReportingDatabaseException with a message for the administrator
     */
    public function saveUrl(string $url): array
    {
        $problem = $this->problem();
        if ($problem !== null) {
            throw new ReportingDatabaseException($problem);
        }
        $url = trim($url);
        if (preg_match('/[\s\'"\\\\]/', $url) === 1) {
            throw new \InvalidArgumentException('The URL contains a space, quote or backslash. Percent-encode them, as in DATABASE_URL (a space is %20).');
        }
        $probe = $this->current->withUrl($url)->probe();
        try {
            $this->environment->set(self::VARIABLE, $url, self::COMMENT);
        } catch (\RuntimeException $e) {
            throw new ReportingDatabaseException('The connection works, but '.$e->getMessage());
        }

        return $probe;
    }

    /** Removes the connection from .env.local; the sync then reports that it is not set. */
    public function remove(): void
    {
        $problem = $this->problem();
        if ($problem !== null) {
            throw new ReportingDatabaseException($problem);
        }
        $this->environment->set(self::VARIABLE, null, self::COMMENT);
    }
}
