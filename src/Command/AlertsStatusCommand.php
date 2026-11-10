<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Alerts\AlertEvaluator;
use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSecrets;
use App\Service\Alerts\AlertSettings;
use App\Service\Email\TransactionalMailer;
use App\Service\FeatureFlags;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:alerts:status',
    description: 'Show alert rules, their status, redacted destinations, last evaluation time, and recent alerts',
)]
final class AlertsStatusCommand extends Command
{
    public function __construct(
        private readonly AlertSettings $settings,
        private readonly AlertSecrets $secrets,
        private readonly AlertEvaluator $evaluator,
        private readonly TransactionalMailer $mailer,
        private readonly FeatureFlags $features,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the status as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $flagOn = $this->features->isEnabled('alerts');

        try {
            $configured = $this->settings->toArray();
        } catch (\Throwable $e) {
            $io->error('The alert settings are invalid'.($e instanceof \InvalidArgumentException ? ': '.$e->getMessage() : '.'));

            return Command::FAILURE;
        }

        $statuses = $this->evaluator->statuses();
        $recent = $this->evaluator->recentAlerts(AlertEvaluator::RECENT_LIMIT);
        $emailProblem = $this->mailer->problem();

        $destinationsSummary = [];
        foreach ($configured['destinations'] as $id => $dest) {
            if ($dest['type'] === AlertRuleValidator::TYPE_WEBHOOK) {
                $secretSource = $this->secrets->source($id);
                $destinationsSummary[$id] = [
                    'id' => $id,
                    'label' => $dest['label'],
                    'type' => 'webhook',
                    'target' => $dest['host'] ?? '',
                    'secret_source' => $secretSource,
                    'signing' => $this->secrets->describe($id),
                    'ready' => $secretSource !== null,
                ];
            } else {
                $count = count($dest['recipients'] ?? []);
                $destinationsSummary[$id] = [
                    'id' => $id,
                    'label' => $dest['label'],
                    'type' => 'email',
                    'target' => sprintf('%d listed recipient%s', $count, $count === 1 ? '' : 's'),
                    'ready' => $emailProblem === null,
                    'problem' => $emailProblem,
                ];
            }
        }

        $rulesSummary = [];
        foreach ($configured['rules'] as $id => $rule) {
            $ruleStatus = $statuses['rules'][$id] ?? ['state' => AlertEvaluator::STATE_WAITING, 'label' => 'Waiting for the first full hour', 'since' => null];
            $rulesSummary[$id] = [
                'id' => $id,
                'enabled' => $rule['enabled'],
                'covers' => $rule['covers'],
                'covers_label' => $this->settings->describeCovers($rule),
                'source' => $rule['source'],
                'condition' => AlertRuleValidator::formatAlertCondition($rule),
                'condition_label' => AlertSettings::describeCondition($rule),
                'window' => $rule['window'],
                'destinations' => $rule['destinations'],
                'state' => $ruleStatus['state'],
                'status_label' => $ruleStatus['label'],
                'firing_since' => $ruleStatus['since'],
            ];
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'enabled' => $flagOn,
                'last_check' => $statuses['last_check']?->format(\DATE_ATOM),
                'last_window_end' => $statuses['last_window_end'],
                'firing_count' => $statuses['firing_count'],
                'rules' => array_values($rulesSummary),
                'destinations' => array_values($destinationsSummary),
                'recent_alerts' => array_map(static fn (array $entry): array => [
                    'occurred_at' => $entry['occurred_at']->format(\DATE_ATOM),
                    'rule' => $entry['rule'],
                    'website' => $entry['website'],
                    'type' => $entry['type'],
                    'destination' => $entry['destination'],
                    'outcome' => $entry['outcome'],
                    'reason' => $entry['reason'],
                ], $recent['entries']),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $flagOn ? Command::SUCCESS : Command::FAILURE;
        }

        if (!$flagOn) {
            $io->warning('Alerts are off (feature flag alerts is false).');
        } else {
            $io->success(sprintf(
                'Alerts are on: %d rule%s, %d firing now.%s',
                count($rulesSummary),
                count($rulesSummary) === 1 ? '' : 's',
                $statuses['firing_count'],
                $statuses['last_check'] !== null ? ' As of the last check, '.$statuses['last_check']->format('Y-m-d H:i').' UTC.' : ' Waiting for the first scheduled check.',
            ));
        }

        if ($rulesSummary === []) {
            $io->text('No alert rules yet.');
        } else {
            $io->table(
                ['Rule', 'Covers', 'Condition', 'Destinations', 'Status'],
                array_map(static fn (array $r): array => [
                    $r['id'],
                    $r['covers_label'],
                    $r['condition_label'],
                    implode(', ', $r['destinations']),
                    $r['status_label'],
                ], array_values($rulesSummary)),
            );
        }

        if ($destinationsSummary !== []) {
            $io->table(
                ['Destination', 'Type', 'Target (redacted)', 'Signing', 'Ready'],
                array_map(static fn (array $d): array => [
                    $d['label'].' ('.$d['id'].')',
                    $d['type'],
                    $d['target'],
                    $d['signing'] ?? 'TransactionalMailer',
                    $d['ready'] ? 'yes' : 'no',
                ], array_values($destinationsSummary)),
            );
        }

        return $flagOn ? Command::SUCCESS : Command::FAILURE;
    }
}
