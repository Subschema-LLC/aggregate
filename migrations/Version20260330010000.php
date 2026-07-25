<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compatibility marker for installs that recorded this historical version';
    }

    public function up(Schema $schema): void
    {
        // Intentionally empty. Some SQL-based installs recorded this version even
        // though Version20260529000000 is the canonical consent_state migration.
    }

    public function down(Schema $schema): void
    {
        // Compatibility markers must not change application schema.
    }
}
