<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Alerts\AlertEvaluator;
use App\Service\FeatureFlags;
use App\Service\Operations\TaskTrigger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:alerts:evaluate',
    description: 'Evaluate enabled alert rules on completed UTC hours and deliver transitions',
    aliases: ['app:alerts:run'],
)]
final class AlertsEvaluateCommand extends Command
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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Evaluate the latest completed UTC hour even if it was already checked')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the evaluation result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->features->isEnabled('alerts')) {
            $io->error('The requested feature is disabled or its configuration is invalid.');

            return Command::FAILURE;
        }

        $result = $this->evaluator->evaluate(TaskTrigger::command(), (bool) $input->getOption('force'));

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
