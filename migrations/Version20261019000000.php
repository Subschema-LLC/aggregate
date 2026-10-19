<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes the audit trail for its page and its purges, now that it records
 * administrator actions as well as job runs: by area and time (filtering, and
 * purging job runs and other entries on their own retention periods), and by
 * action and user (the page's choices and filters). No rows change.
 */
final class Version20261019000000 extends AbstractMigration
{
    /** @var array<string, list<string>> */
    private const INDEXES = [
        'IDX_AUDIT_TRAIL_CATEGORY' => ['category', 'occurred_at'],
        'IDX_AUDIT_TRAIL_OPERATION' => ['operation'],
        'IDX_AUDIT_TRAIL_ACTOR' => ['actor'],
    ];

    public function getDescription(): string
    {
        return 'Index the audit trail by area, action and user for its page and retention purges';
    }

    public function isTransactional(): bool
    {
        // MySQL and MariaDB commit index changes implicitly.
        return !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform);
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('audit_trail')) {
            return;
        }
        $audit = $schema->getTable('audit_trail');
        foreach (self::INDEXES as $name => $columns) {
            if (!$audit->hasIndex($name)) {
                $audit->addIndex($columns, $name);
            }
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('audit_trail')) {
            return;
        }
        $audit = $schema->getTable('audit_trail');
        foreach (array_keys(self::INDEXES) as $name) {
            if ($audit->hasIndex($name)) {
                $audit->dropIndex($name);
            }
        }
    }
}
