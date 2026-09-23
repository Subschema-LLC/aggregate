<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Entity\User;
use App\Kernel;
use App\Service\AggregateConfigLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class BrandingThemeRoutesTest extends TestCase
{
    private string $directory;
    private array $environment;
    private ?BrandingThemeRoutesKernel $kernel = null;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (array_unique([...array_keys($_ENV), ...array_keys($_SERVER)]) as $key) {
            if (str_starts_with($key, 'BRAND_')) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
        $this->directory = sys_get_temp_dir().'/aggregate-branding-theme-routes-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->directory.'/config');
        $this->writeConfig(['brand_primary_color' => '#abc', 'brand_font_family' => 'Open Sans, serif']);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    #[DataProvider('dashboardModes')]
    public function testPublicStylesheetRevalidatesChangedBrandingWithoutDisclosingConfiguration(bool $dashboard): void
    {
        $browser = $this->browser($dashboard);
        $browser->request('GET', '/branding/theme.css');
        $response = $browser->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/css; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
        self::assertEquals(0, $response->headers->getCacheControlDirective('max-age'));
        self::assertFalse($response->headers->has('Set-Cookie'));
        $css = (string) $response->getContent();
        self::assertStringContainsString('--app-brand-primary: #AABBCC;', $css);
        self::assertStringContainsString('--app-brand-font-family: "Open Sans", serif;', $css);
        self::assertStringNotContainsString('private-theme-test-secret', $css);
        self::assertStringNotContainsString('private/logo/path.png', $css);
        self::assertSame('"'.hash('sha256', $css).'"', $response->getEtag());
        $etag = $response->getEtag();

        $browser->request('GET', '/branding/theme.css', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertSame(304, $browser->getResponse()->getStatusCode());
        self::assertSame('', $browser->getResponse()->getContent());

        $this->writeConfig(['brand_primary_color' => '#123', 'brand_font_family' => 'Georgia, serif']);
        $browser->request('GET', '/branding/theme.css', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertNotSame($etag, $browser->getResponse()->getEtag());
        self::assertStringContainsString('--app-brand-primary: #112233;', (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('--app-brand-font-family: "Georgia", serif;', (string) $browser->getResponse()->getContent());
        $this->assertNoDatabaseConnection();
    }

    #[DataProvider('dashboardModes')]
    public function testStylesheetKeepsEnvironmentPrecedenceAndRejectsCssInjection(bool $dashboard): void
    {
        $this->writeConfig([
            'brand_primary_color' => '#abc',
            'brand_accent_color' => '#fff;}body{background:url(https://invalid.example/track)}',
            'brand_font_family' => 'Arial; background: url(https://invalid.example/track)',
            'brand_heading_font_family' => '</style><script>alert(1)</script>',
            'brand_background_color' => '#FFFFFF',
            'brand_surface_color' => '#EEEEEE',
            'brand_text_color' => '#F0F0F0',
        ]);
        $_ENV['BRAND_PRIMARY_COLOR'] = '#123';
        $browser = $this->browser($dashboard);
        $browser->request('GET', '/branding/theme.css');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $css = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('--app-brand-primary: #112233;', $css);
        self::assertStringContainsString('--app-brand-accent: #485FC7;', $css);
        self::assertStringContainsString('--app-brand-font-family: system-ui,', $css);
        self::assertStringContainsString('--app-brand-heading-font-family: system-ui,', $css);
        self::assertStringContainsString('--app-brand-background: #F5F5F5;', $css);
        self::assertStringContainsString('--app-brand-text: #363636;', $css);
        self::assertStringNotContainsString('url(', $css);
        self::assertStringNotContainsString('<script', $css);
        self::assertStringNotContainsString('private-theme-test-secret', $css);
        $this->assertNoDatabaseConnection();
    }

    #[DataProvider('dashboardModes')]
    public function testAnExistingLoginSessionDoesNotMakeTheStylesheetPrivateOrLoadTheUser(bool $dashboard): void
    {
        $browser = $this->browser($dashboard);
        $user = (new User())->setUsername('theme-test-admin')->setRoles(['ROLE_ADMIN'])->setPassword('test-only');
        (new \ReflectionProperty($user, 'id'))->setValue($user, 71);
        $browser->loginUser($user);
        $browser->request('GET', '/branding/theme.css');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'));
        self::assertFalse($browser->getResponse()->headers->has('Set-Cookie'));
        $this->assertNoDatabaseConnection();
    }

    public static function dashboardModes(): iterable
    {
        yield 'dashboard enabled' => [true];
        yield 'headless' => [false];
    }

    private function writeConfig(array $branding): void
    {
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump($branding + [
            'installed' => true,
            'admin_token' => 'private-theme-test-secret',
            'brand_logo_path' => 'private/logo/path.png',
        ]));
    }

    private function browser(bool $dashboard): KernelBrowser
    {
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = $dashboard ? '1' : '0';
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->kernel = new BrandingThemeRoutesKernel($this->directory);
        $this->kernel->boot();
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();

        return $browser;
    }

    private function assertNoDatabaseConnection(): void
    {
        self::assertFalse($this->kernel->getContainer()->get('test.service_container')->get('doctrine.dbal.default_connection')->isConnected());
    }
}

final class BrandingThemeRoutesKernel extends Kernel implements CompilerPassInterface
{
    public function __construct(private readonly string $directory) { parent::__construct('test', true); }
    public function getCacheDir(): string { return $this->directory.'/cache'; }
    public function getLogDir(): string { return $this->directory.'/log'; }
    protected function getContainerClass(): string { return parent::getContainerClass().'_'.md5($this->directory); }
    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition(AggregateConfigLoader::class)->setArgument('$projectDir', $this->directory);
    }
}
