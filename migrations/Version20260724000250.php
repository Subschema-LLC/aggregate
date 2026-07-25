<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724000250 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make anonymous the fail-safe default after legacy rows are backfilled as enhanced';
    }

    public function isTransactional(): bool
    {
        // MySQL and MariaDB implicitly commit ALTER TABLE statements.
        return false;
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('events')->changeColumn('privacy_mode', ['default' => 'anonymous']);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Restoring an enhanced default would make omitted privacy modes unsafe.',
        );
    }
}
