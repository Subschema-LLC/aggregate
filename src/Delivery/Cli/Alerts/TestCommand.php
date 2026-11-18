<?php

declare(strict_types=1);

namespace App\Delivery\Cli\Alerts;

use App\Service\Alerts\AlertEvaluator;
use App\Service\FeatureFlags;
use App\Service\Operations\TaskTrigger;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:alerts:test',
    description: 'Send a test alert to a configured webhook or email destination without changing '
        .'rule state',
)]
final class TestCommand
{
    public function __construct(
        private readonly AlertEvaluator $evaluator,
        private readonly FeatureFlags $features,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Destination ID (e.g. geektown-hub, ops-email)')]
        string $destination,
        #[Option('Specific listed recipient email address (or __all__ for all listed recipients)')]
        ?string $recipient = null,
        #[Option('Optional rule ID to use in the test payload')]
        ?string $rule = null,
    ): int {
        try {
            $this->features->assertEnabled('alerts');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        try {
            $trigger = TaskTrigger::command();
            $result = $this->evaluator->sendTest(trim($destination), $trigger, $recipient, $rule);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$result['sent']) {
            $io->error(sprintf(
                'Test alert to %s failed: %s',
                $result['destination_label'],
                $result['reason'],
            ));

            return Command::FAILURE;
        }
        $io->success(sprintf(
            'Test sent to %s (id %s). Rule status did not change.',
            $result['destination_label'],
            $result['alert_id'],
        ));

        return Command::SUCCESS;
    }
}
