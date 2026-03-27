<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial baseline schema: events, users, messenger_messages';
    }

    public function up(Schema $schema): void
    {
        $events = $schema->createTable('events');
        $events->addColumn('id', 'integer', ['autoincrement' => true]);
        $events->addColumn('website_token', 'string', ['length' => 191]);
        $events->addColumn('event_name', 'string', ['length' => 191, 'default' => 'view']);
        $events->addColumn('url', 'text');
        $events->addColumn('referrer', 'text', ['notnull' => false]);
        $events->addColumn('daily_ip_hash', 'string', ['length' => 191]);
        $events->addColumn('generalized_user_agent', 'string', ['length' => 191]);
        $events->addColumn('screen_width', 'integer', ['notnull' => false]);
        $events->addColumn('session_id', 'string', ['length' => 191, 'notnull' => false]);
        $events->addColumn('custom_data', 'json', ['notnull' => false]);
        $events->addColumn('goal_event', 'string', ['length' => 191, 'notnull' => false]);
        $events->addColumn('created_at', 'datetime_immutable');
        $events->setPrimaryKey(['id']);
        $events->addIndex(['website_token'], 'IDX_EVENTS_WEBSITE_TOKEN');
        $events->addIndex(['session_id'], 'IDX_EVENTS_SESSION_ID');

        $users = $schema->createTable('users');
        $users->addColumn('id', 'integer', ['autoincrement' => true]);
        $users->addColumn('username', 'string', ['length' => 180]);
        $users->addColumn('roles', 'json');
        $users->addColumn('password', 'string', ['length' => 255]);
        $users->addColumn('created_at', 'datetime_immutable');
        $users->setPrimaryKey(['id']);
        $users->addUniqueIndex(['username'], 'UNIQ_IDENTIFIER_USERNAME');

        // Used only in async queue mode (e.g. doctrine://default).
        $messages = $schema->createTable('messenger_messages');
        $messages->addColumn('id', 'bigint', ['autoincrement' => true]);
        $messages->addColumn('body', 'text');
        $messages->addColumn('headers', 'text');
        $messages->addColumn('queue_name', 'string', ['length' => 190]);
        $messages->addColumn('created_at', 'datetime_immutable');
        $messages->addColumn('available_at', 'datetime_immutable');
        $messages->addColumn('delivered_at', 'datetime_immutable', ['notnull' => false]);
        $messages->setPrimaryKey(['id']);
        $messages->addIndex(['queue_name'], 'IDX_75EA56E0FB7336F0');
        $messages->addIndex(['available_at'], 'IDX_75EA56E0E3BD61CE');
        $messages->addIndex(['delivered_at'], 'IDX_75EA56E016BA31DB');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('messenger_messages')) {
            $schema->dropTable('messenger_messages');
        }
        if ($schema->hasTable('users')) {
            $schema->dropTable('users');
        }
        if ($schema->hasTable('events')) {
            $schema->dropTable('events');
        }
    }
}
