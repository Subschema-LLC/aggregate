<?php

declare(strict_types=1);

namespace App\Tests\Security\SignInCode;

use App\Entity\User;
use App\Security\SignInCode\SignInCodeAuthenticator;
use App\Security\SignInCode\SignInCodes;
use App\Security\SignInCode\SignInCodeSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class SignInCodeAuthenticatorTest extends TestCase
{
    private SignInCodes $codes;
    private Session $session;
    private InMemoryStorage $limits;

    protected function setUp(): void
    {
        $this->codes = new SignInCodes('test-secret');
        $this->session = new Session(new MockArraySessionStorage());
        $this->limits = new InMemoryStorage();
    }

    public function testItHandlesOnlyTheCodeForm(): void
    {
        $authenticator = $this->authenticator();

        self::assertTrue($authenticator->supports($this->request(['code' => '1'])));
        self::assertFalse($authenticator->supports($this->request([], 'GET')));
        $login = Request::create('/login', 'POST');
        $login->attributes->set('_route', 'app_login');
        self::assertFalse($authenticator->supports($login));
    }

    public function testTheAccountComesFromThePendingCodeNotTheForm(): void
    {
        $code = (string) $this->codes->issue($this->session, 'scott');
        $passport = $this->authenticator()->authenticate($this->request(['code' => $code, '_username' => 'admin', '_csrf_token' => 't']));

        self::assertSame('scott', $passport->getBadge(UserBadge::class)->getUserIdentifier());
        self::assertSame(SignInCodeAuthenticator::CSRF_TOKEN_ID, $passport->getBadge(CsrfTokenBadge::class)->getCsrfTokenId());
        $this->check($passport, 'scott');
        self::assertTrue($passport->getBadge(CustomCredentials::class)->isResolved());
        self::assertNull($this->codes->pending($this->session), 'The code is used up');
    }

    public function testAWrongCodeFailsAndCounts(): void
    {
        $code = (string) $this->codes->issue($this->session, 'scott');
        $wrong = $code === '111111' ? '222222' : '111111';

        $this->expectException(BadCredentialsException::class);
        try {
            $this->check($this->authenticator()->authenticate($this->request(['code' => $wrong])), 'scott');
        } finally {
            self::assertSame(SignInCodes::MAX_ATTEMPTS - 1, $this->codes->pending($this->session)['attempts_left']);
        }
    }

    public function testWithoutAPendingAccountThereIsNoPassport(): void
    {
        $this->codes->issue($this->session, null);

        try {
            $this->authenticator()->authenticate($this->request(['code' => '123456']));
            self::fail('A decoy produced a passport');
        } catch (CustomUserMessageAuthenticationException $e) {
            self::assertSame(SignInCodeAuthenticator::WRONG_CODE, $e->getMessageKey());
        }
        self::assertSame(SignInCodes::MAX_ATTEMPTS - 1, $this->codes->pending($this->session)['attempts_left'], 'A decoy uses attempts too');
    }

    public function testTooManyWrongCodesForAnAccountPauseCodeSignIn(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->codes->issue($this->session, 'scott');
            try {
                $this->check($this->authenticator()->authenticate($this->request(['code' => 'nope'])), 'scott');
            } catch (BadCredentialsException) {
            }
        }
        $code = (string) $this->codes->issue($this->session, 'scott');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Too many wrong codes were entered for this account');
        $this->check($this->authenticator()->authenticate($this->request(['code' => $code])), 'scott');
    }

    public function testNothingWorksWhileCodesAreUnavailable(): void
    {
        $code = (string) $this->codes->issue($this->session, 'scott');

        $this->expectExceptionMessage('Sign-in codes are not available');
        $this->authenticator(false)->authenticate($this->request(['code' => $code]));
    }

    public function testASecondStepFinishesWhileSignInCodesAreUnavailableAndMarksTheToken(): void
    {
        $code = (string) $this->codes->issue($this->session, 'scott', SignInCodes::KIND_SECOND_STEP);
        $authenticator = $this->authenticator(false);
        $passport = $authenticator->authenticate($this->request(['code' => $code]));
        $this->check($passport, 'scott');

        self::assertSame(SignInCodes::KIND_SECOND_STEP, $passport->getAttribute(SignInCodeAuthenticator::KIND_ATTRIBUTE));
        $user = (new User())->setUsername('scott')->setRoles(['ROLE_USER']);
        $token = $authenticator->createToken(new Passport(new UserBadge('scott', static fn (): User => $user), new CustomCredentials(static fn (): bool => true, '')), 'main');
        self::assertFalse(SignInCodeAuthenticator::completedSecondStep($token), 'A sign-in code is not a second step');
        $passport = new Passport(new UserBadge('scott', static fn (): User => $user), new CustomCredentials(static fn (): bool => true, ''));
        $passport->setAttribute(SignInCodeAuthenticator::KIND_ATTRIBUTE, SignInCodes::KIND_SECOND_STEP);
        $token = $authenticator->createToken($passport, 'main');
        self::assertTrue(SignInCodeAuthenticator::completedSecondStep($token));
        self::assertFalse(SignInCodeAuthenticator::recentlySignedInWithCode($token), 'A second step opens no password reset window');
    }

    public function testTheTokenOpensAShortPasswordResetWindow(): void
    {
        $user = (new User())->setUsername('scott')->setRoles(['ROLE_USER']);
        $token = $this->authenticator()->createToken(new Passport(new UserBadge('scott', static fn (): User => $user), new CustomCredentials(static fn (): bool => true, '')), 'main');

        self::assertTrue(SignInCodeAuthenticator::recentlySignedInWithCode($token));
        self::assertFalse(SignInCodeAuthenticator::recentlySignedInWithCode($token, time() + SignInCodeAuthenticator::PASSWORD_RESET_WINDOW));
        self::assertFalse(SignInCodeAuthenticator::recentlySignedInWithCode(new UsernamePasswordToken($user, 'main', ['ROLE_USER'])), 'A password sign-in opens no window');
        self::assertFalse(SignInCodeAuthenticator::recentlySignedInWithCode(null));
    }

    public function testSuccessReturnsOnlyToADashboardPathAndFailureToTheCodePage(): void
    {
        $authenticator = $this->authenticator();
        $token = new UsernamePasswordToken((new User())->setUsername('scott'), 'main');
        foreach ([
            'http://localhost/dashboard/email?tab=1' => '/dashboard/email?tab=1',
            'https://evil.example/dashboard' => '/dashboard',
            'http://localhost/logout' => '/generated/app_dashboard',
            '' => '/generated/app_dashboard',
        ] as $target => $expected) {
            $request = $this->request();
            if ($target !== '') {
                $request->getSession()->set('_security.main.target_path', $target);
            }
            $location = $authenticator->onAuthenticationSuccess($request, $token, 'main')?->headers->get('Location');
            self::assertSame($expected, $location, $target);
        }

        $request = $this->request();
        $response = $authenticator->onAuthenticationFailure($request, new BadCredentialsException());
        self::assertSame('/generated/app_login_code', $response?->headers->get('Location'));
        self::assertInstanceOf(BadCredentialsException::class, $request->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR));
    }

    private function check(Passport $passport, string $username): void
    {
        $passport->getBadge(CustomCredentials::class)->executeCustomChecker((new User())->setUsername($username));
    }

    /** @param array<string, string> $form */
    private function request(array $form = [], string $method = 'POST'): Request
    {
        $request = Request::create('/login/code/verify', $method, $form);
        $request->attributes->set('_route', SignInCodeAuthenticator::ROUTE);
        $request->setSession($this->session);

        return $request;
    }

    private function authenticator(bool $available = true): SignInCodeAuthenticator
    {
        $settings = $this->createStub(SignInCodeSettings::class);
        $settings->method('isAvailable')->willReturn($available);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route): string => '/generated/'.$route);

        return new SignInCodeAuthenticator(
            $this->codes,
            $settings,
            $urls,
            new RateLimiterFactory(['id' => 'sign_in_code_guess', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '24 hours'], $this->limits),
        );
    }
}
