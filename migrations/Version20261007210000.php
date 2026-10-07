<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces the BigQuery-specific status table with two generic, private
 * tables that every background job shares: processing_tasks (one row per run,
 * with its status, lock and failure details) and audit_trail (one entry per
 * finished task, and later per administrator action). The old table's rows
 * are not carried over; the next scheduled sync starts fresh.
 */
final class Version20261007210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace analytics_bigquery_sync with the generic processing_tasks and audit_trail tables';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('analytics_bigquery_sync')) {
            $schema->dropTable('analytics_bigquery_sync');
        }

        $tasks = $schema->createTable('processing_tasks');
        self::utf8mb4($tasks);
        $tasks->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
        $tasks->addColumn('task_type', Types::STRING, ['length' => 64]);
        $tasks->addColumn('subject', Types::STRING, ['length' => 191, 'notnull' => false]);
        $tasks->addColumn('status', Types::STRING, ['length' => 16]);
        $tasks->addColumn('triggered_by', Types::STRING, ['length' => 16]);
        $tasks->addColumn('requested_by', Types::STRING, ['length' => 191, 'notnull' => false]);
        $tasks->addColumn('lock_key', Types::STRING, ['length' => 255, 'notnull' => false]);
        $tasks->addColumn('started_at', Types::DATETIME_IMMUTABLE);
        $tasks->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tasks->addColumn('row_count', Types::BIGINT, ['notnull' => false]);
        $tasks->addColumn('external_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $tasks->addColumn('details', Types::TEXT, ['notnull' => false]);
        $tasks->setPrimaryKey(['id']);
        // Unique only while set: a running exclusive task holds its lock key.
        // SQL Server gets a filtered index (WHERE lock_key IS NOT NULL) from
        // Doctrine; the other engines already allow several NULLs.
        $tasks->addUniqueIndex(['lock_key'], 'UNIQ_PROCESSING_TASKS_LOCK');
        $tasks->addIndex(['task_type', 'subject', 'status'], 'IDX_PROCESSING_TASKS_TYPE');
        $tasks->addIndex(['started_at'], 'IDX_PROCESSING_TASKS_STARTED');

        $audit = $schema->createTable('audit_trail');
        self::utf8mb4($audit);
        $audit->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
        $audit->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $audit->addColumn('category', Types::STRING, ['length' => 32]);
        $audit->addColumn('operation', Types::STRING, ['length' => 64]);
        $audit->addColumn('subject', Types::STRING, ['length' => 191, 'notnull' => false]);
        $audit->addColumn('outcome', Types::STRING, ['length' => 16]);
        $audit->addColumn('actor', Types::STRING, ['length' => 191, 'notnull' => false]);
        $audit->addColumn('processing_task_id', Types::BIGINT, ['notnull' => false]);
        $audit->addColumn('details', Types::TEXT, ['notnull' => false]);
        $audit->setPrimaryKey(['id']);
        $audit->addIndex(['occurred_at'], 'IDX_AUDIT_TRAIL_OCCURRED');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('audit_trail');
        $schema->dropTable('processing_tasks');

        $table = $schema->createTable('analytics_bigquery_sync');
        self::utf8mb4($table);
        $table->addColumn('name', Types::STRING, ['length' => 64]);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('owner_token', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('succeeded_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('row_count', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('message', Types::STRING, ['length' => 1000, 'notnull' => false]);
        $table->addColumn('job_id', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['name']);
    }

    private static function utf8mb4(Table $table): void
    {
        $table->addOption('charset', 'utf8mb4');
        $table->addOption('collation', 'utf8mb4_unicode_ci');
    }
}
