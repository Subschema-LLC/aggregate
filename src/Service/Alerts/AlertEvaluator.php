<?php

declare(strict_types=1);

namespace App\Service\Alerts;

use App\Entities\AlertRule\WindowBreaches;
use App\Entities\Incident\IncidentState;
use App\Entities\Incident\UtcHour;
use App\Entities\AlertRule\RuleDescription;
use App\Entities\Incident\AlertIds;
use App\Entities\Incident\DeliveryLog;
use App\Entities\Incident\DeliveryRecord;
use App\Features\Alerts\DeliverAlert\DeliverAlert;
use App\Features\Alerts\EvaluateAlerts\EvaluateAlerts;
use App\Features\Alerts\ListAlerts\ListAlerts;
use App\Features\Alerts\SendTestAlert\SendTestAlert;
use App\Features\Alerts\SendTestAlert\TestAlertId;
use App\Features\Alerts\EvaluateAlerts\Evaluation;
use App\Features\Alerts\EvaluateAlerts\HoursToEvaluate;
use App\Integrations\Alerts\AlertChannels;
use App\Integrations\Alerts\AuditDeliveryHistory;
use App\Integrations\Alerts\EmailAlerts;
use App\Integrations\Alerts\EventCountWindowBreaches;
use App\Integrations\Alerts\TaskDeliveryLog;
use App\Integrations\Alerts\TaskIncidentStates;
use App\Integrations\Alerts\YamlAlertRules;
use App\Integrations\Alerts\YamlDestinations;
use App\Repository\EventRepository;
use App\Service\AggregateConfigLoader;
use App\Service\Email\EmailPurpose;
use App\Service\Email\TransactionalMailer;
use App\Service\FeatureFlags;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Runs the hourly alert check: EvaluateAlerts decides which alerts to send
 * and which states to save, and this class carries that out in order. It keeps
 * per-rule/website firing state in processing_tasks.fingerprint, and delivers
 * firing, recovery and test alerts through WebhookSender and TransactionalMailer.
 *
 * State per rule and website (task type `alerts`, subject `<rule>:<website>`):
 * `ok`, `firing:<since>` or `recovered:<window_end>:<since>`. The state names
 * the current alert, and each destination's last delivered alert ID is kept
 * under task type `alerts_delivery`, so an alert that could not be delivered
 * is retried at the next hourly check (for up to RETRY_HOURS) instead of being
 * lost, and a delivered one is never sent twice.
 *
 * Every delivery (sent, suppressed or failed) is recorded in audit_trail with
 * always: true, so turning off an audit switch never hides recent alerts.
 *
 * @phpstan-import-type DestinationConfig from AlertRuleValidator
 * @phpstan-import-type RuleConfig from AlertRuleValidator
 * @phpstan-import-type AlertPayload from WebhookSender
 *
 * @phpstan-type Site array{token: string, name: string, domain: string}
 * @phpstan-type Alert array{type: 'firing'|'recovered', window_end: string, since: string}
 * @phpstan-type Counts array{sent: int, suppressed: int, failed: int}
 */
class AlertEvaluator
{
    public const TASK_TYPE = 'alerts';
    public const DELIVERY_TASK_TYPE = TaskDeliveryLog::TASK_TYPE;
    public const AUDIT_CATEGORY = TaskDeliveryLog::AUDIT_CATEGORY;
    public const OP_FIRING = DeliveryLog::OP_FIRING;
    public const OP_RECOVERED = DeliveryLog::OP_RECOVERED;
    public const OP_TEST = DeliveryLog::OP_TEST;

    public const OUTCOME_SENT = DeliveryLog::OUTCOME_SENT;
    public const OUTCOME_SUPPRESSED = DeliveryLog::OUTCOME_SUPPRESSED;
    public const OUTCOME_FAILED = DeliveryLog::OUTCOME_FAILED;

    public const STATE_OK = ListAlerts::STATE_OK;
    public const STATE_FIRING = ListAlerts::STATE_FIRING;
    public const STATE_WAITING = ListAlerts::STATE_WAITING;
    public const STATE_OFF = ListAlerts::STATE_OFF;
    public const STATE_PROBLEM = ListAlerts::STATE_PROBLEM;

    public const LEASE_SECONDS = 900;
    public const MAX_CATCHUP_HOURS = HoursToEvaluate::MAX_CATCHUP_HOURS;
    /** An undelivered alert is retried at each hourly check until its window ended this many hours ago. */
    public const RETRY_HOURS = DeliverAlert::RETRY_HOURS;
    public const RECENT_LIMIT = ListAlerts::RECENT_LIMIT;

    private readonly ClockInterface $clock;
    private readonly WindowBreaches $breaches;
    private readonly EvaluateAlerts $evaluation;
    private readonly ListAlerts $list;

