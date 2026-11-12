<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ApplicationSettingsController;
use App\Service\AggregateConfigLoader;
use App\Service\DocumentationLinks;
use App\Service\GeneralSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** The collection address on General settings: saved normalized, cleared to '', refused when invalid. */
final class ApplicationSettingsCollectionAddressTest extends TestCase
{
    private const BASE = ['app_host' => 'https://analytics.example.test', 'js_namespace' => 'Aggregate', 'rate_limit_per_minute' => 100,
        'website_activity_active_days' => 1, 'website_activity_stale_days' => 3];

    /** @param array<string, mixed>|null $saved */
    #[DataProvider('submissions')]
    public function testTheAddressIsSavedNormalizedOrRefused(string $current, string $submitted, ?array $saved, string $flashType): void
    {
        $config = $this->config($current, false, $saved);
        [$controller, $request] = $this->controller($config, ['collection_base_url' => $submitted]);

        $controller->saveSettings($request, new GeneralSettings($config, new DocumentationLinks($config)));

        self::assertNotEmpty($request->getSession()->getFlashBag()->get($flashType));
    }

    /** @return iterable<string, array{string, string, ?array<string, mixed>, string}> */
    public static function submissions(): iterable
    {
        yield 'set, without the trailing slash' => ['', 'https://collect.example.test/', self::BASE + ['collection_base_url' => 'https://collect.example.test'], 'success'];
        yield 'cleared back to the App Host' => ['https://collect.example.test', '', self::BASE + ['collection_base_url' => ''], 'success'];
        yield 'unchanged, so not written' => ['https://collect.example.test', 'https://collect.example.test', self::BASE, 'success'];
        yield 'not a URL, nothing saved' => ['', 'collect.example.test', null, 'error'];
    }

    public function testAnEnvironmentOverrideKeepsTheFieldFromBeingSaved(): void
    {
        $config = $this->config('https://env-collect.example.test', true, self::BASE);
        [$controller, $request] = $this->controller($config, ['collection_base_url' => 'https://other.example.test']);

        $controller->saveSettings($request, new GeneralSettings($config, new DocumentationLinks($config)));

        self::assertNotEmpty($request->getSession()->getFlashBag()->get('success'));
    }

    /** @param array<string, mixed>|null $saved what setMany must receive, or null for no save */
    private function config(string $current, bool $overridden, ?array $saved): AggregateConfigLoader
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('isDashboardEnabled')->willReturn(true);
        $config->method('getWithEnvFallback')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => match ($key) {
            'collection_base_url' => $current,
            default => $default,
        });
        $config->method('hasEnvironmentOverride')->willReturnCallback(static fn (string $key): bool => $key === 'collection_base_url' && $overridden);
        if ($saved === null) {
            $config->expects(self::never())->method('setMany');
        } else {
            $config->expects(self::once())->method('setMany')->with($saved);
        }

        return $config;
    }

    /**
     * @param array<string, string> $fields
     * @return array{ApplicationSettingsController, Request}
     */
    private function controller(AggregateConfigLoader $config, array $fields): array
    {
        $request = Request::create('/dashboard/settings/save', 'POST', $fields + ['_csrf_token' => 'token'] + [
            'app_host' => self::BASE['app_host'], 'js_namespace' => self::BASE['js_namespace'], 'rate_limit' => '100',
            'website_activity_active_days' => '1', 'website_activity_stale_days' => '3',
        ]);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/dashboard/settings');

        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $controller = new ApplicationSettingsController($config);
        $controller->setContainer($container);

        return [$controller, $request];
    }
}
