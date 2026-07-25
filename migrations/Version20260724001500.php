<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724001500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add coarse geographic areas and a separate geographic BI suppression threshold';
    }

    public function isTransactional(): bool
    {
        // MySQL and MariaDB implicitly commit DDL statements.
        return false;
    }

    public function up(Schema $schema): void
    {
        $events = $schema->getTable('events');
        if (!$events->hasColumn('geo_area')) {
            $events->addColumn('geo_area', 'string', [
                'length' => 16,
                'notnull' => false,
            ]);
        }

        $privacySettings = $schema->getTable('analytics_privacy_settings');
        if (!$privacySettings->hasColumn('anonymous_geo_min_cell_count')) {
            $privacySettings->addColumn('anonymous_geo_min_cell_count', 'integer', [
                'default' => 25,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $events = $schema->getTable('events');
        if ($events->hasColumn('geo_area')) {
            $events->dropColumn('geo_area');
        }

        $privacySettings = $schema->getTable('analytics_privacy_settings');
        if ($privacySettings->hasColumn('anonymous_geo_min_cell_count')) {
            $privacySettings->dropColumn('anonymous_geo_min_cell_count');
        }
    }
}
