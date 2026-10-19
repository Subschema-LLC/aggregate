<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\SignInCode\SignInCodeSettings;
use App\Service\AggregateConfigLoader;
use App\Service\Email\EmailPurpose;
use App\Service\Email\EmailSecrets;
use App\Service\Email\EmailSettings;
use App\Service\Email\TransactionalMailer;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration → Email: the service that sends transactional email, the
 * sender, each service's credential and a test message to the
 * administrator's own address. Administrators only: a credential can send
 * email as the organization.
 */
final class EmailSettingsController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_SETTINGS = 'email_settings';
    public const CSRF_TEST = 'email_test';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly EmailSettings $settings,
        private readonly EmailSecrets $secrets,
        private readonly TransactionalMailer $mailer,
        private readonly ProcessingTasks $tasks,
        private readonly SignInCodeSettings $signInCodes,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/email', name: 'app_email', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessPermitted();

        $configurationError = null;
        try {
            $settings = $this->settings->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('The email settings could not be read.', ['error_type' => $e::class]);
            $settings = $this->settings->validate([]);
            $configurationError = 'The email settings in YAML or the environment are invalid'.($e instanceof \InvalidArgumentException ? ': '.$e->getMessage() : '.').' Saving this form replaces the YAML values.';
        }

        $secretsError = null;
        try {
            $this->secrets->read();
        } catch (\RuntimeException $e) {
            $secretsError = $e->getMessage().' Correct or remove the file; until then only environment variables provide credentials.';
        }

        $credentials = [];
        foreach ($this->settings->providers()->all() as $code => $provider) {
            $credential = $provider->credential();
            $credentials[$code] = [
                'key' => $credential->key,
                'label' => $credential->label,
                'help' => $credential->help,
                'required' => $credential->required,
                'variable' => $credential->environmentVariable,
                'source' => $this->secrets->source($credential),
            ];
        }

        try {
            $latest = $this->tasks->latest(TransactionalMailer::TASK_TYPE);
        } catch (\Throwable $e) {
            $this->logger->error('The last email sends could not be read.', ['error_type' => $e::class]);
            $latest = [];
        }
        $sends = [];
        foreach (EmailPurpose::cases() as $purpose) {
            if (isset($latest[$purpose->value])) {
                $sends[] = ['label' => $purpose->label(), ...$latest[$purpose->value]];
            }
        }

        $user = $this->getUser();
        try {
            $signInCodes = $this->signInCodes->isEnabled();
        } catch (\Throwable) {
            $signInCodes = false;
        }

        return $this->render('email_settings/index.html.twig', [
            'sign_in_codes' => $signInCodes,
            'sign_in_codes_override' => $this->signInCodes->isEnvironmentControlled(),
            'settings' => $settings,
            'overrides' => $this->settings->environmentOverrides(),
            'providers' => $this->settings->providers()->all(),
            'credentials' => $credentials,
            'configuration_error' => $configurationError,
            'secrets_error' => $secretsError,
            'problem' => $configurationError === null ? $this->mailer->problem($settings) : null,
            'test_problem' => $configurationError === null ? $this->mailer->problem($settings, false) : $configurationError,
            'sends' => $sends,
            'test_address' => $user instanceof User ? $user->getEmail() : null,
        ]);
    }

    #[Route('/dashboard/email/save', name: 'app_email_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $submitted = $request->request->all();
        if (!$this->isCsrfTokenValid(self::CSRF_SETTINGS, (string) ($submitted['_csrf_token'] ?? ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_email');
        }
        unset($submitted['_csrf_token']);

        // Credentials arrive as credential[<key>] (a new value) and remove[<key>].
        $newCredentials = $submitted['credential'] ?? [];
        $removals = $submitted['remove'] ?? [];
        $signInCodes = $submitted[SignInCodeSettings::KEY] ?? null;
        unset($submitted['credential'], $submitted['remove'], $submitted[SignInCodeSettings::KEY]);
        if (!is_array($newCredentials) || !is_array($removals)) {
            $this->addFlash('error', 'The email form was not valid. Nothing was saved.');

            return $this->redirectToRoute('app_email');
        }

        $candidate = [];
        foreach ($submitted as $key => $value) {
            if (!is_scalar($value)) {
                $this->addFlash('error', 'The email form contained an unexpected setting. Nothing was saved.');

                return $this->redirectToRoute('app_email');
            }
            $candidate[(string) $key] = $value;
        }

        $changes = [];
        foreach ($this->settings->providers()->all() as $provider) {
            $credential = $provider->credential();
            $value = $newCredentials[$credential->key] ?? '';
            $remove = ($removals[$credential->key] ?? '') === '1';
            unset($newCredentials[$credential->key], $removals[$credential->key]);
            if (!is_string($value)) {
                $this->addFlash('error', 'The email form was not valid. Nothing was saved.');

                return $this->redirectToRoute('app_email');
            }
            if (trim($value) !== '') {
                $changes[] = [$credential, trim($value)];
            } elseif ($remove) {
                $changes[] = [$credential, null];
            }
        }
        if ($newCredentials !== [] || $removals !== []) {
            $this->addFlash('error', 'The email form named an unknown credential. Nothing was saved.');

            return $this->redirectToRoute('app_email');
        }

        try {
            $current = $this->settings->toArray();
        } catch (\Throwable) {
            $current = $this->settings->validate([]);
        }
        try {
            $this->settings->validate([...$current, ...$candidate]);
            if ($signInCodes !== null) {
                SignInCodeSettings::validate($signInCodes);
            }
            foreach ($changes as [$credential, $value]) {
                if ($this->secrets->isEnvironmentControlled($credential)) {
                    throw new \InvalidArgumentException(sprintf('The %s is set by %s and cannot be changed here.', strtolower($credential->label), $credential->environmentVariable));
                }
                if ($value !== null && (strlen($value) > EmailSecrets::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)) {
                    throw new \InvalidArgumentException(sprintf('The %s must be one line of at most %d characters.', strtolower($credential->label), EmailSecrets::MAX_LENGTH));
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage().' Nothing was saved.');

            return $this->redirectToRoute('app_email');
        }

        try {
            foreach ($changes as [$credential, $value]) {
                $value === null ? $this->secrets->remove($credential) : $this->secrets->set($credential, $value);
            }
            $saved = $this->settings->save($candidate);
            $codesOn = $signInCodes !== null ? $this->signInCodes->save($signInCodes) : $this->signInCodes->isEnabled();
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_email');
        } catch (\Throwable $e) {
            $this->logger->error('The email settings could not be saved.', ['error_type' => $e::class]);
            $this->addFlash('error', $e instanceof \RuntimeException && str_contains($e->getMessage(), 'config/') ? $e->getMessage() : 'The email settings could not be saved. Check the application log and the permissions of the config folder.');

            return $this->redirectToRoute('app_email');
        }

        $this->addFlash('success', 'Email settings were saved.');
        $problem = $this->mailer->problem($saved);
        if (EmailSettings::isEnabled($saved) && $problem !== null) {
            $this->addFlash('warning', 'Email is on, but nothing can be sent yet: '.$problem);
        } elseif ($codesOn && $problem !== null) {
            $this->addFlash('warning', 'Sign-in codes are on, but the sign-in page will not offer them until email can be sent: '.$problem);
        }

        return $this->redirectToRoute('app_email');
    }

    #[Route('/dashboard/email/test', name: 'app_email_test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        $this->denyUnlessPermitted();
        if (!$this->isCsrfTokenValid(self::CSRF_TEST, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_email');
        }
        $user = $this->getUser();
        $address = $user instanceof User ? $user->getEmail() : null;
        if ($user === null || $address === null) {
            $this->addFlash('error', 'Add an email address to your profile first; the test message goes to your own address.');

            return $this->redirectToRoute('app_email');
        }

        $result = $this->mailer->send(EmailPurpose::Test, $address, [], TaskTrigger::dashboard($user->getUserIdentifier()));
        if ($result->sent) {
            $this->addFlash('success', 'A test email was sent to your address.'.($result->messageId !== null ? ' The service’s message ID is '.$result->messageId.'.' : ''));
        } else {
            $this->addFlash('error', (string) $result->error);
        }

        return $this->redirectToRoute('app_email');
    }
}
