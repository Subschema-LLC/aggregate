<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Migrations after server roles shipped keep `events` and
 * `messenger_messages` usable by the previous release, which collection and
 * worker servers still run while a cluster updates (docs/RELEASES.md).
 */
final class MigrationAdditivityTest extends TestCase
{
    /** The last migration before clusters: earlier ones ran on single servers. */
    private const BASELINE = 'Version20261020000001';

    /**
     * Migrations a reviewer checked by hand, with why each is safe for the
     * previous release, when the check misreads them.
     *
     * @var array<string, string>
     */
    private const REVIEWED = [];

    public function testMigrationsSinceClustersAreAdditiveOnTheCollectionTables(): void
    {
        // Until the first migration after the baseline exists, there is nothing to check.
        $this->addToAssertionCount(1);
        foreach (glob(dirname(__DIR__, 2).'/migrations/Version*.php') ?: [] as $file) {
            $version = basename($file, '.php');
            if (strcmp($version, self::BASELINE) <= 0 || isset(self::REVIEWED[$version])) {
                continue;
            }
            self::assertSame([], MigrationAdditivity::problems((string) file_get_contents($file)), sprintf(
                '%s changes %s in a way a server still on the previous release cannot run against. Add nullable or defaulted columns only; remove or rename a release later, after every server runs the code that stopped using it. See docs/RELEASES.md.',
                $version,
                implode(' or ', MigrationAdditivity::TABLES),
            ));
        }
    }

    public function testTheCheckWouldHaveStoppedEarlierSchemaChanges(): void
    {
        $problems = MigrationAdditivity::problems((string) file_get_contents(dirname(__DIR__, 2).'/migrations/Version20260724000000.php'));

        self::assertContains('calls dropColumn() on events or messenger_messages', $problems);
        self::assertContains('calls modifyColumn() on events or messenger_messages', $problems);
    }

    #[DataProvider('notAdditive')]
    public function testWhatTheOldCodeCannotRunAgainstIsFound(string $migration, string $problem): void
    {
        self::assertStringContainsString($problem, implode("\n", MigrationAdditivity::problems($migration)));
    }

    public static function notAdditive(): iterable
    {
        yield 'drop column' => [self::up("\$this->addSql('ALTER TABLE events DROP COLUMN page_title');"), 'drops a column'];
        yield 'MySQL drop' => [self::up("\$this->addSql('ALTER TABLE `events` DROP page_title');"), 'drops a column'];
        yield 'rename column' => [self::up("\$this->addSql('ALTER TABLE events RENAME COLUMN page_path TO path');"), 'renames or retypes a column'];
        yield 'MySQL change' => [self::up("\$this->addSql('ALTER TABLE events CHANGE page_path path VARCHAR(255) NOT NULL');"), 'renames or retypes a column'];
        yield 'retype' => [self::up("\$this->addSql('ALTER TABLE events ALTER COLUMN screen_width TYPE BIGINT');"), 'changes a column (TYPE BIGINT)'];
        yield 'set not null' => [self::up("\$this->addSql('ALTER TABLE events ALTER COLUMN geo_area SET NOT NULL');"), 'changes a column (SET NOT NULL)'];
        yield 'not null without default' => [self::up("\$this->addSql('ALTER TABLE events ADD COLUMN region VARCHAR(8) NOT NULL');"), 'adds a NOT NULL column without a default'];
        yield 'second clause' => [self::up("\$this->addSql('ALTER TABLE events ADD region VARCHAR(8) DEFAULT NULL, DROP page_title');"), 'drops a column'];
        yield 'drop table' => [self::up("\$this->addSql('DROP TABLE IF EXISTS messenger_messages');"), 'drops the table'];
        yield 'SQL Server rename' => [self::up("\$this->addSql(\"EXEC sp_rename 'events.page_path', 'path', 'COLUMN'\");"), 'renames the table or a column'];
        yield 'schema drop' => [self::up("\$events = \$schema->getTable('events');\n\$events->dropColumn('page_title');"), 'calls dropColumn()'];
        yield 'schema rename' => [self::up("\$schema->getTable('events')->renameColumn('a', 'b');"), 'calls renameColumn()'];
        yield 'schema not null' => [self::up("\$table = \$schema->getTable('events');\n\$table->addColumn('region', 'string', ['length' => 8]);"), 'adds a NOT NULL column without a default'];
        yield 'blocking index' => [self::up("\$this->addSql('CREATE INDEX idx_events_region ON events (region)');"), 'builds an index that may block inserts'];
    }

    #[DataProvider('additive')]
    public function testWhatTheOldCodeCanRunAgainstPasses(string $migration): void
    {
        self::assertSame([], MigrationAdditivity::problems($migration));
    }

    public static function additive(): iterable
    {
        yield 'nullable column' => [self::up("\$this->addSql('ALTER TABLE events ADD COLUMN region VARCHAR(8) DEFAULT NULL');")];
        yield 'defaulted not null' => [self::up("\$this->addSql('ALTER TABLE events ADD region VARCHAR(8) NOT NULL DEFAULT \\'\\'');")];
        yield 'relaxing' => [self::up("\$this->addSql('ALTER TABLE events ALTER COLUMN goal_event DROP NOT NULL');")];
        yield 'schema nullable' => [self::up("\$events = \$schema->getTable('events');\n\$events->addColumn('region', 'string', ['length' => 8, 'notnull' => false]);")];
        yield 'another table' => [self::up("\$table = \$schema->createTable('notes');\n\$table->addColumn('id', 'integer');\n\$schema->getTable('events');")];
        yield 'data' => [self::up("\$this->addSql('DELETE FROM events WHERE privacy_mode = \\'legacy\\'');")];
        yield 'drop index' => [self::up("\$this->addSql('ALTER TABLE events DROP INDEX idx_old');")];
        yield 'down is never run' => ["<?php\nfinal class V {\n    public function up(): void {}\n    public function down(): void { \$this->addSql('ALTER TABLE events DROP COLUMN region'); }\n}\n"];
        yield 'online index' => [<<<'PHP'
            <?php
            final class V {
                public function isTransactional(): bool
                {
                    return false;
                }
                public function up(): void
                {
                    $this->addSql('CREATE INDEX CONCURRENTLY idx_events_region ON events (region)');
                    $this->addSql('CREATE INDEX idx_events_region ON events (region) ALGORITHM=INPLACE, LOCK=NONE');
                }
            }
            PHP];
    }

    private static function up(string $body): string
    {
        return "<?php\nfinal class V {\n    public function up(): void\n    {\n        ".$body."\n    }\n}\n";
    }
}
