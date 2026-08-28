<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrandingLogoManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BrandingLogoManagerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/branding-logo-manager-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testStoresVerifiedImageUnderAnEnvironmentSpecificServerName(): void
    {
        $source = $this->projectDir.'/misleading.txt';
        file_put_contents($source, $this->png());
        $manager = new BrandingLogoManager($this->projectDir, 'prod');

        $path = $manager->store(new UploadedFile(
            $source,
            'company.php',
            'application/x-php',
            UPLOAD_ERR_OK,
            true,
        ));

        self::assertMatchesRegularExpression(
            '#^var/branding/prod/logo-[a-f0-9]{32}\.png$#D',
            $path,
        );
        self::assertFileExists($this->projectDir.'/'.$path);
        self::assertTrue($manager->isManagedPath($path));
    }

    public function testRejectsSvgEvenWhenClientClaimsItIsPng(): void
    {
        $source = $this->projectDir.'/logo.png';
        file_put_contents($source, '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');
        $manager = new BrandingLogoManager($this->projectDir, 'test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG, JPEG, or WebP');

        $manager->store(new UploadedFile($source, 'logo.png', 'image/png', UPLOAD_ERR_OK, true));
    }

    public function testRejectsFileLargerThanTwoMebibytes(): void
    {
        $source = $this->projectDir.'/large.png';
        file_put_contents(
            $source,
            $this->png().str_repeat('x', BrandingLogoManager::MAX_FILE_SIZE),
        );
        $manager = new BrandingLogoManager($this->projectDir, 'test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('2 MiB');

        $manager->store(new UploadedFile($source, 'large.png', 'image/png', UPLOAD_ERR_OK, true));
    }

    public function testRemoveManagedNeverDeletesYamlSuppliedOrOtherEnvironmentFiles(): void
    {
        $manager = new BrandingLogoManager($this->projectDir, 'prod');
        $external = $this->projectDir.'/external.png';
        $otherEnvironment = $this->projectDir.'/var/branding/dev/logo-0123456789abcdef0123456789abcdef.png';
        self::assertTrue(mkdir(dirname($otherEnvironment), 0700, true));
        file_put_contents($external, $this->png());
        file_put_contents($otherEnvironment, $this->png());

        $manager->removeManaged($external);
        $manager->removeManaged('var/branding/dev/logo-0123456789abcdef0123456789abcdef.png');

        self::assertFileExists($external);
        self::assertFileExists($otherEnvironment);
    }

    public function testRemoveManagedDeletesOnlyAnExactManagedPath(): void
    {
        $manager = new BrandingLogoManager($this->projectDir, 'prod');
        $path = 'var/branding/prod/logo-0123456789abcdef0123456789abcdef.png';
        self::assertTrue(mkdir(dirname($this->projectDir.'/'.$path), 0700, true));
        file_put_contents($this->projectDir.'/'.$path, $this->png());

        $manager->removeManaged($path);

        self::assertFileDoesNotExist($this->projectDir.'/'.$path);
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

        $items = scandir($directory) ?: [];
        foreach ($items as $item) {
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
