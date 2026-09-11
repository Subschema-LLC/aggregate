<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\InternalTrafficController;
use App\Service\AggregateConfigLoader;
use App\Service\InternalTrafficSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;

final class InternalTrafficControllerTest extends TestCase
{
    private string $projectDir;
    private AggregateConfigLoader $config;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(InternalTrafficSettings::DEFAULTS), InternalTrafficSettings::TOKEN_KEY, 'dashboard_enabled'] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-marker-controller-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'environments' => ['test' => ['internal_traffic_share_token' => str_repeat('a', 64)], 'prod' => ['app_host' => 'https://example.com']],
        ]));
        $this->config = new AggregateConfigLoader($this->projectDir, 'test');
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        unlink($this->projectDir.'/config/aggregate.yaml');
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testAdminSavesMarkerSettingsWithoutChangingTokenOrOtherEnvironments(): void
    {
        $request = $this->request(['action' => 'save', ...array_replace(InternalTrafficSettings::DEFAULTS, [
            'internal_traffic_name' => 'companyStaff', 'internal_traffic_value' => 'team', 'internal_traffic_storage' => 'local_storage',
        ])]);
        $response = $this->controller($request)->save($request);

        self::assertSame(302, $response->getStatusCode());
        $written = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('companyStaff', $written['environments']['test']['internal_traffic_name']);
        self::assertSame('team', $written['environments']['test']['internal_traffic_value']);
        self::assertSame(str_repeat('a', 64), $written['environments']['test']['internal_traffic_share_token']);
        self::assertSame(['app_host' => 'https://example.com'], $written['environments']['prod']);
        self::assertNotEmpty($request->getSession()->getFlashBag()->get('success'));
    }

    public function testAdminRotatesAndRevokesPersistedToken(): void
    {
        $settings = new InternalTrafficSettings($this->config);
        $rotate = $this->request(['action' => 'rotate']);
        $this->controller($rotate)->save($rotate);
        $token = $settings->getShareToken();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
        self::assertNotSame(str_repeat('a', 64), $token);
        $revoke = $this->request(['action' => 'revoke']);
        $this->controller($revoke)->save($revoke);
        self::assertFalse($settings->matchesShareToken($token));
        self::assertSame('', Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['environments']['test']['internal_traffic_share_token']);
    }

    #[DataProvider('rejectedChanges')]
    public function testRejectedChangesDoNotWriteYaml(array $values, bool $csrfValid): void
    {
        $original = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $request = $this->request($values);
        $this->controller($request, csrfValid: $csrfValid)->save($request);
        self::assertSame($original, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertNotEmpty($request->getSession()->getFlashBag()->get('error'));
    }

    public static function rejectedChanges(): iterable
    {
        yield 'invalid csrf saving' => [['action' => 'save', ...InternalTrafficSettings::DEFAULTS], false];
        yield 'invalid csrf rotating' => [['action' => 'rotate'], false];
        yield 'invalid csrf revoking' => [['action' => 'revoke'], false];
        yield 'unknown setting' => [['action' => 'save', ...InternalTrafficSettings::DEFAULTS, 'installed' => true], true];
        yield 'incomplete settings' => [['action' => 'save', 'internal_traffic_name' => 'staff'], true];
    }

    public function testNonAdminCannotChangeSettings(): void
    {
        $request = $this->request(['action' => 'rotate']);
        $this->expectException(AccessDeniedException::class);
        $this->controller($request, admin: false)->save($request);
    }

    public function testHeadlessModeDoesNotExposeAdminActions(): void
    {
        $this->config->set('dashboard_enabled', false);
        $request = $this->request(['action' => 'rotate']);
        $this->expectException(NotFoundHttpException::class);
        $this->controller($request)->save($request);
    }

    private function request(array $values): Request
    {
        $request = Request::create('/dashboard/internal-traffic/save', 'POST', ['_csrf_token' => 'csrf', ...$values]);
        $request->setSession(new Session(new MockArraySessionStorage()));
        return $request;
    }

    private function controller(Request $request, bool $admin = true, bool $csrfValid = true): InternalTrafficController
    {
        $controller = new InternalTrafficController($this->config, new InternalTrafficSettings($this->config), new NullLogger());
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn($admin);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool =>
            $csrfValid && $token->getId() === InternalTrafficController::CSRF_TOKEN_ID);
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/dashboard/internal-traffic');
        $stack = new RequestStack();
        $stack->push($request);
        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $controller->setContainer($container);
        return $controller;
    }
}
