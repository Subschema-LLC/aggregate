<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use App\Service\DropInScripts;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Sends one transactional message, now, through the configured provider, and
 * records it: every feature that sends email calls send(), so they all share
 * the provider, the branded templates and the record.
 *
 * Each send is a processing task (type email_send, subject the purpose) with
 * the provider's message ID as its external ID, which also writes its audit
 * entry. The records never hold the recipient's address, the message or a
 * code in it: a failure's details are redacted before they are stored, logged
 * or shown. The provider's message ID finds the message in its activity log.
 *
 * Messages go straight to the provider rather than through the Messenger
 * queue, so the task records the provider's real answer and a sign-in code
 * never waits in a queue table.
 */
class TransactionalMailer
{
    public const TASK_TYPE = 'email_send';

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly EmailSettings $settings,
        private readonly EmailSecrets $secrets,
        private readonly ProcessingTasks $tasks,
        private readonly Environment $twig,
        private readonly AppBranding $branding,
        private readonly AggregateConfigLoader $config,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'mailer.transport_factory')]
        private readonly Transport $transports,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? Clock::get();
    }

    /**
     * What stops email from being sent, in a sentence for an administrator;
     * null when a message can be sent. Being turned off is not a problem for
     * a test message (EmailPurpose::sendsWhileDisabled()).
     *
     * @param array<string, mixed>|null $settings validated settings; read when null
     */
    public function problem(?array $settings = null, bool $requireEnabled = true): ?string
    {
        try {
            $settings ??= $this->settings->toArray();
        } catch (\Throwable) {
            return 'The email settings in YAML or the environment are invalid. Correct them on the Email page or in config/aggregate.yaml.';
        }
        if ($requireEnabled && !EmailSettings::isEnabled($settings)) {
            return 'Email is turned off.';
        }
        $code = $settings[EmailSettings::KEY_PROVIDER];
        if ($code === '') {
            return 'No email service is chosen.';
        }
        if ($settings[EmailSettings::KEY_FROM_ADDRESS] === '') {
            return 'No sender address is set.';
        }
        $provider = $this->settings->providers()->get($code);
        try {
            $provider->validate($settings, true);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        $credential = $provider->credential();
        try {
            $value = $this->secrets->get($credential);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        if ($credential->required && $value === null) {
            return sprintf('The %s %s is not set.', $provider->label(), strtolower($credential->label));
        }

        return null;
    }

    /**
     * Sends one message to one address and records the attempt.
     *
     * @param array<string, mixed> $context variables for the purpose's templates
     */
    public function send(EmailPurpose $purpose, string $recipient, array $context, TaskTrigger $trigger): EmailResult
    {
        $taskId = $this->startTask($purpose, $trigger);
        $credential = null;
        $label = 'The email service';
        try {
            $settings = $this->settings->toArray();
        } catch (\Throwable $e) {
            return $this->fail($taskId, 'Not sent: the email settings are invalid. '.($e instanceof \InvalidArgumentException ? $e->getMessage() : 'Correct config/aggregate.yaml.'), $recipient, null);
        }
        try {
            $problem = $this->problem($settings, !$purpose->sendsWhileDisabled());
            if ($problem !== null) {
                return $this->fail($taskId, 'Not sent: '.$problem, $recipient, null);
            }
            $provider = $this->settings->providers()->get($settings[EmailSettings::KEY_PROVIDER]);
            $label = $provider->label();
            $credential = $this->secrets->get($provider->credential());

            $email = $this->message($purpose, $recipient, $context, $settings, $label, $taskId);
            $provider->prepare($email, $settings);
            $sent = $provider->transport($this->transports, $settings, $credential)->send($email);
            $messageId = $sent?->getMessageId();
        } catch (\Throwable $e) {
            $reason = self::redact($e->getMessage(), [$recipient, $credential]);
            $this->logger->warning('A transactional email was not sent.', [
                'purpose' => $purpose->value,
                'task' => $taskId,
                'error_type' => $e::class,
                'error' => $reason,
            ]);

            return $this->fail($taskId, sprintf('%s did not accept the message: %s', $label, $reason), $recipient, $credential);
        }

        // Mailgun's and SMTP servers' message IDs look like addresses, so only the given values are removed.
        $messageId = is_string($messageId) && $messageId !== '' ? self::redact($messageId, [$recipient, $credential], 191, false) : null;
        if ($taskId !== null) {
            try {
                $this->tasks->succeed($taskId, $this->clock->now(), 1, sprintf('Sent through %s.', $label), $messageId);
            } catch (\Throwable $e) {
                $this->logger->error('A sent email could not be recorded as a processing task.', ['task' => $taskId, 'error_type' => $e::class]);
            }
        }

        return EmailResult::sent($taskId, $messageId);
    }

    /**
     * Error text with anything that could identify the recipient or reveal a
     * credential removed: the given values (the recipient, the credential),
     * email addresses unless $addresses is false, control characters. Cut to
     * $maxLength characters.
     *
     * @param list<string|null> $values
     */
    public static function redact(string $text, array $values = [], int $maxLength = 500, bool $addresses = true): string
    {
        foreach ($values as $value) {
            if (is_string($value) && strlen($value) >= 4) {
                $text = str_ireplace($value, '[redacted]', $text);
            }
        }
        if ($addresses) {
            $text = (string) preg_replace('/[^\s<>()"\'\[\],;:@]+@(?:[\p{L}\p{N}-]+\.)+[\p{L}\p{N}-]+/u', '[address]', $text);
        }
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_scrub($text, 'UTF-8')));
        if ($text === '') {
            return 'No reason was given.';
        }

        return mb_strlen($text) > $maxLength ? rtrim(mb_substr($text, 0, $maxLength - 1)).'…' : $text;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $settings
     */
    private function message(EmailPurpose $purpose, string $recipient, array $context, array $settings, string $providerLabel, ?int $taskId): Email
    {
        $brand = $this->branding->getName();
        $variables = [
            'brand_name' => $brand,
            'app_url' => DropInScripts::normalizeAppHost($this->config->getWithEnvFallback('app_host', '')),
            'subject' => $purpose->subject($brand),
            'provider_label' => $providerLabel,
            'sent_at' => $this->clock->now(),
            ...$context,
        ];
        $fromName = $settings[EmailSettings::KEY_FROM_NAME] !== '' ? $settings[EmailSettings::KEY_FROM_NAME] : $brand;

        $email = (new Email())
            ->from(new Address($settings[EmailSettings::KEY_FROM_ADDRESS], $fromName))
            ->to(new Address($recipient))
            ->subject($variables['subject'])
            ->text($this->twig->render('email/'.$purpose->value.'.txt.twig', $variables))
            ->html($this->twig->render('email/'.$purpose->value.'.html.twig', $variables));
        if ($settings[EmailSettings::KEY_REPLY_TO] !== '') {
            $email->replyTo($settings[EmailSettings::KEY_REPLY_TO]);
        }
        $headers = $email->getHeaders();
        // RFC 3834: tells out-of-office responders not to answer.
        $headers->addTextHeader('Auto-Submitted', 'auto-generated');
        // Filed under the purpose by the provider (a Postmark tag, a SendGrid
        // category, a Mailgun tag); the task ID ties its events to the record.
        $headers->add(new TagHeader($purpose->value));
        if ($taskId !== null) {
            $headers->add(new MetadataHeader('aggregate_task', (string) $taskId));
        }

        return $email;
    }

    private function startTask(EmailPurpose $purpose, TaskTrigger $trigger): ?int
    {
        try {
            return $this->tasks->start(self::TASK_TYPE, $purpose->value, $trigger, $this->clock->now());
        } catch (\Throwable $e) {
            // The message still goes out; the application log says it was not recorded.
            $this->logger->error('An email send could not be recorded as a processing task.', ['purpose' => $purpose->value, 'error_type' => $e::class]);

            return null;
        }
    }

    private function fail(?int $taskId, string $details, string $recipient, #[\SensitiveParameter] ?string $credential): EmailResult
    {
        $details = self::redact($details, [$recipient, $credential]);
        if ($taskId !== null) {
            try {
                $this->tasks->fail($taskId, $this->clock->now(), $details);
            } catch (\Throwable $e) {
                $this->logger->error('A failed email send could not be recorded.', ['task' => $taskId, 'error_type' => $e::class]);
            }
        }

        return EmailResult::failed($taskId, $details);
    }
}
