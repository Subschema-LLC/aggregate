<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\SiteScriptConfig;
use App\Service\TagManagerSettings;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SiteScriptConfigTest extends TestCase
{
    private string $projectDir;
    private string $firstId;
    private string $secondId;
    private SiteScriptConfig $sites;
    private TagManagerSettings $tags;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-site-scripts-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->register([
            ['name' => 'Storefront', 'domain' => 'shared.example', 'token' => 'storefront-public-token'],
            ['name' => 'Portal', 'domain' => 'shared.example', 'token' => 'portal-public-token'],
        ]);
        $this->firstId = SiteScriptConfig::idForToken('storefront-public-token');
        $this->secondId = SiteScriptConfig::idForToken('portal-public-token');
        $this->sites = new SiteScriptConfig(new WebsiteConfigManager($this->projectDir), $this->projectDir, 'test');
        $this->tags = new TagManagerSettings(new AggregateConfigLoader($this->projectDir, 'test'), $this->sites);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->projectDir);
    }

    public function testSameDomainSitesHaveIndependentDefaultsAndNeverInheritDeploymentTags(): void
    {
        $this->write('config/aggregate.yaml', ['tag_manager' => self::tagSettings('legacy'), 'mail_password' => 'private-deployment-value']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/D', $this->firstId);
        self::assertNotSame($this->firstId, $this->secondId);
        self::assertSame('shared.example', $this->sites->site($this->firstId)['domain']);
        self::assertSame('shared.example', $this->sites->site($this->secondId)['domain']);
        self::assertSame(TagManagerSettings::DEFAULTS, $this->tags->all($this->firstId));
        self::assertSame(TagManagerSettings::DEFAULTS, $this->tags->all($this->secondId));
        self::assertSame(['enabled' => true, 'name' => 'Storefront'], $this->sites->consent($this->firstId));
        self::assertSame(['enabled' => true, 'name' => 'Portal'], $this->sites->consent($this->secondId));
        self::assertDirectoryDoesNotExist($this->projectDir.'/config/tag-manager/sites');

        $deployment = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $registrations = file_get_contents($this->projectDir.'/config/websites.yaml');
        $this->sites->save($this->firstId, self::tagSettings('storefront'), ['enabled' => false, 'name' => 'Store privacy']);
        $this->sites->save($this->secondId, self::tagSettings('portal', 'marketing'), ['enabled' => true, 'name' => 'Portal privacy']);
        $neighbor = file_get_contents($this->sitePath($this->secondId));
        $this->tags->save(self::tagSettings('updated'), $this->firstId);

        self::assertSame(['updated'], array_column($this->tags->all($this->firstId)['tags'], 'id'));
        self::assertSame(['portal'], array_column($this->tags->all($this->secondId)['tags'], 'id'));
        self::assertSame(['analytics'], $this->tags->consentCategories($this->firstId));
        self::assertSame(['analytics', 'marketing'], $this->tags->consentCategories($this->secondId));
        self::assertSame(['enabled' => false, 'name' => 'Store privacy'], $this->sites->consent($this->firstId));
        self::assertSame($neighbor, file_get_contents($this->sitePath($this->secondId)));
        self::assertSame($deployment, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame($registrations, file_get_contents($this->projectDir.'/config/websites.yaml'));
    }

    public function testNestedEnvironmentSavePreservesBaseOtherEnvironmentsAndUnrelatedSettings(): void
    {
        $original = [
            'tag_manager' => self::tagSettings('base'),
            'consent_manager' => ['enabled' => true, 'name' => 'Base privacy'],
            'operator_note' => 'keep this root value',
            'environments' => [
                'test' => [
                    'tag_manager' => self::tagSettings('test'),
                    'consent_manager' => ['enabled' => false, 'name' => 'Test privacy'],
                    'operator_note' => 'keep this test value',
                ],
                'prod' => ['tag_manager' => self::tagSettings('production'), 'operator_note' => 'production only'],
            ],
        ];
        $this->writeSite($this->firstId, $original);
        self::assertSame('test', $this->tags->all($this->firstId)['tags'][0]['id']);
        self::assertSame(['enabled' => false, 'name' => 'Test privacy'], $this->sites->consent($this->firstId));

        $this->sites->save($this->firstId, self::tagSettings('replacement'), ['enabled' => true, 'name' => 'Replacement privacy']);
        $expected = $original;
        $expected['environments']['test']['tag_manager'] = self::tagSettings('replacement');
        $expected['environments']['test']['consent_manager'] = ['enabled' => true, 'name' => 'Replacement privacy'];
        self::assertSame($expected, Yaml::parseFile($this->sitePath($this->firstId)));
    }

    public function testEnvironmentSpecificFileTakesPrecedenceWithoutMergingMainSiteSettings(): void
    {
        $this->writeSite($this->firstId, [
            'tag_manager' => self::tagSettings('base'),
            'environments' => ['test' => ['tag_manager' => self::tagSettings('nested')]],
        ]);
        $this->writeSite($this->firstId, ['consent_manager' => ['enabled' => false, 'name' => 'Override privacy'], 'keep' => 'test file'], '_test');
        $this->writeSite($this->secondId, ['tag_manager' => self::tagSettings('neighbor')]);
        $main = file_get_contents($this->sitePath($this->firstId));
        $neighbor = file_get_contents($this->sitePath($this->secondId));
        self::assertSame(TagManagerSettings::DEFAULTS, $this->tags->all($this->firstId));
        self::assertSame(['enabled' => false, 'name' => 'Override privacy'], $this->sites->consent($this->firstId));

        $this->sites->save($this->firstId, self::tagSettings('override'), ['enabled' => true, 'name' => 'Saved override']);
        self::assertSame([
            'consent_manager' => ['enabled' => true, 'name' => 'Saved override'],
            'keep' => 'test file', 'tag_manager' => self::tagSettings('override'),
        ], Yaml::parseFile($this->sitePath($this->firstId, '_test')));
        self::assertSame($main, file_get_contents($this->sitePath($this->firstId)));
        self::assertSame($neighbor, file_get_contents($this->sitePath($this->secondId)));
    }

    public function testSavingOnlyConsentKeepsExistingTagsAndUnrelatedYaml(): void
    {
        $original = ['tag_manager' => self::tagSettings('existing'), 'operator_note' => ['keep' => true]];
        $this->writeSite($this->firstId, $original);
        $this->sites->saveConsent($this->firstId, ['enabled' => false, 'name' => '  Updated privacy  ']);
        $original['consent_manager'] = ['enabled' => false, 'name' => 'Updated privacy'];
        self::assertSame($original, Yaml::parseFile($this->sitePath($this->firstId)));
    }

    public function testEmptyAndCommentsOnlyFilesRemainValidDefaultConfigurations(): void
    {
        foreach (['', "# Configure this registered website here.\n\n"] as $contents) {
            $this->writeSite($this->firstId, $contents);
            self::assertSame(TagManagerSettings::DEFAULTS, $this->tags->all($this->firstId));
            self::assertSame(['enabled' => true, 'name' => 'Storefront'], $this->sites->consent($this->firstId));
        }
    }

    #[DataProvider('unsafeIds')]
    public function testUnsafeAndUnregisteredIdsCannotReadOrWriteConfiguration(string $id): void
    {
        $this->writeSite(str_repeat('a', 24), ['tag_manager' => self::tagSettings('unregistered')]);
        $before = $this->inventory();
        foreach ([
            fn () => $this->sites->site($id),
            fn () => $this->sites->configuration($id),
            fn () => $this->sites->consent($id),
            fn () => $this->sites->export($id),
            fn () => $this->sites->save($id, TagManagerSettings::DEFAULTS, []),
            fn () => $this->sites->saveConsent($id, []),
            fn () => $this->tags->all($id),
            fn () => $this->tags->save(TagManagerSettings::DEFAULTS, $id),
            fn () => $this->tags->toBrowserConfig($id),
        ] as $operation) {
            $this->assertRejected($operation);
        }
        self::assertSame($before, $this->inventory());
    }

    public static function unsafeIds(): iterable
    {
        foreach (['', '../aggregate', '../../websites', '/aggregate', 'site_test', str_repeat('a', 24), str_repeat('A', 24), str_repeat('g', 24), str_repeat('a', 25), str_repeat('a', 24)."\n", str_repeat('a', 24).'/../../aggregate', '%2e%2e%2faggregate'] as $id) {
            yield [$id];
        }
    }

    public function testUnknownIdCannotCreateTheSiteDirectory(): void
    {
        $this->assertRejected(fn () => $this->sites->save(str_repeat('b', 24), TagManagerSettings::DEFAULTS, []));
        self::assertDirectoryDoesNotExist($this->projectDir.'/config/tag-manager');
    }

    public function testDuplicateRegistrationTokensAreAmbiguousWithoutAffectingAnotherSite(): void
    {
        $this->register([
            ['name' => 'Storefront', 'domain' => 'shared.example', 'token' => 'storefront-public-token'],
            ['name' => 'Duplicate', 'domain' => 'other.example', 'token' => 'storefront-public-token'],
            ['name' => 'Portal', 'domain' => 'shared.example', 'token' => 'portal-public-token'],
        ]);
        $this->writeSite($this->firstId, ['tag_manager' => self::tagSettings('ambiguous')]);
        $before = $this->inventory();
        $this->assertRejected(fn () => $this->sites->configuration($this->firstId));
        $this->assertRejected(fn () => $this->sites->export($this->firstId));
        $this->assertRejected(fn () => $this->sites->save($this->firstId, TagManagerSettings::DEFAULTS, []));
        self::assertSame(['enabled' => true, 'name' => 'Portal'], $this->sites->consent($this->secondId));
        self::assertSame($before, $this->inventory());
    }

    #[DataProvider('invalidConsent')]
    public function testMalformedConsentFailsClosedForReadingExportAndSaving(mixed $consent): void
    {
        $this->writeSite($this->firstId, ['tag_manager' => self::tagSettings('existing'), 'consent_manager' => $consent]);
        $before = file_get_contents($this->sitePath($this->firstId));
        $this->assertRejected(fn () => $this->sites->consent($this->firstId));
        $this->assertRejected(fn () => $this->sites->export($this->firstId));
        $this->assertRejected(fn () => $this->sites->save($this->firstId, TagManagerSettings::DEFAULTS, []));
        $this->assertRejected(fn () => $this->sites->saveConsent($this->firstId, []));
        self::assertSame($before, file_get_contents($this->sitePath($this->firstId)));
        self::assertSame(['enabled' => true, 'name' => 'Portal'], $this->sites->consent($this->secondId));
    }

    public static function invalidConsent(): iterable
    {
        foreach ([null, false, 'enabled', ['unexpected' => true], ['enabled' => null], ['enabled' => 'false'], ['enabled' => 1], ['name' => null], ['name' => false], ['name' => ''], ['name' => '  '], ['name' => "Bad\nname"], ['name' => str_repeat('é', 61)]] as $value) {
            yield [$value];
        }
    }

    public function testInvalidSubmittedConsentCannotReplaceValidConfiguration(): void
    {
        $this->sites->save($this->firstId, self::tagSettings('existing'), ['enabled' => true, 'name' => 'Original']);
        $before = file_get_contents($this->sitePath($this->firstId));
        foreach ([['enabled' => null], ['enabled' => 'false'], ['name' => null], ['name' => "Invalid\xFFname"]] as $submitted) {
            $this->assertRejected(fn () => $this->sites->saveConsent($this->firstId, $submitted));
            self::assertSame($before, file_get_contents($this->sitePath($this->firstId)));
        }
    }

    public function testExplicitNullTagsCannotBeExportedOrReplacedBySavingConsent(): void
    {
        $this->writeSite($this->firstId, ['tag_manager' => null]);
        $before = file_get_contents($this->sitePath($this->firstId));
        $this->assertRejected(fn () => $this->tags->all($this->firstId));
        $this->assertRejected(fn () => $this->sites->export($this->firstId));
        $this->assertRejected(fn () => $this->sites->saveConsent($this->firstId, []));
        $this->assertRejected(fn () => $this->sites->save($this->firstId, TagManagerSettings::DEFAULTS, []));
        self::assertSame($before, file_get_contents($this->sitePath($this->firstId)));
    }

    public function testYamlExportContainsOnlyTheSelectedSitesActivePublicSettings(): void
    {
        $this->write('config/aggregate.yaml', ['mail_password' => 'private-deployment-value', 'tag_manager' => self::tagSettings('legacy')]);
        $this->writeSite($this->firstId, [
            'private_key' => 'private-site-value',
            'tag_manager' => self::tagSettings('base'),
            'environments' => [
                'test' => ['tag_manager' => self::tagSettings('active'), 'consent_manager' => ['enabled' => false, 'name' => 'Active privacy']],
                'prod' => ['private_key' => 'private-production-value', 'tag_manager' => self::tagSettings('production')],
            ],
        ]);
        $this->writeSite($this->secondId, ['tag_manager' => self::tagSettings('neighbor')]);
        $export = $this->sites->export($this->firstId);
        self::assertSame([
            'tag_manager' => self::tagSettings('active'),
            'consent_manager' => ['enabled' => false, 'name' => 'Active privacy'],
        ], Yaml::parse($export));
        foreach (['private-', 'storefront-public-token', 'portal-public-token', 'neighbor', 'legacy', 'production', 'environments'] as $excluded) {
            self::assertStringNotContainsString($excluded, $export);
        }
    }

    #[DataProvider('malformedDocuments')]
    public function testMalformedSiteDocumentDoesNotFallbackOrAffectNeighbor(string $document): void
    {
        $this->writeSite($this->firstId, $document);
        $this->writeSite($this->secondId, ['tag_manager' => self::tagSettings('neighbor')]);
        $before = $this->inventory();
        $this->assertRejected(fn () => $this->tags->all($this->firstId));
        $this->assertRejected(fn () => $this->sites->consent($this->firstId));
        $this->assertRejected(fn () => $this->sites->export($this->firstId));
        $this->assertRejected(fn () => $this->sites->save($this->firstId, TagManagerSettings::DEFAULTS, []));
        self::assertSame('neighbor', $this->tags->all($this->secondId)['tags'][0]['id']);
        self::assertSame($before, $this->inventory());
    }

    public static function malformedDocuments(): iterable
    {
        foreach ([
            'broken: [', 'null', '~', 'false', 'scalar', '- unexpected-list',
            "tag_manager: {enabled: true}\nenvironments: null\n",
            "tag_manager: {enabled: true}\nenvironments: {test: null}\n",
        ] as $document) {
            yield [$document];
        }
    }

    #[DataProvider('unsafeConfigurationNames')]
    public function testNamedLoaderRejectsUnsafeRelativePaths(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AggregateConfigLoader($this->projectDir, 'test', $name);
    }

    public static function unsafeConfigurationNames(): iterable
    {
        foreach (['', '../aggregate', '/aggregate', 'tag-manager/../aggregate', 'tag-manager//sites', 'tag-manager/sites/', 'tag-manager\\sites', 'aggregate.yaml', "aggregate\0"] as $name) {
            yield [$name];
        }
    }

    private function assertRejected(callable $operation): void
    {
        $rejected = false;
        try {
            $operation();
        } catch (\InvalidArgumentException|\RuntimeException) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'Invalid site input or configuration must fail closed.');
    }

    private function register(array $websites): void
    {
        $this->write('config/websites.yaml', ['websites' => $websites]);
    }

    private function writeSite(string $id, array|string $settings, string $suffix = ''): void
    {
        $this->write('config/tag-manager/sites/'.$id.$suffix.'.yaml', $settings);
    }

    private function write(string $relative, array|string $settings): void
    {
        $path = $this->projectDir.'/'.$relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, is_array($settings) ? Yaml::dump($settings, 12, 2) : $settings);
    }

    private function sitePath(string $id, string $suffix = ''): string
    {
        return $this->projectDir.'/config/tag-manager/sites/'.$id.$suffix.'.yaml';
    }

    private function inventory(): array
    {
        $result = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $result[substr($file->getPathname(), strlen($this->projectDir))] = hash_file('sha256', $file->getPathname());
        }
        ksort($result);

        return $result;
    }

    private static function tagSettings(string $id, string $consent = 'analytics'): array
    {
        return TagManagerSettings::validate(['enabled' => true, 'tags' => [
            ['id' => $id, 'src' => 'https://scripts.example/'.$id.'.js', 'consent' => $consent],
        ]]);
    }
}
