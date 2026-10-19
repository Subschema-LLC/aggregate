<?php

declare(strict_types=1);

namespace App\Security\SignInCode;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Two-step sign-in (mfa_required). A correct password never makes a session
 * on its own: the sign-in is turned back into a pending second step, with a
 * code emailed to the account (SignInCodeRequests), and only the code
 * finishes it (SignInCodeAuthenticator), which marks the token.
 *
 * While two-step sign-in is required, any session whose token lacks that mark
 * (signed in before it was required, or by a sign-in code alone) is signed
 * out on its next request. Failing closed: an account without an email
 * address, or an email service that cannot send, stops the sign-in.
 *
 * The audit trail records the password step as password_accepted, or as a
 * failed sign-in with the reason, through OUTCOME_ATTRIBUTE.
 */
class SecondStepListener implements EventSubscriberInterface
{
    use TargetPathTrait;

    public const FIREWALL = 'main';
    /** Request attribute: [action, outcome, details] for AdminAuditSubscriber instead of signed_in. */
    public const OUTCOME_ATTRIBUTE = '_aggregate_sign_in_outcome';

    public function __construct(
        private readonly SignInCodeSettings $settings,
        private readonly SignInCodeRequests $requests,
        private readonly TokenStorageInterface $tokens,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before the audit trail (0) records the sign-in.
            LoginSuccessEvent::class => ['onLoginSuccess', 64],
            // Just after the firewall (8) has loaded the session's token.
            KernelEvents::REQUEST => ['onRequest', 7],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() !== self::FIREWALL || self::isCode($event->getAuthenticator()) || !$this->settings->isSecondStepRequired()) {
            return;
        }
        $request = $event->getRequest();
        $user = $event->getUser();
        // The password alone signs nobody in.
        $this->tokens->setToken(null);

        $problem = $user instanceof User ? null : 'This account cannot use two-step sign-in. Ask an administrator.';
        if ($problem === null && $this->settings->secondStepProblem() !== null) {
            $problem = 'Two-step sign-in is required, but email cannot be sent right now, so no code can reach you. Try again later, or ask an administrator.';
        }
        if ($problem === null && $user instanceof User) {
            $problem = $this->requests->startSecondStep($request, $user);
        }
        if ($problem !== null) {
            $request->attributes->set(self::OUTCOME_ATTRIBUTE, ['sign_in_failed', 'failed', 'The password was right, but the second step could not start: '.$problem]);
            $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, new CustomUserMessageAuthenticationException($problem));
            $event->setResponse(new RedirectResponse($this->urls->generate('app_login')));

            return;
        }

        // Keep where the password sign-in was going, for after the code.
        $target = $event->getResponse()?->headers->get('Location');
        if (is_string($target) && $target !== '') {
            $this->saveTargetPath($request->getSession(), self::FIREWALL, $target);
        }
        $request->attributes->set(self::OUTCOME_ATTRIBUTE, ['password_accepted', 'succeeded', 'The password was right; a code was emailed for the second step.']);
        $event->setResponse(new RedirectResponse($this->urls->generate('app_login_code')));
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $token = $this->tokens->getToken();
        if ($token === null || !$token->getUser() instanceof User || SignInCodeAuthenticator::completedSecondStep($token) || !$this->settings->isSecondStepRequired()) {
            return;
        }
        $this->tokens->setToken(null);
        $request = $event->getRequest();
        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->invalidate();
            if (method_exists($session, 'getFlashBag')) {
                $session->getFlashBag()->add('warning', 'Two-step sign-in is now required. Sign in again to get a code by email.');
            }
        }
        $event->setResponse(new RedirectResponse($this->urls->generate('app_login')));
    }

    private static function isCode(AuthenticatorInterface $authenticator): bool
    {
        if ($authenticator instanceof TraceableAuthenticator) {
            $authenticator = $authenticator->getAuthenticator();
        }

        return $authenticator instanceof SignInCodeAuthenticator;
    }
}
