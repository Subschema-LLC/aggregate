<?php

declare(strict_types=1);

namespace App\Service\Alerts;

use App\Repository\EventRepository;
use App\Service\AggregateConfigLoader;
use App\Service\Email\EmailPurpose;
use App\Service\Email\TransactionalMailer;
use App\Service\FeatureFlags;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\Operations\TaskTrigger;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Evaluates alert rules on completed UTC hours, keeps per-rule/website firing
 * state in processing_tasks.fingerprint, and delivers firing, recovery and
 * test alerts through WebhookSender and TransactionalMailer.
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
    public const DELIVERY_TASK_TYPE = 'alerts_delivery';
    public const AUDIT_CATEGORY = 'operations';
    public const OP_FIRING = 'alert_firing';
    public const OP_RECOVERED = 'alert_recovered';
    public const OP_TEST = 'alert_test';

    public const OUTCOME_SENT = 'sent';
    public const OUTCOME_SUPPRESSED = 'suppressed';
    public const OUTCOME_FAILED = 'failed';

    public const STATE_OK = 'ok';
    public const STATE_FIRING = 'firing';
    public const STATE_WAITING = 'waiting';
    public const STATE_OFF = 'off';
    public const STATE_PROBLEM = 'problem';

    public const LEASE_SECONDS = 900;
    public const MAX_CATCHUP_HOURS = 24;
    /** An undelivered alert is retried at each hourly check until its window ended this many hours ago. */
    public const RETRY_HOURS = 24;
    public const RECENT_LIMIT = 20;

    private const ISO_HOUR_LENGTH = 20;

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly AlertSettings $settings,
        private readonly EventRepository $events,
        private readonly ProcessingTasks $tasks,
        private readonly AuditTrail $audit,
        private readonly Connection $connection,
        private readonly WebhookSender $webhookSender,
        private readonly TransactionalMailer $mailer,
        private readonly AggregateConfigLoader $config,
        private readonly FeatureFlags $features,
        private readonly LoggerInterface $logger,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? Clock::get();
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

        $hours = $this->hoursToEvaluate($cursorHour, $completedHour);
        /** @var Counts $counts */
        $counts = ['sent' => 0, 'suppressed' => 0, 'failed' => 0];
        $skipped = 0;

        try {
            $installation = $this->installationUrl();
            $destinations = $configured['destinations'];
            $delivered = $this->tasks->latest(self::DELIVERY_TASK_TYPE);
            $checked = [];

            foreach ($enabledRules as $rule) {
                if ($this->settings->referenceProblem($rule) !== null) {
                    ++$skipped;
                    continue;
                }
                foreach ($this->settings->coveredWebsites($rule) as $site) {
                    $subject = self::stateSubject($rule['id'], $site['token']);
                    $checked[$subject] = true;
                    $persisted = $latest[$subject]['success']['fingerprint'] ?? null;
                    [$state, $closing] = $this->walk($rule, $site['token'], $hours, $persisted);

                    if ($closing !== null) {
                        // Recovered and broke again during catch-up: close the earlier incident first.
                        $this->deliverToAll($closing, $rule, $site, $destinations, $installation, $delivered, $completedHour, $trigger, $taskId, $counts, false);
                    }
                    $current = self::alertForState($state);
                    if ($current !== null) {
                        $this->deliverToAll($current, $rule, $site, $destinations, $installation, $delivered, $completedHour, $trigger, $taskId, $counts, true);
                    }
                    if ($state !== $persisted) {
                        $this->saveState($subject, $state, $trigger);
                    }
                }
            }

            $this->closeUncheckedIncidents($configured['rules'], $checked, $latest, $destinations, $installation, $delivered, $completedHour, $trigger, $taskId, $counts);

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
        $configured = $this->settings->toArray();
        $destination = $configured['destinations'][$destinationId] ?? null;
        if ($destination === null) {
            throw new \InvalidArgumentException(sprintf('Alert destination "%s" does not exist.', $destinationId));
        }

        $rules = $configured['rules'];
        $rule = ($ruleId !== null && isset($rules[$ruleId])) ? $rules[$ruleId] : (reset($rules) ?: [
            'id' => 'test-rule',
            'enabled' => true,
            'covers' => 'all',
            'source' => AlertRuleValidator::SOURCE_EVENTS,
            'privacy_mode' => AlertRuleValidator::PRIVACY_ALL,
            'event_name' => '',
            'condition' => AlertRuleValidator::CONDITION_FEWER_THAN,
            'threshold' => 5,
            'window' => AlertRuleValidator::WINDOW_HOUR,
            'destinations' => [$destinationId],
        ]);

        $sites = $this->settings->coveredWebsites($rule);
        $site = $sites[0] ?? [
            'token' => 'test-website-token',
            'name' => 'Example Website',
            'domain' => 'example.com',
        ];

        $windowEnd = $this->completedUtcHour();
        $windowEndIso = self::formatIsoHour($windowEnd);
        $alertId = substr(bin2hex(random_bytes(16)), 0, 24);
        $incidentId = self::computeIncidentId($this->installationUrl(), $rule['id'], $site['token'], 'test:'.$alertId);

        /** @var AlertPayload $payload */
        $payload = [
            'schema_version' => '1',
            'id' => $alertId,
            'incident' => $incidentId,
            'type' => 'test',
            'rule' => $rule['id'],
            'website' => $site['token'],
            'host' => $site['domain'] !== '' ? $site['domain'] : 'example.com',
            'installation' => $this->installationUrl(),
            'alert_condition' => AlertRuleValidator::formatAlertCondition($rule),
            'window_end' => $windowEndIso,
        ];

        if ($destination['type'] === AlertRuleValidator::TYPE_WEBHOOK) {
            $result = $this->webhookSender->send($destination, $payload);
            $reason = $result['sent']
                ? sprintf('Test alert sent (%s; rule status unchanged).', $result['reason'])
                : $result['reason'];

            $this->recordDelivery(
                self::OP_TEST,
                $result['sent'] ? self::OUTCOME_SENT : self::OUTCOME_FAILED,
                $payload['rule'],
                $payload['host'],
                $destination['label'],
                $trigger->requestedBy,
                null,
                $reason,
            );

            return [
                'sent' => $result['sent'],
                'destination_label' => $destination['label'],
                'alert_id' => $alertId,
                'reason' => $reason,
            ];
        }

        $listed = $destination['recipients'] ?? [];
        if ($recipient !== null && $recipient !== '' && $recipient !== '__all__') {
            $matched = null;
            foreach ($listed as $addr) {
                if (strcasecmp($addr, trim($recipient)) === 0) {
                    $matched = $addr;
                    break;
                }
            }
            if ($matched === null) {
                throw new \InvalidArgumentException('Choose a recipient from the destination’s configured recipient list.');
            }
            $targets = [$matched];
        } else {
            $targets = $listed;
        }

        $sentCount = 0;
        $lastError = null;
        foreach ($targets as $address) {
            $emailResult = $this->sendAlertEmail($address, $rule, $payload, $trigger);
            if ($emailResult['sent']) {
                ++$sentCount;
            } else {
                $lastError = $emailResult['reason'];
            }
        }

        $allSent = $sentCount === count($targets) && $sentCount > 0;
        $reason = $allSent
            ? 'Test alert (rule status unchanged)'
            : ($lastError ?? 'Not sent: no recipient could be reached.');

        $this->recordDelivery(
            self::OP_TEST,
            $allSent ? self::OUTCOME_SENT : self::OUTCOME_FAILED,
            $payload['rule'],
            $payload['host'],
            $destination['label'],
            $trigger->requestedBy,
            null,
            $reason,
        );

        return [
            'sent' => $allSent,
            'destination_label' => $destination['label'],
            'alert_id' => $alertId,
            'reason' => $reason,
        ];
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
        try {
            $latest = $this->tasks->latest(self::TASK_TYPE);
        } catch (\Throwable) {
            $latest = [];
        }

        $job = $latest[''] ?? null;
        $jobSuccess = $job !== null ? $job['success'] : null;
        $jobAttempt = $job !== null ? $job['attempt'] : null;
        $lastCheck = $jobAttempt !== null ? ($jobAttempt['finished_at'] ?? $jobAttempt['started_at']) : null;
        $lastWindowEnd = $jobSuccess['fingerprint'] ?? null;

        try {
            $rules = $this->settings->rules();
        } catch (\Throwable) {
            $rules = [];
        }

        $status = static fn (string $state, string $label, ?string $since = null, ?string $sinceFormatted = null): array => [
            'state' => $state,
            'label' => $label,
            'since' => $since,
            'since_formatted' => $sinceFormatted,
        ];

        $ruleStatuses = [];
        $firingCount = 0;

        foreach ($rules as $id => $rule) {
            if (!$rule['enabled']) {
                $ruleStatuses[$id] = $status(self::STATE_OFF, 'Off');
                continue;
            }

            $problem = $this->settings->referenceProblem($rule);
            if ($problem !== null) {
                $ruleStatuses[$id] = $status(self::STATE_PROBLEM, 'Not checked: '.$problem);
                continue;
            }

            $sites = $this->settings->coveredWebsites($rule);
            if ($sites === []) {
                $ruleStatuses[$id] = $status(self::STATE_PROBLEM, 'Not checked: it covers no websites.');
                continue;
            }

            $earliestFiring = null;
            $anyEvaluated = false;
            foreach ($sites as $site) {
                $fingerprint = $latest[self::stateSubject($id, $site['token'])]['success']['fingerprint'] ?? null;
                if ($fingerprint === null) {
                    continue;
                }
                $anyEvaluated = true;
                $parsed = self::parseState($fingerprint);
                if ($parsed['kind'] === self::STATE_FIRING && $parsed['since'] !== null
                    && ($earliestFiring === null || strcmp($parsed['since'], $earliestFiring) < 0)) {
                    $earliestFiring = $parsed['since'];
                }
            }

            if ($earliestFiring !== null) {
                ++$firingCount;
                $sinceDate = self::parseIsoHour($earliestFiring);
                $formatted = $sinceDate !== null ? $sinceDate->format('Y-m-d H:00').' UTC' : $earliestFiring;
                $ruleStatuses[$id] = $status(self::STATE_FIRING, 'Firing since '.$formatted, $earliestFiring, $formatted);
            } elseif ($anyEvaluated) {
                $ruleStatuses[$id] = $status(self::STATE_OK, 'OK');
            } else {
                $ruleStatuses[$id] = $status(self::STATE_WAITING, 'Waiting for the first full hour');
            }
        }

        return [
            'last_check' => $lastCheck,
            'last_window_end' => $lastWindowEnd,
            'firing_count' => $firingCount,
            'rules' => $ruleStatuses,
        ];
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
        $operations = [self::OP_FIRING, self::OP_RECOVERED, self::OP_TEST];
        try {
            $total = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM audit_trail WHERE operation IN (:ops)',
                ['ops' => $operations],
                ['ops' => ArrayParameterType::STRING],
            );
            $sql = $this->connection->getDatabasePlatform()->modifyLimitQuery(
                'SELECT id, occurred_at, operation, subject, outcome, details FROM audit_trail WHERE operation IN (:ops) ORDER BY occurred_at DESC, id DESC',
                $limit,
            );
            $rows = $this->connection->fetchAllAssociative(
                $sql,
                ['ops' => $operations],
                ['ops' => ArrayParameterType::STRING],
            );
        } catch (\Throwable) {
            return ['entries' => [], 'has_more' => false];
        }

        $entries = [];
        foreach ($rows as $row) {
            $time = $this->connection->convertToPHPValue($row['occurred_at'], Types::DATETIME_IMMUTABLE);
            $occurredAt = $time instanceof \DateTimeImmutable
                ? new \DateTimeImmutable($time->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'))
                : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $parts = explode(' · ', (string) ($row['subject'] ?? ''), 3);
            $ruleId = $parts[0];
            $website = $parts[1] ?? '';
            $destination = $parts[2] ?? '';

            $operation = (string) $row['operation'];
            $type = match ($operation) {
                self::OP_RECOVERED => 'recovered',
                self::OP_TEST => 'test',
                default => 'firing',
            };
            $typeLabel = match ($type) {
                'recovered' => 'Recovered',
                'test' => 'Test',
                default => 'Firing',
            };

            $outcome = (string) $row['outcome'];
            $outcomeLabel = match ($outcome) {
                self::OUTCOME_SENT => 'Sent',
                self::OUTCOME_SUPPRESSED => 'Suppressed',
                default => 'Failed',
            };

            $entries[] = [
                'id' => (int) $row['id'],
                'occurred_at' => $occurredAt,
                'rule' => $ruleId,
                'website' => $website,
                'type' => $type,
                'type_label' => $typeLabel,
                'destination' => $destination,
                'outcome' => $outcome,
                'outcome_label' => $outcomeLabel,
                'reason' => (string) ($row['details'] ?? ''),
            ];
        }

        return [
            'entries' => $entries,
            'has_more' => $total > $limit,
        ];
    }

    /**
     * Checks whether the sliding window [hourEnd - window, hourEnd) breaches the rule.
     *
     * @param RuleConfig $rule
     */
    public function isWindowBreached(array $rule, string $websiteToken, \DateTimeImmutable $hourEnd): bool
    {
        $windowHours = AlertRuleValidator::windowHours($rule['window']);
        $from = $hourEnd->modify('-'.$windowHours.' hours');

        if ($rule['source'] === AlertRuleValidator::SOURCE_TRACKING_FAILURES) {
            $cap = $rule['threshold'] + 1;
            $count = $this->tasks->countTrackingFailuresUpTo($websiteToken, $from, $hourEnd, $cap);

            return $count > $rule['threshold'];
        }

        $cap = $rule['condition'] === AlertRuleValidator::CONDITION_NO_EVENTS ? 1 : $rule['threshold'];
        $count = $this->events->countEventsUpTo(
            $websiteToken,
            $from,
            $hourEnd,
            $cap,
            $rule['privacy_mode'],
            $rule['event_name'] !== '' ? $rule['event_name'] : null,
        );

        return $count < $cap;
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
        return substr(hash('sha256', implode("\0", ['alert-v1', $installation, $ruleId, $websiteToken, $type, $windowEndIso])), 0, 24);
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
        return substr(hash('sha256', implode("\0", ['incident-v1', $installation, $ruleId, $websiteToken, $sinceIso])), 0, 24);
    }

    public static function formatIsoHour(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:00:00\Z');
    }

    public static function parseIsoHour(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:00:00Z$/D', $value) !== 1) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Walks the hours to evaluate for one rule and website and works out the
     * new state. Only the net change is reported: an incident that opened and
     * closed during catch-up sends nothing, and one that closed and opened
     * again closes the earlier incident (returned as the closing alert) before
     * the new one fires.
     *
     * @param RuleConfig               $rule
     * @param list<\DateTimeImmutable> $hours
     *
     * @return array{0: string, 1: ?Alert}
     */
    private function walk(array $rule, string $token, array $hours, ?string $persisted): array
    {
        $before = self::parseState($persisted);
        $wasFiring = $before['kind'] === self::STATE_FIRING;
        $firing = $wasFiring;
        $since = $before['since'];
        $persistedClosedAt = null;

        foreach ($hours as $hourEnd) {
            $breached = $this->isWindowBreached($rule, $token, $hourEnd);
            if ($breached && !$firing) {
                $firing = true;
                $since = self::formatIsoHour($hourEnd);
            } elseif (!$breached && $firing) {
                if ($wasFiring && $persistedClosedAt === null && $since === $before['since']) {
                    $persistedClosedAt = self::formatIsoHour($hourEnd);
                }
                $firing = false;
            }
        }

        $closesPersisted = $wasFiring && $persistedClosedAt !== null && $before['since'] !== null;
        if ($firing && $since !== null && (!$wasFiring || $since !== $before['since'])) {
            $closing = $closesPersisted
                ? ['type' => 'recovered', 'window_end' => (string) $persistedClosedAt, 'since' => (string) $before['since']]
                : null;

            return ['firing:'.$since, $closing];
        }
        if ($closesPersisted) {
            return ['recovered:'.$persistedClosedAt.':'.$before['since'], null];
        }

        return [$persisted ?? 'ok', null];
    }

    /**
     * Closes incidents that the main loop did not check: a rule turned off or
     * no longer covering a website sends a recovery to its destinations; a
     * deleted rule has no destinations left, so its state is reset and the
     * audit trail says no recovery was sent. Recoveries that could not be
     * delivered are retried here too.
     *
     * @param array<string, RuleConfig>                                     $rules
     * @param array<string, true>                                           $checked
     * @param array<string, array{attempt: array<string, mixed>, success: ?array<string, mixed>}> $latest
     * @param array<string, DestinationConfig>                              $destinations
     * @param array<string, array{attempt: array<string, mixed>, success: ?array<string, mixed>}> $delivered
     * @param Counts                                                        $counts
     */
    private function closeUncheckedIncidents(
        array $rules,
        array $checked,
        array $latest,
        array $destinations,
        string $installation,
        array &$delivered,
        \DateTimeImmutable $completedHour,
        TaskTrigger $trigger,
        int $taskId,
        array &$counts,
    ): void {
        foreach ($latest as $subject => $item) {
            $subject = (string) $subject;
            if ($subject === '' || isset($checked[$subject])) {
                continue;
            }
            $before = self::parseState(isset($item['success']['fingerprint']) ? (string) $item['success']['fingerprint'] : null);
            if ($before['kind'] !== self::STATE_FIRING && $before['kind'] !== 'recovered') {
                continue;
            }
            $parts = explode(':', $subject, 2);
            if (count($parts) !== 2 || $before['since'] === null) {
                continue;
            }
            [$ruleId, $token] = $parts;
            if (str_starts_with($token, '#')) {
                continue;
            }
            $rule = $rules[$ruleId] ?? null;

            if ($rule === null) {
                if ($before['kind'] === self::STATE_FIRING) {
                    $this->saveState($subject, 'ok', $trigger);
                    $this->recordDelivery(self::OP_RECOVERED, self::OUTCOME_SUPPRESSED, $ruleId, $token, '(none)', $trigger->requestedBy, $taskId, 'The rule was deleted while firing, so no recovery was sent.');
                    ++$counts['suppressed'];
                }
                continue;
            }

            $covered = null;
            foreach ($this->settings->coveredWebsites($rule) as $site) {
                if ($site['token'] === $token) {
                    $covered = $site;
                    break;
                }
            }
            if ($rule['enabled'] && $covered !== null) {
                // An enabled rule that still covers the website but was skipped
                // (see referenceProblem()): its state is unknown, so keep it.
                continue;
            }
            $site = $covered ?? $this->settings->website($token) ?? ['token' => $token, 'name' => $token, 'domain' => ''];

            if ($before['kind'] === self::STATE_FIRING) {
                $closeAt = self::formatIsoHour($completedHour);
                if (strcmp($closeAt, $before['since']) <= 0) {
                    // Turned off in the hour it fired: close at the next check, so
                    // the recovery's window_end is later than the firing alert's.
                    continue;
                }
                $closing = ['type' => 'recovered', 'window_end' => $closeAt, 'since' => $before['since']];
                $this->deliverToAll($closing, $rule, $site, $destinations, $installation, $delivered, $completedHour, $trigger, $taskId, $counts, true);
                $this->saveState($subject, 'recovered:'.$closing['window_end'].':'.$before['since'], $trigger);
                continue;
            }

            $current = self::alertForState('recovered:'.$before['recovered_at'].':'.$before['since']);
            if ($current !== null) {
                $this->deliverToAll($current, $rule, $site, $destinations, $installation, $delivered, $completedHour, $trigger, $taskId, $counts, true);
            }
        }
    }

    /**
     * @param Alert                            $alert
     * @param RuleConfig                       $rule
     * @param Site                             $site
     * @param array<string, DestinationConfig> $destinations
     * @param array<string, array{attempt: array<string, mixed>, success: ?array<string, mixed>}> $delivered
     * @param Counts                           $counts
     */
    private function deliverToAll(
        array $alert,
        array $rule,
        array $site,
        array $destinations,
        string $installation,
        array &$delivered,
        \DateTimeImmutable $completedHour,
        TaskTrigger $trigger,
        int $taskId,
        array &$counts,
        bool $retryable,
    ): void {
        $windowEnd = self::parseIsoHour($alert['window_end']);
        if ($windowEnd === null) {
            return;
        }
        $payload = $this->buildPayload($alert['type'], $rule, $site, $installation, $windowEnd, $alert['since']);

        foreach ($rule['destinations'] as $destinationId) {
            $destination = $destinations[$destinationId] ?? null;
            if ($destination !== null) {
                $this->deliverOne($destination, $rule, $site, $payload, $alert['since'], $windowEnd, $installation, $delivered, $completedHour, $trigger, $taskId, $counts, $retryable);
            }
        }
    }

    /**
     * Sends one alert to one destination unless it already has it.
     *
     * @param DestinationConfig $destination
     * @param RuleConfig        $rule
     * @param Site              $site
     * @param AlertPayload      $payload
     * @param array<string, array{attempt: array<string, mixed>, success: ?array<string, mixed>}> $delivered
     * @param Counts            $counts
     */
    private function deliverOne(
        array $destination,
        array $rule,
        array $site,
        array $payload,
        string $since,
        \DateTimeImmutable $windowEnd,
        string $installation,
        array &$delivered,
        \DateTimeImmutable $completedHour,
        TaskTrigger $trigger,
        int $taskId,
        array &$counts,
        bool $retryable,
    ): void {
        $subject = self::deliverySubject($rule['id'], $site['token'], $destination['id']);
        $last = $delivered[$subject]['success']['fingerprint'] ?? null;
        $operation = $payload['type'] === 'recovered' ? self::OP_RECOVERED : self::OP_FIRING;

        if ($last === $payload['id']) {
            if ($payload['type'] === 'firing' && $retryable) {
                ++$counts['suppressed'];
            }

            return;
        }

        if ($payload['type'] === 'recovered') {
            $firingId = self::computeAlertId($installation, $rule['id'], $site['token'], 'firing', $since);
            if ($last !== $firingId) {
                $this->markDelivered($subject, $payload['id'], 'Recovery not sent: the firing alert never reached this destination.', $trigger, $delivered);
                $this->recordDelivery($operation, self::OUTCOME_SUPPRESSED, $payload['rule'], $payload['host'], $destination['label'], $trigger->requestedBy, $taskId, 'Not sent: the firing alert for this incident never reached this destination, so its recovery is not sent either.');
                ++$counts['suppressed'];

                return;
            }
        }

        $retryUntil = $windowEnd->modify('+'.self::RETRY_HOURS.' hours');
        if ($retryable && $retryUntil <= $completedHour) {
            return; // Retries ran out earlier; the last failure is in the audit trail.
        }
        $lastAttempt = !$retryable || $retryUntil <= $completedHour->modify('+1 hour');

        if ($destination['type'] === AlertRuleValidator::TYPE_WEBHOOK) {
            $result = $this->webhookSender->send($destination, $payload);
            $sent = $result['sent'];
            $reason = $result['reason'];
        } else {
            [$sent, $reason] = $this->emailAll($destination['recipients'] ?? [], $rule, $payload, $trigger);
        }

        if ($sent) {
            $this->markDelivered($subject, $payload['id'], sprintf('Delivered %s alert %s.', $payload['type'], $payload['id']), $trigger, $delivered);
            $this->recordDelivery($operation, self::OUTCOME_SENT, $payload['rule'], $payload['host'], $destination['label'], $trigger->requestedBy, $taskId, $reason);
            ++$counts['sent'];

            return;
        }

        if ($retryable) {
            $reason = rtrim($reason, '.').'. '.($lastAttempt ? 'Gave up: no more retries for this alert.' : 'Will retry at the next hourly check.');
        }
        $this->recordDelivery($operation, self::OUTCOME_FAILED, $payload['rule'], $payload['host'], $destination['label'], $trigger->requestedBy, $taskId, $reason);
        ++$counts['failed'];
    }

    /**
     * @param list<string> $recipients
     * @param RuleConfig   $rule
     * @param AlertPayload $payload
     *
     * @return array{0: bool, 1: string}
     */
    private function emailAll(array $recipients, array $rule, array $payload, TaskTrigger $trigger): array
    {
        $sentCount = 0;
        $lastError = null;
        foreach ($recipients as $address) {
            $emailResult = $this->sendAlertEmail($address, $rule, $payload, $trigger);
            if ($emailResult['sent']) {
                ++$sentCount;
            } else {
                $lastError = $emailResult['reason'];
            }
        }

        if ($sentCount === 0) {
            return [false, $lastError ?? 'Not sent: no recipient is listed.'];
        }
        if ($sentCount < count($recipients)) {
            // Retrying would send the alert again to the recipients who already have it.
            return [true, sprintf('Delivered to %d of %d listed recipients (id %s); the others failed: %s', $sentCount, count($recipients), $payload['id'], rtrim((string) $lastError, '.'))];
        }

        return [true, sprintf('Delivered to listed recipients (id %s)', $payload['id'])];
    }

    /**
     * @param RuleConfig $rule
     * @param Site       $site
     *
     * @return AlertPayload
     */
    private function buildPayload(
        string $type,
        array $rule,
        array $site,
        string $installation,
        \DateTimeImmutable $windowEnd,
        string $sinceIso,
    ): array {
        $windowEndIso = self::formatIsoHour($windowEnd);
        $alertId = self::computeAlertId($installation, $rule['id'], $site['token'], $type, $windowEndIso);
        $incidentId = self::computeIncidentId($installation, $rule['id'], $site['token'], $sinceIso);

        return [
            'schema_version' => '1',
            'id' => $alertId,
            'incident' => $incidentId,
            'type' => $type === 'recovered' ? 'recovered' : 'firing',
            'rule' => $rule['id'],
            'website' => $site['token'],
            'host' => $site['domain'] !== '' ? $site['domain'] : $site['token'],
            'installation' => $installation,
            'alert_condition' => AlertRuleValidator::formatAlertCondition($rule),
            'window_end' => $windowEndIso,
        ];
    }

    /**
     * @param RuleConfig   $rule
     * @param AlertPayload $payload
     *
     * @return array{sent: bool, reason: string}
     */
    private function sendAlertEmail(string $recipient, array $rule, array $payload, TaskTrigger $trigger): array
    {
        $title = match ($payload['type']) {
            'recovered' => sprintf('Recovered: %s on %s', $payload['rule'], $payload['host']),
            'test' => sprintf('Test alert: %s on %s', $payload['rule'], $payload['host']),
            default => sprintf('Alert firing: %s on %s', $payload['rule'], $payload['host']),
        };

        $result = $this->mailer->send(
            EmailPurpose::Alert,
            $recipient,
            [
                'alert_title' => $title,
                'alert_type' => $payload['type'],
                'alert_rule' => $payload['rule'],
                'alert_website' => $payload['website'],
                'alert_host' => $payload['host'],
                'alert_installation' => $payload['installation'],
                'alert_condition' => $payload['alert_condition'],
                'alert_condition_description' => AlertSettings::describeCondition($rule),
                'alert_window_end' => $payload['window_end'],
                'alert_id' => $payload['id'],
                'alert_incident' => $payload['incident'],
            ],
            $trigger,
        );

        if ($result->sent) {
            return ['sent' => true, 'reason' => 'Delivered to listed recipients'];
        }

        return ['sent' => false, 'reason' => TransactionalMailer::redact((string) $result->error, [$recipient])];
    }

    private function recordDelivery(
        string $operation,
        string $outcome,
        string $ruleId,
        string $websiteHost,
        string $destinationLabel,
        ?string $actor,
        ?int $taskId,
        string $details,
    ): void {
        $this->audit->record(
            self::AUDIT_CATEGORY,
            $operation,
            $outcome,
            $this->now(),
            self::formatSubject($ruleId, $websiteHost, $destinationLabel),
            $actor,
            $taskId,
            WebhookSender::redact($details),
            always: true,
        );
    }

    public static function formatSubject(string $ruleId, string $websiteHost, string $destinationLabel): string
    {
        return sprintf('%s · %s · %s', $ruleId, $websiteHost, $destinationLabel);
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
     * Records that a destination has an alert, so it is neither retried nor sent twice.
     *
     * @param array<string, array{attempt: array<string, mixed>, success: ?array<string, mixed>}> $delivered
     */
    private function markDelivered(string $subject, string $alertId, string $details, TaskTrigger $trigger, array &$delivered): void
    {
        $now = $this->now();
        $id = $this->tasks->start(self::DELIVERY_TASK_TYPE, $subject, $trigger, $now);
        if ($id !== null) {
            $this->tasks->succeed($id, $now, null, $details, null, $alertId);
        }
        $delivered[$subject]['success']['fingerprint'] = $alertId;
    }

    /**
     * @return array{kind: 'none'|'ok'|'firing'|'recovered', since: ?string, recovered_at: ?string}
     */
    public static function parseState(?string $fingerprint): array
    {
        if ($fingerprint === null) {
            return ['kind' => 'none', 'since' => null, 'recovered_at' => null];
        }
        if (str_starts_with($fingerprint, 'firing:')) {
            $since = substr($fingerprint, 7);
            if (self::parseIsoHour($since) !== null) {
                return ['kind' => 'firing', 'since' => $since, 'recovered_at' => null];
            }
        }
        if (str_starts_with($fingerprint, 'recovered:')) {
            $rest = substr($fingerprint, 10);
            $recoveredAt = substr($rest, 0, self::ISO_HOUR_LENGTH);
            $since = substr($rest, self::ISO_HOUR_LENGTH + 1);
            if (self::parseIsoHour($recoveredAt) !== null && self::parseIsoHour($since) !== null) {
                return ['kind' => 'recovered', 'since' => $since, 'recovered_at' => $recoveredAt];
            }
        }

        return ['kind' => 'ok', 'since' => null, 'recovered_at' => null];
    }

    /**
     * The alert a state stands for (its current alert), or null for none.
     *
     * @return Alert|null
     */
    private static function alertForState(string $state): ?array
    {
        $parsed = self::parseState($state);
        if ($parsed['kind'] === 'firing' && $parsed['since'] !== null) {
            return ['type' => 'firing', 'window_end' => $parsed['since'], 'since' => $parsed['since']];
        }
        if ($parsed['kind'] === 'recovered' && $parsed['since'] !== null && $parsed['recovered_at'] !== null) {
            return ['type' => 'recovered', 'window_end' => $parsed['recovered_at'], 'since' => $parsed['since']];
        }

        return null;
    }

    /**
     * Subject of a rule and website's state; a website token too long for
     * processing_tasks' 191 characters is hashed (and such a state is not
     * closed automatically, since its website cannot be read back).
     */
    public static function stateSubject(string $ruleId, string $websiteToken): string
    {
        $subject = $ruleId.':'.$websiteToken;

        return strlen($subject) <= 191 ? $subject : $ruleId.':#'.hash('sha256', $websiteToken);
    }

    /**
     * Subject of a destination's delivery record; long website tokens are
     * hashed so the subject stays within processing_tasks' 191 characters.
     */
    public static function deliverySubject(string $ruleId, string $websiteToken, string $destinationId): string
    {
        $subject = $ruleId.':'.$destinationId.':'.$websiteToken;

        return strlen($subject) <= 191 ? $subject : $ruleId.':'.$destinationId.':#'.hash('sha256', $websiteToken);
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function hoursToEvaluate(?\DateTimeImmutable $cursorHour, \DateTimeImmutable $completedHour): array
    {
        if ($cursorHour === null || $cursorHour >= $completedHour) {
            return [$completedHour];
        }

        $earliest = $completedHour->modify('-'.(self::MAX_CATCHUP_HOURS - 1).' hours');
        $start = $cursorHour->modify('+1 hour');
        if ($start < $earliest) {
            $start = $earliest;
        }

        $hours = [];
        $current = $start;
        while ($current <= $completedHour) {
            $hours[] = $current;
            $current = $current->modify('+1 hour');
        }

        return $hours === [] ? [$completedHour] : $hours;
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
