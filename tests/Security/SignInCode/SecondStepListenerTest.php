<?php

declare(strict_types=1);

namespace App\Tests\Security\SignInCode;

use App\Entity\User;
use App\Security\SignInCode\SecondStepListener;
use App\Security\SignInCode\SignInCodeAuthenticator;
use App\Security\SignInCode\SignInCodeRequests;
use App\Security\SignInCode\SignInCodes;
use App\Security\SignInCode\SignInCodeSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class SecondStepListenerTest extends TestCase
{
    private TokenStorage $tokens;
    private SignInCodes $codes;
    private ?string $startProblem = null;
    /** @var list<string> */
    private array $started = [];

    protected function setUp(): void
    {
        $this->tokens = new TokenStorage();
        $this->codes = new SignInCodes('test-secret');
    }

    public function testACorrectPasswordOnlyStartsTheSecondStep(): void
    {
        $event = $this->login($this->user('scott@example.com'), 'http://localhost/dashboard/email');
        $this->listener()->onLoginSuccess($event);

        self::assertNull($this->tokens->getToken(), 'The password alone makes no session');
        self::assertSame('/generated/app_login_code', $event->getResponse()?->headers->get('Location'));
        self::assertSame(['scott'], $this->started);
        self::assertSame('http://localhost/dashboard/email', $event->getRequest()->getSession()->get('_security.main.target_path'), 'The code returns where the password was going');
        self::assertSame('password_accepted', $event->getRequest()->attributes->get(SecondStepListener::OUTCOME_ATTRIBUTE)[0]);
    }

    public function testTheSignInStopsWhenNoCodeCanBeSent(): void
    {
        foreach ([
            [$this->user(null), null, 'your account has no email address'],
            [$this->user('scott@example.com'), 'Email is turned off.', 'email cannot be sent right now'],
        ] as [$user, $emailProblem, $message]) {
            $this->startProblem = $user->getEmail() === null ? 'Two-step sign-in is required, and your account has no email address to send a code to.' : null;
            $event = $this->login($user);
            $this->listener(true, $emailProblem)->onLoginSuccess($event);

            self::assertNull($this->tokens->getToken());
            self::assertSame('/generated/app_login', $event->getResponse()?->headers->get('Location'));
            $error = $event->getRequest()->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR);
            self::assertInstanceOf(AuthenticationException::class, $error);
            self::assertStringContainsString($message, $error->getMessageKey());
            self::assertSame(['sign_in_failed', 'failed'], array_slice($event->getRequest()->attributes->get(SecondStepListener::OUTCOME_ATTRIBUTE), 0, 2));
        }
    }

    public function testNothingChangesWhileTwoStepSignInIsOffOrForTheCodeItself(): void
    {
        $event = $this->login($this->user('scott@example.com'));
        $this->listener(false)->onLoginSuccess($event);
        self::assertNotNull($this->tokens->getToken());
        self::assertSame('http://localhost/dashboard', $event->getResponse()?->headers->get('Location'));

        $code = $this->login($this->user('scott@example.com'), null, $this->createStub(SignInCodeAuthenticator::class));
        $this->listener()->onLoginSuccess($code);
        self::assertNotNull($this->tokens->getToken(), 'The code finishes the sign-in');
        self::assertSame([], $this->started);
    }

    public function testASessionThatSkippedTheSecondStepSignsInAgain(): void
    {
        $token = new UsernamePasswordToken($this->user('scott@example.com'), 'main', ['ROLE_USER']);
        $this->tokens->setToken($token);
        $request = $this->request();
        $request->getSession()->set('kept', 'no');
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener()->onRequest($event);

        self::assertNull($this->tokens->getToken());
        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertSame('/generated/app_login', $event->getResponse()->headers->get('Location'));
        self::assertNull($request->getSession()->get('kept'), 'The old session is discarded');
        self::assertStringContainsString('Two-step sign-in is now required', implode(' ', $request->getSession()->getFlashBag()->get('warning')));
    }

    public function testASessionThatPassedTheSecondStepOrWhileItIsOffIsLeftAlone(): void
    {
        foreach ([[true, true], [false, false]] as [$passed, $required]) {
            $token = new UsernamePasswordToken($this->user('scott@example.com'), 'main', ['ROLE_USER']);
            if ($passed) {
                $token->setAttribute(SignInCodeAuthenticator::SECOND_STEP_ATTRIBUTE, time());
            }
            $this->tokens->setToken($token);
            $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $this->request(), HttpKernelInterface::MAIN_REQUEST);

            $this->listener($required)->onRequest($event);

            self::assertSame($token, $this->tokens->getToken());
            self::assertNull($event->getResponse());
        }
    }

    private function login(User $user, ?string $target = null, ?AuthenticatorInterface $authenticator = null): LoginSuccessEvent
    {
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
        $this->tokens->setToken($token);

        return new LoginSuccessEvent(
            $authenticator ?? $this->createStub(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): User => $user)),
            $token,
            $this->request(),
            new RedirectResponse($target ?? 'http://localhost/dashboard'),
            'main',
        );
    }

    private function request(): Request
    {
        $request = Request::create('/login', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function user(?string $email): User
    {
        $user = (new User())->setUsername('scott')->setRoles(['ROLE_USER']);
        $user->setEmail($email);

        return $user;
    }

    private function listener(bool $required = true, ?string $emailProblem = null): SecondStepListener
    {
        $settings = $this->createStub(SignInCodeSettings::class);
        $settings->method('isSecondStepRequired')->willReturn($required);
        $settings->method('secondStepProblem')->willReturn($emailProblem);
        $requests = $this->createStub(SignInCodeRequests::class);
        $requests->method('startSecondStep')->willReturnCallback(function (Request $request, User $user): ?string {
            if ($this->startProblem !== null) {
                return $this->startProblem;
            }
            $this->started[] = $user->getUserIdentifier();
            $this->codes->issue($request->getSession(), $user->getUserIdentifier(), SignInCodes::KIND_SECOND_STEP);

            return null;
        });
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route): string => '/generated/'.$route);

        return new SecondStepListener($settings, $requests, $this->tokens, $urls);
    }
}
