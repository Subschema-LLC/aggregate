<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\BrandingLogoController;
use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

final class BrandingLogoControllerTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private string $projectDir;
    private bool $envLogoPathExisted;
    private mixed $envLogoPath;
    private bool $serverLogoPathExisted;
    private mixed $serverLogoPath;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-branding-controller-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
        $this->envLogoPathExisted = array_key_exists('BRAND_LOGO_PATH', $_ENV);
        $this->envLogoPath = $_ENV['BRAND_LOGO_PATH'] ?? null;
        $this->serverLogoPathExisted = array_key_exists('BRAND_LOGO_PATH', $_SERVER);
        $this->serverLogoPath = $_SERVER['BRAND_LOGO_PATH'] ?? null;
        unset($_ENV['BRAND_LOGO_PATH'], $_SERVER['BRAND_LOGO_PATH']);
    }

    protected function tearDown(): void
    {
        @unlink($this->projectDir.'/config/aggregate.yaml');
        @unlink($this->projectDir.'/logo.png');
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);

        if ($this->envLogoPathExisted) {
            $_ENV['BRAND_LOGO_PATH'] = $this->envLogoPath;
        } else {
            unset($_ENV['BRAND_LOGO_PATH']);
        }
        if ($this->serverLogoPathExisted) {
            $_SERVER['BRAND_LOGO_PATH'] = $this->serverLogoPath;
        } else {
            unset($_SERVER['BRAND_LOGO_PATH']);
        }
    }

    public function testServesAValidatedLogoWithSecureConditionalResponseHeaders(): void
    {
        $logoPath = $this->projectDir.'/logo.png';
        file_put_contents($logoPath, base64_decode(self::PNG, true));
        file_put_contents(
            $this->projectDir.'/config/aggregate.yaml',
            Yaml::dump(['brand_logo_path' => 'logo.png']),
        );
        $controller = new BrandingLogoController($this->createBranding());

        $response = $controller(Request::create('/branding/logo', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(base64_decode(self::PNG, true), $response->getContent());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        self::assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('"'.hash_file('sha256', $logoPath).'"', $response->headers->get('ETag'));
        self::assertNotNull($response->headers->get('Last-Modified'));

        $conditionalRequest = Request::create('/branding/logo', 'GET');
        $conditionalRequest->headers->set('If-None-Match', (string) $response->headers->get('ETag'));
        $conditionalResponse = $controller($conditionalRequest);

        self::assertSame(304, $conditionalResponse->getStatusCode());
    }

    public function testReturnsNotFoundWhenNoValidLogoIsConfigured(): void
    {
        $response = (new BrandingLogoController($this->createBranding()))(
            Request::create('/branding/logo', 'GET'),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    private function createBranding(): AppBranding
    {
        return new AppBranding(
            new AggregateConfigLoader($this->projectDir, 'test'),
            $this->projectDir,
        );
    }
}
