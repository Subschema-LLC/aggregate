<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928000000 extends AbstractMigration
{
    private const DIMENSIONS = [
        'event_name', 'goal_event', 'referrer_channel', 'device_class',
        'viewport_bucket', 'geo_area',
    ];

    public function getDescription(): string
    {
        return 'Add declared BI glossary metadata and eight stable reporting views';
    }

    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        $table = new Table('analytics_glossary');
        // MySQL-family code comparisons are case-sensitive. SQL Server inherits
        // the database collation, matching fact columns so plain joins do not
        // fail with a collation conflict.
        $table->addOption('charset', 'utf8mb4');
        $table->addOption('collation', 'utf8mb4_bin');
        foreach (['entry_type' => 16, 'subject' => 64, 'code' => 191, 'locale' => 35] as $name => $length) {
            $table->addColumn($name, Types::STRING, ['length' => $length]);
        }
        $table->addColumn('label', Types::STRING, ['length' => 191]);
        $table->addColumn('label_locale', Types::STRING, ['length' => 35, 'notnull' => false]);
        $table->addColumn('group_label', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('description', Types::TEXT, ['notnull' => false]);
        $table->addColumn('description_locale', Types::STRING, ['length' => 35, 'notnull' => false]);
        $table->addColumn('sort_order', Types::INTEGER);
        $table->addColumn('is_default_locale', Types::SMALLINT);
        $table->addColumn('source', Types::STRING, ['length' => 16]);
        $table->addColumn('synced_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['entry_type', 'subject', 'code', 'locale']);
        $table->addIndex(['entry_type', 'subject', 'is_default_locale'], 'IDX_GLOSSARY_DEFAULT');
        $this->addCreateTableSql($schema, $table);

        foreach (self::DIMENSIONS as $dimension) {
            $geoLevel = $dimension === 'geo_area'
                ? ",\n    CASE WHEN code LIKE 'country:%' THEN 'country' ELSE 'continent' END AS geo_level"
                : '';
            $this->addSql(sprintf(<<<'SQL'
CREATE VIEW bi_dim_%1$s_v1 AS
SELECT
    code AS %1$s,
    label AS %1$s_label,
    group_label AS %1$s_group,
    description AS %1$s_description,
    sort_order AS %1$s_sort%2$s
FROM analytics_glossary
WHERE entry_type = 'value' AND subject = '%1$s' AND is_default_locale = 1
SQL, $dimension, $geoLevel));
        }
        $this->addSql(<<<'SQL'
CREATE VIEW bi_glossary_values_v1 AS
SELECT
    subject AS dimension,
    code,
    locale,
    label,
    label_locale,
    CASE WHEN label_locale = locale THEN 0 ELSE 1 END AS is_fallback,
    group_label,
    description,
    sort_order,
    is_default_locale
FROM analytics_glossary
WHERE entry_type = 'value'
SQL);
        $this->addSql(<<<'SQL'
CREATE VIEW bi_glossary_columns_v1 AS
SELECT
    subject AS object_name,
    code AS column_name,
    locale,
    label,
    label_locale,
    CASE WHEN label_locale = locale THEN 0 ELSE 1 END AS is_fallback,
    description,
    is_default_locale
FROM analytics_glossary
WHERE entry_type = 'column'
SQL);
    }

    public function down(Schema $schema): void
    {
        foreach (self::DIMENSIONS as $dimension) {
            $this->addDropViewSql('bi_dim_'.$dimension.'_v1');
        }
        $this->addDropViewSql('bi_glossary_values_v1');
        $this->addDropViewSql('bi_glossary_columns_v1');
        $this->addSql('DROP TABLE analytics_glossary');
    }

    private function addCreateTableSql(Schema $schema, Table $table): void
    {
        if ($schema->hasTable($table->getName())) {
            return;
        }
        foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
            $this->addSql($sql);
        }
    }

    private function addDropViewSql(string $viewName): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLServerPlatform) {
            $this->addSql(sprintf("IF OBJECT_ID('%1\$s', 'V') IS NOT NULL DROP VIEW %1\$s", $viewName));

            return;
        }
        $this->addSql('DROP VIEW IF EXISTS '.$viewName);
    }
}
