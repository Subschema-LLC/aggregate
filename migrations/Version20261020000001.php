<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives accounts that existed before permissions what they could see then:
 * the Websites page, for every website, read-only. Administrators keep
 * everything and are left alone.
 *
 * Separate from Version20261020000000, which adds the access column: Doctrine
 * runs a migration's own SQL before its schema changes, so the update has to
 * come in a later migration. Only rows without access are changed, so a rerun
 * after a failure is safe.
 */
final class Version20261020000001 extends AbstractMigration
{
    private const FORMER_VIEWER_ACCESS = '{"permission_groups":[],"websites":{"all":true,"groups":[],"sites":[]},"pages":{"websites":"view"}}';

    public function getDescription(): string
    {
        return 'Give accounts from before permissions read-only access to the Websites page';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users') || !$schema->getTable('users')->hasColumn('access')) {
            return;
        }
        foreach ($this->connection->fetchAllAssociative('SELECT id, roles FROM users') as $row) {
            $roles = json_decode((string) $row['roles'], true);
            if (is_array($roles) && in_array('ROLE_ADMIN', $roles, true)) {
                continue;
            }
            $this->addSql('UPDATE users SET access = ? WHERE id = ? AND access IS NULL', [self::FORMER_VIEWER_ACCESS, (int) $row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        // The previous migration's down() removes the column with the access in it.
    }
}
