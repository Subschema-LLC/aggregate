<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use DoctrineMigrations\Version20260724000000;
use DoctrineMigrations\Version20260724000250;
use DoctrineMigrations\Version20260724001500;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 2).'/migrations/Version20260724000000.php';
require_once dirname(__DIR__, 2).'/migrations/Version20260724000250.php';
require_once dirname(__DIR__, 2).'/migrations/Version20260724001500.php';

final class PrivacyModeSchemaMigrationTest extends TestCase
{
    public function testPrivacyMigrationsRemoveDailyHashAndMakeAnonymousTheInsertDefault(): void
    {
        $schema = $this->legacySchema();
        $connection = $this->createStub(Connection::class);
        $logger = $this->createStub(LoggerInterface::class);

        (new Version20260724000000($connection, $logger))->up($schema);

        $events = $schema->getTable('events');
        self::assertFalse($events->hasColumn('daily_ip_hash'));
        self::assertTrue($events->hasColumn('visitor_id'));
        self::assertSame('enhanced', $events->getColumn('privacy_mode')->getDefault());
        self::assertFalse($events->getColumn('generalized_user_agent')->getNotnull());
        self::assertFalse($events->getColumn('consent_state')->getNotnull());
        self::assertTrue($events->hasIndex('IDX_EVENTS_VISITOR_ID'));
        self::assertTrue($events->hasIndex('IDX_EVENTS_CONSENT_STATE'));
        self::assertTrue($events->hasIndex('IDX_EVENTS_PRIVACY_SITE_CREATED'));
        self::assertTrue($schema->hasTable('analytics_privacy_settings'));
        self::assertSame(
            5,
            $schema->getTable('analytics_privacy_settings')
                ->getColumn('anonymous_min_cell_count')
                ->getDefault(),
        );

        (new Version20260724000250($connection, $logger))->up($schema);

        self::assertSame('anonymous', $events->getColumn('privacy_mode')->getDefault());

        (new Version20260724001500($connection, $logger))->up($schema);

        self::assertTrue($events->hasColumn('geo_area'));
        self::assertSame(16, $events->getColumn('geo_area')->getLength());
        self::assertFalse($events->getColumn('geo_area')->getNotnull());
        self::assertSame(
            25,
            $schema->getTable('analytics_privacy_settings')
                ->getColumn('anonymous_geo_min_cell_count')
                ->getDefault(),
        );
    }

    private function legacySchema(): Schema
    {
        // Built with the schema editors: DBAL 4.5 deprecates the table and column mutators.
        $string = static fn (string $name, int $length): Column => Column::editor()
            ->setUnquotedName($name)->setTypeName('string')->setLength($length)->create();
        $events = Table::editor()
            ->setUnquotedName('events')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName('integer')->setAutoincrement(true)->create(),
                $string('website_token', 191),
                $string('daily_ip_hash', 191),
                $string('generalized_user_agent', 191),
                Column::editor()->setUnquotedName('consent_state')->setTypeName('string')->setLength(20)->setDefaultValue('unknown')->create(),
                Column::editor()->setUnquotedName('created_at')->setTypeName('datetime_immutable')->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create();

        return Schema::editor()->setTables($events)->create();
    }
}
