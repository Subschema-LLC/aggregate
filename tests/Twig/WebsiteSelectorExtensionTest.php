<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\User;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use App\Twig\WebsiteSelectorExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Yaml\Yaml;

final class WebsiteSelectorExtensionTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/aggregate-website-selector-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir . '/config', 0700, true));
    }

    protected function tearDown(): void
    {
        if (is_file($this->projectDir . '/config/websites.yaml')) {
            unlink($this->projectDir . '/config/websites.yaml');
        }
        if (is_file($this->projectDir . '/config/website-scopes.yaml')) {
            unlink($this->projectDir . '/config/website-scopes.yaml');
        }
        rmdir($this->projectDir . '/config');
        rmdir($this->projectDir);
    }

    public function testReturnsNullWhenUserNotSignedIn(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $extension = new WebsiteSelectorExtension($security, new WebsiteConfigManager($this->projectDir));
        self::assertNull($extension->options());
    }

    public function testReturnsWebsitesAndGroupsWhenUserSignedIn(): void
    {
        file_put_contents($this->projectDir . '/config/websites.yaml', Yaml::dump([
            'websites' => [
                ['name' => 'Site A', 'domain' => 'a.com', 'token' => 'token-a-12345678901234567890123456'],
                ['name' => 'Site B', 'domain' => 'b.com', 'token' => 'token-b-12345678901234567890123456'],
            ],
        ]));

        file_put_contents($this->projectDir . '/config/website-scopes.yaml', Yaml::dump([
            'label' => ['singular' => 'Brand', 'plural' => 'Brands'],
            'groups' => [
                'main-brand' => [
                    'name' => 'Main Brand',
                    'members' => ['token-a-12345678901234567890123456'],
                ],
            ],
        ]));

        $user = new User();
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $websiteManager = new WebsiteConfigManager($this->projectDir);
        $scopes = new WebsiteScopeCatalog($this->projectDir, 'test');
        $extension = new WebsiteSelectorExtension($security, $websiteManager, $scopes);

        $options = $extension->options();
        self::assertNotNull($options);
        self::assertSame('Brands', $options['group_label']);
        self::assertCount(1, $options['groups']);
        self::assertSame('main-brand', $options['groups'][0]['id']);
        self::assertSame('group:main-brand', $options['groups'][0]['value']);
        self::assertCount(2, $options['websites']);
        self::assertSame('Site A', $options['websites'][0]['name']);
        self::assertSame('main-brand', $options['websites'][0]['group_id']);
        self::assertSame('Main Brand', $options['websites'][0]['group_name']);
        self::assertNull($options['websites'][1]['group_id']);
    }
}
