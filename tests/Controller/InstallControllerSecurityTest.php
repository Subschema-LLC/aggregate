<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Kernel;
use App\Service\AggregateConfigLoader;
use App\Service\InstallationChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;

final class InstallControllerSecurityTest extends TestCase
{
    private string $temporaryDirectory;
    private array $environment;
    private ?InstallSecurityTestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = '1';
        $this->temporaryDirectory = sys_get_temp_dir().'/aggregate-install-security-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    #[DataProvider('invalidTokens')]
    public function testDirectInstallRequestRejectsInvalidCsrfBeforeConfigurationOrDatabaseChanges(array $parameters): void
    {
        $browser = $this->browser();
        $browser->request('POST', '/install/execute', $parameters + [
            'admin_username' => 'untrusted-admin',
            'admin_password' => 'untrusted-password',
            'js_namespace' => 'UntrustedNamespace',
        ]);

        self::assertSame(403, $browser->getResponse()->getStatusCode());
        self::assertFileDoesNotExist($this->temporaryDirectory.'/config/aggregate.yaml');
    }

    public static function invalidTokens(): iterable
    {
        yield 'missing' => [[]];
        yield 'forged' => [['_csrf_token' => 'forged']];
        yield 'array' => [['_csrf_token' => ['forged']]];
    }

    public function testRenderedInstallFormCarriesATokenThatWorksOnlyInItsSession(): void
    {
        $browser = $this->browser();
        $crawler = $browser->request('GET', '/install');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $token = $crawler->filter('form input[name="_csrf_token"]')->attr('value');
        self::assertNotEmpty($token);

        // An empty form with a valid token reaches ordinary validation without
        // migrations or writes. This also works without browser JavaScript.
        $browser->request('POST', '/install/execute', ['_csrf_token' => $token]);
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame('/install', $browser->getResponse()->headers->get('Location'));

        $browser->getCookieJar()->clear();
        $browser->request('POST', '/install/execute', ['_csrf_token' => $token]);
        self::assertSame(403, $browser->getResponse()->getStatusCode());
        self::assertFileDoesNotExist($this->temporaryDirectory.'/config/aggregate.yaml');
    }

    private function browser(): KernelBrowser
    {
        $this->kernel = new InstallSecurityTestKernel($this->temporaryDirectory);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $checker = $this->createStub(InstallationChecker::class);
        $checker->method('isInstalled')->willReturn(false);
        $checker->method('isConfigValid')->willReturn(true);
        $container->set(InstallationChecker::class, $checker);
        $container->set(AggregateConfigLoader::class, new AggregateConfigLoader($this->temporaryDirectory, 'test'));
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();

        return $browser;
    }
}

final class InstallSecurityTestKernel extends Kernel
{
    public function __construct(private readonly string $temporaryDirectory)
    {
        parent::__construct('test', true);
    }

    public function getCacheDir(): string
    {
        return $this->temporaryDirectory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->temporaryDirectory.'/log';
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass().'_'.md5($this->temporaryDirectory);
    }
}
