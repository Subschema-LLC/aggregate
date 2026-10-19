<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds processing_tasks.fingerprint: what a successful run produced, so a
 * later run can tell whether it would produce the same. A view sync records
 * the fingerprint of the view it copied and, on a scheduled run, keeps the
 * copy when the view has not changed since. Existing rows have none, so the
 * first sync after the update copies every view as before.
 */
final class Version20261018000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a fingerprint to processing tasks, so view syncs can keep copies of unchanged views';
    }

    public function isTransactional(): bool
    {
        // MySQL and MariaDB commit ALTER TABLE implicitly.
        return !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform);
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('processing_tasks')) {
            return;
        }
        $tasks = $schema->getTable('processing_tasks');
        if (!$tasks->hasColumn('fingerprint')) {
            $tasks->addColumn('fingerprint', Types::STRING, ['length' => 64, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('processing_tasks') && $schema->getTable('processing_tasks')->hasColumn('fingerprint')) {
            $schema->getTable('processing_tasks')->dropColumn('fingerprint');
        }
    }
}
