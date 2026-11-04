<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\User;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use App\Tests\Support\TwigComponents;
use App\Twig\WebsiteSelectorExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

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

    public function testCardControlsRendersGlobalWebsiteSelector(): void
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

        $extension = new WebsiteSelectorExtension(
            $security,
            new WebsiteConfigManager($this->projectDir),
            new WebsiteScopeCatalog($this->projectDir, 'test'),
        );

        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['test_card_controls.html.twig' => '{{ component("Ui:CardControls") }}']),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['strict_variables' => true]);
        $twig->addExtension($extension);
        TwigComponents::register($twig);

        $html = $twig->render('test_card_controls.html.twig');

        self::assertStringContainsString('class="global-website-select"', $html);
        self::assertStringContainsString('<option value="all">All websites</option>', $html);
        self::assertStringContainsString('<optgroup label="Brands">', $html);
        self::assertStringContainsString('<option value="group:main-brand" data-group-id="main-brand">Main Brand</option>', $html);
        self::assertStringContainsString('Site A (a.com)</option>', $html);
        self::assertStringContainsString('Site B (b.com)</option>', $html);
    }

    /** The tag and consent manager pages choose their website themselves, including the shared configuration. */
    public function testCardControlsLeaveTheGlobalWebsiteSelectorOutWhenAPageTurnsItOff(): void
    {
        file_put_contents($this->projectDir . '/config/websites.yaml', Yaml::dump([
            'websites' => [
                ['name' => 'Site A', 'domain' => 'a.com', 'token' => 'token-a-12345678901234567890123456'],
            ],
        ]));
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(new User());
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['without_selector.html.twig' => '{{ component("Ui:CardControls", {website_selector: false}) }}']),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['strict_variables' => true]);
        $twig->addExtension(new WebsiteSelectorExtension($security, new WebsiteConfigManager($this->projectDir)));
        TwigComponents::register($twig);

        $html = $twig->render('without_selector.html.twig');

        self::assertStringNotContainsString('global-website-select', $html);
        self::assertStringNotContainsString('data-global-website-selector-wrapper', $html);
        self::assertStringContainsString('Expand all', $html, 'The card buttons stay.');
    }

    public function testTheTagAndConsentManagerPagesTurnTheGlobalWebsiteSelectorOff(): void
    {
        foreach (['tag_manager/index.html.twig', 'consent_manager/index.html.twig'] as $template) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $template);
            self::assertStringContainsString("component('Ui:CardControls', {class: 'page-card-controls mb-0', website_selector: false})", $source, $template);
        }
    }
}
