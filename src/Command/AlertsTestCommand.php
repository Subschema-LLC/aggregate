<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Alerts\AlertEvaluator;
use App\Service\FeatureFlags;
use App\Service\Operations\TaskTrigger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:alerts:test',
    description: 'Send a test alert to a configured webhook or email destination without changing rule state',
)]
final class AlertsTestCommand extends Command
{
    public function __construct(
        private readonly AlertEvaluator $evaluator,
        private readonly FeatureFlags $features,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('destination', InputArgument::REQUIRED, 'Destination ID (e.g. geektown-hub, ops-email)')
            ->addOption('recipient', null, InputOption::VALUE_REQUIRED, 'Specific listed recipient email address (or __all__ for all listed recipients)')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, 'Optional rule ID to use in the test payload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $this->features->assertEnabled('alerts');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $destinationId = trim((string) $input->getArgument('destination'));
        $recipient = $input->getOption('recipient') !== null ? (string) $input->getOption('recipient') : null;
        $ruleId = $input->getOption('rule') !== null ? (string) $input->getOption('rule') : null;

        try {
            $result = $this->evaluator->sendTest($destinationId, TaskTrigger::command(), $recipient, $ruleId);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$result['sent']) {
            $io->error(sprintf('Test alert to %s failed: %s', $result['destination_label'], $result['reason']));

            return Command::FAILURE;
        }

        $io->success(sprintf('Test sent to %s (id %s). Rule status did not change.', $result['destination_label'], $result['alert_id']));

        return Command::SUCCESS;
    }
}
