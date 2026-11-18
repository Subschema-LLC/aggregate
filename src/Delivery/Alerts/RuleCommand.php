<?php

declare(strict_types=1);

namespace App\Delivery\Alerts;

use App\Features\Alerts\DeleteAlertRule\DeleteAlertRule;
use App\Features\Alerts\SaveAlertRule\RuleAlreadyExists;
use App\Features\Alerts\SaveAlertRule\SaveAlertRule;
use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSettings;
use App\Service\FeatureFlags;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:alerts:rule',
    description: 'List, create, edit or delete alert rules in config/aggregate.yaml',
    aliases: ['app:alerts:rules'],
)]
final class RuleCommand
{
    public function __construct(
        private readonly AlertSettings $settings,
        private readonly FeatureFlags $features,
        private readonly SaveAlertRule $saveRule,
        private readonly DeleteAlertRule $deleteRule,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('list, create, edit or delete')]
        string $action,
        #[MapInput]
        RuleOptions $options,
    ): int {
        $action = strtolower(trim($action));
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
                'create', 'add' => $this->create($io, $options),
                'edit', 'update' => $this->edit($io, $options),
                'delete', 'remove' => $this->delete($io, $options),
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

    private function create(SymfonyStyle $io, RuleOptions $options): int
    {
        try {
            $saved = $this->saveRule->create($options->candidate([]));
        } catch (RuleAlreadyExists $e) {
            throw new \InvalidArgumentException(
                sprintf('Alert rule "%s" already exists. Use edit to change it.', $e->id),
            );
        }
        $condition = AlertRuleValidator::formatAlertCondition($saved);
        $io->success(sprintf('Saved alert rule "%s" (%s).', $saved['id'], $condition));

        return Command::SUCCESS;
    }

    private function edit(SymfonyStyle $io, RuleOptions $options): int
    {
        $id = trim((string) ($options->id ?? ''));
        $base = $this->settings->rule($id) ?? ['id' => $id];
        $saved = $this->saveRule->edit($options->candidate($base));
        $condition = AlertRuleValidator::formatAlertCondition($saved);
        $io->success(sprintf('Updated alert rule "%s" (%s).', $saved['id'], $condition));

        return Command::SUCCESS;
    }

    private function delete(SymfonyStyle $io, RuleOptions $options): int
    {
        $id = trim((string) ($options->id ?? ''));
        $this->deleteRule->delete($id);
        $io->success(sprintf('Deleted alert rule "%s".', $id));

        return Command::SUCCESS;
    }

    private function unknown(SymfonyStyle $io): int
    {
        $io->error('Choose list, create, edit or delete.');

        return Command::INVALID;
    }
}
