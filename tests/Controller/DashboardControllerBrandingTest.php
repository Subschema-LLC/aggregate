<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DashboardController;
use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\BrandingLogoManager;
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

        self::assertSame('/dashboard', $response->getTargetUrl());
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
            ['Branding is controlled by environment variables; no YAML values were changed.'],
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
            new AnalyticsPrivacySettings($this->createStub(Connection::class)),
            $this->createStub(UserRepository::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LoggerInterface::class),
            new BrandingLogoManager($this->projectDir, 'test'),
        );

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn($admin);
        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn($csrfValid);
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/dashboard');
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
