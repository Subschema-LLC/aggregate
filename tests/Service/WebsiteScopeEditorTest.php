<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use App\Service\WebsiteScopeEditor;
use App\Service\WebsiteScopeResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The scope editor finds a website by its script id the way ingestion and the
 * script settings do: websites that share a token select nothing.
 */
final class WebsiteScopeEditorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-scope-editor-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/config', 0700, true);
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump(['anonymous_tracking_enabled' => true]));
        file_put_contents($this->directory.'/config/websites.yaml', Yaml::dump(['websites' => [
            ['name' => 'Store', 'domain' => 'store.example', 'token' => 'store-token'],
            ['name' => 'Blog', 'domain' => 'blog.example', 'token' => 'shared-token'],
            ['name' => 'Docs', 'domain' => 'docs.example', 'token' => 'shared-token'],
        ]]));
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testAWebsiteWithItsOwnTokenIsChosenNamedAndSaved(): void
    {
        $editor = $this->editor();
        $id = WebsiteConfigManager::idForToken('store-token');

        $selection = $editor->selection('website:'.$id, 'app_settings');

        self::assertSame('website', $selection['kind']);
        self::assertSame('Store', $selection['label']);
        self::assertNull($selection['error']);
        self::assertSame('store-token', $editor->tokenForSiteId($id));

        $editor->saveCollection('website:'.$id, ['anonymous_tracking_enabled' => false, 'anonymous_excluded_paths' => []], ['tracking']);
        self::assertFalse((new WebsiteScopeCatalog($this->directory, 'test'))->readOverlay('websites', $id)['values']['anonymous_tracking_enabled']);
    }

    public function testWebsitesThatShareATokenCannotBeChosenOrSaved(): void
    {
        $editor = $this->editor();
        $id = WebsiteConfigManager::idForToken('shared-token');

        self::assertNull($editor->tokenForSiteId($id));

        $selection = $editor->selection('website:'.$id, 'app_settings');
        self::assertSame('deployment', $selection['kind']);
        self::assertSame('Every website', $selection['label']);
        self::assertNotNull($selection['error']);

        $values = array_column($selection['choices'], 'value');
        self::assertContains('website:'.WebsiteConfigManager::idForToken('store-token'), $values);
        self::assertNotContains('website:'.$id, $values, 'neither website that shares the token is offered');

        try {
            $editor->saveCollection('website:'.$id, ['anonymous_tracking_enabled' => false, 'anonymous_excluded_paths' => []], ['tracking']);
            self::fail('A shared token must not get a setting file.');
        } catch (\InvalidArgumentException) {
        }
        self::assertFileDoesNotExist($this->directory.'/config/'.WebsiteScopeCatalog::DIRECTORY.'/websites/'.$id.'.yaml');
    }

    private function editor(): WebsiteScopeEditor
    {
        $loader = new AggregateConfigLoader($this->directory, 'test');

        return new WebsiteScopeEditor(
            new WebsiteScopeCatalog($this->directory, 'test'),
            new WebsiteScopeResolver($loader, new WebsiteScopeCatalog($this->directory, 'test')),
            new WebsiteConfigManager($this->directory),
            $loader,
        );
    }
}
