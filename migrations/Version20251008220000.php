<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251008220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create websites, page_views, events tables (portable)';
    }

    public function up(Schema $schema): void
    {
        // websites
        $websites = $schema->createTable('websites');
        $websites->addColumn('id', 'integer', ['autoincrement' => true]);
        $websites->addColumn('name', 'string', ['length' => 191]);
        $websites->addColumn('domain', 'string', ['length' => 191]);
        $websites->addColumn('public_token', 'string', ['length' => 191]);
        $websites->setPrimaryKey(['id']);
        $websites->addUniqueIndex(['public_token'], 'UNIQ_WEBSITES_PUBLIC_TOKEN');

        // page_views
        $views = $schema->createTable('page_views');
        $views->addColumn('id', 'integer', ['autoincrement' => true]);
        $views->addColumn('website_id', 'integer');
        $views->addColumn('url', 'text');
        $views->addColumn('referrer', 'text', ['notnull' => false]);
        $views->addColumn('daily_ip_hash', 'string', ['length' => 191]);
        $views->addColumn('generalized_user_agent', 'string', ['length' => 191]);
        $views->addColumn('screen_width', 'integer', ['notnull' => false]);
        $views->addColumn('created_at', 'datetime_immutable');
        $views->setPrimaryKey(['id']);
        $views->addIndex(['website_id'], 'IDX_PAGE_VIEWS_WEBSITE_ID');
        $views->addForeignKeyConstraint('websites', ['website_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_PAGE_VIEWS_WEBSITE');

        // events
        $events = $schema->createTable('events');
        $events->addColumn('id', 'integer', ['autoincrement' => true]);
        $events->addColumn('website_id', 'integer');
        $events->addColumn('page_view_id', 'integer', ['notnull' => false]);
        $events->addColumn('event_name', 'string', ['length' => 191]);
        $events->addColumn('custom_data', 'json', ['notnull' => false]);
        $events->addColumn('created_at', 'datetime_immutable');
        $events->setPrimaryKey(['id']);
        $events->addIndex(['website_id'], 'IDX_EVENTS_WEBSITE_ID');
        $events->addIndex(['page_view_id'], 'IDX_EVENTS_PAGE_VIEW_ID');
        $events->addForeignKeyConstraint('websites', ['website_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EVENTS_WEBSITE');
        $events->addForeignKeyConstraint('page_views', ['page_view_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_EVENTS_PAGE_VIEW');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('events');
        $schema->dropTable('page_views');
        $schema->dropTable('websites');
    }
}
