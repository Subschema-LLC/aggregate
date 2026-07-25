<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anonymous and enhanced privacy modes to the unified events table';
    }

    public function isTransactional(): bool
    {
        // MySQL and MariaDB implicitly commit DDL statements.
        return false;
    }

    public function up(Schema $schema): void
    {
        $events = $schema->getTable('events');

        if ($events->hasColumn('daily_ip_hash')) {
            $events->dropColumn('daily_ip_hash');
        }

        if (!$events->hasColumn('visitor_id')) {
            $events->addColumn('visitor_id', 'string', [
                'length' => 191,
                'notnull' => false,
            ]);
        }

        if (!$events->hasColumn('privacy_mode')) {
            // Backfill pre-migration rows as enhanced. The next schema migration
            // changes the default to anonymous before ingestion resumes.
            $events->addColumn('privacy_mode', 'string', [
                'length' => 20,
                'default' => 'enhanced',
            ]);
        }

        if (!$events->hasColumn('device_class')) {
            $events->addColumn('device_class', 'string', [
                'length' => 20,
                'default' => 'unknown',
            ]);
        }

        if (!$events->hasColumn('viewport_bucket')) {
            $events->addColumn('viewport_bucket', 'string', [
                'length' => 20,
                'default' => 'unknown',
            ]);
        }

        $events->changeColumn('generalized_user_agent', ['notnull' => false]);
        $events->changeColumn('consent_state', [
            'notnull' => false,
            'default' => null,
        ]);

        if (!$events->hasIndex('IDX_EVENTS_VISITOR_ID')) {
            $events->addIndex(['visitor_id'], 'IDX_EVENTS_VISITOR_ID');
        }

        if (!$events->hasIndex('IDX_EVENTS_CONSENT_STATE')) {
            $events->addIndex(['consent_state'], 'IDX_EVENTS_CONSENT_STATE');
        }

        if (!$events->hasIndex('IDX_EVENTS_PRIVACY_SITE_CREATED')) {
            $events->addIndex(
                ['privacy_mode', 'website_token', 'created_at'],
                'IDX_EVENTS_PRIVACY_SITE_CREATED',
            );
        }

        if (!$schema->hasTable('analytics_privacy_settings')) {
            $privacySettings = $schema->createTable('analytics_privacy_settings');
            $privacySettings->addOption('charset', 'utf8mb4');
            $privacySettings->addOption('collation', 'utf8mb4_unicode_ci');
            $privacySettings->addColumn('id', 'integer');
            $privacySettings->addColumn('anonymous_min_cell_count', 'integer', ['default' => 5]);
            $privacySettings->addColumn('updated_at', 'datetime_immutable');
            $privacySettings->setPrimaryKey(['id']);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Daily IP hashes were permanently removed and the unified schema may contain anonymous events.',
        );
    }
}
