<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User profiles and permissions: an optional display name and email each user
 * manages, and a standard user's permission groups and own grants (a JSON
 * document, read by App\Security\Access\AccessGrant). Administrators stay
 * administrators. Other existing accounts keep what they could see before:
 * the Websites page, for every website, read-only.
 */
final class Version20261020000000 extends AbstractMigration
{
    private const FORMER_VIEWER_ACCESS = '{"permission_groups":[],"websites":{"all":true,"groups":[],"sites":[]},"pages":{"websites":"view"}}';

    public function getDescription(): string
    {
        return 'Add display name, email and access to users';
    }

    public function isTransactional(): bool
    {
        // MySQL and MariaDB commit column changes implicitly.
        return !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform);
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }
        $users = $schema->getTable('users');
        if (!$users->hasColumn('display_name')) {
            $users->addColumn('display_name', 'string', ['length' => 120, 'notnull' => false]);
        }
        if (!$users->hasColumn('email')) {
            $users->addColumn('email', 'string', ['length' => 254, 'notnull' => false]);
        }
        if ($users->hasColumn('access')) {
            return;
        }
        $users->addColumn('access', 'json', ['notnull' => false]);
        foreach ($this->connection->fetchAllAssociative('SELECT id, roles FROM users') as $row) {
            $roles = json_decode((string) $row['roles'], true);
            if (is_array($roles) && in_array('ROLE_ADMIN', $roles, true)) {
                continue;
            }
            $this->addSql('UPDATE users SET access = ? WHERE id = ?', [self::FORMER_VIEWER_ACCESS, (int) $row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }
        $users = $schema->getTable('users');
        foreach (['access', 'email', 'display_name'] as $column) {
            if ($users->hasColumn($column)) {
                $users->dropColumn($column);
            }
        }
    }
}
