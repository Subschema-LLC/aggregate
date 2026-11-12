<?php

declare(strict_types=1);

namespace App\Tests\Service\Cluster;

use App\Service\AggregateConfigLoader;
use App\Service\Cluster\ServerRoleRefusal;
use App\Service\ParsedYamlCache;
use App\Service\SiteScriptConfig;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Configuration is changed only where a role administers: a collection,
 * worker or balancer server refuses every writer, so neither a future page
 * nor a script can split a cluster's settings, and no file changes.
 */
final class ConfigurationWritersRoleTest extends TestCase
{
    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['SERVER_ROLE'], $_SERVER['SERVER_ROLE'], $_ENV['ADMINISTRATION_URL'], $_SERVER['ADMINISTRATION_URL']);
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'mysql://aggregate:secret@10.0.0.5:3306/aggregate?serverVersion=8.0.0';
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'prod';
        $this->directory = sys_get_temp_dir().'/aggregate-role-writers-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory.'/config');
        file_put_contents($this->directory.'/config/websites.yaml', Yaml::dump(['websites' => [['name' => 'Shop', 'domain' => 'shop.example', 'token' => 'shop-token']]], 4));
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    #[DataProvider('readOnlyRoles')]
    public function testServersThatDoNotAdministerRefuseEveryWriter(string $role): void
    {
        $this->settings(['server_role' => $role, 'administration_url' => 'https://admin.example.com', 'app_host' => 'https://collect.example.com']);
        $files = $this->snapshot();
        $writers = [
            'settings' => fn () => $this->config()->set('app_host', 'https://elsewhere.example.com'),
            'websites' => fn () => (new WebsiteConfigManager($this->directory))->addWebsite('Blog', 'blog.example'),
            'website groups' => fn () => $this->scopeFiles()->write($this->directory.'/config/website-scopes.yaml', "scopes: {}\n"),
            'website overrides' => fn () => $this->scopeFiles()->writeOverlay('websites', 'shop', ['bot_traffic' => ['mode' => 'exclude']]),
            'site scripts' => fn () => (new SiteScriptConfig(new WebsiteConfigManager($this->directory), $this->directory, 'prod', $this->config()))->saveConsent(SiteScriptConfig::idForToken('shop-token'), ['enabled' => true]),
        ];
        foreach ($writers as $what => $write) {
            try {
                $write();
                self::fail('A '.$role.' server changed its '.$what.'.');
            } catch (ServerRoleRefusal $e) {
                self::assertStringContainsString('This is a '.$role.' server: its configuration is changed on the administration server (https://admin.example.com)', $e->getMessage());
            }
        }
        self::assertSame($files, $this->snapshot(), 'No configuration file changed.');
    }

    public static function readOnlyRoles(): iterable
    {
        yield ['collection'];
        yield ['worker'];
        yield ['balancer'];
    }

    #[DataProvider('administeringRoles')]
    public function testServersThatAdministerWriteAsBefore(string $role): void
    {
        $this->settings(['server_role' => $role, 'app_host' => 'https://analytics.example.com']);

        $this->config()->set('documentation_url', 'https://docs.example.com/');
        self::assertTrue((new WebsiteConfigManager($this->directory))->addWebsite('Blog', 'blog.example'));
        self::assertSame('https://docs.example.com/', Yaml::parseFile($this->directory.'/config/aggregate.yaml')['documentation_url']);
    }

    public static function administeringRoles(): iterable
    {
        yield ['all'];
        yield ['administration'];
    }

    private function config(): AggregateConfigLoader
    {
        return new AggregateConfigLoader($this->directory, 'prod', parsedYaml: new ParsedYamlCache($this->directory.'/cache'));
    }

    private function scopeFiles(): WebsiteScopeFiles
    {
        return new WebsiteScopeFiles($this->directory, new ParsedYamlCache($this->directory.'/cache'));
    }

    private function settings(array $settings): void
    {
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump($settings, 4));
    }

    /** @return array<string, string> every file under config/, by path */
    private function snapshot(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory.'/config', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }
}
