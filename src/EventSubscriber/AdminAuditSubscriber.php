<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Repository\UserRepository;
use App\Security\SignInCode\SecondStepListener;
use App\Security\SignInCode\SignInCodeAuthenticator;
use App\Security\SignInCode\SignInCodes;
use App\Service\Operations\AdminActions;
use App\Service\Operations\AdminAudit;
use App\Service\Operations\AuditTrailSettings;
use App\Service\Operations\ConfigurationChanges;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/**
 * Records dashboard administrator actions and sign-ins in the audit trail.
 *
 * A request to a route AdminActions lists is recorded once its controller has
 * run, with the signed-in username, what was acted on, the settings it
 * changed (by name, from ConfigurationChanges) and its outcome: denied for a
 * 403 or a change DashboardAccessSubscriber sent back (REFUSED), failed for another error status or an error message on the page, and
 * succeeded otherwise. A request the firewall turned away (not signed in)
 * never reaches its controller and is not recorded, and neither is a signed-out
 * request to the installer that did not get past its setup code.
 *
 * Every request passes through here, collection requests included, so the
 * services that record are fetched from the locator only when an action runs.
 */
final class AdminAuditSubscriber implements EventSubscriberInterface, ServiceSubscriberInterface
{
    /** A controller sets this request attribute to name what it acted on: a user, website or group. */
    public const SUBJECT = '_audit_subject';
    /** A controller sets this to a short summary of what it did, such as the kind of account created. Never a submitted value. */
    public const DETAILS = '_audit_details';
    /** Set when a change was turned away without a 403, such as a view-only user's save sent back to the page. */
    public const REFUSED = '_audit_refused';
    private const ACTION = '_audit_action';
    private const ERRORS_BEFORE = '_audit_errors_before';
    /** The website or group a settings page saved, from its scope field. */
    private const SCOPE = '/^(?:deployment|group:[a-z0-9][a-z0-9-]{0,62}|website:[a-f0-9]{24})$/D';

