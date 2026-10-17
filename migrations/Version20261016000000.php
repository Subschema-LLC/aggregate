<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces the events index on website_token with one on (website_token,
 * created_at). The Websites page and every sign-in look up each website's
 * newest event; with the time in the index that is one index entry per
 * website instead of every event the website has. The new index starts with
 * website_token, so lookups by token alone still use it, and the old one is
 * dropped rather than kept beside it.
 *
 * Building it reads the whole events table once, which can take a while on a
 * large one; the update runs it with the site in maintenance mode. The index
 * SQL is issued directly, so SQLite does not rebuild the table.
 */
final class Version20261016000000 extends AbstractMigration
{
    private const OLD_INDEX = 'IDX_EVENTS_WEBSITE_TOKEN';
    private const NEW_INDEX = 'IDX_EVENTS_TOKEN_CREATED';

    public function getDescription(): string
    {
        return 'Index events by website token and time, so a website\'s newest event is one index entry';
    }

    public function isTransactional(): bool
    {
        // MySQL and MariaDB commit index DDL implicitly.
        return !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform);
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('events')) {
            return;
        }
        $events = $schema->getTable('events');
        $platform = $this->connection->getDatabasePlatform();
        if (!$events->hasIndex(self::NEW_INDEX)) {
            $this->addSql($platform->getCreateIndexSQL(new Index(self::NEW_INDEX, ['website_token', 'created_at']), 'events'));
        }
        if ($events->hasIndex(self::OLD_INDEX)) {
            $this->addSql($platform->getDropIndexSQL(self::OLD_INDEX, 'events'));
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('events')) {
            return;
        }
        $events = $schema->getTable('events');
        $platform = $this->connection->getDatabasePlatform();
        if (!$events->hasIndex(self::OLD_INDEX)) {
            $this->addSql($platform->getCreateIndexSQL(new Index(self::OLD_INDEX, ['website_token']), 'events'));
        }
        if ($events->hasIndex(self::NEW_INDEX)) {
            $this->addSql($platform->getDropIndexSQL(self::NEW_INDEX, 'events'));
        }
    }
}
