<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DashboardController;
use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\BrandingLogoManager;
use App\Service\TrackingFailureRetryRunner;
use App\Service\TrackingFailureSettings;
use App\Service\WebsiteConfigManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class DashboardControllerBrandingTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/dashboard-branding-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testAdminCanSaveBrandingAndUploadALogo(): void
    {
        $source = $this->projectDir.'/logo.bin';
        file_put_contents($source, $this->png());
        $upload = new UploadedFile($source, 'brand.php', 'application/x-php', UPLOAD_ERR_OK, true);
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => 'Example',
        ], ['brand_logo' => $upload]);
        $config = $this->config();
        $config->expects(self::once())
            ->method('get')
            ->with('brand_logo_path', '')
            ->willReturn('');
        $config->expects(self::once())
            ->method('setMany')
            ->with(self::callback(function (array $settings): bool {
                self::assertSame('Example Analytics', $settings['brand_name'] ?? null);
                self::assertSame('Example', $settings['brand_logo_text'] ?? null);
                self::assertMatchesRegularExpression(
                    '#^var/branding/test/logo-[a-f0-9]{32}\.png$#D',
                    $settings['brand_logo_path'] ?? '',
                );
                self::assertFileExists($this->projectDir.'/'.$settings['brand_logo_path']);

                return true;
            }));
        $controller = $this->controller($config, $request);

        $response = $controller->saveBrandingSettings($request);

        self::assertSame('/dashboard/branding', $response->getTargetUrl());
        self::assertSame(['Branding updated successfully.'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testTextOnlySaveDoesNotOverwriteAConcurrentlyChangedLogoPath(): void
    {
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Renamed Analytics',
            'brand_logo_text' => 'Renamed',
        ]);
        $config = $this->config();
        $config->expects(self::never())->method('get');
        $config->expects(self::once())
            ->method('setMany')
            ->with([
                'brand_name' => 'Renamed Analytics',
                'brand_logo_text' => 'Renamed',
            ]);
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertSame(['Branding updated successfully.'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testIdentitySaveDoesNotOverwriteUnchangedThemeFormValues(): void
    {
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Renamed Analytics',
            '_original_brand_name' => 'Old Analytics',
            'brand_logo_text' => 'Old',
            '_original_brand_logo_text' => 'Old',
            'brand_primary_color' => '#00d1b2',
            '_original_brand_primary_color' => '#00D1B2',
            'brand_accent_color' => '#485FC7',
            '_original_brand_accent_color' => '#485fc7',
            'brand_navbar_color' => '#14161A',
            '_original_brand_navbar_color' => '#14161A',
            'brand_background_color' => '#F5F5F5',
            '_original_brand_background_color' => '#f5f5f5',
            'brand_surface_color' => '#FFFFFF',
            '_original_brand_surface_color' => '#fff',
            'brand_text_color' => '#363636',
            '_original_brand_text_color' => '#363636',
            'brand_font_family' => 'system-ui, Arial, sans-serif',
            '_original_brand_font_family' => 'system-ui, Arial, sans-serif',
            'brand_heading_font_family' => 'Georgia, serif',
            '_original_brand_heading_font_family' => '"Georgia", serif',
        ]);
        $config = $this->config();
        $config->expects(self::once())
            ->method('setMany')
            ->with(['brand_name' => 'Renamed Analytics']);
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertSame(['Branding updated successfully.'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testEnvironmentControlledDarkSurfacesCanBeCorrectedWithWhiteText(): void
    {
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => 'Example',
            'brand_text_color' => '#fff',
        ]);
        $config = $this->config();
        $config->method('hasEnvironmentOverride')->willReturnCallback(
            static fn (string $key): bool => in_array($key, [
                'brand_background_color',
                'brand_surface_color',
            ], true),
        );
        $config->method('getWithEnvFallback')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => match ($key) {
                'brand_background_color' => '#111827',
                'brand_surface_color' => '#1F2937',
                'brand_text_color' => '#777777',
                default => $default,
            },
        );
        $config->expects(self::once())
            ->method('setMany')
            ->with([
                'brand_text_color' => '#FFFFFF',
                'brand_name' => 'Example Analytics',
                'brand_logo_text' => 'Example',
            ]);
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertSame(['Branding updated successfully.'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testAdminCanSaveNormalizedThemeColorsAndFontsWithoutRewritingIdentity(): void
    {
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Example Analytics',
            '_original_brand_name' => 'Example Analytics',
            'brand_logo_text' => 'Example',
            '_original_brand_logo_text' => 'Example',
            'brand_primary_color' => '#0ab',
            'brand_accent_color' => '#123456',
            'brand_background_color' => '#111827',
            'brand_surface_color' => '#1F2937',
            'brand_text_color' => '#F9FAFB',
            'brand_font_family' => '"Open   Sans", serif',
            'brand_heading_font_family' => 'Georgia, serif',
        ]);
        $config = $this->config();
        $config->expects(self::once())
            ->method('setMany')
            ->with([
                'brand_primary_color' => '#00AABB',
                'brand_accent_color' => '#123456',
                'brand_background_color' => '#111827',
                'brand_surface_color' => '#1F2937',
                'brand_text_color' => '#F9FAFB',
                'brand_font_family' => 'Open Sans, serif',
                'brand_heading_font_family' => 'Georgia, serif',
            ]);
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertSame(['Branding updated successfully.'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testLowContrastThemeNeverChangesConfiguration(): void
    {
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => 'Example',
            'brand_background_color' => '#FFFFFF',
            'brand_surface_color' => '#EEEEEE',
            'brand_text_color' => '#F0F0F0',
        ]);
        $config = $this->config();
        $config->expects(self::never())->method('setMany');
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertStringContainsString(
            'at least 4.5:1 contrast',
            $request->getSession()->getFlashBag()->peek('error')[0] ?? '',
        );
    }

    public function testInvalidThemeColorNeverChangesConfiguration(): void
    {
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => 'Example',
            'brand_primary_color' => 'red; background: black',
        ]);
        $config = $this->config();
        $config->expects(self::never())->method('setMany');
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertStringContainsString(
            '#RGB or #RRGGBB',
            $request->getSession()->getFlashBag()->peek('error')[0] ?? '',
        );
    }

    public function testUnsafeThemeFontNeverChangesConfiguration(): void
    {
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => 'Example',
            'brand_font_family' => 'Arial; background: url(https://example.test)',
        ]);
        $config = $this->config();
        $config->expects(self::never())->method('setMany');
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertStringContainsString(
            'comma-separated local/system font family names',
            $request->getSession()->getFlashBag()->peek('error')[0] ?? '',
        );
    }

    public function testEnvironmentControlledThemeFieldIsIgnored(): void
    {
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => 'Example',
            'brand_primary_color' => '#ABCDEF',
        ]);
        $config = $this->config();
        $config->method('hasEnvironmentOverride')->willReturnCallback(
            static fn (string $key): bool => $key === 'brand_primary_color',
        );
        $config->expects(self::once())
            ->method('setMany')
            ->with([
                'brand_name' => 'Example Analytics',
                'brand_logo_text' => 'Example',
            ]);
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertSame(['Branding updated successfully.'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testEnvironmentControlledTextIsIgnoredWhileYamlBackedTextIsSaved(): void
    {
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_logo_text' => 'YAML wordmark',
        ]);
        $config = $this->config();
        $config->method('hasEnvironmentOverride')->willReturnCallback(
            static fn (string $key): bool => $key === 'brand_name',
        );
        $config->expects(self::once())
            ->method('setMany')
            ->with(['brand_logo_text' => 'YAML wordmark']);
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertSame(['Branding updated successfully.'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testFullyEnvironmentControlledTextProducesANoChangeWarning(): void
    {
        $request = $this->request(['_csrf_token' => 'valid-token']);
        $config = $this->config();
        $config->method('hasEnvironmentOverride')->willReturn(true);
        $config->expects(self::never())->method('setMany');
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertSame(
            ['No branding changes were submitted; environment-controlled values were left unchanged.'],
            $request->getSession()->getFlashBag()->peek('warning'),
        );
    }

    public function testEnvironmentControlledLogoPathRejectsUploadWithoutStoringAFile(): void
    {
        $source = $this->projectDir.'/logo.png';
        file_put_contents($source, $this->png());
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => 'Example',
        ], [
            'brand_logo' => new UploadedFile($source, 'logo.png', 'image/png', UPLOAD_ERR_OK, true),
        ]);
        $config = $this->config();
        $config->method('hasEnvironmentOverride')->willReturn(true);
        $config->expects(self::never())->method('get');
        $config->expects(self::never())->method('setMany');
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertFileExists($source);
        self::assertStringContainsString(
            'BRAND_LOGO_PATH',
            $request->getSession()->getFlashBag()->peek('error')[0] ?? '',
        );
    }

    public function testInvalidImageNeverChangesConfiguration(): void
    {
        $source = $this->projectDir.'/logo.svg';
        file_put_contents($source, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => 'Example',
        ], [
            'brand_logo' => new UploadedFile($source, 'logo.svg', 'image/svg+xml', UPLOAD_ERR_OK, true),
        ]);
        $config = $this->config();
        $config->expects(self::once())->method('get')->willReturn('');
        $config->expects(self::never())->method('setMany');
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertStringContainsString(
            'PNG, JPEG, or WebP',
            $request->getSession()->getFlashBag()->peek('error')[0] ?? '',
        );
    }

    public function testAStoredUploadIsRemovedWhenTheConfigurationCommitFails(): void
    {
        $source = $this->projectDir.'/logo.png';
        file_put_contents($source, $this->png());
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => 'Example',
        ], [
            'brand_logo' => new UploadedFile($source, 'logo.png', 'image/png', UPLOAD_ERR_OK, true),
        ]);
        $config = $this->config();
        $config->expects(self::once())->method('get')->willReturn('');
        $config->expects(self::once())
            ->method('setMany')
            ->willThrowException(new \RuntimeException('read-only configuration'));
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertSame(
            [],
            glob($this->projectDir.'/var/branding/test/logo-*') ?: [],
        );
        self::assertSame(
            ['Branding settings could not be saved. Check the application logs.'],
            $request->getSession()->getFlashBag()->peek('error'),
        );
    }

    public function testRemovingAUiManagedLogoCommitsBeforeDeletingIt(): void
    {
        $oldPath = 'var/branding/test/logo-0123456789abcdef0123456789abcdef.png';
        self::assertTrue(mkdir(dirname($this->projectDir.'/'.$oldPath), 0700, true));
        file_put_contents($this->projectDir.'/'.$oldPath, $this->png());
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => '',
            'remove_brand_logo' => '1',
        ]);
        $config = $this->config();
        $config->expects(self::once())->method('get')->willReturn($oldPath);
        $config->expects(self::once())
            ->method('setMany')
            ->with([
                'brand_name' => 'Example Analytics',
                'brand_logo_text' => '',
                'brand_logo_path' => '',
            ]);
        $controller = $this->controller($config, $request);

        $controller->saveBrandingSettings($request);

        self::assertFileDoesNotExist($this->projectDir.'/'.$oldPath);
    }

    public function testNonAdminCannotChangeBranding(): void
    {
        $request = $this->request([
            '_csrf_token' => 'valid-token',
            'brand_name' => 'Example Analytics',
        ]);
        $config = $this->config();
        $config->expects(self::never())->method('setMany');
        $controller = $this->controller($config, $request, admin: false);

        $this->expectException(AccessDeniedException::class);

        $controller->saveBrandingSettings($request);
    }

    public function testInvalidCsrfTokenIsRejectedBeforeUploadProcessing(): void
    {
        $source = $this->projectDir.'/logo.png';
        file_put_contents($source, $this->png());
        $request = $this->request([
            '_csrf_token' => 'invalid-token',
            'brand_name' => 'Example Analytics',
        ], [
            'brand_logo' => new UploadedFile($source, 'logo.png', 'image/png', UPLOAD_ERR_OK, true),
        ]);
        $config = $this->config();
        $config->expects(self::never())->method('get');
        $config->expects(self::never())->method('setMany');
        $controller = $this->controller($config, $request, csrfValid: false);

        $controller->saveBrandingSettings($request);

        self::assertFileExists($source);
        self::assertSame(
            ['Invalid security token. Please try again.'],
            $request->getSession()->getFlashBag()->peek('error'),
        );
    }

    /** @return AggregateConfigLoader&MockObject */
    private function config(): AggregateConfigLoader
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('isDashboardEnabled')->willReturn(true);

        return $config;
    }

    private function controller(
        AggregateConfigLoader $config,
        Request $request,
        bool $admin = true,
        bool $csrfValid = true,
    ): DashboardController {
        $controller = new DashboardController(
            $this->createStub(WebsiteConfigManager::class),
            $config,
            new AnalyticsPrivacySettings($this->createStub(AggregateConfigLoader::class)),
            $this->createStub(UserRepository::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LoggerInterface::class),
            new BrandingLogoManager($this->projectDir, 'test'),
            new AnonymousBiViewManager($this->createStub(Connection::class)),
            new TrackingFailureSettings($this->createStub(AggregateConfigLoader::class)),
            $this->createStub(TrackingFailureRetryRunner::class),
        );

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn($admin);
        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn($csrfValid);
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->with('app_branding_settings')->willReturn('/dashboard/branding');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $container = new Container();
        $container->set('security.authorization_checker', $authorizationChecker);
        $container->set('security.csrf.token_manager', $csrfTokenManager);
        $container->set('request_stack', $requestStack);
        $container->set('router', $router);
        $controller->setContainer($container);

        return $controller;
    }

    /** @param array<string, mixed> $parameters @param array<string, mixed> $files */
    private function request(array $parameters, array $files = []): Request
    {
        $request = Request::create('/dashboard/settings/branding', 'POST', $parameters, [], $files);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function png(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($png);

        return $png;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
