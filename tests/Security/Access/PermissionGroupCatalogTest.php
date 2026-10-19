<?php

declare(strict_types=1);

namespace App\Tests\Security\Access;

use App\Security\Access\PermissionGroupCatalog;
use App\Service\Operations\ConfigurationChanges;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class PermissionGroupCatalogTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-permission-groups-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testAMissingFileMeansNoGroups(): void
    {
        $catalog = $this->catalog();
        self::assertSame([], $catalog->groups());
        self::assertFalse($catalog->hasLoadError());
        self::assertFileDoesNotExist($catalog->path());
    }

    public function testGroupsAreCreatedChangedAndDeletedInYaml(): void
    {
        $catalog = $this->catalog();
        $id = $catalog->create('Client editors', 'Brand A tags', [
            'websites' => ['groups' => ['brand-a']],
            'pages' => ['tag_manager' => 'edit', 'websites' => 'view'],
        ]);
        self::assertSame('client-editors', $id);
        self::assertSame(['permission_groups' => ['client-editors' => [
            'name' => 'Client editors',
            'description' => 'Brand A tags',
            'websites' => ['groups' => ['brand-a']],
            'pages' => ['websites' => 'view', 'tag_manager' => 'edit'],
        ]]], Yaml::parseFile($catalog->path()));

        // The same name again is refused; a similar one gets its own id.
        try {
            $catalog->create('client EDITORS', '', []);
            self::fail('Two groups with one name must be refused.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('already named', $error->getMessage());
        }
        self::assertSame('client-editors-2', $catalog->create('Client editors!', '', ['websites' => ['all' => true]]));

        $catalog->update('client-editors', 'Editors', '', ['pages' => ['consent_manager' => 'edit']]);
        $fresh = $this->catalog();
        self::assertSame(['name' => 'Editors', 'description' => '', 'websites' => ['all' => false, 'groups' => [], 'sites' => []], 'pages' => ['consent_manager' => 'edit']], $fresh->group('client-editors'));
        self::assertTrue($fresh->group('client-editors-2')['websites']['all']);

        $fresh->delete('client-editors');
        self::assertSame(['client-editors-2'], array_keys($this->catalog()->groups()));
        $this->expectException(\InvalidArgumentException::class);
        $fresh->delete('client-editors');
    }

    public function testSavingRecordsTheChangedGroupsForTheAuditTrail(): void
    {
        ConfigurationChanges::start();
        try {
            $this->catalog()->create('Analysts', '', ['pages' => ['data_model' => 'view']]);
        } finally {
            $changes = ConfigurationChanges::finish();
        }
        self::assertSame(['config/permission-groups.yaml' => ['permission_groups']], $changes);
    }

    public function testAnInvalidFileGrantsNothingAndIsNotSavedOver(): void
    {
        foreach ([
            "permission_groups:\n  editors:\n    name: Editors\n    pages: { users: edit }\n" => 'Unknown page "users"',
            "permission_groups:\n  editors:\n    name: Editors\n    roles: [admin]\n" => 'unknown setting "roles"',
            "permission_groups:\n  Editors:\n    name: Editors\n" => 'lowercase letters',
            "permission_groups:\n  a: { name: Same }\n  b: { name: same }\n" => 'Two permission groups are named',
            "groups: {}\n" => 'may only contain permission_groups',
            "permission_groups: [\n" => 'could not be read as YAML',
        ] as $yaml => $message) {
            file_put_contents($this->projectDir.'/config/permission-groups.yaml', $yaml);
            $catalog = $this->catalog();
            self::assertTrue($catalog->hasLoadError(), $yaml);
            self::assertStringContainsString($message, (string) $catalog->loadError());
            self::assertSame([], $catalog->groups(), 'an invalid file grants nothing');
            try {
                $catalog->create('New', '', []);
                self::fail('Saving over an invalid file must be refused.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Correct it before saving', $error->getMessage());
            }
            self::assertSame($yaml, file_get_contents($this->projectDir.'/config/permission-groups.yaml'));
        }
    }

    public function testHandWrittenGroupsAreRead(): void
    {
        file_put_contents($this->projectDir.'/config/permission-groups.yaml', <<<'YAML'
            # Edited by hand.
            permission_groups:
              2024:
                name: Year group
              analysts:
                name: Analysts
                description: Read models
                websites: { all: true }
                pages: { reporting_views: view, data_model: view }
            YAML);
        $groups = $this->catalog()->groups();
        self::assertSame(['2024', 'analysts'], array_map('strval', array_keys($groups)));
        self::assertSame(['data_model' => 'view', 'reporting_views' => 'view'], $groups['analysts']['pages']);
        self::assertTrue($groups['analysts']['websites']['all']);
    }

    private function catalog(): PermissionGroupCatalog
    {
        return new PermissionGroupCatalog($this->projectDir, 'test');
    }
}
