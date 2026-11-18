<?php

declare(strict_types=1);

namespace App\Delivery\Cli\Alerts;

use App\Service\Alerts\AlertEvaluator;
use App\Service\FeatureFlags;
use App\Service\Operations\TaskTrigger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:alerts:evaluate',
    description: 'Evaluate enabled alert rules on completed UTC hours and deliver transitions',
    aliases: ['app:alerts:run'],
)]
final class EvaluateCommand
{
    public function __construct(
        private readonly AlertEvaluator $evaluator,
        private readonly FeatureFlags $features,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Option('Evaluate the latest completed UTC hour even if it was already checked')]
        bool $force = false,
        #[Option('Print the evaluation result as JSON')]
        bool $json = false,
    ): int {
        if (!$this->features->isEnabled('alerts')) {
            $io->error('The requested feature is disabled or its configuration is invalid.');

            return Command::FAILURE;
        }

        $result = $this->evaluator->evaluate(TaskTrigger::command(), $force);
        if ($json) {
            $flags = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
            $output->writeln(json_encode($result, $flags));

            return $result['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;
        }

        if ($result['status'] === 'failed') {
            $io->error($result['message']);

            return Command::FAILURE;
        }
        $io->success($result['message']);

        return Command::SUCCESS;
    }
}
