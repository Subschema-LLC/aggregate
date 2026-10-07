<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the private status table for BigQuery sync (app:bigquery:sync): one
 * row per synced view with its last result, used as a lease so replicas do
 * not sync the same view at once. It holds no event data.
 */
final class Version20261007000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the analytics_bigquery_sync status table for BigQuery sync';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('analytics_bigquery_sync');
        $table->addOption('charset', 'utf8mb4');
        $table->addOption('collation', 'utf8mb4_unicode_ci');
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

    public function down(Schema $schema): void
    {
        $schema->dropTable('analytics_bigquery_sync');
    }
}
