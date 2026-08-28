<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AppBrandingTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private string $temporaryDir;
    private string $projectDir;

    /** @var array<string, array{env_exists: bool, env: mixed, server_exists: bool, server: mixed}> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        $this->temporaryDir = sys_get_temp_dir().'/aggregate-branding-test-'.bin2hex(random_bytes(8));
        $this->projectDir = $this->temporaryDir.'/project';
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));

        foreach (['BRAND_NAME', 'BRAND_LOGO_TEXT', 'BRAND_LOGO_PATH'] as $key) {
            $this->savedEnvironment[$key] = [
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => $saved) {
            if ($saved['env_exists']) {
                $_ENV[$key] = $saved['env'];
            } else {
                unset($_ENV[$key]);
            }

            if ($saved['server_exists']) {
                $_SERVER[$key] = $saved['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }

        $this->removeDirectory($this->temporaryDir);
    }

    public function testDefaultsToAggregateAnalyticsAndTextOnlyBranding(): void
    {
        $branding = $this->createBranding();

        self::assertSame(AppBranding::DEFAULT_NAME, $branding->getName());
        self::assertSame(AppBranding::DEFAULT_NAME, $branding->getLogoText());
        self::assertFalse($branding->hasLogo());
        self::assertNull($branding->getLogoPath());
        self::assertNull($branding->getLogoMimeType());
        self::assertNull($branding->getLogoVersion());
        self::assertSame([
            'name' => 'Aggregate Analytics',
            'logo_text' => 'Aggregate Analytics',
            'has_logo' => false,
            'logo_version' => null,
            'name_overridden' => false,
            'logo_text_overridden' => false,
            'logo_path_overridden' => false,
        ], $branding->toArray());
    }

    public function testLegacyNavigationLabelIsUsedWhenNewBrandingKeysAreAbsent(): void
    {
        $branding = $this->createBranding([
            'brand' => ['label' => 'Legacy Insights'],
        ]);

        self::assertSame('Legacy Insights', $branding->getName());
        self::assertSame('Legacy Insights', $branding->getLogoText());
    }

    public function testUsesNormalizedCustomTextAndAProjectRelativeLogo(): void
    {
        self::assertTrue(mkdir($this->projectDir.'/assets', 0700));
        $logoPath = $this->projectDir.'/assets/logo.png';
        file_put_contents($logoPath, base64_decode(self::PNG, true));
        $this->writeConfig([
            'brand_name' => '  Example Analytics  ',
            'brand_logo_text' => '  Example  ',
            'brand_logo_path' => 'assets/logo.png',
        ]);

        $branding = $this->createBranding();

        self::assertSame('Example Analytics', $branding->getName());
        self::assertSame('Example', $branding->getLogoText());
        self::assertTrue($branding->hasLogo());
        self::assertSame(realpath($logoPath), $branding->getLogoPath());
        self::assertSame('image/png', $branding->getLogoMimeType());
        self::assertSame(hash_file('sha256', $logoPath), $branding->getLogoVersion());
        self::assertSame(file_get_contents($logoPath), $branding->getValidatedLogo()['content'] ?? null);
    }

    public function testResetDropsTheBufferedLogoSnapshot(): void
    {
        $logoPath = $this->projectDir.'/logo.png';
        $firstContent = base64_decode(self::PNG, true);
        self::assertIsString($firstContent);
        file_put_contents($logoPath, $firstContent);
        $this->writeConfig(['brand_logo_path' => 'logo.png']);
        $branding = $this->createBranding();
        $firstVersion = $branding->getLogoVersion();

        $secondContent = $firstContent.'updated';
        file_put_contents($logoPath, $secondContent);
        $branding->reset();

        self::assertNotSame($firstVersion, $branding->getLogoVersion());
        self::assertSame($secondContent, $branding->getValidatedLogo()['content'] ?? null);
    }

    public function testExplicitEmptyLogoTextIsAllowedOnlyWithAValidLogo(): void
    {
        $logoPath = $this->projectDir.'/logo.png';
        file_put_contents($logoPath, base64_decode(self::PNG, true));
        $this->writeConfig([
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => '',
            'brand_logo_path' => 'logo.png',
        ]);

        self::assertSame('', $this->createBranding()->getLogoText());

        file_put_contents($logoPath, 'not an image');
        $branding = $this->createBranding();
        self::assertFalse($branding->hasLogo());
        self::assertSame('Example Analytics', $branding->getLogoText());
    }

    public function testEmptyBrandingEnvironmentValuesOverrideYamlWhenMeaningful(): void
    {
        $logoPath = $this->projectDir.'/logo.png';
        file_put_contents($logoPath, base64_decode(self::PNG, true));
        $this->writeConfig([
            'brand_name' => 'YAML Analytics',
            'brand_logo_text' => 'YAML wordmark',
            'brand_logo_path' => 'logo.png',
        ]);
        $_ENV['BRAND_NAME'] = '';
        $_ENV['BRAND_LOGO_TEXT'] = '';
        $_ENV['BRAND_LOGO_PATH'] = '';

        $branding = $this->createBranding();

        self::assertSame(AppBranding::DEFAULT_NAME, $branding->getName());
        self::assertFalse($branding->hasLogo());
        self::assertSame(AppBranding::DEFAULT_NAME, $branding->getLogoText());
        self::assertTrue($branding->toArray()['name_overridden']);
        self::assertTrue($branding->toArray()['logo_text_overridden']);
        self::assertTrue($branding->toArray()['logo_path_overridden']);
    }

    public function testInvalidTextValuesFallBackSafely(): void
    {
        $logoPath = $this->projectDir.'/logo.png';
        file_put_contents($logoPath, base64_decode(self::PNG, true));
        $this->writeConfig([
            'brand_name' => str_repeat('é', 101),
            'brand_logo_text' => "unsafe\ntext",
            'brand_logo_path' => 'logo.png',
        ]);

        $branding = $this->createBranding();

        self::assertSame(AppBranding::DEFAULT_NAME, $branding->getName());
        self::assertSame(AppBranding::DEFAULT_NAME, $branding->getLogoText());
    }

    public function testRejectsAProjectRelativePathThatEscapesTheProjectRoot(): void
    {
        $outsideLogo = $this->temporaryDir.'/outside.png';
        file_put_contents($outsideLogo, base64_decode(self::PNG, true));
        $this->writeConfig([
            'brand_name' => 'Example Analytics',
            'brand_logo_text' => '',
            'brand_logo_path' => '../outside.png',
        ]);

        $branding = $this->createBranding();

        self::assertFalse($branding->hasLogo());
        self::assertNull($branding->getValidatedLogo());
        self::assertSame('Example Analytics', $branding->getLogoText());
    }

    public function testAcceptsAnAbsoluteLocalLogoPath(): void
    {
        $outsideLogo = $this->temporaryDir.'/outside.png';
        file_put_contents($outsideLogo, base64_decode(self::PNG, true));
        $this->writeConfig(['brand_logo_path' => $outsideLogo]);

        self::assertSame(realpath($outsideLogo), $this->createBranding()->getLogoPath());
    }

    public function testRejectsAFileUriEvenWhenItPointsToAValidLocalImage(): void
    {
        $logoPath = $this->projectDir.'/logo.png';
        file_put_contents($logoPath, base64_decode(self::PNG, true));
        $this->writeConfig(['brand_logo_path' => 'file://'.$logoPath]);

        self::assertFalse($this->createBranding()->hasLogo());
    }

    public function testRejectsAProjectSymlinkThatEscapesTheProjectRoot(): void
    {
        $outsideLogo = $this->temporaryDir.'/outside.png';
        file_put_contents($outsideLogo, base64_decode(self::PNG, true));
        self::assertTrue(symlink($outsideLogo, $this->projectDir.'/linked-logo.png'));
        $this->writeConfig(['brand_logo_path' => 'linked-logo.png']);

        self::assertFalse($this->createBranding()->hasLogo());
    }

    public function testRejectsAnImageThatExceedsTheTotalPixelLimit(): void
    {
        $image = base64_decode(self::PNG, true);
        self::assertIsString($image);
        $image = substr_replace($image, pack('N2', 4096, 4096), 16, 8);
        file_put_contents($this->projectDir.'/oversized.png', $image);
        $this->writeConfig(['brand_logo_path' => 'oversized.png']);

        self::assertFalse($this->createBranding()->hasLogo());
    }

    /** @param array<string, mixed> $mainNavigation */
    private function createBranding(array $mainNavigation = []): AppBranding
    {
        return new AppBranding(
            new AggregateConfigLoader($this->projectDir, 'test'),
            $this->projectDir,
            $mainNavigation,
        );
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): void
    {
        file_put_contents(
            $this->projectDir.'/config/aggregate.yaml',
            Yaml::dump($config, 4, 2),
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }
}
