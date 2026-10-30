<?php

declare(strict_types=1);

namespace App\Service\ForensicDatabase;

use App\Service\ReportingDatabase\ReportingDatabaseConnection;
use App\Service\ReportingDatabase\ReportingDatabaseException;
use Doctrine\DBAL\Connection;

/**
 * The forensic database: a third database, kept apart from the application
 * database and the reporting database, that holds complete copies of the raw
 * tables (with identifiers hashed) and the private views, for the people who
 * investigate problems. Its address is FORENSIC_DATABASE_URL, written like
 * DATABASE_URL and kept in .env.local or the server environment because it
 * holds a password. Connections, URLs and fields work as for the reporting
 * database (ReportingDatabaseConnection); nothing here returns or logs the
 * password.
 *
 * Aggregate writes here only when the database says who reads it: its own
 * comment carries the word aggregate-forensic-copy, optionally followed by
 * source=<the application database>, on MariaDB (10.5 and later) and
 * PostgreSQL; on engines that keep no database comment (MySQL, SQL Server,
 * SQLite), forensic_database_access_restricted confirms instead that access is
 * restricted. A database marked as a read-only reporting copy for BI users is
 * always refused, and so are the application and reporting databases
 * themselves.
 */
class ForensicDatabaseConnection extends ReportingDatabaseConnection
{
    public const DEFAULT_SQLITE_FILE = 'var/forensic.db';
    public const VARIABLE = 'FORENSIC_DATABASE_URL';
    protected const NAME = 'forensic database';
    protected const EXAMPLE_DATABASE = 'aggregate_forensic';
    /**
     * The word that marks a database, in its own comment, as a forensic copy
     * that only the people who investigate problems can read, for example
     * "Forensic copy, restricted to the support team [aggregate-forensic-copy source=aggregate]".
     */
    public const FORENSIC_MARK = 'aggregate-forensic-copy';

    public function __construct(
        ?string $forensicUrl,
        private readonly string $forensicProjectDir,
        private readonly Connection $forensicApplication,
        private readonly ReportingDatabaseConnection $reporting,
        private readonly ForensicDatabaseSettings $settings,
    ) {
        parent::__construct($forensicUrl, $forensicProjectDir, $forensicApplication);
    }

    public function withUrl(string $url): self
    {
        return new self($url, $this->forensicProjectDir, $this->forensicApplication, $this->reporting, $this->settings);
    }

    /**
     * The forensic mark, after checking that this database may receive
     * forensic copies; null when the engine cannot be marked and the settings
     * confirm that access is restricted.
     *
     * @return array{source: ?string}|null
     *
     * @throws ReportingDatabaseException with a message for the administrator when it may not
     */
    public function assertMarkAllowsSync(): ?array
    {
        $comment = $this->comment();
        if ($comment === null) {
            if (!$this->settings->accessRestricted()) {
                throw new ReportingDatabaseException('This database engine keeps no database comment, so the forensic database cannot be marked as a forensic copy. Once only the people who investigate problems can read it, set '.ForensicDatabaseSettings::KEY_ACCESS_RESTRICTED.': true, or confirm it on the forensic database page.');
            }

            return null;
        }
        if (self::parseMark($comment) !== null) {
            throw new ReportingDatabaseException('The forensic database is marked as a read-only reporting copy for BI users ('.self::MARK.'), who can read every table in it, so Aggregate copies no forensic data there. Use a separate database for forensic copies.');
        }
        $mark = self::parseForensicMark($comment);
        if ($mark === null) {
            throw new ReportingDatabaseException('The forensic database is not marked as a forensic copy. Once only the people who investigate problems can read it, add the word '.self::FORENSIC_MARK.' to the database\'s comment, followed by source= and the application database\'s name; see the forensic database guide.');
        }
        $application = $this->applicationDatabase();
        if ($mark['source'] !== null && $application !== null && strcasecmp($mark['source'], $application) !== 0) {
            throw new ReportingDatabaseException(sprintf(
                'The forensic database is marked as the forensic copy of the database %s, not of this installation\'s database %s, so Aggregate does not write to it. Use a forensic database of its own, or change the mark in the database\'s comment if this copy now belongs to this installation.',
                $mark['source'],
                $application,
            ));
        }

        return $mark;
    }

    /**
     * The forensic mark in a database comment: the word
     * aggregate-forensic-copy, on its own, optionally followed by
     * source=<database name>.
     *
     * @return array{source: ?string}|null
     */
    public static function parseForensicMark(string $comment): ?array
    {
        if (preg_match('/(?<![A-Za-z0-9_-])'.preg_quote(self::FORENSIC_MARK, '/').'(?![A-Za-z0-9_-])(?:\s+source=([A-Za-z0-9_$-]{1,64}))?/i', $comment, $match) !== 1) {
            return null;
        }

        return ['source' => ($match[1] ?? '') !== '' ? $match[1] : null];
    }

    /**
     * What allows the copy, for messages: the mark, or the confirmation on an
     * engine that cannot be marked.
     *
     * @param array{source: ?string}|null $mark from assertMarkAllowsSync()
     */
    public static function markSentence(?array $mark): string
    {
        return $mark === null
            ? 'This engine cannot be marked; '.ForensicDatabaseSettings::KEY_ACCESS_RESTRICTED.' confirms that access to it is restricted.'
            : 'It is marked as a forensic copy'.($mark['source'] !== null ? ' of '.$mark['source'] : '').'.';
    }

    protected function copyableTables(): array
    {
        return ForensicCatalog::names();
    }

    /**
     * Forensic copies hold raw events: they must not land next to the
     * application's own tables, nor in the reporting database, whose BI users
     * read every table.
     *
     * @param array<string, mixed> $params
     */
    protected function assertSeparate(array $params): void
    {
        if (self::sameDatabase($params, $this->forensicApplication->getParams())) {
            throw new ReportingDatabaseException(self::VARIABLE.' names the application database. Create a separate database for forensic copies (it can be on the same server), so a sync never replaces tables next to the application\'s own.');
        }
        if (!$this->reporting->isConfigured()) {
            return;
        }
        try {
            $reporting = $this->reporting->params();
        } catch (ReportingDatabaseException) {
            // An invalid reporting URL leads nowhere; its own page reports it.
            return;
        }
        if (self::sameDatabase($params, $reporting)) {
            throw new ReportingDatabaseException(self::VARIABLE.' names the reporting database, whose BI users can read every table in it. Create a separate database for forensic copies.');
        }
    }
}
