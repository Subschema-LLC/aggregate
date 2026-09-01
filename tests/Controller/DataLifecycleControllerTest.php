<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DataLifecycleController;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsDataLifecyclePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;

final class DataLifecycleControllerTest extends TestCase
{
    private const KEYS = [
        AnalyticsDataLifecyclePolicy::KEY_ARCHIVING_ENABLED,
        AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS,
        AnalyticsDataLifecyclePolicy::KEY_RETENTION_ENABLED,
        AnalyticsDataLifecyclePolicy::KEY_ANONYMOUS_RETENTION_DAYS,
        AnalyticsDataLifecyclePolicy::KEY_ENHANCED_RETENTION_DAYS,
        AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_RETENTION_DAYS,
        AnalyticsDataLifecyclePolicy::KEY_MAINTENANCE_BATCH_SIZE,
    ];

    private string $projectDir;

    /** @var array<string, array{env_exists: bool, env: mixed, server_exists: bool, server: mixed}> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-lifecycle-controller-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));

        foreach (self::KEYS as $key) {
            $environmentKey = strtoupper($key);
            $this->savedEnvironment[$environmentKey] = [
                'env_exists' => array_key_exists($environmentKey, $_ENV),
                'env' => $_ENV[$environmentKey] ?? null,
                'server_exists' => array_key_exists($environmentKey, $_SERVER),
                'server' => $_SERVER[$environmentKey] ?? null,
            ];
            unset($_ENV[$environmentKey], $_SERVER[$environmentKey]);
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

        @unlink($this->projectDir.'/config/aggregate.yaml');
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);
    }

    public function testAdminCanViewTheEffectiveLifecycleSettings(): void
    {
        $config = $this->config($this->validStoredValues());
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                'dashboard/data_lifecycle.html.twig',
                self::callback(static fn (array $context): bool =>
                    $context['lifecycle_settings'][AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS] === 90
                    && $context['lifecycle_environment_overrides'] === array_fill_keys(self::KEYS, false)
                    && $context['lifecycle_configuration_error'] === null),
            )
            ->willReturn('lifecycle settings');
        $controller = $this->controller($config, twig: $twig);

        $response = $controller->index();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('lifecycle settings', $response->getContent());
    }

    public function testInvalidEffectiveConfigurationRendersAnExplicitErrorState(): void
    {
        $stored = $this->validStoredValues();
        $stored[AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS] = 0;
        $config = $this->config($stored);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                'dashboard/data_lifecycle.html.twig',
                self::callback(static fn (array $context): bool =>
                    $context['lifecycle_settings'][AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS] === 90
                    && is_string($context['lifecycle_configuration_error'])
                    && $context['lifecycle_configuration_error'] !== ''),
            )
            ->willReturn('invalid lifecycle settings');
        $controller = $this->controller($config, logger: $logger, twig: $twig);

        $response = $controller->index();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAdminSavePersistsAValidatedPolicy(): void
    {
        $config = $this->config($this->validStoredValues());
        $session = new Session(new MockArraySessionStorage());
        $request = $this->saveRequest($this->validSubmittedValues(), $session);
        $controller = $this->controller($config, request: $request);

        $response = $controller->save($request);

        self::assertSame('/dashboard/data-lifecycle', $response->getTargetUrl());
        self::assertSame(
            ['Analytics archiving and retention settings were saved.'],
            $session->getFlashBag()->peek('success'),
        );
        self::assertSame([
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVING_ENABLED => true,
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS => 120,
            AnalyticsDataLifecyclePolicy::KEY_RETENTION_ENABLED => true,
            AnalyticsDataLifecyclePolicy::KEY_ANONYMOUS_RETENTION_DAYS => 400,
            AnalyticsDataLifecyclePolicy::KEY_ENHANCED_RETENTION_DAYS => 180,
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_RETENTION_DAYS => 800,
            AnalyticsDataLifecyclePolicy::KEY_MAINTENANCE_BATCH_SIZE => 2500,
        ], Yaml::parseFile($this->projectDir.'/config/aggregate.yaml'));
    }

    #[DataProvider('rejectedForms')]
    public function testMalformedOrUnsafeFormDoesNotChangeYaml(array $submitted): void
    {
        $config = $this->config($this->validStoredValues());
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        self::assertIsString($before);
        $session = new Session(new MockArraySessionStorage());
        $request = $this->saveRequest($submitted, $session);
        $controller = $this->controller($config, request: $request);

        $controller->save($request);

        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertNotSame([], $session->getFlashBag()->peek('error'));
        self::assertSame([], $session->getFlashBag()->peek('success'));
    }

    public static function rejectedForms(): iterable
    {
        $valid = self::validSubmittedValuesStatic();

        yield 'unknown setting' => [array_merge($valid, ['unexpected_setting' => '1'])];

        $missing = $valid;
        unset($missing[AnalyticsDataLifecyclePolicy::KEY_MAINTENANCE_BATCH_SIZE]);
        yield 'missing setting' => [$missing];

        yield 'malformed integer' => [array_replace($valid, [
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS => 'ninety',
        ])];

        yield 'raw data could be deleted before it is archived' => [array_replace($valid, [
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS => '401',
            AnalyticsDataLifecyclePolicy::KEY_ANONYMOUS_RETENTION_DAYS => '400',
        ])];
    }

    public function testInvalidCsrfTokenDoesNotChangeYaml(): void
    {
        $config = $this->config($this->validStoredValues());
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $session = new Session(new MockArraySessionStorage());
        $submitted = $this->validSubmittedValues();
        $submitted['_csrf_token'] = 'invalid-token';
        $request = $this->saveRequest($submitted, $session);
        $controller = $this->controller($config, request: $request, csrfValid: false);

        $controller->save($request);

        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame(
            ['Invalid security token. Please try again.'],
            $session->getFlashBag()->peek('error'),
        );
    }

    public function testEnvironmentControlledSettingIsNeitherRequiredNorOverwritten(): void
    {
        $_ENV['ANALYTICS_ARCHIVE_AFTER_DAYS'] = '120';
        $config = $this->config($this->validStoredValues());
        $session = new Session(new MockArraySessionStorage());
        $submitted = $this->validSubmittedValues();
        unset($submitted[AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS]);
        $request = $this->saveRequest($submitted, $session);
        $controller = $this->controller($config, request: $request);

        $controller->save($request);

        $written = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame(90, $written[AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS]);
        self::assertSame(2500, $written[AnalyticsDataLifecyclePolicy::KEY_MAINTENANCE_BATCH_SIZE]);
        self::assertSame(
            ['Environment-controlled lifecycle settings were left unchanged.'],
            $session->getFlashBag()->peek('warning'),
        );
        self::assertNotSame([], $session->getFlashBag()->peek('success'));
    }

    public function testNonAdminCannotViewSettings(): void
    {
        $controller = $this->controller($this->config($this->validStoredValues()), admin: false);

        $this->expectException(AccessDeniedException::class);

        $controller->index();
    }

    public function testNonAdminCannotSaveSettings(): void
    {
        $config = $this->config($this->validStoredValues());
        $request = $this->saveRequest(
            $this->validSubmittedValues(),
            new Session(new MockArraySessionStorage()),
        );
        $controller = $this->controller($config, request: $request, admin: false, expectCsrfCheck: false);

        $this->expectException(AccessDeniedException::class);

        $controller->save($request);
    }

    /** @param array<string, mixed> $values */
    private function config(array $values): AggregateConfigLoader
    {
        file_put_contents(
            $this->projectDir.'/config/aggregate.yaml',
            Yaml::dump($values, 4, 2),
        );

        return new AggregateConfigLoader($this->projectDir, 'test');
    }