    public function __construct(private readonly ContainerInterface $locator)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onController',
            // Before the session listener saves the session (-1000), so the flash bag can still be read.
            KernelEvents::RESPONSE => ['onResponse', -900],
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public static function getSubscribedServices(): array
    {
        return [
            AdminAudit::class => AdminAudit::class,
            AuditTrailSettings::class => AuditTrailSettings::class,
            TokenStorageInterface::class => '?'.TokenStorageInterface::class,
            UserRepository::class => '?'.UserRepository::class,
            LoggerInterface::class => '?'.LoggerInterface::class,
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        $action = is_string($route) ? AdminActions::forRoute($route, $request->getMethod()) : null;
        if ($action === null) {
            return;
        }
        $request->attributes->set(self::ACTION, $action);
        $request->attributes->set(self::ERRORS_BEFORE, self::errorCount($request));
        ConfigurationChanges::start();
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $action = $request->attributes->get(self::ACTION);
        if (!$event->isMainRequest() || !is_array($action)) {
            return;
        }
        $request->attributes->remove(self::ACTION);
        $changes = ConfigurationChanges::finish();
        try {
            $this->recordResponse($request, $event->getResponse(), $action, $changes);
        } catch (\Throwable $e) {
            // The action already happened; a problem recording it must not change the response.
            if ($this->locator->has(LoggerInterface::class)) {
                $this->locator->get(LoggerInterface::class)->warning('An administrator action could not be recorded in the audit trail.', ['exception' => $e]);
            }
        }
    }

    /**
     * @param array{area: string, action: string} $action
     * @param array<string, list<string>>         $changes
     */
    private function recordResponse(Request $request, Response $response, array $action, array $changes): void
    {
        $status = $response->getStatusCode();
        $outcome = match (true) {
            $status === 403, $request->attributes->get(self::REFUSED) === true => AdminAudit::DENIED,
            $status >= 400, self::errorCount($request) > (int) $request->attributes->get(self::ERRORS_BEFORE, 0) => AdminAudit::FAILED,
            default => AdminAudit::SUCCEEDED,
        };
        $actor = $this->username();
        $subject = $request->attributes->get(self::SUBJECT);
        // Only the installer runs signed out; it names its account once the setup
        // code is accepted, so anonymous requests cannot add entries.
        if ($actor === null && !is_string($subject)) {
            return;
        }
        if (!is_string($subject)) {
            // all() never throws for a non-scalar value, which get() would.
            $scope = $request->request->all()['scope'] ?? $request->query->all()['scope'] ?? null;
            $subject = is_string($scope) && preg_match(self::SCOPE, $scope) === 1 && $scope !== 'deployment' ? $scope : null;
        }
        $summary = $request->attributes->get(self::DETAILS);
        $details = implode(' ', array_filter([
            is_string($summary) ? $summary : null,
            ConfigurationChanges::describe($changes),
        ], static fn (?string $part): bool => $part !== null && $part !== ''));
        $this->audit()->record(
            $action['area'],
            $action['action'],
            $outcome,
            $actor,
            $subject,
            $details === '' ? null : $details,
            in_array($action['action'], AdminActions::ALWAYS, true),
        );
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $username = $event->getAuthenticatedToken()->getUserIdentifier();
        // A password that only started two-step sign-in (SecondStepListener).
        $outcome = $event->getRequest()->attributes->get(SecondStepListener::OUTCOME_ATTRIBUTE);
        if (is_array($outcome) && count($outcome) === 3) {
            [$action, $result, $details] = $outcome;
            $this->audit()->record(AuditTrailSettings::SIGN_INS, (string) $action, (string) $result, $username, null, (string) $details);

            return;
        }
        $details = null;
        if (self::isSignInCode($event->getAuthenticator())) {
            $details = $event->getPassport()->getAttribute(SignInCodeAuthenticator::KIND_ATTRIBUTE) === SignInCodes::KIND_SECOND_STEP
                ? 'With a password and an emailed code (two-step sign-in).'
                : 'With an emailed sign-in code.';
        }
        $this->audit()->record(AuditTrailSettings::SIGN_INS, 'signed_in', AdminAudit::SUCCEEDED, $username, null, $details);
    }

    /**
     * Names the account only when it exists: what someone types into the
     * username field can be anything, a password included.
     */
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (!$this->locator->get(AuditTrailSettings::class)->isRecorded(AuditTrailSettings::SIGN_INS)) {
            return;
        }
        $badge = $event->getPassport()?->hasBadge(UserBadge::class) ? $event->getPassport()->getBadge(UserBadge::class) : null;
        $username = $badge instanceof UserBadge ? $badge->getUserIdentifier() : '';
        $known = false;
        if ($username !== '' && $this->locator->has(UserRepository::class)) {
            try {
                $known = $this->locator->get(UserRepository::class)->findOneBy(['username' => $username]) !== null;
            } catch (\Throwable) {
                $known = false;
            }
        }
        $withCode = self::isSignInCode($event->getAuthenticator());
        $details = match (true) {
            $event->getException() instanceof TooManyLoginAttemptsAuthenticationException => 'Too many attempts; sign-ins are paused for a minute.',
            $withCode && $known && $event->getPassport()?->getAttribute(SignInCodeAuthenticator::KIND_ATTRIBUTE) === SignInCodes::KIND_SECOND_STEP => 'Wrong or expired two-step sign-in code.',
            $withCode && $known => 'Wrong or expired sign-in code.',
            $withCode => 'A sign-in code was entered with no code pending for an account.',
            $known => 'Wrong password.',
            default => 'No account has the username entered.',
        };
        $this->audit()->record(AuditTrailSettings::SIGN_INS, 'sign_in_failed', AdminAudit::FAILED, $known ? $username : null, null, $details);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user !== null) {
            $this->audit()->record(AuditTrailSettings::SIGN_INS, 'signed_out', AdminAudit::SUCCEEDED, $user->getUserIdentifier());
        }
    }

    /** Whether an emailed code signed in, through the debug wrapper the dev and test environments add. */
    private static function isSignInCode(AuthenticatorInterface $authenticator): bool
    {
        if ($authenticator instanceof TraceableAuthenticator) {
            $authenticator = $authenticator->getAuthenticator();
        }

        return $authenticator instanceof SignInCodeAuthenticator;
    }

    private function audit(): AdminAudit
    {
        return $this->locator->get(AdminAudit::class);
    }

    private function username(): ?string
    {
        if (!$this->locator->has(TokenStorageInterface::class)) {
            return null;
        }

        return $this->locator->get(TokenStorageInterface::class)->getToken()?->getUserIdentifier();
    }

    /** Error messages waiting to be shown, which the dashboard's forms add when an action fails. */
    private static function errorCount(Request $request): int
    {
        if (!$request->hasSession()) {
            return 0;
        }
        $session = $request->getSession();

        return $session instanceof FlashBagAwareSessionInterface ? count($session->getFlashBag()->peek('error')) : 0;
    }
}
