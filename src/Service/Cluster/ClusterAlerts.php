<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertEvaluator;
use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSettings;
use App\Service\Alerts\WebhookSender;
use App\Service\Email\EmailPurpose;
use App\Service\Email\TransactionalMailer;
use App\Service\FeatureFlags;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\TaskTrigger;

/**
 * Tells the alert destinations (JOIN-55's signed webhooks and email) when a
 * server of the cluster stops pulling its configuration, and when it is
 * back. Only the administration server can: the others have no secrets.
 * Sent as the same JSON v1 alert, with the rule `cluster-config` and the
 * server's name as the host, so an existing receiver needs no change.
 */
class ClusterAlerts
{
    public const RULE = 'cluster-config';

    public function __construct(
        private readonly AlertSettings $alerts,
        private readonly ClusterSettings $settings,
        private readonly WebhookSender $webhooks,
        private readonly TransactionalMailer $mailer,
        private readonly FeatureFlags $features,
        private readonly AuditTrail $audit,
        private readonly AggregateConfigLoader $config,
    ) {
    }

    /**
     * @param 'firing'|'recovered' $type
     * @return list<string> what happened at each destination, for the command's output
     */
    public function send(string $server, string $type, int $since, int $now, string $condition): array
    {
        if (!$this->features->isEnabled('alerts')) {
            return ['Alerts are turned off (feature flag alerts), so no destination was told.'];
        }
        $wanted = $this->settings->alertDestinations();
        $destinations = array_filter(
            $this->alerts->destinations(),
            static fn (array $destination): bool => $wanted === null || in_array($destination['id'], $wanted, true),
        );
        if ($destinations === []) {
            return ['No alert destination is configured, so nobody was told.'];
        }
        $installation = AlertSettings::normalizeInstallationUrl($this->config->getWithEnvFallback('app_host', 'http://localhost'));
        $sinceIso = gmdate('Y-m-d\TH:i:s\Z', $since);
        $payload = [
            'schema_version' => '1',
            'id' => substr(bin2hex(random_bytes(16)), 0, 24),
            'incident' => AlertEvaluator::computeIncidentId($installation, self::RULE, '', $server.':'.$sinceIso),
            'type' => $type,
            'rule' => self::RULE,
            'website' => '',
            'host' => $server,
            'installation' => $installation,
            'alert_condition' => $condition,
            'window_end' => gmdate('Y-m-d\TH:i:00\Z', $now),
        ];
        $results = [];
        foreach ($destinations as $destination) {
            if ($destination['type'] === AlertRuleValidator::TYPE_WEBHOOK) {
                $result = $this->webhooks->send($destination, $payload);
                [$sent, $reason] = [$result['sent'], $result['reason']];
            } else {
                $sent = false;
                $reason = 'No recipient could be reached.';
                foreach ($destination['recipients'] ?? [] as $recipient) {
                    $email = $this->mailer->send(EmailPurpose::Alert, $recipient, [
                        'alert_title' => sprintf('%s: %s on %s', $type === 'recovered' ? 'Recovered' : 'Alert firing', self::RULE, $server),
                        'alert_type' => $type,
                        'alert_rule' => self::RULE,
                        'alert_website' => '',
                        'alert_host' => $server,
                        'alert_installation' => $installation,
                        'alert_condition' => $condition,
                        'alert_condition_description' => $condition,
                        'alert_window_end' => $payload['window_end'],
                        'alert_id' => $payload['id'],
                        'alert_incident' => $payload['incident'],
                    ], TaskTrigger::schedule());
                    $sent = $sent || $email->sent;
                    $reason = $email->sent ? 'Delivered to listed recipients' : TransactionalMailer::redact((string) $email->error, [$recipient]);
                }
            }
            $this->audit->record(
                AlertEvaluator::AUDIT_CATEGORY,
                $type === 'recovered' ? AlertEvaluator::OP_RECOVERED : AlertEvaluator::OP_FIRING,
                $sent ? AlertEvaluator::OUTCOME_SENT : AlertEvaluator::OUTCOME_FAILED,
                new \DateTimeImmutable('@'.$now),
                AlertEvaluator::formatSubject(self::RULE, $server, $destination['label']),
                null,
                null,
                WebhookSender::redact($reason),
                always: true,
            );
            $results[] = $destination['label'].': '.($sent ? 'sent' : 'not sent ('.WebhookSender::redact($reason).')');
        }

        return $results;
    }
}