    private function controller(
        AggregateConfigLoader $config,
        ?Request $request = null,
        bool $admin = true,
        bool $csrfValid = true,
        ?bool $expectCsrfCheck = null,
        ?LoggerInterface $logger = null,
        ?Environment $twig = null,
    ): DataLifecycleController {
        $controller = new DataLifecycleController(
            $config,
            new AnalyticsDataLifecyclePolicy($config),
            $logger ?? $this->createStub(LoggerInterface::class),
        );

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn($admin);

        $request ??= Request::create('/dashboard/data-lifecycle', 'GET');
        if (!$request->hasSession()) {
            $request->setSession(new Session(new MockArraySessionStorage()));
        }
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $expectCsrfCheck ??= $request->isMethod('POST') && $admin;
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->expects($expectCsrfCheck ? self::once() : self::never())
            ->method('isTokenValid')
            ->with(self::callback(static fn (CsrfToken $token): bool =>
                $token->getId() === DataLifecycleController::CSRF_TOKEN_ID))
            ->willReturn($csrfValid);

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/dashboard/data-lifecycle');

        $container = new Container();
        $container->set('security.authorization_checker', $authorizationChecker);
        $container->set('security.csrf.token_manager', $csrfTokenManager);
        $container->set('request_stack', $requestStack);
        $container->set('router', $router);
        if ($twig !== null) {
            $container->set('twig', $twig);
        }
        $controller->setContainer($container);

        return $controller;
    }

    /** @param array<string, mixed> $submitted */
    private function saveRequest(array $submitted, Session $session): Request
    {
        $request = Request::create('/dashboard/data-lifecycle/save', 'POST', $submitted);
        $request->setSession($session);

        return $request;
    }

    /** @return array<string, bool|int> */
    private function validStoredValues(): array
    {
        return [
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVING_ENABLED => false,
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS => 90,
            AnalyticsDataLifecyclePolicy::KEY_RETENTION_ENABLED => false,
            AnalyticsDataLifecyclePolicy::KEY_ANONYMOUS_RETENTION_DAYS => 365,
            AnalyticsDataLifecyclePolicy::KEY_ENHANCED_RETENTION_DAYS => 90,
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_RETENTION_DAYS => 730,
            AnalyticsDataLifecyclePolicy::KEY_MAINTENANCE_BATCH_SIZE => 1000,
        ];
    }

    /** @return array<string, string> */
    private function validSubmittedValues(): array
    {
        return self::validSubmittedValuesStatic();
    }

    /** @return array<string, string> */
    private static function validSubmittedValuesStatic(): array
    {
        return [
            '_csrf_token' => 'valid-token',
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVING_ENABLED => '1',
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_AFTER_DAYS => '120',
            AnalyticsDataLifecyclePolicy::KEY_RETENTION_ENABLED => '1',
            AnalyticsDataLifecyclePolicy::KEY_ANONYMOUS_RETENTION_DAYS => '400',
            AnalyticsDataLifecyclePolicy::KEY_ENHANCED_RETENTION_DAYS => '180',
            AnalyticsDataLifecyclePolicy::KEY_ARCHIVE_RETENTION_DAYS => '800',
            AnalyticsDataLifecyclePolicy::KEY_MAINTENANCE_BATCH_SIZE => '2500',
        ];
    }
}
