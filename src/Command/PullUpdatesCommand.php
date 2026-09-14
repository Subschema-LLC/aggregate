<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ApplicationUpdateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:updates:pull',
    description: 'Fast-forward a clean checkout from the same branch of Subschema-LLC/aggregate',
)]
final class PullUpdatesCommand extends Command
{
    public function __construct(private readonly ApplicationUpdateService $updates)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
Run as the deployment user after reviewing changes and preparing backups and maintenance.
Fetches the current branch from the official repository and fast-forwards a clean checkout.
Local edits, untracked files, detached HEADs and divergent history prevent the update.
This updates source code only. Complete dependencies, migrations, cache, assets and worker/PHP
restarts using DEPLOYMENT.md#updates before resuming service. No deployment steps run automatically.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->updates->pull();
        } catch (\RuntimeException $e) {
            $io->error(OutputFormatter::escape($e->getMessage()));

            return Command::FAILURE;
        }

        if (!$result['changed']) {
            $io->success('The checkout already matches the official branch; source code is unchanged.');

            return Command::SUCCESS;
        }

        $io->success('Application source code was updated.');
        $io->definitionList(
            ['Branch' => OutputFormatter::escape($result['branch'])],
            ['Previous commit' => $result['previous_commit']],
            ['Installed commit' => $result['current_commit']],
        );
        $io->warning('Deployment is not complete. Follow DEPLOYMENT.md#updates before resuming service.');
        $io->listing([
            'Install locked Composer dependencies for your deployment.',
            'Review and run the required database migrations.',
            'Clear the application cache and compile assets.',
            'Restart async workers and reload PHP as required by your host.',
            'Verify /api/health and resume collection.',
        ]);

        return Command::SUCCESS;
    }
}
