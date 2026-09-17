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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class DashboardControllerPrivacySettingsTest extends TestCase
{
    public function testAdminSaveWritesBothThresholdsDirectlyToTheDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('UPDATE analytics_privacy_settings'),
                self::callback(static fn (array $parameters): bool =>
                    $parameters['minimum'] === 14
                    && $parameters['geo_minimum'] === 40),
                self::anything(),
            )
            ->willReturn(1);
        $session = new Session(new MockArraySessionStorage());
        $controller = $this->controller($connection, $session);

        $response = $controller->saveAnalyticsPrivacySettings(Request::create(
            '/dashboard/settings/analytics-privacy',
            'POST',
            [
                '_csrf_token' => 'valid-token',
                'anonymous_min_cell_count' => '14',
                'anonymous_geo_min_cell_count' => '40',
            ],
        ));

        self::assertSame('/dashboard/privacy', $response->getTargetUrl());
        self::assertSame(
            ['BI disclosure thresholds updated successfully.'],
            $session->getFlashBag()->peek('success'),
        );
    }

    #[DataProvider('invalidThresholds')]
    public function testInvalidThresholdDoesNotWriteToTheDatabase(
        mixed $minimum,
        mixed $geoMinimum,
    ): void {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');
        $session = new Session(new MockArraySessionStorage());
        $controller = $this->controller($connection, $session);

        $response = $controller->saveAnalyticsPrivacySettings(Request::create(
            '/dashboard/settings/analytics-privacy',
            'POST',
            [
                '_csrf_token' => 'valid-token',
                'anonymous_min_cell_count' => $minimum,
                'anonymous_geo_min_cell_count' => $geoMinimum,
            ],
        ));

        self::assertSame('/dashboard/privacy', $response->getTargetUrl());
        self::assertNotSame([], $session->getFlashBag()->peek('error'));
    }

    public static function invalidThresholds(): iterable
    {
        yield 'hourly below floor' => ['1', '25'];
        yield 'hourly above maximum' => ['1001', '25'];
        yield 'geography below floor' => ['5', '9'];
        yield 'geography above maximum' => ['5', '1001'];
        yield 'non-numeric' => ['five', 'twenty-five'];
        yield 'non-scalar' => [['5'], '25'];
        yield 'missing' => [null, null];
    }

    public function testInvalidCsrfTokenDoesNotWriteToTheDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');
        $session = new Session(new MockArraySessionStorage());
        $controller = $this->controller($connection, $session, csrfValid: false);

        $response = $controller->saveAnalyticsPrivacySettings(Request::create(
            '/dashboard/settings/analytics-privacy',
            'POST',
            [
                '_csrf_token' => 'invalid-token',
                'anonymous_min_cell_count' => '14',
                'anonymous_geo_min_cell_count' => '40',
            ],
        ));

        self::assertSame('/dashboard/privacy', $response->getTargetUrl());
        self::assertSame(
            ['Invalid security token. Please try again.'],
            $session->getFlashBag()->peek('error'),
        );
    }

    public function testNonAdminCannotSaveThresholds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');
        $controller = $this->controller(
            $connection,
            new Session(new MockArraySessionStorage()),
            admin: false,
        );

        $this->expectException(AccessDeniedException::class);

        $controller->saveAnalyticsPrivacySettings(Request::create(
            '/dashboard/settings/analytics-privacy',
            'POST',
            [
                '_csrf_token' => 'valid-token',
                'anonymous_min_cell_count' => '14',
                'anonymous_geo_min_cell_count' => '40',
            ],
        ));
    }

    public function testDisclosurePageRendersAnExplicitRepairStateWhenTheSingletonCannotBeRead(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('isDashboardEnabled')->willReturn(true);
        $config->method('getWithEnvFallback')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $default,
        );
        $config->method('getBoolWithEnvFallback')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => $default,
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findBy')->willReturn([]);

        $controller = new DashboardController(
            $this->createStub(WebsiteConfigManager::class),
            $config,
            new AnalyticsPrivacySettings($connection),
            $userRepository,
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $logger,
            new BrandingLogoManager(sys_get_temp_dir(), 'test'),
        );
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                'dashboard/privacy_settings.html.twig',
                self::callback(static fn (array $context): bool =>
                    $context['analytics_privacy_settings_error'] === true
                    && $context['anonymous_min_cell_count'] === 5
                    && $context['anonymous_geo_min_cell_count'] === 25),
            )
            ->willReturn('dashboard');

        $container = new Container();
        $container->set('security.authorization_checker', $authorizationChecker);
        $container->set('twig', $twig);
        $controller->setContainer($container);

        $response = $controller->privacySettings();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('dashboard', $response->getContent());
    }

    private function controller(
        Connection $connection,
        Session $session,
        bool $csrfValid = true,
        bool $admin = true,
    ): DashboardController {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('isDashboardEnabled')->willReturn(true);

        $controller = new DashboardController(
            $this->createStub(WebsiteConfigManager::class),
            $config,
            new AnalyticsPrivacySettings($connection),
            $this->createStub(UserRepository::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LoggerInterface::class),
            new BrandingLogoManager(sys_get_temp_dir(), 'test'),
        );

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn($admin);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->expects($admin ? self::once() : self::never())
            ->method('isTokenValid')
            ->with(self::callback(static fn (CsrfToken $token): bool =>
                $token->getId() === 'analytics_privacy_settings'))
            ->willReturn($csrfValid);
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->with('app_privacy_settings')->willReturn('/dashboard/privacy');

        $request = Request::create('/dashboard/settings/analytics-privacy', 'POST');
        $request->setSession($session);
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
}
