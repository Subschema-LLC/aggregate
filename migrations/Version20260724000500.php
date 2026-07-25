<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purge legacy non-granted enhanced rows and queued Doctrine tracker envelopes';
    }

    public function up(Schema $schema): void
    {
        // This raw-only cleanup runs after all privacy-mode schema migrations
        // have completed successfully.
        $this->addSql(
            "DELETE FROM events WHERE privacy_mode = 'enhanced' AND (consent_state IS NULL OR consent_state <> 'granted')",
        );
        $this->addSql(
            "DELETE FROM messenger_messages WHERE body LIKE '%TrackEventMessage%' OR headers LIKE '%TrackEventMessage%'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Legacy non-granted events and queued tracker envelopes were permanently removed.',
        );
    }
}
