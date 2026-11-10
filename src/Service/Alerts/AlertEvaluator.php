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
 * Evaluates alert rules on completed UTC hours, persists per-rule/website
 * firing state in processing_tasks.fingerprint, and delivers firing, recovery,
 * and test alerts through WebhookSender and TransactionalMailer.
 *
 * Every delivery attempt (sent, suppressed, or failed) is recorded in
 * audit_trail with always: true so disabling an audit switch never hides
 * recent alerts or breaks deduplication.
 *
 * @phpstan-import-type DestinationConfig from AlertRuleValidator
 * @phpstan-import-type RuleConfig from AlertRuleValidator
 * @phpstan-import-type AlertPayload from WebhookSender
 */
class AlertEvaluator
{
    public const TASK_TYPE = 'alerts';
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

    public const LEASE_SECONDS = 900;
    public const MAX_CATCHUP_HOURS = 24;
    public const RECENT_LIMIT = 20;

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
     * Evaluates all enabled rules over completed UTC hours since the last
     * successful run (up to 24 hours of catch-up).
     *
     * @return array{status: 'off'|'not_due'|'busy'|'ran'|'failed', evaluated_hours: int, sent: int, suppressed: int, failed: int, window_end: ?string, message: string}
     */
    public function evaluate(TaskTrigger $trigger, bool $force = false): array
    {
        if (!$this->features->isEnabled('alerts')) {
            return [
                'status' => 'off',
                'evaluated_hours' => 0,
                'sent' => 0,
                'suppressed' => 0,
                'failed' => 0,
                'window_end' => null,
                'message' => 'Alerts are turned off.',
            ];
        }

        try {
            $configured = $this->settings->toArray();
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'evaluated_hours' => 0,
                'sent' => 0,
                'suppressed' => 0,
                'failed' => 0,
                'window_end' => null,
                'message' => 'The alert settings in YAML are invalid'.($e instanceof \InvalidArgumentException ? ': '.$e->getMessage() : '.'),
            ];
        }

        $enabledRules = array_filter($configured['rules'], static fn (array $r): bool => $r['enabled']);
        if ($enabledRules === []) {
            return [
                'status' => 'off',
                'evaluated_hours' => 0,
                'sent' => 0,
                'suppressed' => 0,
                'failed' => 0,
                'window_end' => null,
                'message' => 'No alert rules are enabled.',
            ];
        }

        $now = $this->now();
        $completedHour = $this->completedUtcHour($now);
        $completedIso = self::formatIsoHour($completedHour);

        $latest = $this->tasks->latest(self::TASK_TYPE);
        $cursorFingerprint = $latest['']['success']['fingerprint'] ?? null;
        $cursorHour = self::parseIsoHour($cursorFingerprint);

        if (!$force && $cursorHour !== null && $cursorHour >= $completedHour) {
            return [
                'status' => 'not_due',
                'evaluated_hours' => 0,
                'sent' => 0,
                'suppressed' => 0,
                'failed' => 0,
                'window_end' => $completedIso,
                'message' => 'Not due yet.',
            ];
        }

        $taskId = $this->tasks->start(self::TASK_TYPE, null, $trigger, $now, self::LEASE_SECONDS);
        if ($taskId === null) {
            return [
                'status' => 'busy',
                'evaluated_hours' => 0,
                'sent' => 0,
                'suppressed' => 0,
                'failed' => 0,
                'window_end' => $completedIso,
                'message' => 'Another alert evaluation is in progress.',
            ];
        }

        $hours = $this->hoursToEvaluate($cursorHour, $completedHour);
        $sent = 0;
        $suppressed = 0;
        $failed = 0;

