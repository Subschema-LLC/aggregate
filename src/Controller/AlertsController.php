<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertEvaluator;
use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSecrets;
use App\Service\Alerts\AlertSettings;
use App\Service\Email\TransactionalMailer;
use App\Service\FeatureFlags;
use App\Service\Operations\TaskTrigger;
use App\Service\ReportingTimezone;
use App\Service\Scheduler\Scheduler;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration → Alerts: threshold rules on completed UTC hours, webhook and
 * email destinations, webhook signing secrets, test alerts, and the 20 most
 * recent alert deliveries. Administrators only in v1 (DashboardPages::ADMIN).
 */
final class AlertsController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_RULE = 'alerts_rule';
    public const CSRF_DESTINATION = 'alerts_destination';
    public const CSRF_SECRET = 'alerts_secret';
    public const CSRF_TEST = 'alerts_test';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AlertSettings $settings,
        private readonly AlertSecrets $secrets,
        private readonly AlertEvaluator $evaluator,
        private readonly TransactionalMailer $mailer,
        private readonly Scheduler $scheduler,
        private readonly WebsiteConfigManager $websites,
        private readonly WebsiteScopeCatalog $scopes,
        private readonly ReportingTimezone $reportingTimezone,
        private readonly FeatureFlags $features,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/alerts', name: 'app_alerts', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessPermitted();

        return $this->renderIndex($request);
    }

    #[Route('/dashboard/alerts/rules/new', name: 'app_alerts_rule_new', methods: ['GET'])]
    public function newRule(): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        return $this->renderRuleForm(null, false);
    }

    #[Route('/dashboard/alerts/rules/{id}/edit', name: 'app_alerts_rule_edit', requirements: ['id' => '[a-z0-9][a-z0-9_-]{0,62}'], methods: ['GET'])]
    public function editRule(string $id): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        $rule = $this->settings->rule($id);
        if ($rule === null) {
            throw $this->createNotFoundException(sprintf('Alert rule "%s" does not exist.', $id));
        }

        return $this->renderRuleForm($rule, true);
    }

    #[Route('/dashboard/alerts/rules/save', name: 'app_alerts_rule_save', methods: ['POST'])]
    public function saveRule(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        $submitted = $request->request->all();
        $isEditing = ($submitted['_editing'] ?? '') === '1';
        if (!$this->isCsrfTokenValid(self::CSRF_RULE, (string) ($submitted['_csrf_token'] ?? ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_alerts');
        }

        $source = strtolower(trim((string) ($submitted['source'] ?? AlertRuleValidator::SOURCE_EVENTS)));
        $condition = (string) ($submitted['condition'] ?? ($source === AlertRuleValidator::SOURCE_TRACKING_FAILURES ? AlertRuleValidator::CONDITION_MORE_THAN : AlertRuleValidator::CONDITION_FEWER_THAN));
        if ($source === AlertRuleValidator::SOURCE_TRACKING_FAILURES) {
            $condition = AlertRuleValidator::CONDITION_MORE_THAN;
        }
        $destinations = $submitted['destinations'] ?? [];
        if (!is_array($destinations)) {
            $destinations = [];
        }

        $candidate = [
            'id' => trim((string) ($submitted['id'] ?? '')),
            'enabled' => ($submitted['enabled'] ?? '1') === '1',
            'covers' => trim((string) ($submitted['covers'] ?? '')),
            'source' => $source,
            'privacy_mode' => $source === AlertRuleValidator::SOURCE_EVENTS ? (string) ($submitted['privacy_mode'] ?? AlertRuleValidator::PRIVACY_ALL) : AlertRuleValidator::PRIVACY_ALL,
            'event_name' => $source === AlertRuleValidator::SOURCE_EVENTS ? trim((string) ($submitted['event_name'] ?? '')) : '',
            'condition' => $condition,
            'threshold' => $condition === AlertRuleValidator::CONDITION_NO_EVENTS ? 0 : trim((string) ($submitted['threshold'] ?? '')),
            'window' => trim((string) ($submitted['window'] ?? AlertRuleValidator::WINDOW_DAY)),
            'destinations' => array_values(array_filter(array_map('strval', $destinations), static fn (string $v): bool => trim($v) !== '')),
        ];

        try {
            if (!$isEditing && $candidate['id'] !== '' && $this->settings->rule($candidate['id']) !== null) {
                throw new \InvalidArgumentException(sprintf('Alert rule "%s" already exists. Choose another Rule ID or edit the existing rule.', $candidate['id']));
            }
            $saved = $this->settings->saveRule($candidate);
            $this->addFlash('success', sprintf('Saved alert rule "%s".', $saved['id']));

            return $this->redirectToRoute('app_alerts');
        } catch (\InvalidArgumentException $e) {
            return $this->renderRuleForm(
                $candidate,
                $isEditing,
                [['field' => self::fieldForRuleError($e->getMessage()), 'message' => $e->getMessage()]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Alert rule could not be saved.', ['exception_class' => $e::class]);
            $this->addFlash('error', 'The alert rule could not be saved. Check config/aggregate.yaml and its file permissions.');

            return $this->redirectToRoute('app_alerts');
        }
    }

    #[Route('/dashboard/alerts/rules/{id}/delete', name: 'app_alerts_rule_delete_confirm', requirements: ['id' => '[a-z0-9][a-z0-9_-]{0,62}'], methods: ['GET'])]
    public function confirmDeleteRule(string $id): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        $rule = $this->settings->rule($id);
        if ($rule === null) {
            throw $this->createNotFoundException(sprintf('Alert rule "%s" does not exist.', $id));
        }

        return $this->renderDashboardPage('alerts/rule_delete.html.twig', [
            'rule' => $rule,
            'covers_label' => $this->settings->describeCovers($rule),
            'condition_label' => AlertSettings::describeCondition($rule),
        ]);
    }

    #[Route('/dashboard/alerts/rules/{id}/delete', name: 'app_alerts_rule_delete', requirements: ['id' => '[a-z0-9][a-z0-9_-]{0,62}'], methods: ['POST'])]
    public function deleteRule(Request $request, string $id): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        if (!$this->isCsrfTokenValid(self::CSRF_RULE, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_alerts');
        }

        try {
            $this->settings->deleteRule($id);
            $this->addFlash('success', sprintf('Deleted alert rule "%s".', $id));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Alert rule could not be deleted.', ['exception_class' => $e::class]);
            $this->addFlash('error', 'The alert rule could not be deleted.');
        }

        return $this->redirectToRoute('app_alerts');
    }

    #[Route('/dashboard/alerts/destinations/new', name: 'app_alerts_destination_new', methods: ['GET'])]
    public function newDestination(): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        return $this->renderDestinationForm(null, false);
    }

    #[Route('/dashboard/alerts/destinations/{id}/edit', name: 'app_alerts_destination_edit', requirements: ['id' => '[a-z0-9][a-z0-9_-]{0,62}'], methods: ['GET'])]
    public function editDestination(string $id): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        $destination = $this->settings->destination($id);
        if ($destination === null) {
            throw $this->createNotFoundException(sprintf('Alert destination "%s" does not exist.', $id));
        }

        return $this->renderDestinationForm($destination, true);
    }

    #[Route('/dashboard/alerts/destinations/save', name: 'app_alerts_destination_save', methods: ['POST'])]
    public function saveDestination(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        $submitted = $request->request->all();
        $isEditing = ($submitted['_editing'] ?? '') === '1';
        if (!$this->isCsrfTokenValid(self::CSRF_DESTINATION, (string) ($submitted['_csrf_token'] ?? ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_alerts');
        }

        $id = trim((string) ($submitted['id'] ?? ''));
        $label = trim((string) ($submitted['label'] ?? ''));
        if ($id === '' && $label !== '') {
            $id = AlertRuleValidator::slugify($label);
        }
        $type = strtolower(trim((string) ($submitted['type'] ?? AlertRuleValidator::TYPE_WEBHOOK)));
        $existing = $isEditing && $id !== '' ? $this->settings->destination($id) : null;

        $candidate = [
            'id' => $id,
            'label' => $label,
            'type' => $type,
        ];
        if ($type === AlertRuleValidator::TYPE_WEBHOOK) {
            $url = trim((string) ($submitted['url'] ?? ''));
            if ($url === '' && $existing !== null && ($existing['type'] ?? '') === AlertRuleValidator::TYPE_WEBHOOK) {
                $url = (string) ($existing['url'] ?? '');
            }
            $candidate['url'] = $url;
        } else {
            $recipientsInput = trim((string) ($submitted['recipients'] ?? ''));
            if ($recipientsInput === '' && $existing !== null && ($existing['type'] ?? '') === AlertRuleValidator::TYPE_EMAIL) {
                $candidate['recipients'] = $existing['recipients'] ?? [];
            } else {
                $candidate['recipients'] = $recipientsInput;
            }
        }

        $secretValue = trim((string) ($submitted['secret'] ?? ''));

        try {
            if (!$isEditing && $id !== '' && $this->settings->destination($id) !== null) {
                throw new \InvalidArgumentException(sprintf('Alert destination "%s" already exists.', $id));
            }
            $saved = $this->settings->saveDestination($candidate);
            if ($type === AlertRuleValidator::TYPE_WEBHOOK && $secretValue !== '') {
                $this->secrets->set($secretValue, $saved['id']);
            }
            $this->addFlash('success', sprintf('Saved destination "%s".', $saved['label']));

            return $this->redirectToRoute('app_alerts');
        } catch (\InvalidArgumentException $e) {
            // Never echo submitted URL or recipients back into the form on error if editing; keep redacted display.
            $formView = [
                'id' => $id,
                'label' => $label,
                'type' => $type,
                'host' => $existing['host'] ?? null,
                'recipients' => $existing['recipients'] ?? [],
            ];

            return $this->renderDestinationForm(
                $formView,
                $isEditing,
                [['field' => self::fieldForDestinationError($e->getMessage()), 'message' => $e->getMessage()]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Alert destination could not be saved.', ['exception_class' => $e::class]);
            $this->addFlash('error', 'The destination could not be saved. Check config/aggregate.yaml and config/secrets permissions.');

            return $this->redirectToRoute('app_alerts');
        }
    }

    #[Route('/dashboard/alerts/destinations/{id}/delete', name: 'app_alerts_destination_delete_confirm', requirements: ['id' => '[a-z0-9][a-z0-9_-]{0,62}'], methods: ['GET'])]
    public function confirmDeleteDestination(string $id): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        $destination = $this->settings->destination($id);
        if ($destination === null) {
            throw $this->createNotFoundException(sprintf('Alert destination "%s" does not exist.', $id));
        }

        return $this->renderDashboardPage('alerts/destination_delete.html.twig', [
            'destination' => [
                'id' => $destination['id'],
                'label' => $destination['label'],
                'type' => $destination['type'],
                'target' => $destination['type'] === AlertRuleValidator::TYPE_WEBHOOK
                    ? ($destination['host'] ?? '')
                    : sprintf('%d listed recipient%s', count($destination['recipients'] ?? []), count($destination['recipients'] ?? []) === 1 ? '' : 's'),
            ],
        ]);
    }

    #[Route('/dashboard/alerts/destinations/{id}/delete', name: 'app_alerts_destination_delete', requirements: ['id' => '[a-z0-9][a-z0-9_-]{0,62}'], methods: ['POST'])]
    public function deleteDestination(Request $request, string $id): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        if (!$this->isCsrfTokenValid(self::CSRF_DESTINATION, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_alerts');
        }

        try {
            $this->settings->deleteDestination($id);
            $this->secrets->remove($id);
            $this->addFlash('success', sprintf('Deleted destination "%s".', $id));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Alert destination could not be deleted.', ['exception_class' => $e::class]);
            $this->addFlash('error', 'The destination could not be deleted.');
        }

        return $this->redirectToRoute('app_alerts');
    }

    #[Route('/dashboard/alerts/secret', name: 'app_alerts_secret_save', methods: ['POST'])]
    public function saveSecret(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        if (!$this->isCsrfTokenValid(self::CSRF_SECRET, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_alerts');
        }

        $action = (string) $request->request->get('secret_action', 'save');
        $destinationId = trim((string) $request->request->get('destination_id', ''));
        $targetId = $destinationId !== '' ? $destinationId : null;

        try {
            if ($this->secrets->isEnvironmentControlled($targetId)) {
                throw new \InvalidArgumentException('The webhook signing secret is set in the environment and cannot be changed here.');
            }
            if ($action === 'generate') {
                $generated = $this->secrets->generate($targetId);

                return $this->renderIndex($request, ['generated_secret' => $generated]);
            }
            if ($action === 'remove') {
                $this->secrets->remove($targetId);
                $this->addFlash('success', 'Removed the saved webhook signing secret.');

                return $this->redirectToRoute('app_alerts');
            }
            $value = trim((string) $request->request->get('webhook_secret', ''));
            if ($value === '') {
                $this->addFlash('success', 'Kept the existing webhook signing secret.');

                return $this->redirectToRoute('app_alerts');
            }
            $this->secrets->set($value, $targetId);
            $this->addFlash('success', 'Saved the webhook signing secret in '.AlertSecrets::FILE.'.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Webhook signing secret could not be saved.', ['exception_class' => $e::class]);
            $this->addFlash('error', 'The webhook signing secret could not be saved. Check permissions on config/secrets.');
        }

        return $this->redirectToRoute('app_alerts');
    }

    #[Route('/dashboard/alerts/test', name: 'app_alerts_test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $this->denyUnlessEnabled();

        if (!$this->isCsrfTokenValid(self::CSRF_TEST, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_alerts');
        }

        $destinationId = trim((string) $request->request->get('destination', ''));
        $recipientIndex = trim((string) $request->request->get('recipient_index', '__all__'));
        $recipientAddress = null;
        if ($recipientIndex !== '' && $recipientIndex !== '__all__' && ctype_digit($recipientIndex)) {
            $dest = $this->settings->destination($destinationId);
            $listed = $dest['recipients'] ?? [];
            $idx = (int) $recipientIndex;
            $recipientAddress = $listed[$idx] ?? '__invalid__';
        } elseif ($request->request->has('recipient')) {
            $rawRecipient = trim((string) $request->request->get('recipient', ''));
            if ($rawRecipient !== '' && $rawRecipient !== '__all__') {
                $recipientAddress = $rawRecipient;
            }
        }

        $actor = $this->getUser()?->getUserIdentifier() ?? 'administrator';

        try {
            $result = $this->evaluator->sendTest(
                $destinationId,
                TaskTrigger::dashboard($actor),
                $recipientAddress,
            );

            return $this->renderIndex($request, [
                'test_result' => [
                    'sent' => $result['sent'],
                    'destination_id' => $destinationId,
                    'destination_label' => $result['destination_label'],
                    'message' => $result['sent']
                        ? sprintf('Test sent to %s. Recorded in Recent alerts; rule status did not change.', $result['destination_label'])
                        : sprintf('Failed: %s', $result['reason']),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->renderIndex($request, [
                'test_result' => [
                    'sent' => false,
                    'destination_id' => $destinationId,
                    'destination_label' => $destinationId,
                    'message' => 'Failed: '.$e->getMessage(),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('Test alert failed.', ['exception_class' => $e::class]);

            return $this->renderIndex($request, [
                'test_result' => [
                    'sent' => false,
                    'destination_id' => $destinationId,
                    'destination_label' => $destinationId,
                    'message' => 'Failed: the test alert could not be sent.',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function renderIndex(Request $request, array $extra = [], int $status = Response::HTTP_OK): Response
    {
        $featureEnabled = $this->features->isEnabled('alerts');
        if (!$featureEnabled) {
            $response = $this->renderDashboardPage('alerts/index.html.twig', [
                'feature_enabled' => false,
            ]);
            $response->setStatusCode($status);

            return $response;
        }

        $configurationError = null;
        try {
            $configured = $this->settings->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('The alert settings could not be read.', ['exception_class' => $e::class]);
            $configured = ['destinations' => [], 'rules' => []];
            $configurationError = 'The alert settings in YAML are invalid'.($e instanceof \InvalidArgumentException ? ': '.$e->getMessage() : '.').' Correct config/aggregate.yaml or save a rule to replace them.';
        }

        $secretsError = null;
        try {
            $this->secrets->read();
        } catch (\RuntimeException $e) {
            $secretsError = $e->getMessage();
        }

        $statuses = $this->evaluator->statuses();
        $recent = $this->evaluator->recentAlerts(AlertEvaluator::RECENT_LIMIT);
        $emailProblem = $this->mailer->problem();
        $schedulerHealth = $this->scheduler->health();
        $schedulerLate = in_array($schedulerHealth['state'] ?? 'never', ['late', 'never'], true);

        $destinationsView = [];
        $hasEmailDestination = false;
        foreach ($configured['destinations'] as $id => $dest) {
            if ($dest['type'] === AlertRuleValidator::TYPE_WEBHOOK) {
                $secretSource = $this->secrets->source($id);
                $destinationsView[$id] = [
                    'id' => $id,
                    'label' => $dest['label'],
                    'type' => 'webhook',
                    'target' => $dest['host'] ?? '',
                    'signing_label' => 'HMAC-SHA256 in '.AlertSecrets::FILE,
                    'secret_source' => $secretSource,
                    'test_problem' => $secretSource === null ? 'Set or generate a webhook signing secret before sending a test.' : null,
                    'recipient_options' => [],
                ];
            } else {
                $hasEmailDestination = true;
                $recipients = $dest['recipients'] ?? [];
                $count = count($recipients);
                $options = [];
                foreach (array_values($recipients) as $idx => $addr) {
                    $options[] = [
                        'index' => (string) $idx,
                        'label' => self::maskEmailForConfirmation($addr, $idx + 1),
                    ];
                }
                $destinationsView[$id] = [
                    'id' => $id,
                    'label' => $dest['label'],
                    'type' => 'email',
                    'target' => sprintf('%d listed recipient%s', $count, $count === 1 ? '' : 's'),
                    'signing_label' => 'TransactionalMailer (alert)',
                    'secret_source' => null,
                    'test_problem' => $emailProblem,
                    'recipient_options' => $options,
                    'recipient_count' => $count,
                ];
            }
        }

        $rulesView = [];
        foreach ($configured['rules'] as $id => $rule) {
            $destLabels = [];
            foreach ($rule['destinations'] as $destId) {
                $destLabels[] = $configured['destinations'][$destId]['label'] ?? $destId;
            }
            $ruleStatus = $statuses['rules'][$id] ?? [
                'state' => AlertEvaluator::STATE_WAITING,
                'label' => 'Waiting for the first full hour',
                'since' => null,
                'since_formatted' => null,
            ];
            $rulesView[] = [
                'id' => $id,
                'enabled' => $rule['enabled'],
                'covers_label' => $this->settings->describeCovers($rule),
                'condition_label' => AlertSettings::describeCondition($rule),
                'destinations_label' => implode(', ', $destLabels),
                'status' => $ruleStatus,
            ];
        }

        $confirmDestinationId = trim((string) $request->query->get('test', ''));
        $confirmDestination = $confirmDestinationId !== '' ? ($destinationsView[$confirmDestinationId] ?? null) : null;

        $response = $this->renderDashboardPage('alerts/index.html.twig', [
            'feature_enabled' => true,
            'configuration_error' => $configurationError,
            'secrets_error' => $secretsError,
            'rules' => $rulesView,
            'destinations' => array_values($destinationsView),
            'last_check' => $statuses['last_check'],
            'last_window_end' => $statuses['last_window_end'],
            'firing_count' => $statuses['firing_count'],
            'scheduler_late' => $schedulerLate,
            'scheduler_state' => $schedulerHealth['state'] ?? 'never',
            'email_problem' => $hasEmailDestination ? $emailProblem : null,
            'secret_source' => $this->secrets->source(),
            'secret_env_controlled' => $this->secrets->isEnvironmentControlled(),
            'confirm_destination' => $confirmDestination,
            'recent_alerts' => $recent['entries'],
            'older_removed' => $recent['older_removed'],
            'generated_secret' => $extra['generated_secret'] ?? null,
            'test_result' => $extra['test_result'] ?? null,
        ]);
        $response->setStatusCode($status);

        return $response;
    }

    /**
     * @param array<string, mixed>|null                      $rule
     * @param list<array{field: string, message: string}>    $errors
     */
    private function renderRuleForm(?array $rule, bool $isEditing, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        $configured = ['destinations' => [], 'rules' => []];
        try {
            $configured = $this->settings->toArray();
        } catch (\Throwable) {
        }

        $defaults = [
            'id' => '',
            'enabled' => true,
            'covers' => 'all',
            'source' => AlertRuleValidator::SOURCE_EVENTS,
            'privacy_mode' => AlertRuleValidator::PRIVACY_ALL,
            'event_name' => '',
            'condition' => AlertRuleValidator::CONDITION_FEWER_THAN,
            'threshold' => 5,
            'window' => AlertRuleValidator::WINDOW_DAY,
            'destinations' => [],
        ];
        $values = array_replace($defaults, $rule ?? []);

        $coversChoices = [];
        foreach ($this->websites->getWebsites() as $site) {
            $token = (string) ($site['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $domain = AlertSettings::bareHost((string) ($site['domain'] ?? ''));
            $coversChoices[] = [
                'value' => 'website:'.$token,
                'label' => sprintf('Website: %s%s', $site['name'] ?? 'Unnamed', $domain !== '' ? ' ('.$domain.')' : ''),
            ];
        }
        foreach ($this->scopes->groups() as $groupId => $group) {
            $count = count($group['members']);
            $coversChoices[] = [
                'value' => 'group:'.$groupId,
                'label' => sprintf('Group: %s (%d website%s)', $group['name'], $count, $count === 1 ? '' : 's'),
            ];
        }
        $coversChoices[] = [
            'value' => 'all',
            'label' => 'All active websites',
        ];

        $destinationsView = [];
        foreach ($configured['destinations'] as $id => $dest) {
            $destinationsView[] = [
                'id' => $id,
                'label' => $dest['label'],
                'type' => $dest['type'],
                'target' => $dest['type'] === AlertRuleValidator::TYPE_WEBHOOK
                    ? ($dest['host'] ?? '')
                    : sprintf('%d recipient%s', count($dest['recipients'] ?? []), count($dest['recipients'] ?? []) === 1 ? '' : 's'),
            ];
        }

        $tzName = 'UTC';
        try {
            $tzName = $this->reportingTimezone->name();
        } catch (\Throwable) {
        }

        $response = $this->renderDashboardPage('alerts/rule_form.html.twig', [
            'rule' => $values,
            'is_editing' => $isEditing,
            'errors' => $errors,
            'covers_choices' => $coversChoices,
            'destinations' => $destinationsView,
            'reporting_timezone' => $tzName,
            'non_utc_timezone' => $tzName !== 'UTC',
            'summary_text' => $this->buildSummaryText($values),
            'alert_condition_preview' => $this->previewAlertCondition($values),
        ]);
        $response->setStatusCode($status);

        return $response;
    }

    /**
     * @param array<string, mixed>|null                   $destination
     * @param list<array{field: string, message: string}> $errors
     */
    private function renderDestinationForm(?array $destination, bool $isEditing, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        $defaults = [
            'id' => '',
            'label' => '',
            'type' => AlertRuleValidator::TYPE_WEBHOOK,
            'host' => null,
            'recipients' => [],
        ];
        $values = array_replace($defaults, $destination ?? []);
        $secretSource = $isEditing && $values['id'] !== '' ? $this->secrets->source((string) $values['id']) : $this->secrets->source();

        $response = $this->renderDashboardPage('alerts/destination_form.html.twig', [
            'destination' => [
                'id' => $values['id'],
                'label' => $values['label'],
                'type' => $values['type'],
                'host' => $values['host'] ?? null,
                'recipient_count' => is_array($values['recipients'] ?? null) ? count($values['recipients']) : 0,
            ],
            'is_editing' => $isEditing,
            'errors' => $errors,
            'secret_source' => $secretSource,
        ]);
        $response->setStatusCode($status);

        return $response;
    }

    /**
     * Masks an email address on the main dashboard page so the page never
     * renders raw recipient email addresses in HTML while still letting the
     * administrator pick a specific listed recipient by index in the test step.
     */
    private static function maskEmailForConfirmation(string $address, int $number): string
    {
        $parts = explode('@', $address, 2);
        $domain = $parts[1] ?? 'example.com';

        return sprintf('Recipient #%d (%s)', $number, $domain);
    }

    /** @param array<string, mixed> $values */
    private function buildSummaryText(array $values): string
    {
        $coversText = 'selected websites';
        $covers = (string) ($values['covers'] ?? 'all');
        if ($covers === 'all') {
            $coversText = 'all active websites';
        } elseif (str_starts_with($covers, 'group:')) {
            $groupId = substr($covers, 6);
            $coversText = 'group '.($this->scopes->groupName($groupId) ?? $groupId);
        } elseif ($covers !== '') {
            $token = str_starts_with($covers, 'website:') ? substr($covers, 8) : $covers;
            $site = $this->websites->findOneByToken($token);
            if ($site !== null) {
                $domain = AlertSettings::bareHost((string) ($site['domain'] ?? ''));
                $coversText = $domain !== '' ? sprintf('%s (%s)', $site['name'], $domain) : (string) $site['name'];
            }
        }

        $window = (string) ($values['window'] ?? AlertRuleValidator::WINDOW_DAY);
        $windowText = match ($window) {
            AlertRuleValidator::WINDOW_HOUR => '1 hour (1 completed UTC hour)',
            AlertRuleValidator::WINDOW_WEEK => '1 week (168 completed UTC hours)',
            default => '1 day (24 completed UTC hours)',
        };

        $source = (string) ($values['source'] ?? AlertRuleValidator::SOURCE_EVENTS);
        $threshold = (int) ($values['threshold'] ?? 5);
        if ($source === AlertRuleValidator::SOURCE_TRACKING_FAILURES) {
            return sprintf('Alert when %s records more than %d tracking failures in the last %s.', $coversText, $threshold, $windowText);
        }

        $mode = (string) ($values['privacy_mode'] ?? AlertRuleValidator::PRIVACY_ALL);
        $modeWord = match ($mode) {
            AlertRuleValidator::PRIVACY_ENHANCED => 'enhanced ',
            AlertRuleValidator::PRIVACY_ANONYMOUS => 'anonymous ',
            default => '',
        };
        $eventName = trim((string) ($values['event_name'] ?? ''));
        $eventWord = $eventName !== '' ? sprintf('"%s" ', $eventName) : '';
        $condition = (string) ($values['condition'] ?? AlertRuleValidator::CONDITION_FEWER_THAN);
        if ($condition === AlertRuleValidator::CONDITION_NO_EVENTS) {
            return sprintf('Alert when %s records no %s%sevents in the last %s.', $coversText, $modeWord, $eventWord, $windowText);
        }

        return sprintf('Alert when %s records fewer than %d %s%sevents in the last %s.', $coversText, max(1, $threshold), $modeWord, $eventWord, $windowText);
    }

    /** @param array<string, mixed> $values */
    private function previewAlertCondition(array $values): string
    {
        $window = in_array($values['window'] ?? '', ['hour', 'day', 'week'], true) ? (string) $values['window'] : 'day';
        $source = (string) ($values['source'] ?? AlertRuleValidator::SOURCE_EVENTS);
        $threshold = max(0, (int) ($values['threshold'] ?? 5));
        if ($source === AlertRuleValidator::SOURCE_TRACKING_FAILURES) {
            return sprintf('more_than_%d_tracking_failures_per_%s', $threshold, $window);
        }
        if (($values['condition'] ?? '') === AlertRuleValidator::CONDITION_NO_EVENTS) {
            return 'no_events_per_'.$window;
        }

        return sprintf('fewer_than_%d_per_%s', max(1, $threshold), $window);
    }

    private static function fieldForRuleError(string $message): string
    {
        $lower = strtolower($message);
        return match (true) {
            str_contains($lower, 'rule id') || str_contains($lower, 'already exists') => 'rule-id',
            str_contains($lower, 'website') || str_contains($lower, 'group') || str_contains($lower, 'cover') => 'rule-covers',
            str_contains($lower, 'source') => 'rule-source',
            str_contains($lower, 'privacy mode') => 'privacy-mode',
            str_contains($lower, 'event name') => 'event-name',
            str_contains($lower, 'condition') => 'condition',
            str_contains($lower, 'threshold') => 'threshold',
            str_contains($lower, 'window') || str_contains($lower, 'retention') => 'window',
            str_contains($lower, 'destination') => 'destinations-group',
            default => 'rule-id',
        };
    }

    private static function fieldForDestinationError(string $message): string
    {
        $lower = strtolower($message);
        return match (true) {
            str_contains($lower, 'label') => 'destination-label',
            str_contains($lower, 'destination id') || str_contains($lower, 'already exists') => 'destination-id',
            str_contains($lower, 'type') => 'destination-type',
            str_contains($lower, 'url') || str_contains($lower, 'https') => 'destination-url',
            str_contains($lower, 'recipient') || str_contains($lower, 'email') => 'destination-recipients',
            default => 'destination-label',
        };
    }

    private function denyUnlessEnabled(): void
    {
        if (!$this->features->isEnabled('alerts')) {
            throw $this->createNotFoundException('Alerts are turned off.');
        }
    }
}
