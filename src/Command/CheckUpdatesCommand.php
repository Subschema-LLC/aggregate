<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ApplicationUpdateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:updates:check',
    description: 'Check the current Git branch for updates from Subschema-LLC/aggregate',
)]
final class CheckUpdatesCommand extends Command
{
    public function __construct(private readonly ApplicationUpdateService $updates)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Check GitHub now instead of using cached results')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the update status as JSON')
            ->setHelp('Checks the same branch in the official repository. This command does not change application code. Results are cached for one hour.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->updates->check((bool) $input->getOption('refresh'));
        $failed = in_array($status['state'], ['unknown', 'error', 'unavailable'], true);

        if ($input->getOption('json')) {
            $output->writeln(
                json_encode($status, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                OutputInterface::OUTPUT_RAW,
            );

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->definitionList(
            ['Repository' => ApplicationUpdateService::REPOSITORY],
            ['Branch' => OutputFormatter::escape($status['branch'] ?? 'Unavailable')],
            ['Installed commit' => $status['current_commit'] ?? 'Unavailable'],
            ['GitHub commit' => $status['latest_commit'] ?? 'Unavailable'],
            ['Checked at (UTC)' => $status['checked_at'] === null ? 'Not checked' : gmdate('Y-m-d H:i:s', $status['checked_at'])],
        );

        $message = OutputFormatter::escape($status['message']);
        if ($failed) {
            $io->error($message);
        } elseif ($status['state'] === 'up_to_date') {
            $io->success($message);
        } else {
            $io->note($message);
        }

        if ($status['compare_url'] !== null) {
            $io->text('Review changes: '.OutputFormatter::escape($status['compare_url']));
        }
        if ($status['state'] === 'available') {
            $io->text('After preparing your deployment, run: php bin/console app:updates:pull');
            $io->text('See DEPLOYMENT.md#updates for backups, dependencies, migrations, assets, and worker restarts.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
