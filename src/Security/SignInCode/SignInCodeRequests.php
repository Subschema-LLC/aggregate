<?php

declare(strict_types=1);

namespace App\Security\SignInCode;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Email\EmailPurpose;
use App\Service\Email\TransactionalMailer;
use App\Service\Operations\AdminAudit;
use App\Service\Operations\AuditTrailSettings;
use App\Service\Operations\TaskTrigger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Someone on the sign-in page asks for a code, by username or email
 * address. The answer is the same whether or not an account matches, and the
 * email is sent after the response (kernel.terminate), so neither the page
 * nor its timing says which accounts exist.
 *
 * Limits: sign_in_code_request per account and sign_in_code_request_address
 * per client address (config/packages/framework.yaml). Asking too often from
 * one address is the only thing the page reports; an account that asked too
 * often simply gets no new code until its window passes.
 *
 * Each request is recorded under sign_ins (when that area is on), naming the
 * account only when it exists; each send is a processing task (TaskTrigger
 * "request").
 */
class SignInCodeRequests implements EventSubscriberInterface, ResetInterface
{
    /** @var list<array{username: string, address: string, code: string, purpose: EmailPurpose}> */
    private array $outbox = [];

    public function __construct(
        private readonly SignInCodes $codes,
        private readonly UserRepository $users,
        private readonly TransactionalMailer $mailer,
        private readonly AdminAudit $audit,
        private readonly LoggerInterface $logger,
        #[Target('sign_in_code_request')]
        private readonly RateLimiterFactoryInterface $accountLimiter,
        #[Target('sign_in_code_request_address')]
        private readonly RateLimiterFactoryInterface $addressLimiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'deliver'];
    }

    /**
     * Handles one request for a code. False only when this client address
     * asked too often; nothing was issued then.
     */
    public function request(Request $request, string $identifier): bool
    {
        if (!$this->addressLimiter->create(hash('sha256', (string) $request->getClientIp()))->consume()->isAccepted()) {
            $this->record(false, null, 'Too many codes requested from one address.');

            return false;
        }
        $session = $request->getSession();
        $user = $this->find($identifier);
        if ($user === null) {
            $this->codes->issue($session, null);
            $this->record(false, null, 'No account with an email address matches what was entered.');

            return true;
        }
        $username = $user->getUserIdentifier();
        if (!$this->accountLimiter->create(hash('sha256', 'account:'.$username))->consume()->isAccepted()) {
            // Keep a code this browser already has; otherwise behave as for no account.
            if ($this->codes->pending($session) === null) {
                $this->codes->issue($session, null);
            }
            $this->record(false, $username, 'Too many codes requested for this account; none was sent.');

            return true;
        }

        $code = $this->codes->issue($session, $username);
        $this->outbox[] = ['username' => $username, 'address' => (string) $user->getEmail(), 'code' => (string) $code, 'purpose' => EmailPurpose::SignInCode];
        $this->record(true, $username, 'A sign-in code was requested.');

        return true;
    }

    /**
     * Starts the second step of a sign-in whose password was accepted: issues
     * a code for this browser and sends it after the response. Returns why
     * the sign-in cannot continue, or null. The account limit applies, so a
     * stolen password cannot be used to flood its owner's mailbox.
     */
    public function startSecondStep(Request $request, User $user): ?string
    {
        $address = $user->getEmail();
        if ($address === null) {
            return 'Two-step sign-in is required, and your account has no email address to send a code to. Ask an administrator to add one.';
        }
        $username = $user->getUserIdentifier();
        if (!$this->accountLimiter->create(hash('sha256', 'account:'.$username))->consume()->isAccepted()) {
            return 'Too many codes were sent for this account. Wait 15 minutes, then sign in again.';
        }
        $code = $this->codes->issue($request->getSession(), $username, SignInCodes::KIND_SECOND_STEP);
        $this->outbox[] = ['username' => $username, 'address' => $address, 'code' => (string) $code, 'purpose' => EmailPurpose::SecondStep];

        return null;
    }

    /** Sends the codes this request issued, after its response. */
    public function deliver(): void
    {
        $outbox = $this->outbox;
        $this->outbox = [];
        foreach ($outbox as $message) {
            try {
                $this->mailer->send($message['purpose'], $message['address'], [
                    'code' => $message['code'],
                    'minutes' => intdiv(SignInCodes::LIFETIME, 60),
                ], TaskTrigger::request($message['username']));
            } catch (\Throwable $e) {
                // TransactionalMailer records its own failures; this is for anything before it.
                $this->logger->error('A sign-in code could not be sent.', ['error_type' => $e::class]);
            }
        }
    }

    public function reset(): void
    {
        $this->outbox = [];
    }

    /**
     * The one account with this username, or with this email address
     * (ignoring case), that has an address to send to. An address several
     * accounts share matches none of them: those users sign in by username.
     */
    private function find(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '' || mb_strlen($identifier) > 254) {
            return null;
        }
        try {
            $user = str_contains($identifier, '@')
                ? $this->users->findOnlyByEmail($identifier)
                : $this->users->findOneBy(['username' => $identifier]);
        } catch (\Throwable $e) {
            $this->logger->error('An account could not be looked up for a sign-in code.', ['error_type' => $e::class]);

            return null;
        }

        return $user instanceof User && $user->getEmail() !== null ? $user : null;
    }

    private function record(bool $issued, ?string $username, string $details): void
    {
        $this->audit->record(AuditTrailSettings::SIGN_INS, 'sign_in_code_requested', $issued ? AdminAudit::SUCCEEDED : AdminAudit::FAILED, $username, null, $details);
    }
}
