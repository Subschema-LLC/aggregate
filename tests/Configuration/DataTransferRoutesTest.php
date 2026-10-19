<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Command\CopyDataCommand;
use App\Command\ExportDataCommand;
use App\Command\ImportDataCommand;
use App\Controller\DataTransferController;
use App\Entity\User;
use App\Kernel;
use App\Service\DataTransfer\DataTransfer;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class DataTransferRoutesTest extends TestCase
{
    private const ROUTES = [
        'app_data_transfer' => ['/dashboard/data-transfer', 'GET'],
        'app_data_transfer_export' => ['/dashboard/data-transfer/export', 'POST'],
        'app_data_transfer_import' => ['/dashboard/data-transfer/import', 'POST'],
        'app_data_transfer_copy' => ['/dashboard/data-transfer/copy', 'POST'],
    ];

    private string $directory;
    private array $environment;
    private ?DataTransferRoutesTestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        $this->directory = sys_get_temp_dir().'/aggregate-data-transfer-routes-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testThePageRequiresAnAdministratorAndTheCommandsStayAvailable(): void
    {
        $kernel = $this->boot(true);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertTrue($container->has(DataTransferController::class));
        foreach ([ExportDataCommand::class, ImportDataCommand::class, CopyDataCommand::class] as $command) {
            self::assertTrue($container->has($command));
        }
        $browser = new KernelBrowser($kernel);
        $browser->disableReboot();
        $routes = $container->get('router')->getRouteCollection();
        foreach (self::ROUTES as $name => [$path, $method]) {
            $route = $routes->get($name);
            self::assertNotNull($route);
            self::assertSame($path, $route->getPath());
            self::assertSame([$method], $route->getMethods());
            $browser->request($method, $path);
            self::assertSame(302, $browser->getResponse()->getStatusCode(), $path);
            self::assertSame('/login', parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_PATH));
        }
        $administration = array_values(array_filter($container->getParameter('app.main_navigation')['items'], static fn (array $item): bool => $item['label'] === 'Administration'))[0]['children'];
        $link = array_values(array_filter($administration, static fn (array $item): bool => ($item['route'] ?? null) === 'app_data_transfer'));
        self::assertCount(1, $link);
        self::assertSame('ROLE_ADMIN', $link[0]['role']);

        $browser->loginUser(DataTransferRoutesUserProvider::user());
        $browser->request('GET', '/dashboard/data-transfer');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Data transfer', (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('audit trail', (string) $browser->getResponse()->getContent());
        self::assertStringNotContainsString('AGGREGATE_TRANSFER_TARGET_URL=', (string) $browser->getResponse()->getContent());
    }

    public function testHeadlessBootOmitsThePageAndKeepsTheTransfer(): void
    {
        $kernel = $this->boot(false);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertFalse($container->has(DataTransferController::class));
        self::assertInstanceOf(DataTransfer::class, $container->get(DataTransfer::class));
        self::assertTrue($container->has(ExportDataCommand::class));
        $browser = new KernelBrowser($kernel);
        $browser->disableReboot();
        $routes = $container->get('router')->getRouteCollection();
        foreach (self::ROUTES as $name => [$path, $method]) {
            self::assertNull($routes->get($name));
            $browser->request($method, $path);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $path);
        }
        self::assertFalse($container->get('doctrine.dbal.default_connection')->isConnected());
    }

    private function boot(bool $dashboard): DataTransferRoutesTestKernel
    {
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = $dashboard ? '1' : '0';
        $this->kernel = new DataTransferRoutesTestKernel($this->directory);
        $this->kernel->boot();

        return $this->kernel;
    }
}

final class DataTransferRoutesTestKernel extends Kernel implements CompilerPassInterface
{
    public function __construct(private readonly string $directory)
    {
        parent::__construct('test', true);
    }

    public function getCacheDir(): string
    {
        return $this->directory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory.'/log';
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass().'_'.md5($this->directory);
    }

    public function process(ContainerBuilder $container): void
    {
        $container->setDefinition('security.user.provider.concrete.app_user_provider', new Definition(DataTransferRoutesUserProvider::class));
    }
}

/** @implements UserProviderInterface<User> */
final class DataTransferRoutesUserProvider implements UserProviderInterface
{
    public static function user(): User
    {
        $user = (new User())->setUsername('transfer-admin')->setRoles(['ROLE_ADMIN'])->setPassword('test-only');
        (new \ReflectionProperty($user, 'id'))->setValue($user, 21);

        return $user;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return self::user();
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        return self::user();
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class;
    }
}
