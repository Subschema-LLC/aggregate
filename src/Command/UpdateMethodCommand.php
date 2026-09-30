<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ApplicationUpdateService;
use App\Service\DocumentationLinks;
use App\Service\Update\ApplicationUpdater;
use App\Service\UpdateSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Show or choose the update method (updates_method) without the dashboard. It
 * saves the same YAML setting as the Updates page.
 */
#[AsCommand(name: 'app:updates:method', description: 'Show or choose how this installation updates: release (ZIPs) or repository (Git, advanced)')]
final class UpdateMethodCommand extends Command
{
    public function __construct(
        private readonly UpdateSettings $settings,
        private readonly ApplicationUpdateService $updates,
        private readonly ApplicationUpdater $updater,
        private readonly ?DocumentationLinks $documentation = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('method', InputArgument::OPTIONAL, 'release or repository; omit to show the current method')
            ->setHelp(<<<'HELP'
Without an argument, shows the update method and whether it fits this directory.

  release     Signed release ZIPs (recommended). The server needs no Git,
              Composer or Node; each release is verified before it is installed.
  repository  Fast-forward pulls of a Git clone (advanced). Installs the newest
              commit of updates_branch, which is not a signed release, and needs
              Git and Composer on the server.

The choice is saved as updates_method in the active config/aggregate.yaml, the
same setting the dashboard Updates page changes. It does not change any files.
See docs/UPDATES.md before switching.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $method = $input->getArgument('method');
        try {
            if ($method === null) {
                return $this->show($io);
            }
            $method = UpdateSettings::validateMethod($method);
            $problem = $this->updater->methodChangeProblem();
            if ($problem !== null) {
                $io->error($problem);

                return Command::FAILURE;
            }
            $this->settings->saveMethod($method);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('This installation now updates '.$this->label($method).'. Saved as updates_method in config/aggregate.yaml.');
        if ($method !== $this->updates->detectedMethod()) {
            $io->warning($method === UpdateSettings::METHOD_REPOSITORY
                ? 'This directory is not a Git clone yet, so repository updates cannot run until it is one. See "Set up a Git clone": '.$this->guide('updates.git-clone')
                : 'This directory is a Git clone, so release ZIPs cannot be installed over it. Install a release into a new directory; see "Switch methods": '.$this->guide('updates.switch'));
        }

        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io): int
    {
        $source = $this->updates->source();
        $io->definitionList(
            ['Update method' => $source['method'] === null ? 'Not chosen' : ucfirst($this->label($source['method']))],
            ['This directory' => $source['detected'] === UpdateSettings::METHOD_REPOSITORY ? 'Git clone (fits repository updates)' : 'No .git folder (fits release ZIPs)'],
            ['Used by app:updates:apply' => ucfirst($this->label($source['source'] === 'git' ? UpdateSettings::METHOD_REPOSITORY : UpdateSettings::METHOD_RELEASE))],
        );
        if ($source['mismatch'] !== null) {
            $io->error($source['mismatch']);

            return Command::FAILURE;
        }
        if ($source['method'] === null) {
            $io->note('Choose a method with php bin/console app:updates:method release (recommended) or repository (advanced), or on the Updates page.');
        }

        return Command::SUCCESS;
    }

    private function label(string $method): string
    {
        return $method === UpdateSettings::METHOD_REPOSITORY ? 'directly from the repository (advanced)' : 'with release ZIPs';
    }

    /** A section of the update guide on the documentation site, or its file when documentation links are off. */
    private function guide(string $topic): string
    {
        return $this->documentation?->reference($topic) ?? DocumentationLinks::file($topic);
    }
}
