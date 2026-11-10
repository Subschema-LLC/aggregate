<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSettings;
use App\Service\FeatureFlags;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:alerts:rule',
    description: 'List, create, edit or delete alert rules in config/aggregate.yaml',
    aliases: ['app:alerts:rules'],
)]
final class AlertsRuleCommand extends Command
{
    public function __construct(
        private readonly AlertSettings $settings,
        private readonly FeatureFlags $features,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'list, create, edit or delete')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Rule ID slug (e.g. geektown-weekly)')
            ->addOption('covers', null, InputOption::VALUE_REQUIRED, 'Covered target: all, group:<id>, or website:<token>')
            ->addOption('website', null, InputOption::VALUE_REQUIRED, 'Covered website token')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Covered website group ID')
            ->addOption('all-websites', null, InputOption::VALUE_NONE, 'Cover all registered websites')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Rule source: events or tracking_failures')
            ->addOption('privacy-mode', null, InputOption::VALUE_REQUIRED, 'Filter privacy mode (events only): all, enhanced or anonymous')
            ->addOption('event-name', null, InputOption::VALUE_REQUIRED, 'Filter event name (events only, e.g. page_view)')
            ->addOption('condition', null, InputOption::VALUE_REQUIRED, 'Condition: no_events, fewer_than or more_than')
            ->addOption('threshold', 'N', InputOption::VALUE_REQUIRED, 'Threshold integer N')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Sliding window: hour, day or week')
            ->addOption('destination', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Destination ID (repeatable)')
            ->addOption('enabled', null, InputOption::VALUE_REQUIRED, 'Whether the rule is enabled (1 or 0)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = strtolower(trim((string) $input->getArgument('action')));

        if ($action !== 'list') {
            try {
                $this->features->assertEnabled('alerts');
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        try {
            return match ($action) {
                'list' => $this->list($io),
                'create', 'add' => $this->create($io, $input),
                'edit', 'update' => $this->edit($io, $input),
                'delete', 'remove' => $this->delete($io, $input),
                default => $this->unknown($io),
            };
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function list(SymfonyStyle $io): int
    {
        $rules = $this->settings->rules();
        if ($rules === []) {
            $io->text('No alert rules.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($rules as $rule) {
            $rows[] = [
                $rule['id'],
                $this->settings->describeCovers($rule),
                AlertSettings::describeCondition($rule),
                implode(', ', $rule['destinations']),
                $rule['enabled'] ? 'enabled' : 'off',
            ];
        }
        $io->table(['Rule', 'Covers', 'Condition', 'Destinations', 'State'], $rows);

        return Command::SUCCESS;
    }

    private function create(SymfonyStyle $io, InputInterface $input): int
    {
        $id = trim((string) ($input->getOption('id') ?? ''));
        if ($id !== '' && $this->settings->rule($id) !== null) {
            throw new \InvalidArgumentException(sprintf('Alert rule "%s" already exists. Use edit to change it.', $id));
        }

        $candidate = $this->candidateFromInput($input, []);
        $saved = $this->settings->saveRule($candidate);
        $io->success(sprintf('Saved alert rule "%s" (%s).', $saved['id'], AlertRuleValidator::formatAlertCondition($saved)));

        return Command::SUCCESS;
    }

    private function edit(SymfonyStyle $io, InputInterface $input): int
    {
        $id = trim((string) ($input->getOption('id') ?? ''));
        $existing = $this->settings->rule($id);
        if ($existing === null) {
            throw new \InvalidArgumentException(sprintf('Alert rule "%s" does not exist.', $id));
        }

        $candidate = $this->candidateFromInput($input, $existing);
        $saved = $this->settings->saveRule($candidate);
        $io->success(sprintf('Updated alert rule "%s" (%s).', $saved['id'], AlertRuleValidator::formatAlertCondition($saved)));

        return Command::SUCCESS;
    }

    private function delete(SymfonyStyle $io, InputInterface $input): int
    {
        $id = trim((string) ($input->getOption('id') ?? ''));
        $this->settings->deleteRule($id);
        $io->success(sprintf('Deleted alert rule "%s".', $id));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $base
     *
     * @return array<string, mixed>
     */
    private function candidateFromInput(InputInterface $input, array $base): array
    {
        $candidate = $base;
        if ($input->getOption('id') !== null) {
            $candidate['id'] = (string) $input->getOption('id');
        }
        if ($input->getOption('all-websites') === true) {
            $candidate['covers'] = 'all';
        } elseif ($input->getOption('group') !== null) {
            $candidate['covers'] = 'group:'.trim((string) $input->getOption('group'));
        } elseif ($input->getOption('website') !== null) {
            $candidate['covers'] = 'website:'.trim((string) $input->getOption('website'));
        } elseif ($input->getOption('covers') !== null) {
            $candidate['covers'] = (string) $input->getOption('covers');
        }
        if ($input->getOption('source') !== null) {
            $candidate['source'] = (string) $input->getOption('source');
            if (strtolower(trim($candidate['source'])) === AlertRuleValidator::SOURCE_TRACKING_FAILURES) {
                $candidate['privacy_mode'] = AlertRuleValidator::PRIVACY_ALL;
                $candidate['event_name'] = '';
            }
        }
        if ($input->getOption('privacy-mode') !== null) {
            $candidate['privacy_mode'] = (string) $input->getOption('privacy-mode');
        }
        if ($input->getOption('event-name') !== null) {
            $candidate['event_name'] = (string) $input->getOption('event-name');
        }
        if ($input->getOption('condition') !== null) {
            $candidate['condition'] = (string) $input->getOption('condition');
        }
        if ($input->getOption('threshold') !== null) {
            $candidate['threshold'] = $input->getOption('threshold');
        }
        if ($input->getOption('window') !== null) {
            $candidate['window'] = (string) $input->getOption('window');
        }
        $destinations = $input->getOption('destination');
        if (is_array($destinations) && $destinations !== []) {
            $candidate['destinations'] = $destinations;
        }
        if ($input->getOption('enabled') !== null) {
            $candidate['enabled'] = $input->getOption('enabled');
        }

        return $candidate;
    }

    private function unknown(SymfonyStyle $io): int
    {
        $io->error('Choose list, create, edit or delete.');

        return Command::INVALID;
    }
}
