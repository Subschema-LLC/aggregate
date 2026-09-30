<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\UpdatesController;
use App\Service\AggregateConfigLoader;
use App\Service\ApplicationUpdateService;
use App\Service\FeatureFlags;
use App\Service\Update\ApplicationUpdater;
use App\Service\Update\SystemCheck;
use App\Service\UpdateSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

        $response = $this->controller($updates, method: 'repository')->index();

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
        self::assertStringContainsString('href="https://docs.example.test/aggregate/operate/updates">update guide</a>', $html);
        self::assertStringContainsString('php bin/console app:updates:check --refresh', $html);
        self::assertStringContainsString('php bin/console app:updates:apply', $html);
        self::assertStringContainsString('method="post" action="/dashboard/updates/install"', $html);
        self::assertStringContainsString('Install update', $html);
        self::assertStringContainsString('runs database migrations', $html);
        self::assertStringContainsString('config/*.local.yaml', $html);
        self::assertStringContainsString('SQLite', $html);
        self::assertStringNotContainsString('name="database_backup_confirmed"', $html);
        self::assertStringNotContainsString('disabled aria-describedby="install-unavailable"', $html);
        self::assertStringContainsString('Configured branch', $html);
        self::assertStringContainsString('Installed branch', $html);
        self::assertStringContainsString('the public repository needs no token', $html);
    }

    public function testMismatchedBranchExplainsConfigurationWithoutSuggestingPull(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn(array_replace($this->availableStatus(), [
            'branch' => 'master',
            'installed_branch' => 'development',
        ]));

        $html = (string) $this->controller($updates, method: 'repository')->index()->getContent();

        self::assertStringContainsString('installed branch does not match', $html);
        self::assertStringContainsString('updates_branch', $html);
        self::assertStringContainsString('development', $html);
        self::assertStringContainsString('master', $html);
        self::assertStringNotContainsString('app:updates:pull', $html);
        self::assertStringNotContainsString('<code>php bin/console app:updates:apply</code>', $html, 'No install command is suggested.');
        $this->assertInstallDisabled($html, 'The installed branch does not match updates_branch');
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

        $html = (string) $this->controller($updates, method: 'release')->index()->getContent();

        self::assertStringContainsString('Release package', $html);
        self::assertStringContainsString('Installed version', $html);
        self::assertStringContainsString('1.0.0', $html);
        self::assertStringContainsString('1.1.0', $html);
        foreach (['release_url', 'package_url', 'manifest_url', 'signature_url'] as $key) {
            self::assertStringContainsString('href="'.$status[$key].'"', $html);
        }
        self::assertStringContainsString('does not verify signatures', $html);
        self::assertStringContainsString('app:updates:verify-package', $html);
        self::assertStringContainsString('app:updates:apply --package=', $html);
        self::assertStringContainsString('Install update 1.1.0', $html);
        self::assertStringNotContainsString('app:updates:pull', $html);
        self::assertStringNotContainsString('Installed branch', $html);
    }

    public function testUpToDateInstallationShowsTheInstallButtonDisabledWithTheReason(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn(array_replace($this->availableStatus(), ['state' => 'up_to_date', 'latest_commit' => str_repeat('a', 40)]));
        $updater = $this->createStub(ApplicationUpdater::class);
        $updater->method('isSqlite')->willReturn(false);
        $updater->method('status')->willReturn(null);

        $html = (string) $this->controller($updates, updater: $updater, method: 'repository')->index()->getContent();

        $this->assertInstallDisabled($html, 'This installation is up to date. The button becomes available when Check now finds a newer commit.');
        self::assertStringContainsString('Install update', $html);
        self::assertStringNotContainsString('name="database_backup_confirmed"', $html);
        self::assertStringContainsString('php bin/console app:updates:apply', $html);
    }

    public function testIncompatibleReleaseCannotBeReportedAsAvailable(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn(array_replace($this->availableStatus(), [
            'state' => 'incompatible',
            'installation_type' => 'release',
            'message' => 'Requires PHP >=8.4.',
        ]));

        $html = (string) $this->controller($updates, method: 'release')->index()->getContent();

        self::assertStringContainsString('Release requirements are not met', $html);
        self::assertStringNotContainsString('Update available', $html);
        $this->assertInstallDisabled($html, 'There is no installable update right now (release requirements are not met)');
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

        $html = (string) $this->controller($updates, method: 'repository')->index()->getContent();

        self::assertStringContainsString('Update checking unavailable', $html);
        self::assertStringContainsString('This installation has no Git checkout.', $html);
        self::assertStringContainsString('Not checked', $html);
        self::assertStringNotContainsString('Up to date', $html);
        self::assertStringNotContainsString('Review changes on GitHub', $html);
        self::assertStringNotContainsString('<time ', $html);
        self::assertStringContainsString('href="https://docs.example.test/aggregate/operate/updates">update guide</a>', $html);
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

        // With documentation links off, the guide comes from the update source's branch, escaped.
        $html = (string) $this->controller($updates, method: 'repository', documentationUrl: '')->index()->getContent();

        self::assertStringContainsString('Update check failed', $html);
        self::assertStringContainsString('GitHub returned &lt;unexpected&gt; content.', $html);
        self::assertStringContainsString('&lt;development&gt;', $html);
        self::assertStringContainsString('/blob/%3Cdevelopment%3E/docs/UPDATES.md', $html);
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
        $updater->method('backgroundProblems')->willReturn([]);
        $updater->expects(self::once())->method('startInBackground')->with(['--database-backup-confirmed', '--release=1.1.0']);
        $request->request->set('database_backup_confirmed', '1');

        $response = $this->controller($updates, $request, updater: $updater, csrfId: UpdatesController::INSTALL_CSRF_TOKEN_ID, method: 'release')->install($request);

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

        $this->controller($updates, $request, updater: $updater, csrfId: UpdatesController::INSTALL_CSRF_TOKEN_ID, method: 'repository')->install($request);

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
        $updater->method('backgroundProblems')->willReturn($backgroundProblems);
        $updater->expects(self::never())->method('startInBackground');

        $this->controller($updates, $request, updater: $updater, csrfId: UpdatesController::INSTALL_CSRF_TOKEN_ID, problems: $problems, method: 'repository')->install($request);

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

    public function testReleaseMethodShowsOnlyTheZipPanelWithDownloadAndUpload(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn(array_replace($this->availableStatus(), [
            'installation_type' => 'release', 'branch' => 'master', 'latest_version' => '2026.09.02', 'adopting' => true,
            'repository' => 'Subschema-LLC/aggregate', 'repository_url' => 'https://github.com/Subschema-LLC/aggregate',
        ]));

        $html = (string) $this->controller($updates, method: 'release')->index()->getContent();

        self::assertStringContainsString('This installation updates with release ZIPs.', $html);
        self::assertStringContainsString('<details class="box update-panel" id="update-zip" open>', $html);
        self::assertStringNotContainsString('id="update-repository"', $html);
        self::assertStringNotContainsString('id="update-method"', $html, 'The chooser only shows until a method is chosen.');
        self::assertStringContainsString('<input type="hidden" name="method" value="release">', $html);
        self::assertStringNotContainsString('name="method" value="git"', $html);
        self::assertStringContainsString('Install update 2026.09.02', $html);
        self::assertStringContainsString('action="/dashboard/updates/upload" enctype="multipart/form-data"', $html);
        self::assertStringContainsString('name="release_files[]" accept=".zip,.json,.sig" multiple required', $html);
        self::assertStringContainsString('Unknown (no release.json)', $html);
        self::assertStringContainsString('<details class="box update-panel" id="update-settings">', $html);
        self::assertStringContainsString('Switch update method', $html);
        self::assertStringContainsString('id="switch-method-release" name="updates_method" value="release" required checked', $html);
        self::assertStringContainsString('id="switch-method-repository" name="updates_method" value="repository" required aria-describedby', $html);
        self::assertStringContainsString('The repository method is for advanced users.', $html);
        self::assertStringContainsString('updates_method: release   # release (recommended) or repository (advanced)'."\n".'updates_branch: master', $html);
        self::assertStringContainsString('<details class="box update-panel" id="system-check">', $html);
        self::assertStringContainsString('<details class="box content update-panel" id="command-line">', $html);
        self::assertStringContainsString('php bin/console app:updates:method release', $html);
        self::assertStringNotContainsStringIgnoringCase('plesk', $html, 'The page names no hosting vendor.');
    }

    public function testRepositoryMethodShowsOnlyTheRepositoryPanelWithAnAdvancedWarning(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn($this->availableStatus());

        $html = (string) $this->controller($updates, method: 'repository')->index()->getContent();

        self::assertStringContainsString('This installation updates directly from the repository (advanced).', $html);
        self::assertStringContainsString('<details class="box update-panel" id="update-repository" open>', $html);
        self::assertStringContainsString('<span class="tag is-warning is-light ml-2">Advanced</span>', $html);
        self::assertStringContainsString('<strong>Advanced option.</strong>', $html);
        self::assertStringContainsString('href="https://docs.example.test/aggregate/operate/updates#update-from-the-repository-advanced"', $html);
        self::assertStringContainsString('<input type="hidden" name="method" value="git">', $html);
        self::assertStringNotContainsString('id="update-zip"', $html);
        self::assertStringNotContainsString('action="/dashboard/updates/upload"', $html);
        self::assertStringContainsString('id="switch-method-repository" name="updates_method" value="repository" required checked', $html);
        self::assertStringContainsString('Git clone', $html);
    }

    public function testUnchosenMethodShowsOnlyTheChooserWithTheFittingMethodSelected(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn($this->availableStatus());

        $html = (string) $this->controller($updates, detected: 'repository')->index()->getContent();

        self::assertStringContainsString('Choose how this installation updates.', $html);
        self::assertStringContainsString('<details class="box update-panel" id="update-method" open>', $html);
        self::assertStringContainsString('Not chosen yet', $html);
        self::assertStringContainsString('action="/dashboard/updates/method"', $html);
        self::assertStringContainsString('id="choose-method-repository" name="updates_method" value="repository" required checked', $html);
        self::assertStringContainsString('id="choose-method-release" name="updates_method" value="release" required aria-describedby', $html);
        self::assertStringContainsString('Recommended', $html);
        self::assertStringContainsString('The repository method is for advanced users.', $html);
        self::assertStringContainsString('Use this method', $html);
        self::assertStringNotContainsString('id="update-zip"', $html);
        self::assertStringNotContainsString('id="update-repository"', $html);
        self::assertStringNotContainsString('action="/dashboard/updates/install"', $html);
        self::assertStringNotContainsString('action="/dashboard/updates/upload"', $html);
        self::assertStringNotContainsString('Switch update method', $html);
        self::assertStringContainsString('# updates_method: release   # not chosen yet', $html);
    }

    public function testChosenMethodThatDoesNotFitTheDirectoryExplainsHowToFixIt(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn(array_replace($this->availableStatus(), [
            'state' => 'unavailable', 'current_commit' => null, 'latest_commit' => null, 'installed_branch' => null,
            'message' => 'The update method is repository, but the application directory is not a Git clone.',
        ]));

        $html = (string) $this->controller($updates, method: 'repository', detected: 'release')->index()->getContent();

        self::assertStringContainsString('id="repository-unavailable"', $html);
        self::assertStringContainsString('href="https://docs.example.test/aggregate/operate/updates#set-up-a-git-clone"', $html);
        self::assertStringContainsString('type="button" disabled aria-describedby="repository-unavailable"', $html);
        self::assertStringNotContainsString('action="/dashboard/updates/install"', $html);
        self::assertStringContainsString('<details class="box update-panel" id="update-settings" open>', $html);
        self::assertStringContainsString('This directory is not a Git clone, so it must be set up as one first.', $html);
    }

    public function testDashboardNeverInstallsBeforeAMethodIsChosen(): void
    {
        $request = $this->request('POST', ['_csrf_token' => 'valid-token'], '/dashboard/updates/install');
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn($this->availableStatus());
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->method('isSqlite')->willReturn(true);
        $updater->expects(self::never())->method('startInBackground');

        $this->controller($updates, $request, updater: $updater, csrfId: UpdatesController::INSTALL_CSRF_TOKEN_ID, detected: 'repository')->install($request);

        self::assertStringContainsString('Choose an update method', $request->getSession()->getFlashBag()->peek('error')[0]);
    }

    #[DataProvider('methodSubmissions')]
    public function testAdministratorsChooseOrSwitchTheMethodSavedToYaml(mixed $method, string $detected, ?array $saved, string $type, string $flash): void
    {
        $request = $this->request('POST', ['_csrf_token' => 'valid-token', 'updates_method' => $method], '/dashboard/updates/method');
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects($saved === null ? self::never() : self::once())->method('check')->with(true);

        $response = $this->controller($updates, $request, csrfId: UpdatesController::METHOD_CSRF_TOKEN_ID, savedSettings: $saved, detected: $detected)->saveMethod($request);

        self::assertSame('/dashboard/updates', $response->headers->get('Location'));
        self::assertStringContainsString($flash, implode(' ', $request->getSession()->getFlashBag()->peekAll()[$type] ?? []));
    }

    public static function methodSubmissions(): iterable
    {
        yield 'release on a release directory' => ['release', 'release', ['updates_method' => 'release'], 'success', 'now updates with release ZIPs'];
        yield 'repository on a clone' => ['repository', 'repository', ['updates_method' => 'repository'], 'success', 'from the repository (advanced)'];
        yield 'repository without .git warns' => ['repository', 'release', ['updates_method' => 'repository'], 'warning', 'not a Git clone yet'];
        yield 'release on a clone warns' => ['release', 'repository', ['updates_method' => 'release'], 'warning', 'is a Git clone'];
        yield 'unknown method' => ['git', 'release', null, 'error', 'updates_method must be release'];
        yield 'array method' => [['release'], 'release', null, 'error', 'updates_method must be release'];
        yield 'missing method' => [null, 'release', null, 'error', 'updates_method must be release'];
    }

    public function testMethodCannotChangeWhileAnUpdateNeedsAttention(): void
    {
        $request = $this->request('POST', ['_csrf_token' => 'valid-token', 'updates_method' => 'release'], '/dashboard/updates/method');
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::never())->method('check');
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->method('methodChangeProblem')->willReturn('The update method cannot change while an update is running or needs attention.');

        $this->controller($updates, $request, updater: $updater, csrfId: UpdatesController::METHOD_CSRF_TOKEN_ID)->saveMethod($request);

        self::assertStringContainsString('cannot change while an update', $request->getSession()->getFlashBag()->peek('error')[0]);
    }

    public function testOnlyAdministratorsCanChooseTheMethod(): void
    {
        $request = $this->request('POST', ['_csrf_token' => 'valid-token', 'updates_method' => 'repository'], '/dashboard/updates/method');
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::never())->method('check');
        $controller = $this->controller($updates, $request, admin: false);

        $this->expectException(AccessDeniedException::class);
        $controller->saveMethod($request);
    }

    public function testMethodIsNotSavedWithAnInvalidToken(): void
    {
        $request = $this->request('POST', ['_csrf_token' => 'forged', 'updates_method' => 'repository'], '/dashboard/updates/method');
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::never())->method('check');

        $this->controller($updates, $request, csrfValid: false, csrfId: UpdatesController::METHOD_CSRF_TOKEN_ID)->saveMethod($request);

        self::assertStringContainsString('Invalid security token', $request->getSession()->getFlashBag()->peek('error')[0]);
    }

    public function testSystemCheckProblemsOpenThatPanelAndDisableTheButtons(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn(array_replace($this->availableStatus(), ['installation_type' => 'release', 'latest_version' => '2026.09.02']));
        $checks = [
            ['id' => 'writable', 'label' => 'Application files writable', 'status' => 'error', 'detail' => 'User www-data cannot write <src/>.', 'scope' => 'update'],
            ['id' => 'background', 'label' => 'Dashboard button can start updates', 'status' => 'ok', 'detail' => 'Command-line PHP found.', 'scope' => 'dashboard'],
        ];

        $html = (string) $this->controller($updates, checks: $checks, problems: ['User www-data cannot write src/.'], method: 'release')->index()->getContent();

        self::assertStringContainsString('<details class="box update-panel" id="system-check" open>', $html);
        self::assertStringContainsString('1 problem', $html);
        self::assertStringContainsString('User www-data cannot write &lt;src/&gt;.', $html);
        self::assertStringContainsString('<span class="tag is-danger is-light">Problem</span>', $html);
        self::assertStringContainsString('(dashboard)', $html);
        $this->assertInstallDisabled($html, 'The system check below found a problem that stops updates from the dashboard.');
        self::assertStringContainsString('aria-describedby="upload-unavailable"', $html);
    }

    #[DataProvider('settingsSubmissions')]
    public function testBranchIsValidatedAndSavedToYaml(array $submitted, ?array $saved, string $flash): void
    {
        $request = $this->request('POST', ['_csrf_token' => 'valid-token'] + $submitted, '/dashboard/updates/settings');
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects($saved === null ? self::never() : self::once())->method('check')->with(true);

        $controller = $this->controller($updates, $request, csrfId: UpdatesController::SETTINGS_CSRF_TOKEN_ID, savedSettings: $saved);
        $response = $controller->saveSettings($request);

        self::assertSame('/dashboard/updates', $response->headers->get('Location'));
        self::assertStringContainsString($flash, implode(' ', $request->getSession()->getFlashBag()->peekAll()[$saved === null ? 'error' : 'success'] ?? []));
    }

    public static function settingsSubmissions(): iterable
    {
        yield 'branch' => [['updates_branch' => ' master '], ['updates_branch' => 'master'], 'saved to the YAML'];
        yield 'nested branch' => [['updates_branch' => 'releases/stable'], ['updates_branch' => 'releases/stable'], 'saved'];
        yield 'invalid branch' => [['updates_branch' => '../master'], null, 'updates_branch must be a valid Git branch'];
        yield 'array branch' => [['updates_branch' => ['master']], null, 'updates_branch must be a valid Git branch'];
        yield 'repository and source cannot be set here' => [['updates_branch' => 'master', 'updates_repository' => 'evil/repo', 'updates_source' => 'git'], ['updates_branch' => 'master'], 'saved'];
    }

    public function testUploadedReleaseIsVerifiedThenStartedInTheBackground(): void
    {
        $file = $this->uploadedFile('aggregate-2026.09.02.zip');
        $request = $this->request('POST', ['_csrf_token' => 'valid-token'], '/dashboard/updates/upload');
        $request->files->set('release_files', [$file, $this->uploadedFile('aggregate-release.json'), $this->uploadedFile('aggregate-release.json.sig')]);
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->method('isSqlite')->willReturn(true);
        $updater->method('backgroundProblems')->willReturn([]);
        $updater->expects(self::once())->method('stageUpload')->with(self::callback(static fn (array $files): bool => array_keys($files) === ['aggregate-2026.09.02.zip', 'aggregate-release.json', 'aggregate-release.json.sig']))
            ->willReturn(['package' => '/p.zip', 'manifest' => '/m.json', 'signature' => '/s.sig', 'version' => '2026.09.02']);
        $updater->expects(self::once())->method('startInBackground')->with(['--package=/p.zip', '--manifest=/m.json', '--signature=/s.sig']);

        $this->controller($this->createMock(ApplicationUpdateService::class), $request, updater: $updater, csrfId: UpdatesController::UPLOAD_CSRF_TOKEN_ID, method: 'release')->upload($request);

        self::assertStringContainsString('Verified release 2026.09.02. The update has started', $request->getSession()->getFlashBag()->peek('success')[0]);
    }

    #[DataProvider('refusedUploads')]
    public function testUploadsAreRefusedBeforeStaging(bool $withFiles, bool $sqlite, array $problems, string $message): void
    {
        $request = $this->request('POST', ['_csrf_token' => 'valid-token'], '/dashboard/updates/upload');
        if ($withFiles) {
            $request->files->set('release_files', [$this->uploadedFile('aggregate-2026.09.02.zip')]);
        }
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->method('isSqlite')->willReturn($sqlite);
        $updater->method('backgroundProblems')->willReturn([]);
        $updater->expects(self::never())->method('stageUpload');
        $updater->expects(self::never())->method('startInBackground');

        $this->controller($this->createMock(ApplicationUpdateService::class), $request, updater: $updater, csrfId: UpdatesController::UPLOAD_CSRF_TOKEN_ID, problems: $problems, method: 'release')->upload($request);

        self::assertStringContainsString($message, $request->getSession()->getFlashBag()->peek('error')[0]);
    }

    public static function refusedUploads(): iterable
    {
        yield 'no files' => [false, true, [], 'Choose the release ZIP'];
        yield 'server database without backup' => [true, false, [], 'current database backup'];
        yield 'system check problem' => [true, true, ['User www-data cannot write src/.'], 'cannot write src/'];
    }

    public function testOversizedUploadExplainsTheServerLimit(): void
    {
        $request = Request::create('/dashboard/updates/upload', 'POST', [], [], [], ['CONTENT_LENGTH' => '99999999']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $updater = $this->createMock(ApplicationUpdater::class);
        $updater->expects(self::never())->method('stageUpload');

        $this->controller($this->createMock(ApplicationUpdateService::class), $request, updater: $updater, csrfId: UpdatesController::UPLOAD_CSRF_TOKEN_ID)->upload($request);

        self::assertStringContainsString('larger than this server accepts', $request->getSession()->getFlashBag()->peek('error')[0]);
    }

    private function uploadedFile(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aggregate-upload-');
        file_put_contents($path, 'fixture');

        return new UploadedFile($path, $name, null, null, true);
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

        $html = (string) $this->controller($updates, updater: $updater, method: 'repository')->index()->getContent();

        self::assertStringContainsString('Last update needs attention', $html);
        self::assertStringContainsString('Migration &lt;failed&gt;.', $html);
        self::assertStringContainsString('app:updates:apply --resume', $html);
        self::assertStringContainsString('app:updates:rollback', $html);
        self::assertStringContainsString('config/goals.local.yaml', $html);
        $this->assertInstallDisabled($html, 'The last update needs attention');
    }

    private function assertInstallDisabled(string $html, string $reason): void
    {
        self::assertStringNotContainsString('action="/dashboard/updates/install"', $html);
        self::assertStringContainsString('type="button" disabled aria-describedby="install-unavailable"', $html);
        self::assertStringContainsString($reason, $html);
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
        array $problems = [],
        array $checks = [],
        array $settings = [],
        ?array $savedSettings = null,
        ?string $method = null,
        ?string $detected = null,
        string $documentationUrl = 'https://docs.example.test/aggregate/',
    ): UpdatesController {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('isDashboardEnabled')->willReturn($dashboardEnabled);
        $config->method('all')->willReturn(['feature_flags' => ['updates' => ['enabled' => $featureEnabled]]] + $settings + ($method !== null ? ['updates_method' => $method] : []));
        $updates->method('detectedMethod')->willReturn($detected ?? $method ?? 'release');
        if ($savedSettings !== null) {
            $config->expects(self::once())->method('setMany')->with($savedSettings);
        } else {
            $config->expects(self::never())->method('setMany');
        }
        if ($updater === null) {
            $updater = $this->createStub(ApplicationUpdater::class);
            $updater->method('status')->willReturn(null);
            $updater->method('isSqlite')->willReturn(true);
        }
        $systemCheck = $this->createStub(SystemCheck::class);
        $systemCheck->method('run')->willReturn($checks);
        $systemCheck->method('problems')->willReturn($problems);
        $controller = new UpdatesController($config, $updates, new FeatureFlags($config), $updater, $systemCheck, new UpdateSettings($config));

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
            'app_updates_settings' => '/dashboard/updates/settings',
            'app_updates_upload' => '/dashboard/updates/upload',
            'app_updates_method' => '/dashboard/updates/method',
        }));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'rendered-token'));

        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        \App\Tests\Support\TwigComponents::register($twig, $documentationUrl);
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