    public function __construct(
        private readonly AlertSettings $settings,
        EventRepository $events,
        private readonly ProcessingTasks $tasks,
        private readonly AuditTrail $audit,
        Connection $connection,
        private readonly WebhookSender $webhookSender,
        private readonly TransactionalMailer $mailer,
        private readonly AggregateConfigLoader $config,
        private readonly FeatureFlags $features,
        private readonly LoggerInterface $logger,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? Clock::get();
        $this->breaches = new EventCountWindowBreaches($events, $tasks);
        $this->evaluation = new EvaluateAlerts($this->breaches);
        $this->list = new ListAlerts(
            new YamlAlertRules($settings->store()),
            $settings->validator()->references(),
            $settings->coverage(),
            new TaskIncidentStates($tasks),
            new AuditDeliveryHistory($connection),
        );
    }

    /**
     * The most recent completed UTC hour boundary at $now (minutes and seconds = 0).
     */
    public function completedUtcHour(?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $utc = ($now ?? $this->now())->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('G'), 0, 0);
    }

    /**
     * Whether a rule and website is still firing although its rule was turned
     * off, deleted or no longer covers the website: the next check closes it.
     */
    public function hasOpenIncidents(): bool
    {
        try {
            $latest = $this->tasks->latest(self::TASK_TYPE);
        } catch (\Throwable) {
            return false;
        }
        foreach ($latest as $subject => $item) {
            if ($subject !== '' && self::parseState($item['success']['fingerprint'] ?? null)['kind'] === self::STATE_FIRING) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluates all enabled rules over completed UTC hours since the last
     * successful run (up to 24 hours of catch-up), retries alerts that could
     * not be delivered, and closes incidents of rules that were turned off.
     *
     * @return array{status: 'off'|'not_due'|'busy'|'ran'|'failed', evaluated_hours: int, sent: int, suppressed: int, failed: int, window_end: ?string, message: string}
     */
    public function evaluate(TaskTrigger $trigger, bool $force = false): array
    {
        $result = static fn (string $status, string $message, ?string $windowEnd = null, int $hours = 0, int $sent = 0, int $suppressed = 0, int $failed = 0): array => [
            'status' => $status,
            'evaluated_hours' => $hours,
            'sent' => $sent,
            'suppressed' => $suppressed,
            'failed' => $failed,
            'window_end' => $windowEnd,
            'message' => $message,
        ];

        if (!$this->features->isEnabled('alerts')) {
            return $result('off', 'Alerts are turned off.');
        }

        try {
            $configured = $this->settings->toArray();
        } catch (\Throwable $e) {
            return $result('failed', 'The alert settings in YAML are invalid'.($e instanceof \InvalidArgumentException ? ': '.$e->getMessage() : '.'));
        }

        $enabledRules = array_filter($configured['rules'], static fn (array $r): bool => $r['enabled']);
        if ($enabledRules === [] && !$this->hasOpenIncidents()) {
            return $result('off', 'No alert rules are enabled.');
        }

        $now = $this->now();
        $completedHour = $this->completedUtcHour($now);
        $completedIso = self::formatIsoHour($completedHour);

        $latest = $this->tasks->latest(self::TASK_TYPE);
        $cursorHour = self::parseIsoHour($latest['']['success']['fingerprint'] ?? null);

        if (!$force && $cursorHour !== null && $cursorHour >= $completedHour) {
            return $result('not_due', 'Not due yet.', $completedIso);
        }

        $taskId = $this->tasks->start(self::TASK_TYPE, null, $trigger, $now, self::LEASE_SECONDS);
        if ($taskId === null) {
            return $result('busy', 'Another alert evaluation is in progress.', $completedIso);
        }

        /** @var Counts $counts */
        $counts = ['sent' => 0, 'suppressed' => 0, 'failed' => 0];
        $skipped = [];

        try {
            $installation = $this->installationUrl();
            $destinations = $configured['destinations'];
            $log = new TaskDeliveryLog($this->tasks, $this->audit, $this->clock, $trigger, $taskId, $this->tasks->latest(self::DELIVERY_TASK_TYPE));
            $channels = new AlertChannels($this->webhookSender, new EmailAlerts($this->mailer, $trigger));
            $deliver = new DeliverAlert($channels, $log);

            $states = [];
            foreach ($latest as $subject => $item) {
                if ((string) $subject !== '') {
                    $fingerprint = $item['success']['fingerprint'] ?? null;
                    $states[(string) $subject] = $fingerprint === null ? null : (string) $fingerprint;
                }
            }
            $hours = $this->evaluation->hours($cursorHour, $completedHour);
            $checked = [];
            $run = function (Evaluation $evaluation, array $sites) use ($configured, $destinations, $installation, $deliver, $log, $completedHour, $trigger, &$counts): void {
                $this->carryOut($evaluation, $configured['rules'], $sites, $destinations, $installation, $deliver, $log, $completedHour, $trigger, $counts);
            };

            foreach ($enabledRules as $rule) {
                if ($this->settings->referenceProblem($rule) !== null) {
                    $skipped[] = $rule['id'];
                    continue;
                }
                foreach ($this->settings->coveredWebsites($rule) as $site) {
                    $subject = self::stateSubject($rule['id'], $site['token']);
                    $checked[$subject] = true;
                    $evaluation = $this->evaluation->evaluateWebsite($rule, $site['token'], $hours, $states[$subject] ?? null);
                    $run($evaluation, [$rule['id'] => [$site['token'] => $site]]);
                }
            }

            $sites = [];
            $coverage = [];
            foreach ($configured['rules'] as $ruleId => $rule) {
                $coverage[$ruleId] = [];
                foreach ($this->settings->coveredWebsites($rule) as $site) {
                    $sites[$ruleId][$site['token']] = $site;
                    $coverage[$ruleId][] = $site['token'];
                }
            }
            $run($this->evaluation->closeUnchecked($states, $checked, $configured['rules'], $coverage, $completedHour), $sites);
            $skipped = count($skipped);

            $summary = sprintf(
                'Evaluated %d rule%s for window ending %s: %d sent, %d suppressed, %d failed.',
                count($enabledRules) - $skipped,
                count($enabledRules) - $skipped === 1 ? '' : 's',
                $completedIso,
                $counts['sent'],
                $counts['suppressed'],
                $counts['failed'],
            );
            if ($skipped > 0) {
                $summary .= sprintf(' %d rule%s skipped: see the Alerts page.', $skipped, $skipped === 1 ? '' : 's');
            }
            // The evaluation itself succeeded (and moves the hourly cursor);
            // failed deliveries are recorded per destination and retried.
            $this->tasks->succeed($taskId, $this->now(), $counts['sent'], $summary, null, $completedIso);

            return $result(
                $counts['failed'] > 0 && $counts['sent'] === 0 ? 'failed' : 'ran',
                $summary,
                $completedIso,
                count($hours),
                $counts['sent'],
                $counts['suppressed'],
                $counts['failed'],
            );
        } catch (\Throwable $e) {
            $reason = WebhookSender::redact($e->getMessage());
            $this->logger->error('Alert evaluation failed.', ['exception_class' => $e::class, 'error' => $reason]);
            $this->tasks->fail($taskId, $this->now(), 'Alert evaluation failed: '.$reason);

            return $result('failed', 'Alert evaluation failed: '.$reason, $completedIso, 0, $counts['sent'], $counts['suppressed'], $counts['failed'] + 1);
        }
    }

    /**
     * Sends a synchronous test alert to a destination without changing any
     * rule's state. For an email destination, $recipient may select one of its
     * listed addresses or '__all__' / null for all listed addresses.
     *
     * @return array{sent: bool, destination_label: string, alert_id: string, reason: string}
     */
    public function sendTest(
        string $destinationId,
        TaskTrigger $trigger,
        ?string $recipient = null,
        ?string $ruleId = null,
    ): array {
        $this->features->assertEnabled('alerts');
        $store = $this->settings->store();
        $send = new SendTestAlert(
            new YamlDestinations($store),
            new YamlAlertRules($store),
            $this->settings->coverage(),
            new AlertChannels($this->webhookSender, new EmailAlerts($this->mailer, $trigger)),
            new TaskDeliveryLog($this->tasks, $this->audit, $this->clock, $trigger, null),
        );
        $alertId = substr(bin2hex(random_bytes(16)), 0, 24);
        $id = new TestAlertId($alertId, $this->completedUtcHour(), $this->installationUrl());

        return $send->send($destinationId, $recipient, $ruleId, $id);
    }

    /**
     * Current status of every configured rule and the overall last check time.
     *
     * @return array{
     *     last_check: ?\DateTimeImmutable,
     *     last_window_end: ?string,
     *     firing_count: int,
     *     rules: array<string, array{
     *         state: 'ok'|'firing'|'waiting'|'off'|'problem',
     *         label: string,
     *         since: ?string,
     *         since_formatted: ?string
     *     }>
     * }
     */
    public function statuses(): array
    {
        return $this->list->statuses();
    }

    /**
     * Reads the most recent alert delivery entries from audit_trail.
     *
     * @return array{
     *     entries: list<array{
     *         id: int,
     *         occurred_at: \DateTimeImmutable,
     *         rule: string,
     *         website: string,
     *         type: 'firing'|'recovered'|'test',
     *         type_label: string,
     *         destination: string,
     *         outcome: string,
     *         outcome_label: string,
     *         reason: string
     *     }>,
     *     has_more: bool
     * }
     */
    public function recentAlerts(int $limit = self::RECENT_LIMIT): array
    {
        return $this->list->recentAlerts($limit);
    }

    /**
     * Checks whether the sliding window [hourEnd - window, hourEnd) breaches the rule;
     * see EventCountWindowBreaches.
     *
     * @param RuleConfig $rule
     */
    public function isWindowBreached(array $rule, string $websiteToken, \DateTimeImmutable $hourEnd): bool
    {
        return $this->breaches->isBreached($rule, $websiteToken, $hourEnd);
    }

    /**
     * Deterministic alert ID for firing/recovered alerts (same across retries,
     * distinct across rules, websites, windows, and types).
     */
    public static function computeAlertId(
        string $installation,
        string $ruleId,
        string $websiteToken,
        string $type,
        string $windowEndIso,
    ): string {
        return AlertIds::alert($installation, $ruleId, $websiteToken, $type, $windowEndIso);
    }

    /**
     * Deterministic incident ID shared by a firing alert and the recovered
     * alert that closes the same incident (keyed by the firing start time).
     */
    public static function computeIncidentId(
        string $installation,
        string $ruleId,
        string $websiteToken,
        string $sinceIso,
    ): string {
        return AlertIds::incident($installation, $ruleId, $websiteToken, $sinceIso);
    }

    public static function formatIsoHour(\DateTimeImmutable $time): string
    {
        return UtcHour::format($time);
    }

    public static function parseIsoHour(?string $value): ?\DateTimeImmutable
    {
        return UtcHour::parse($value);
    }

    /**
     * Carries out an Evaluation in order: delivers its alerts, saves its states
     * and closes incidents of deleted rules.
     *
     * @param array<string, RuleConfig>                                     $rules
     * @param array<string, array<string, Site>>                            $sites covered websites by rule
     * @param array<string, DestinationConfig>                              $destinations
     * @param Counts                                                        $counts
     */
    private function carryOut(
        Evaluation $evaluation,
        array $rules,
        array $sites,
        array $destinations,
        string $installation,
        DeliverAlert $deliver,
        DeliveryLog $log,
        \DateTimeImmutable $completedHour,
        TaskTrigger $trigger,
        array &$counts,
    ): void {
        foreach ($evaluation->steps() as $step) {
            if ($step['kind'] === 'save') {
                $this->saveState($step['subject'], $step['state'], $trigger);
            } elseif ($step['kind'] === 'deleted') {
                $this->saveState($step['subject'], IncidentState::OK, $trigger);
                $log->record(self::OP_RECOVERED, self::OUTCOME_SUPPRESSED, $step['rule_id'], $step['website_token'], '(none)', 'The rule was deleted while firing, so no recovery was sent.');
                ++$counts['suppressed'];
            } else {
                $alert = $step['alert'];
                $token = $alert->websiteToken;
                $site = $sites[$alert->ruleId][$token] ?? $this->settings->website($token) ?? ['token' => $token, 'name' => $token, 'domain' => ''];
                $deliver->deliver($alert, $rules[$alert->ruleId], $site, $destinations, $installation, $completedHour, $step['retryable'], $counts);
            }
        }
    }

    public static function formatSubject(string $ruleId, string $websiteHost, string $destinationLabel): string
    {
        return TaskDeliveryLog::subject($ruleId, $websiteHost, $destinationLabel);
    }

    private function saveState(string $subject, string $fingerprint, TaskTrigger $trigger): void
    {
        $now = $this->now();
        $id = $this->tasks->start(self::TASK_TYPE, $subject, $trigger, $now);
        if ($id !== null) {
            $this->tasks->succeed($id, $now, null, 'State: '.$fingerprint, null, $fingerprint);
        }
    }

    /**
     * @return array{kind: 'none'|'ok'|'firing'|'recovered', since: ?string, recovered_at: ?string}
     */
    public static function parseState(?string $fingerprint): array
    {
        return IncidentState::parse($fingerprint);
    }

    /**
     * Subject of a rule and website's state; a website token too long for
     * processing_tasks' 191 characters is hashed (and such a state is not
     * closed automatically, since its website cannot be read back).
     */
    public static function stateSubject(string $ruleId, string $websiteToken): string
    {
        return IncidentState::subject($ruleId, $websiteToken);
    }

    /**
     * Subject of a destination's delivery record; long website tokens are
     * hashed so the subject stays within processing_tasks' 191 characters.
     */
    public static function deliverySubject(string $ruleId, string $websiteToken, string $destinationId): string
    {
        return DeliveryRecord::subject($ruleId, $websiteToken, $destinationId);
    }

    private function installationUrl(): string
    {
        return AlertSettings::normalizeInstallationUrl($this->config->getWithEnvFallback('app_host', 'http://localhost'));
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
