<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ApplicationUpdateService;
use App\Service\DocumentationLinks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:updates:pull',
    description: 'Fast-forward a clean checkout from the configured branch of Subschema-LLC/aggregate',
)]
final class PullUpdatesCommand extends Command
{
    public function __construct(
        private readonly ApplicationUpdateService $updates,
        private readonly ?DocumentationLinks $documentation = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
Run as the deployment user after reviewing changes and preparing backups and maintenance.
Fetches updates_branch from config/aggregate.yaml (default: master) in the official repository.
The installed branch must match that setting; the command never switches branches.
Local edits, untracked files, detached HEADs and divergent history prevent the update, except
edits to config/goals.yaml, config/navigation.yaml and config/quick_search.yaml: those move to the
untracked config/NAME.local.yaml override before the shipped file is updated.
This updates source code only. To also install dependencies, run migrations, rebuild the cache and
show a maintenance page while files change, use app:updates:apply instead.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->updates->pull();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$result['changed']) {
            $io->success('The checkout already matches the official branch; source code is unchanged.');

            return Command::SUCCESS;
        }

        $io->success('Application source code was updated.');
        foreach ($result['overrides_created'] ?? [] as $override) {
            $io->note('Your edits now live in '.$override.'. It replaces the matching shipped parameter as a whole.');
        }
        $io->definitionList(
            ['Branch' => OutputFormatter::escape($result['branch'])],
            ['Previous commit' => $result['previous_commit']],
            ['Installed commit' => $result['current_commit']],
        );
        $io->warning('Deployment is not complete. Follow the manual update steps before resuming service, or use app:updates:apply next time to run these steps automatically. Manual update steps: '
            .($this->documentation?->reference('install.manual-update') ?? DocumentationLinks::file('install.manual-update')));
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
