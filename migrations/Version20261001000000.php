<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a seventh glossary dimension view for website labels. It reads the
 * existing private analytics_glossary table in the same shape as the six
 * views of Version20260928000000; app:analytics:glossary:sync fills it from
 * the registered websites.
 */
final class Version20261001000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the bi_dim_website_token_v1 glossary view for website labels';
    }

    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE VIEW bi_dim_website_token_v1 AS
SELECT
    code AS website_token,
    label AS website_token_label,
    group_label AS website_token_group,
    description AS website_token_description,
    sort_order AS website_token_sort
FROM analytics_glossary
WHERE entry_type = 'value' AND subject = 'website_token' AND is_default_locale = 1
SQL);
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLServerPlatform) {
            $this->addSql("IF OBJECT_ID('bi_dim_website_token_v1', 'V') IS NOT NULL DROP VIEW bi_dim_website_token_v1");

            return;
        }
        $this->addSql('DROP VIEW IF EXISTS bi_dim_website_token_v1');
    }
}
