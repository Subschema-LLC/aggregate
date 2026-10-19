<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\AdminAuditSubscriber;
use App\Repository\UserRepository;
use App\Service\Operations\AdminAudit;
use App\Service\Operations\AuditTrailSettings;
use App\Service\Operations\ConfigurationChanges;
use App\Tests\Support\RecordingAdminAudit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class AdminAuditSubscriberTest extends TestCase
{
    private TokenStorage $tokens;
    /** @var array<string, bool> */
    private array $recorded = ['sign_ins' => false];
    private ?UserRepository $users = null;

    protected function setUp(): void
    {
        RecordingAdminAudit::$entries = [];
        $this->tokens = new TokenStorage();
    }

    protected function tearDown(): void
    {
        ConfigurationChanges::finish();
    }

    public function testItWatchesRequestsAfterTheControllerAndBeforeTheSessionIsSaved(): void
    {
        $events = AdminAuditSubscriber::getSubscribedEvents();
        self::assertSame('onController', $events[KernelEvents::CONTROLLER]);
        self::assertSame(['onResponse', -900], $events[KernelEvents::RESPONSE]);
        self::assertArrayHasKey(LoginFailureEvent::class, $events);
        self::assertArrayHasKey(LogoutEvent::class, $events);
    }

    public function testRoutesThatAreNotAdministratorActionsTouchNothing(): void
    {
        // The locator would fail if a service were fetched: collection requests stay cheap.
        $subscriber = new AdminAuditSubscriber(new ServiceLocator([]));
        foreach ([['api_receive', 'POST'], ['app_settings_save', 'GET'], ['app_audit_trail', 'GET']] as [$route, $method]) {
            $request = $this->request($route, $method);
            $subscriber->onController($this->controllerEvent($request));
            $subscriber->onResponse($this->responseEvent($request, new Response()));
            self::assertFalse(ConfigurationChanges::isRecording());
        }
        self::assertSame([], RecordingAdminAudit::$entries);
    }

    public function testASavedFormIsRecordedWithTheUserAndTheSettingNames(): void
    {
        $this->signIn('scott');
        $subscriber = $this->subscriber();
        $request = $this->request('app_settings_save');

        $subscriber->onController($this->controllerEvent($request));
        self::assertTrue(ConfigurationChanges::isRecording());
        ConfigurationChanges::record('config/aggregate.yaml', ['js_namespace', 'app_host']);
        $request->getSession()->getFlashBag()->add('success', 'Saved.');
        $subscriber->onResponse($this->responseEvent($request, new Response('', 302)));

        self::assertSame([[
            'area' => 'settings', 'action' => 'general_settings_saved', 'outcome' => 'succeeded', 'actor' => 'scott',
            'subject' => null, 'details' => 'Changed aggregate.yaml: app_host, js_namespace', 'always' => false,
        ]], RecordingAdminAudit::$entries);
        self::assertFalse(ConfigurationChanges::isRecording());
    }

    public function testTheOutcomeComesFromTheStatusAndNewErrorMessages(): void
    {
        $this->signIn('scott');
        $subscriber = $this->subscriber();
        $outcome = function (int $status, bool $error, bool $earlierError = false) use ($subscriber): string {
            $request = $this->request('app_branding_settings_save');
            if ($earlierError) {
                // A message left from an earlier request does not make this one fail.
                $request->getSession()->getFlashBag()->add('error', 'Earlier problem.');
            }
            $subscriber->onController($this->controllerEvent($request));
            if ($error) {
                $request->getSession()->getFlashBag()->add('error', 'Upload a PNG, JPEG, or WebP logo.');
            }
            $subscriber->onResponse($this->responseEvent($request, new Response('', $status)));

            return array_pop(RecordingAdminAudit::$entries)['outcome'];
        };

        self::assertSame('succeeded', $outcome(302, false));
        self::assertSame('succeeded', $outcome(302, false, true));
        self::assertSame('failed', $outcome(302, true, true));
        self::assertSame('failed', $outcome(500, false));
        self::assertSame('failed', $outcome(404, false));
        self::assertSame('denied', $outcome(403, false));
    }

    public function testTheSubjectIsWhatTheControllerNamedOrTheScopeSaved(): void
    {
        $this->signIn('scott');
        $subscriber = $this->subscriber();
        $subject = function (array $form, ?string $named = null) use ($subscriber): ?string {
            $request = $this->request('app_data_model_save', 'POST', $form);
            $subscriber->onController($this->controllerEvent($request));
            if ($named !== null) {
                $request->attributes->set(AdminAuditSubscriber::SUBJECT, $named);
            }
            $subscriber->onResponse($this->responseEvent($request, new Response('', 302)));

            return array_pop(RecordingAdminAudit::$entries)['subject'];
        };

        self::assertSame('group:brand-a', $subject(['scope' => 'group:brand-a']));
        self::assertSame('website:'.str_repeat('a1', 12), $subject(['scope' => 'website:'.str_repeat('a1', 12)]));
        self::assertNull($subject(['scope' => 'deployment']));
        self::assertNull($subject(['scope' => 'group:../../etc']));
        self::assertNull($subject(['scope' => ['group:a']]));
        self::assertSame('ana', $subject(['scope' => 'group:brand-a'], 'ana'));
    }

    public function testSignedOutRequestsAreRecordedOnlyOnceTheInstallerNamesItsAccount(): void
    {
        $subscriber = $this->subscriber();
        $request = $this->request('app_install_execute');
        $subscriber->onController($this->controllerEvent($request));
        $subscriber->onResponse($this->responseEvent($request, new Response('', 403)));
        self::assertSame([], RecordingAdminAudit::$entries, 'a forged installer request adds nothing');

        $request = $this->request('app_install_execute');
        $subscriber->onController($this->controllerEvent($request));
        $request->attributes->set(AdminAuditSubscriber::SUBJECT, 'admin');
        $subscriber->onResponse($this->responseEvent($request, new Response('', 302)));
        self::assertSame(['users', 'installed', 'succeeded', null, 'admin'], array_values(array_slice(RecordingAdminAudit::$entries[0], 0, 5)));
    }

    public function testChangesToTheSwitchesAreAlwaysRecordedWithTheirSummary(): void
    {
        $this->signIn('scott');
        $subscriber = $this->subscriber();
        $request = $this->request('app_audit_trail_settings');
        $subscriber->onController($this->controllerEvent($request));
        ConfigurationChanges::record('config/aggregate.yaml', ['audit_trail_areas.settings']);
        $request->attributes->set(AdminAuditSubscriber::DETAILS, 'Turned off: settings.');
        $subscriber->onResponse($this->responseEvent($request, new Response('', 302)));

        $entry = RecordingAdminAudit::$entries[0];
        self::assertTrue($entry['always']);
        self::assertSame('Turned off: settings. Changed aggregate.yaml: audit_trail_areas.settings', $entry['details']);
    }

    public function testSubRequestsAreIgnored(): void
    {
        $this->signIn('scott');
        $subscriber = $this->subscriber();
        $request = $this->request('app_settings_save');
        $subscriber->onController($this->controllerEvent($request, HttpKernelInterface::SUB_REQUEST));
        $subscriber->onResponse($this->responseEvent($request, new Response(), HttpKernelInterface::SUB_REQUEST));

        self::assertSame([], RecordingAdminAudit::$entries);
        self::assertFalse(ConfigurationChanges::isRecording());
    }

    public function testSignInsAndSignOutsAreRecordedUnderTheirArea(): void
    {
        $subscriber = $this->subscriber();
        $token = new UsernamePasswordToken((new User())->setUsername('scott')->setRoles(['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']);
        $subscriber->onLoginSuccess(new LoginSuccessEvent($this->createStub(AuthenticatorInterface::class), new SelfValidatingPassport(new UserBadge('scott')), $token, new Request(), null, 'main'));
        $subscriber->onLogout(new LogoutEvent(new Request(), $token));
        $subscriber->onLogout(new LogoutEvent(new Request(), null));

        self::assertSame([['sign_ins', 'signed_in', 'scott'], ['sign_ins', 'signed_out', 'scott']], array_map(
            static fn (array $entry): array => [$entry['area'], $entry['action'], $entry['actor']],
            RecordingAdminAudit::$entries,
        ));
    }

    public function testAFailedSignInNamesTheAccountOnlyWhenItExists(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturnCallback(static fn (array $criteria): ?User => $criteria['username'] === 'scott' ? new User() : null);
        $this->users = $users;
        $this->recorded['sign_ins'] = true;
        $subscriber = $this->subscriber();

        $subscriber->onLoginFailure($this->failure('scott', new BadCredentialsException()));
        $subscriber->onLoginFailure($this->failure('my-password-typed-here', new BadCredentialsException()));
        $subscriber->onLoginFailure($this->failure('scott', new TooManyLoginAttemptsAuthenticationException()));
        $subscriber->onLoginFailure($this->failure(null, new BadCredentialsException()));

        self::assertSame([
            ['failed', 'scott', 'Wrong password.'],
            ['failed', null, 'No account has the username entered.'],
            ['failed', 'scott', 'Too many attempts; sign-ins are paused for a minute.'],
            ['failed', null, 'No account has the username entered.'],
        ], array_map(static fn (array $entry): array => [$entry['outcome'], $entry['actor'], $entry['details']], RecordingAdminAudit::$entries));
        self::assertStringNotContainsString('my-password', (string) json_encode(RecordingAdminAudit::$entries));
    }

    public function testFailedSignInsAreNotLookedUpWhileSignInsAreOff(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('findOneBy');
        $this->users = $users;

        $this->subscriber()->onLoginFailure($this->failure('scott', new BadCredentialsException()));

        self::assertSame([], RecordingAdminAudit::$entries);
    }

    private function subscriber(): AdminAuditSubscriber
    {
        $settings = $this->createStub(AuditTrailSettings::class);
        $settings->method('isRecorded')->willReturnCallback(fn (string $area): bool => $this->recorded[$area] ?? true);
        $services = [
            AdminAudit::class => static fn (): AdminAudit => new RecordingAdminAudit(),
            AuditTrailSettings::class => static fn (): AuditTrailSettings => $settings,
            'Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface' => fn (): TokenStorage => $this->tokens,
        ];
        if ($this->users !== null) {
            $users = $this->users;
            $services[UserRepository::class] = static fn (): UserRepository => $users;
        }

        return new AdminAuditSubscriber(new ServiceLocator($services));
    }

    private function signIn(string $username): void
    {
        $this->tokens->setToken(new UsernamePasswordToken((new User())->setUsername($username)->setRoles(['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
    }

    /** @param array<string, mixed> $form */
    private function request(string $route, string $method = 'POST', array $form = []): Request
    {
        $request = Request::create('/dashboard/example', $method, $form);
        $request->attributes->set('_route', $route);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function controllerEvent(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): ControllerEvent
    {
        return new ControllerEvent($this->createStub(HttpKernelInterface::class), static fn (): Response => new Response(), $request, $type);
    }

    private function responseEvent(Request $request, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, $response);
    }

    private function failure(?string $username, \Symfony\Component\Security\Core\Exception\AuthenticationException $exception): LoginFailureEvent
    {
        $passport = $username === null ? null : new Passport(new UserBadge($username), new \Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials('secret'));

        return new LoginFailureEvent($exception, $this->createStub(AuthenticatorInterface::class), new Request(), null, 'main', $passport);
    }
}
