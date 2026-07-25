<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260529000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add consent_state column to events table';
    }

    public function up(Schema $schema): void
    {
        $events = $schema->getTable('events');

        if (!$events->hasColumn('consent_state')) {
            $events->addColumn('consent_state', 'string', [
                'length' => 20,
                'notnull' => true,
                'default' => 'unknown',
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $events = $schema->getTable('events');

        if ($events->hasColumn('consent_state')) {
            $events->dropColumn('consent_state');
        }
    }
}
