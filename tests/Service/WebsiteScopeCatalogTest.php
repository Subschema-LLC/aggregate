<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Operations\ConfigurationChanges;
use App\Service\SiteScriptConfig;
use App\Service\WebsiteScopeCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Group changes write config/website-scopes.yaml, and removing a group or a
 * website removes its setting file. Each check reads the files back with a
 * new catalog, as the next request would.
 */
final class WebsiteScopeCatalogTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-scope-catalog-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testGroupChangesAreSavedAndReadBack(): void
    {
        $catalog = $this->catalog();
        $id = $catalog->createGroup('EU shops');
        self::assertSame('eu-shops', $id);
        self::assertSame('eu-shops-2', $catalog->createGroup('EU shops'), 'a second group with the same name gets its own id');
        $catalog->renameGroup($id, 'European shops');
        $catalog->assign('token-a', $id);
        $catalog->assign('token-b', $id);
        $catalog->assign('token-b', 'eu-shops-2');
        $catalog->setLabel('Region', 'Regions');

        $read = $this->catalog();
        self::assertSame(['name' => 'European shops', 'members' => ['token-a']], $read->groups()[$id]);
        self::assertSame('eu-shops-2', $read->groupIdForToken('token-b'), 'a website belongs to one group at a time');
        self::assertSame(['singular' => 'Region', 'plural' => 'Regions'], $read->label());
        self::assertSame(['label' => ['singular' => 'Region', 'plural' => 'Regions'], 'groups' => [
            'eu-shops' => ['name' => 'European shops', 'members' => ['token-a']],
            'eu-shops-2' => ['name' => 'EU shops', 'members' => ['token-b']],
        ]], Yaml::parseFile($this->projectDir.'/config/website-scopes.yaml'));
    }

    public function testAShorterFileReplacesALongerOneWhileAnActionIsRecorded(): void
    {
        $catalog = $this->catalog();
        $id = $catalog->createGroup('A group with a rather long name for this test');
        $catalog->assign('token-a', $id);

        ConfigurationChanges::start();
        $catalog->renameGroup($id, 'Short');
        $catalog->assign('token-a', null);
        $changes = ConfigurationChanges::finish();

        $contents = (string) file_get_contents($this->projectDir.'/config/website-scopes.yaml');
        self::assertStringNotContainsString("\0", $contents);
        self::assertSame(['name' => 'Short'], Yaml::parse($contents)['groups'][$id]);
        // Group ids are named, website tokens never.
        self::assertSame(['config/website-scopes.yaml' => ['groups.'.$id]], $changes);
    }

    public function testDeletingAGroupOrForgettingAWebsiteRemovesItsSettingFile(): void
    {
        $catalog = $this->catalog();
        $id = $catalog->createGroup('Shops');
        $catalog->assign('token-a', $id);
        $catalog->saveOverlay('groups', $id, ['collection_profile' => 'strict'], []);
        $website = SiteScriptConfig::idForToken('token-a');
        $catalog->saveOverlay('websites', $website, ['collection_profile' => 'strict'], []);
        $groupFile = $this->projectDir.'/config/website-scopes/groups/'.$id.'.yaml';
        $websiteFile = $this->projectDir.'/config/website-scopes/websites/'.$website.'.yaml';
        self::assertFileExists($groupFile);
        self::assertFileExists($websiteFile);

        $documents = $this->catalog()->overlayDocuments();
        self::assertSame([['group', $id, 'Shops', true], ['website', $website, $website, true]], array_map(
            static fn (array $document): array => [$document['kind'], $document['id'], $document['label'], $document['ok']],
            $documents,
        ));
        self::assertSame(['collection_profile' => 'strict'], $documents[1]['values']);

        $catalog = $this->catalog();
        $catalog->forgetWebsite('token-a');
        self::assertFileDoesNotExist($websiteFile);
        self::assertSame([], $this->catalog()->groups()[$id]['members']);

        $catalog->deleteGroup($id);
        self::assertFileDoesNotExist($groupFile);
        self::assertSame([], $this->catalog()->groups());
    }

    public function testSettingFilesAreReadOnlyByAValidIdAndWithinTheSizeLimit(): void
    {
        $catalog = $this->catalog();
        self::assertSame(['ok' => true, 'values' => []], $catalog->readOverlay('websites', str_repeat('ab', 12)), 'no file means no settings');
        self::assertFalse($catalog->readOverlay('websites', '../aggregate')['ok']);
        self::assertFalse($catalog->readOverlay('settings', 'shops')['ok']);
        try {
            $catalog->saveOverlay('groups', '../shops', ['collection_profile' => 'strict'], []);
            self::fail('A setting file outside config/website-scopes must be refused.');
        } catch (\InvalidArgumentException) {
        }

        mkdir($this->projectDir.'/config/website-scopes/groups', 0700, true);
        file_put_contents($this->projectDir.'/config/website-scopes/groups/shops.yaml', 'collection_profile: '.str_repeat('x', 262144));
        self::assertFalse($catalog->readOverlay('groups', 'shops')['ok'], 'an oversized file is not parsed');

        // Saving removes the file once no settings are left.
        file_put_contents($this->projectDir.'/config/website-scopes/groups/shops.yaml', "collection_profile: strict\n");
        $catalog->saveOverlay('groups', 'shops', [], ['collection_profile']);
        self::assertFileDoesNotExist($this->projectDir.'/config/website-scopes/groups/shops.yaml');
    }

    private function catalog(): WebsiteScopeCatalog
    {
        return new WebsiteScopeCatalog($this->projectDir, 'test');
    }
}
