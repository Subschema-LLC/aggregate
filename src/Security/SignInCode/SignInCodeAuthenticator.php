<?php

declare(strict_types=1);

namespace App\Security\SignInCode;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Signs a browser in with the code it was emailed (POST /login/code/verify).
 * The account comes from the browser's pending sign-in (SignInCodes), never
 * from the form, so a code only ever signs in the account it was sent for.
 *
 * Guessing is bounded three ways: MAX_ATTEMPTS per code, the firewall's
 * login throttling (shared with passwords), and sign_in_code_guess, which
 * pauses code sign-in for an account after too many wrong codes from any
 * browser. Password sign-in is not affected by that pause.
 *
 * The token remembers when a code signed it in, so for PASSWORD_RESET_WINDOW
 * seconds the profile lets that user set a new password without the current
 * one: an emailed code is how someone who forgot their password gets back in.
 */
class SignInCodeAuthenticator extends AbstractAuthenticator
{
    use TargetPathTrait;

    public const ROUTE = 'app_login_code_verify';
    public const CSRF_TOKEN_ID = 'login_code_verify';
    public const TOKEN_ATTRIBUTE = 'aggregate_signed_in_with_code_at';
    public const PASSWORD_RESET_WINDOW = 900;
    public const WRONG_CODE = 'That code is wrong or has expired. Check the latest email, or ask for a new code.';

    public function __construct(
        private readonly SignInCodes $codes,
        private readonly SignInCodeSettings $settings,
        private readonly UrlGeneratorInterface $urls,
        #[Target('sign_in_code_guess')]
        private readonly RateLimiterFactoryInterface $guessLimiter,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST') && $request->attributes->get('_route') === self::ROUTE;
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->settings->isAvailable()) {
            throw new CustomUserMessageAuthenticationException('Sign-in codes are not available. Sign in with your password.');
        }
        $session = $request->getSession();
        $typed = (string) $request->request->get('code', '');
        $username = $this->codes->pendingUsername($session);
        if ($username === null) {
            // Nothing pending, or a decoy: use an attempt, as a real code would.
            $this->codes->verify($session, $typed);
            throw new CustomUserMessageAuthenticationException(self::WRONG_CODE);
        }

        return new Passport(
            new UserBadge($username),
            new CustomCredentials(function (string $typed, UserInterface $user) use ($session): bool {
                $limiter = $this->guessLimiter->create(hash('sha256', 'account:'.$user->getUserIdentifier()));
                if ($limiter->consume(0)->getRemainingTokens() === 0) {
                    $this->codes->clear($session);
                    throw new CustomUserMessageAuthenticationException('Too many wrong codes were entered for this account. Sign in with your password, or try a code again tomorrow.');
                }
                if ($this->codes->verify($session, $typed) === $user->getUserIdentifier()) {
                    return true;
                }
                $limiter->consume();

                return false;
            }, $typed),
            [new CsrfTokenBadge(self::CSRF_TOKEN_ID, (string) $request->request->get('_csrf_token', ''))],
        );
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $token = parent::createToken($passport, $firewallName);
        $token->setAttribute(self::TOKEN_ATTRIBUTE, time());

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $target = $this->getTargetPath($request->getSession(), $firewallName);
        $this->removeTargetPath($request->getSession(), $firewallName);
        $path = is_string($target) ? (string) parse_url($target, PHP_URL_PATH) : '';
        $query = is_string($target) ? (string) parse_url($target, PHP_URL_QUERY) : '';

        // Only a dashboard path on this site, never an address someone else chose.
        return new RedirectResponse(str_starts_with($path, '/dashboard') && !str_starts_with($path, '//')
            ? $path.($query !== '' ? '?'.$query : '')
            : $this->urls->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urls->generate('app_login_code'));
    }

    /** Whether this session was signed in with a code less than PASSWORD_RESET_WINDOW seconds ago. */
    public static function recentlySignedInWithCode(?TokenInterface $token, ?int $now = null): bool
    {
        $at = $token?->hasAttribute(self::TOKEN_ATTRIBUTE) ? $token->getAttribute(self::TOKEN_ATTRIBUTE) : null;
        $now ??= time();

        return is_int($at) && $at <= $now && $now - $at < self::PASSWORD_RESET_WINDOW;
    }
}