        try {
            $installation = $this->installationUrl();
            $destinations = $configured['destinations'];

            foreach ($enabledRules as $rule) {
                $websites = $this->settings->coveredWebsites($rule);
                foreach ($websites as $site) {
                    $subjectKey = $rule['id'].':'.$site['token'];
                    $persistedFingerprint = $latest[$subjectKey]['success']['fingerprint'] ?? null;
                    $state = $persistedFingerprint;
                    $transition = null;
                    $continuingBreach = false;

                    foreach ($hours as $hourEnd) {
                        $breached = $this->isWindowBreached($rule, $site['token'], $hourEnd);
                        $isFiring = $state !== null && str_starts_with($state, 'firing:');

                        if ($breached) {
                            if (!$isFiring) {
                                $sinceIso = self::formatIsoHour($hourEnd);
                                $state = 'firing:'.$sinceIso;
                                $transition = [
                                    'type' => 'firing',
                                    'window_end' => $hourEnd,
                                    'since_iso' => $sinceIso,
                                ];
                                $continuingBreach = false;
                            } else {
                                $sinceIso = substr((string) $state, 7);
                                if ($transition === null) {
                                    $continuingBreach = true;
                                }
                            }
                        } else {
                            if ($isFiring) {
                                $sinceIso = substr((string) $state, 7);
                                $state = 'ok';
                                $transition = [
                                    'type' => 'recovered',
                                    'window_end' => $hourEnd,
                                    'since_iso' => $sinceIso,
                                ];
                                $continuingBreach = false;
                            } elseif ($state === null) {
                                $state = 'ok';
                            }
                        }
                    }

                    if ($transition !== null) {
                        $payload = $this->buildPayload(
                            $transition['type'],
                            $rule,
                            $site,
                            $installation,
                            $transition['window_end'],
                            $transition['since_iso'],
                        );
                        foreach ($rule['destinations'] as $destId) {
                            $destination = $destinations[$destId] ?? null;
                            if ($destination === null) {
                                continue;
                            }
                            $outcome = $this->deliver($destination, $rule, $payload, $trigger, $taskId);
                            if ($outcome === self::OUTCOME_SENT) {
                                ++$sent;
                            } elseif ($outcome === self::OUTCOME_SUPPRESSED) {
                                ++$suppressed;
                            } else {
                                ++$failed;
                            }
                        }
                    } elseif ($continuingBreach && $state !== null && str_starts_with($state, 'firing:')) {
                        $sinceIso = substr($state, 7);
                        $payload = $this->buildPayload('firing', $rule, $site, $installation, $completedHour, $sinceIso);
                        foreach ($rule['destinations'] as $destId) {
                            $destination = $destinations[$destId] ?? null;
                            if ($destination === null) {
                                continue;
                            }
                            $this->recordDelivery(
                                self::OP_FIRING,
                                self::OUTCOME_SUPPRESSED,
                                $rule['id'],
                                $site['domain'] !== '' ? $site['domain'] : $site['token'],
                                $destination['label'],
                                $trigger->requestedBy,
                                $taskId,
                                sprintf('Already alerted for this gap (since %s).', $sinceIso),
                            );
                            ++$suppressed;
                        }
                    }

                    if ($state !== null && $state !== $persistedFingerprint) {
                        $this->saveRuleWebsiteState($subjectKey, $state, $trigger, $this->now());
                    }
                }
            }

            $summary = sprintf(
                'Evaluated %d rule%s for window ending %s: %d sent, %d suppressed, %d failed.',
                count($enabledRules),
                count($enabledRules) === 1 ? '' : 's',
                $completedIso,
                $sent,
                $suppressed,
                $failed,
            );
            $this->tasks->succeed($taskId, $this->now(), $sent, $summary, null, $completedIso);

            return [
                'status' => $failed > 0 && $sent === 0 ? 'failed' : 'ran',
                'evaluated_hours' => count($hours),
                'sent' => $sent,
                'suppressed' => $suppressed,
                'failed' => $failed,
                'window_end' => $completedIso,
                'message' => $summary,
            ];
        } catch (\Throwable $e) {
            $reason = WebhookSender::redact($e->getMessage());
            $this->logger->error('Alert evaluation failed.', ['exception_class' => $e::class, 'error' => $reason]);
            $this->tasks->fail($taskId, $this->now(), 'Alert evaluation failed: '.$reason);

            return [
                'status' => 'failed',
                'evaluated_hours' => 0,
                'sent' => $sent,
                'suppressed' => $suppressed,
                'failed' => $failed + 1,
                'window_end' => $completedIso,
                'message' => 'Alert evaluation failed: '.$reason,
            ];
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
     *         state: 'ok'|'firing'|'waiting'|'off',
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

        $jobSuccess = $latest['']['success'] ?? null;
        $jobAttempt = $latest['']['attempt'] ?? null;
        $lastCheck = $jobAttempt['finished_at'] ?? $jobAttempt['started_at'] ?? null;
        $lastWindowEnd = $jobSuccess['fingerprint'] ?? null;

        try {
            $rules = $this->settings->rules();
        } catch (\Throwable) {
            $rules = [];
        }

        $ruleStatuses = [];
        $firingCount = 0;

        foreach ($rules as $id => $rule) {
            if (!$rule['enabled']) {
                $ruleStatuses[$id] = [
                    'state' => self::STATE_OFF,
                    'label' => 'Off',
                    'since' => null,
                    'since_formatted' => null,
                ];
                continue;
            }

            $sites = $this->settings->coveredWebsites($rule);
            if ($sites === []) {
                $ruleStatuses[$id] = [
                    'state' => self::STATE_WAITING,
                    'label' => 'Waiting for the first full hour',
                    'since' => null,
                    'since_formatted' => null,
                ];
                continue;
            }

            $earliestFiring = null;
            $anyEvaluated = false;
            foreach ($sites as $site) {
                $fp = $latest[$id.':'.$site['token']]['success']['fingerprint'] ?? null;
                if ($fp === null) {
                    continue;
                }
                $anyEvaluated = true;
                if (str_starts_with($fp, 'firing:')) {
                    $sinceIso = substr($fp, 7);
                    if ($earliestFiring === null || strcmp($sinceIso, $earliestFiring) < 0) {
                        $earliestFiring = $sinceIso;
                    }
                }
            }

            if ($earliestFiring !== null) {
                ++$firingCount;
                $sinceDate = self::parseIsoHour($earliestFiring);
                $formatted = $sinceDate !== null ? $sinceDate->format('Y-m-d H:00').' UTC' : $earliestFiring;
                $ruleStatuses[$id] = [
                    'state' => self::STATE_FIRING,
                    'label' => 'Firing since '.$formatted,
                    'since' => $earliestFiring,
                    'since_formatted' => $formatted,
                ];
            } elseif ($anyEvaluated) {
                $ruleStatuses[$id] = [
                    'state' => self::STATE_OK,
                    'label' => 'OK',
                    'since' => null,
                    'since_formatted' => null,
                ];
            } else {
                $ruleStatuses[$id] = [
                    'state' => self::STATE_WAITING,
                    'label' => 'Waiting for the first full hour',
                    'since' => null,
                    'since_formatted' => null,
                ];
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
     *     older_removed: bool
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
            return ['entries' => [], 'older_removed' => false];
        }

        $entries = [];
        foreach ($rows as $row) {
            $time = $this->connection->convertToPHPValue($row['occurred_at'], Types::DATETIME_IMMUTABLE);
            $occurredAt = $time instanceof \DateTimeImmutable
                ? new \DateTimeImmutable($time->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'))
                : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $parts = explode(' · ', (string) ($row['subject'] ?? ''), 3);
            $ruleId = $parts[0] ?? '';
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
            'older_removed' => $total > $limit,
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
     * @param RuleConfig                                      $rule
     * @param array{token: string, name: string, domain: string} $site
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
            'type' => $type,
            'rule' => $rule['id'],
            'website' => $site['token'],
            'host' => $site['domain'] !== '' ? $site['domain'] : $site['token'],
            'installation' => $installation,
            'alert_condition' => AlertRuleValidator::formatAlertCondition($rule),
            'window_end' => $windowEndIso,
        ];
    }

    /**
     * @param DestinationConfig $destination
     * @param RuleConfig        $rule
     * @param AlertPayload      $payload
     *
     * @return 'sent'|'suppressed'|'failed'
     */
    private function deliver(
        array $destination,
        array $rule,
        array $payload,
        TaskTrigger $trigger,
        int $taskId,
    ): string {
        $operation = $payload['type'] === 'recovered' ? self::OP_RECOVERED : self::OP_FIRING;
        $subject = self::formatSubject($payload['rule'], $payload['host'], $destination['label']);

        if ($this->alreadySent($operation, $subject, $payload['id'])) {
            $this->recordDelivery(
                $operation,
                self::OUTCOME_SUPPRESSED,
                $payload['rule'],
                $payload['host'],
                $destination['label'],
                $trigger->requestedBy,
                $taskId,
                sprintf('Already alerted for this gap (id %s).', $payload['id']),
            );

            return self::OUTCOME_SUPPRESSED;
        }

        if ($destination['type'] === AlertRuleValidator::TYPE_WEBHOOK) {
            $result = $this->webhookSender->send($destination, $payload);
            $outcome = $result['sent'] ? self::OUTCOME_SENT : self::OUTCOME_FAILED;
            $this->recordDelivery(
                $operation,
                $outcome,
                $payload['rule'],
                $payload['host'],
                $destination['label'],
                $trigger->requestedBy,
                $taskId,
                $result['reason'],
            );

            return $outcome;
        }

        $recipients = $destination['recipients'] ?? [];
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

        $allSent = $sentCount === count($recipients) && $sentCount > 0;
        $outcome = $allSent ? self::OUTCOME_SENT : self::OUTCOME_FAILED;
        $reason = $allSent
            ? sprintf('Delivered to listed recipients (id %s)', $payload['id'])
            : ($lastError ?? 'Not sent: no recipient could be reached.');

        $this->recordDelivery(
            $operation,
            $outcome,
            $payload['rule'],
            $payload['host'],
            $destination['label'],
            $trigger->requestedBy,
            $taskId,
            $reason,
        );

        return $outcome;
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

    private function alreadySent(string $operation, string $subject, string $alertId): bool
    {
        try {
            $found = $this->connection->fetchOne(
                'SELECT id FROM audit_trail WHERE operation = :op AND subject = :sub AND outcome = :out AND details LIKE :id LIMIT 1',
                [
                    'op' => $operation,
                    'sub' => $subject,
                    'out' => self::OUTCOME_SENT,
                    'id' => '%'.$alertId.'%',
                ],
            );

            return $found !== false && $found !== null;
        } catch (\Throwable) {
            return false;
        }
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

    private function saveRuleWebsiteState(string $subjectKey, string $fingerprint, TaskTrigger $trigger, \DateTimeImmutable $now): void
    {
        $id = $this->tasks->start(self::TASK_TYPE, $subjectKey, $trigger, $now);
        if ($id !== null) {
            $this->tasks->succeed($id, $now, null, 'State: '.$fingerprint, null, $fingerprint);
        }
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
