<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\UpdatesController;
use App\Service\AggregateConfigLoader;
use App\Service\ApplicationUpdateService;
use App\Service\FeatureFlags;
use App\Service\Update\ApplicationUpdater;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class UpdatesControllerTest extends TestCase
{
    public function testAdminCanViewCachedStatusAndDeploymentInstructions(): void
    {
        $status = $this->availableStatus();
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::once())->method('check')->with(false)->willReturn($status);

        $response = $this->controller($updates)->index();

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('Update available', $html);
        self::assertStringContainsString('development', $html);
        self::assertStringContainsString($status['current_commit'], $html);
        self::assertStringContainsString($status['latest_commit'], $html);
        self::assertStringContainsString('href="'.$status['compare_url'].'"', $html);
        self::assertStringContainsString('2026-09-14 12:00:00 UTC', $html);
        self::assertStringContainsString('https://github.com/Subschema-LLC/aggregate', $html);
        self::assertStringContainsString('method="post" action="/dashboard/updates/refresh"', $html);
        self::assertStringContainsString('name="_csrf_token" value="rendered-token"', $html);
        self::assertStringContainsString('Check now', $html);
        self::assertStringContainsString('cached for one hour', $html);
        self::assertStringContainsString('href="https://github.com/Subschema-LLC/aggregate/blob/development/DEPLOYMENT.md#updates"', $html);
        self::assertStringContainsString('php bin/console app:updates:check --refresh', $html);
        self::assertStringContainsString('php bin/console app:updates:apply', $html);
        self::assertStringContainsString('method="post" action="/dashboard/updates/install"', $html);
        self::assertStringContainsString('Install update', $html);
        self::assertStringContainsString('runs database migrations', $html);
        self::assertStringContainsString('config/*.local.yaml', $html);
        self::assertStringContainsString('SQLite', $html);
        self::assertStringNotContainsString('name="database_backup_confirmed"', $html);
        self::assertStringContainsString('Configured branch', $html);
        self::assertStringContainsString('Installed branch', $html);
        self::assertStringContainsString('normally needs no token', $html);
    }

    public function testMismatchedBranchExplainsConfigurationWithoutSuggestingPull(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn(array_replace($this->availableStatus(), [
            'branch' => 'master',
            'installed_branch' => 'development',
        ]));

        $html = (string) $this->controller($updates)->index()->getContent();

        self::assertStringContainsString('installed branch does not match', $html);
        self::assertStringContainsString('updates_branch', $html);
        self::assertStringContainsString('development', $html);
        self::assertStringContainsString('master', $html);
        self::assertStringNotContainsString('app:updates:pull', $html);
        self::assertStringNotContainsString('app:updates:apply', $html);
        self::assertStringNotContainsString('Install update', $html);
    }

    public function testPackagedReleaseShowsVersionsAndVerificationLinksWithoutGitPullInstructions(): void
    {
        $status = array_replace($this->availableStatus(), [
            'installation_type' => 'release',
            'branch' => 'master',
            'installed_branch' => 'master',
            'current_version' => '1.0.0',
            'latest_version' => '1.1.0',
            'release_url' => 'https://github.com/Subschema-LLC/aggregate/releases/tag/v1.1.0',
            'package_url' => 'https://github.com/Subschema-LLC/aggregate/releases/download/v1.1.0/aggregate-1.1.0.zip',
            'manifest_url' => 'https://github.com/Subschema-LLC/aggregate/releases/download/v1.1.0/release-manifest.json',
            'signature_url' => 'https://github.com/Subschema-LLC/aggregate/releases/download/v1.1.0/release-manifest.sig',
            'signature_verified' => false,
        ]);
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn($status);

        $html = (string) $this->controller($updates)->index()->getContent();

        self::assertStringContainsString('Release package', $html);
        self::assertStringContainsString('Installed version', $html);
        self::assertStringContainsString('1.0.0', $html);
        self::assertStringContainsString('1.1.0', $html);
        foreach (['release_url', 'package_url', 'manifest_url', 'signature_url'] as $key) {
            self::assertStringContainsString('href="'.$status[$key].'"', $html);
        }
        self::assertStringContainsString('signature has not been verified', $html);
        self::assertStringContainsString('app:updates:verify-package', $html);
        self::assertStringContainsString('app:updates:apply --package=', $html);
        self::assertStringContainsString('Downloads the signed release 1.1.0', $html);
        self::assertStringNotContainsString('app:updates:pull', $html);
        self::assertStringNotContainsString('Installed branch', $html);
    }

    public function testIncompatibleReleaseCannotBeReportedAsAvailable(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn(array_replace($this->availableStatus(), [
            'state' => 'incompatible',
            'installation_type' => 'release',
            'message' => 'Requires PHP >=8.4.',
        ]));

        $html = (string) $this->controller($updates)->index()->getContent();

        self::assertStringContainsString('Release requirements are not met', $html);
        self::assertStringNotContainsString('Update available', $html);
        self::assertStringNotContainsString('Install update', $html);
        self::assertStringNotContainsString('app:updates:pull', $html);
    }

    public function testUnavailableStatusDoesNotDisplayMissingRevisionsOrTimeAsCurrent(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::once())->method('check')->with(false)->willReturn([
            'state' => 'unavailable',
            'branch' => null,
            'current_commit' => null,
            'latest_commit' => null,
            'checked_at' => null,
            'message' => 'This installation has no Git checkout.',
            'compare_url' => null,
        ]);

        $html = (string) $this->controller($updates)->index()->getContent();

        self::assertStringContainsString('Update checking unavailable', $html);
        self::assertStringContainsString('This installation has no Git checkout.', $html);
        self::assertStringContainsString('Not checked', $html);
        self::assertStringNotContainsString('Up to date', $html);
        self::assertStringNotContainsString('Review changes on GitHub', $html);
        self::assertStringNotContainsString('<time ', $html);
        self::assertStringContainsString('href="https://github.com/Subschema-LLC/aggregate">deployment guide</a>', $html);
    }

    public function testErrorStatusRendersEscapedDetailsWithoutClaimingTheCheckoutIsCurrent(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::once())->method('check')->willReturn(array_replace($this->availableStatus(), [
            'state' => 'error',
            'message' => 'GitHub returned <unexpected> content.',
            'branch' => '<development>',
            'latest_commit' => null,
            'compare_url' => null,
        ]));

        $html = (string) $this->controller($updates)->index()->getContent();

        self::assertStringContainsString('Update check failed', $html);
        self::assertStringContainsString('GitHub returned &lt;unexpected&gt; content.', $html);
        self::assertStringContainsString('&lt;development&gt;', $html);
        self::assertStringContainsString('/blob/%3Cdevelopment%3E/DEPLOYMENT.md#updates', $html);
        self::assertStringNotContainsString('Up to date', $html);
    }

    public function testAdminRefreshRequestsAFreshCheckThenRedirects(): void
    {
        $request = $this->request('POST', ['_csrf_token' => 'valid-token']);
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::once())->method('check')->with(true)->willReturn($this->availableStatus());

        $response = $this->controller($updates, $request)->refresh($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/dashboard/updates', $response->headers->get('Location'));
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidOrMalformedCsrfNeverRefreshes(array $submitted): void
    {
        $request = $this->request('POST', $submitted);
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::never())->method('check');

        $response = $this->controller($updates, $request, csrfValid: false)->refresh($request);

        self::assertSame('/dashboard/updates', $response->headers->get('Location'));
        self::assertSame(
            ['Invalid security token. Please try again.'],
            $request->getSession()->getFlashBag()->peek('error'),
        );
    }

    public static function invalidTokens(): iterable
    {
        yield 'missing token' => [[]];
        yield 'invalid token' => [['_csrf_token' => 'invalid-token']];
        yield 'array token' => [['_csrf_token' => ['valid-token']]];
        yield 'numeric token' => [['_csrf_token' => 123]];
        yield 'null token' => [['_csrf_token' => null]];
    }

    #[DataProvider('requestMethods')]
    public function testRequestsWithoutAdministratorAuthorizationCannotCheckForUpdates(string $method): void
    {
        $request = $this->request($method, ['_csrf_token' => 'valid-token']);
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::never())->method('check');
        $controller = $this->controller($updates, $request, admin: false);

        $this->expectException(AccessDeniedException::class);

        if ($method === 'POST') {
            $controller->refresh($request);
        } else {
            $controller->index();
        }
    }

    #[DataProvider('requestMethods')]
    public function testDashboardDisabledPreventsUpdateChecks(string $method): void
    {
        $request = $this->request($method, ['_csrf_token' => 'valid-token']);
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::never())->method('check');
        $controller = $this->controller($updates, $request, dashboardEnabled: false);

        $this->expectException(NotFoundHttpException::class);

        if ($method === 'POST') {
            $controller->refresh($request);
        } else {
            $controller->index();
        }
    }

    public static function requestMethods(): iterable
    {
        yield 'view status' => ['GET'];
        yield 'refresh status' => ['POST'];
    }

    #[DataProvider('requestMethods')]
    public function testDisabledFeatureBlocksDirectRequestsBeforeCheckingUpdates(string $method): void
    {
        $request = $this->request($method, ['_csrf_token' => 'valid-token']);
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::never())->method('check');
        $controller = $this->controller($updates, $request, featureEnabled: false);

        $this->expectException(NotFoundHttpException::class);
        if ($method === 'POST') {
            $controller->refresh($request);
        } else {
            $controller->index();
        }
    }

    public function testInstallStartsTheSameCommandInTheBackgroundAfterPreflight(): void
    {
        $request = $this->request('POST', ['_csrf_token' => 'valid-token'], '/dashboard/updates/install');
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::once())->method('check')->with(true)->willReturn(array_replace($this->availableStatus(), [
            'installation_type' => 'release', 'latest_version' => '1.1.0',
        ]));
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->method('isSqlite')->willReturn(false);
        $updater->method('preflight')->willReturn(['problems' => [], 'warnings' => []]);
        $updater->method('backgroundProblems')->willReturn([]);
        $updater->expects(self::once())->method('startInBackground')->with(['--database-backup-confirmed', '--release=1.1.0']);
        $request->request->set('database_backup_confirmed', '1');

        $response = $this->controller($updates, $request, updater: $updater, csrfId: UpdatesController::INSTALL_CSRF_TOKEN_ID)->install($request);

        self::assertSame('/dashboard/updates', $response->headers->get('Location'));
        self::assertStringContainsString('The update has started', $request->getSession()->getFlashBag()->peek('success')[0]);
    }

    public function testInstallRequiresADatabaseBackupConfirmationForServerDatabases(): void
    {
        $request = $this->request('POST', ['_csrf_token' => 'valid-token'], '/dashboard/updates/install');
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn($this->availableStatus());
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->method('isSqlite')->willReturn(false);
        $updater->expects(self::never())->method('startInBackground');

        $this->controller($updates, $request, updater: $updater, csrfId: UpdatesController::INSTALL_CSRF_TOKEN_ID)->install($request);

        self::assertStringContainsString('database backup', $request->getSession()->getFlashBag()->peek('error')[0]);
    }

    #[DataProvider('blockedInstalls')]
    public function testInstallNeverStartsWhenNoUpdateOrPreflightFails(string $state, array $problems, array $backgroundProblems, string $message): void
    {
        $request = $this->request('POST', ['_csrf_token' => 'valid-token'], '/dashboard/updates/install');
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn(array_replace($this->availableStatus(), ['state' => $state]));
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->method('isSqlite')->willReturn(true);
        $updater->method('preflight')->willReturn(['problems' => $problems, 'warnings' => []]);
        $updater->method('backgroundProblems')->willReturn($backgroundProblems);
        $updater->expects(self::never())->method('startInBackground');

        $this->controller($updates, $request, updater: $updater, csrfId: UpdatesController::INSTALL_CSRF_TOKEN_ID)->install($request);

        self::assertStringContainsString($message, $request->getSession()->getFlashBag()->peek('error')[0]);
    }

    public static function blockedInstalls(): iterable
    {
        yield 'already current' => ['up_to_date', [], [], 'No installable update'];
        yield 'diverged checkout' => ['diverged', [], [], 'No installable update'];
        yield 'files not writable' => ['available', ['This user cannot write the application directory.'], [], 'cannot write'];
        yield 'console differs from web' => ['available', [], ['The command-line console uses a different environment or database than the web server.'], 'different environment'];
    }

    public function testInstallWithInvalidCsrfNeverChecksOrStarts(): void
    {
        $request = $this->request('POST', ['_csrf_token' => 'invalid-token'], '/dashboard/updates/install');
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::never())->method('check');
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->expects(self::never())->method('startInBackground');

        $this->controller($updates, $request, csrfValid: false, updater: $updater, csrfId: UpdatesController::INSTALL_CSRF_TOKEN_ID)->install($request);

        self::assertSame(['Invalid security token. Please try again.'], $request->getSession()->getFlashBag()->peek('error'));
    }

    public function testFailedUpdateShowsRecoveryCommandsAndHidesInstallButton(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn($this->availableStatus());
        $updater = $this->createStub(ApplicationUpdater::class);
        $updater->method('isSqlite')->willReturn(true);
        $updater->method('status')->willReturn([
            'id' => '20260929T120000Z-abc123', 'type' => 'git', 'status' => 'needs_attention', 'running' => false,
            'step' => 'migrations', 'to' => ['commit' => str_repeat('b', 40)], 'error' => 'Migration <failed>.',
            'report' => ['files' => ['overrides_created' => ['config/goals.local.yaml']]],
            'log' => [['at' => 1789387200, 'level' => 'error', 'message' => 'Update stopped at "migrations".']],
        ]);

        $html = (string) $this->controller($updates, updater: $updater)->index()->getContent();

        self::assertStringContainsString('Last update needs attention', $html);
        self::assertStringContainsString('Migration &lt;failed&gt;.', $html);
        self::assertStringContainsString('app:updates:apply --resume', $html);
        self::assertStringContainsString('app:updates:rollback', $html);
        self::assertStringContainsString('config/goals.local.yaml', $html);
        self::assertStringNotContainsString('Install update', $html);
    }

    private function controller(
        ApplicationUpdateService $updates,
        ?Request $request = null,
        bool $admin = true,
        bool $dashboardEnabled = true,
        bool $csrfValid = true,
        bool $featureEnabled = true,
        ?ApplicationUpdater $updater = null,
        string $csrfId = UpdatesController::CSRF_TOKEN_ID,
    ): UpdatesController {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('isDashboardEnabled')->willReturn($dashboardEnabled);
        $config->method('all')->willReturn(['feature_flags' => ['updates' => ['enabled' => $featureEnabled]]]);
        if ($updater === null) {
            $updater = $this->createStub(ApplicationUpdater::class);
            $updater->method('status')->willReturn(null);
            $updater->method('isSqlite')->willReturn(true);
        }
        $controller = new UpdatesController($config, $updates, new FeatureFlags($config), $updater);

        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects($dashboardEnabled ? self::once() : self::never())
            ->method('isGranted')->with('ROLE_ADMIN')->willReturn($admin);

        $request ??= $this->request('GET');
        $stack = new RequestStack();
        $stack->push($request);
        $submitted = $request->request->all();
        $expectCsrfCheck = $request->isMethod('POST') && $admin && $dashboardEnabled && $featureEnabled
            && is_string($submitted['_csrf_token'] ?? null);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->expects($expectCsrfCheck ? self::once() : self::never())
            ->method('isTokenValid')
            ->with(self::callback(static fn (CsrfToken $token): bool =>
                $token->getId() === $csrfId
                && $token->getValue() === $submitted['_csrf_token']))
            ->willReturn($csrfValid);

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/dashboard/updates');

        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '{% block body %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('path', static fn (string $route): string => match ($route) {
            'app_dashboard' => '/dashboard',
            'app_updates' => '/dashboard/updates',
            'app_updates_refresh' => '/dashboard/updates/refresh',
            'app_updates_install' => '/dashboard/updates/install',
        }));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'rendered-token'));

        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        \App\Tests\Support\TwigComponents::register($twig);
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    private function request(string $method, array $submitted = [], string $path = ''): Request
    {
        $path = $path !== '' ? $path : ($method === 'POST' ? '/dashboard/updates/refresh' : '/dashboard/updates');
        $request = Request::create($path, $method, $submitted);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function availableStatus(): array
    {
        return [
            'state' => 'available',
            'installation_type' => 'git',
            'branch' => 'development',
            'installed_branch' => 'development',
            'current_commit' => str_repeat('a', 40),
            'latest_commit' => str_repeat('b', 40),
            'checked_at' => 1789387200,
            'message' => 'New commits are available on the development branch.',
            'compare_url' => 'https://github.com/Subschema-LLC/aggregate/compare/'.str_repeat('a', 40).'...'.str_repeat('b', 40),
        ];
    }
}
