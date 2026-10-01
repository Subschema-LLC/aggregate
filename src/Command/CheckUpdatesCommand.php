<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ApplicationUpdateService;
use App\Service\DocumentationLinks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:updates:check',
    description: 'Check the configured branch for source or release updates from Subschema-LLC/aggregate',
)]
final class CheckUpdatesCommand extends Command
{
    public function __construct(
        private readonly ApplicationUpdateService $updates,
        private readonly ?DocumentationLinks $documentation = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Check GitHub now instead of using cached results')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the update status as JSON')
            ->setHelp('Checks updates_branch (default: master) of updates_repository (default: the official repository) in config/aggregate.yaml. The update method (updates_method, see app:updates:method) decides what is compared: the repository method compares Git commits, the release method checks signed release ZIPs, and the deployment method compares the deployed commit with the branch and reports whether app:updates:deployed ran for the current files. Until a method is chosen, a Git clone uses the repository method and anything else uses release ZIPs. This command does not change application code. Results are cached for one hour.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->updates->check((bool) $input->getOption('refresh'));
        $failed = in_array($status['state'], ['unknown', 'error', 'unavailable', 'incompatible', 'disabled'], true);

        if ($input->getOption('json')) {
            $output->writeln(
                json_encode($status, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                OutputInterface::OUTPUT_RAW,
            );

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $isRelease = ($status['installation_type'] ?? 'git') === 'release';
        $isDeployment = ($status['installation_type'] ?? 'git') === 'deployment';
        $installedBranch = $status['installed_branch'] ?? null;
        $io->definitionList(...[
            ['Repository' => OutputFormatter::escape((string) ($status['repository'] ?? ApplicationUpdateService::REPOSITORY))],
            ['Update method' => ($isRelease ? 'Release ZIPs' : ($isDeployment ? 'Deployed another way' : 'From the repository (Git, advanced)')).(($status['update_method'] ?? null) === null ? ' (not chosen; fits this directory)' : '')],
            ['Configured branch' => OutputFormatter::escape($status['branch'] ?? 'Unavailable')],
            ...($isDeployment ? [
                ['Deployed commit' => ($status['current_commit'] ?? 'Unknown').(($status['commit_source'] ?? null) === 'repository' ? ' (read from the deployment repository)' : '')],
                ['GitHub commit' => $status['latest_commit'] ?? 'Unavailable'],
                ['Commits behind' => is_int($status['commits_behind'] ?? null) ? (string) $status['commits_behind'] : 'Unknown'],
                ['Post-deployment steps' => is_int($status['deployed_at'] ?? null)
                    ? 'Last ran '.gmdate('Y-m-d H:i', $status['deployed_at']).' UTC'.(($status['deployment_pending'] ?? false) ? '; files changed since' : '')
                    : 'Not recorded'],
                ['Deployment repository' => OutputFormatter::escape((string) ($status['deployment_repository'] ?? 'None found'))],
            ] : ($isRelease ? [
                ['Installed version' => OutputFormatter::escape($status['current_version'] ?? (($status['adopting'] ?? false) ? 'Unknown (no release.json)' : 'Unavailable'))],
                ['Latest release version' => OutputFormatter::escape($status['latest_version'] ?? 'Unavailable')],
                ['Installed commit' => $status['current_commit'] ?? 'Unavailable'],
                ['GitHub commit' => $status['latest_commit'] ?? 'Unavailable'],
            ] : [
                ['Installed branch' => OutputFormatter::escape($installedBranch ?? ($status['current_commit'] !== null ? 'Detached HEAD' : 'Unavailable'))],
                ['Installed commit' => $status['current_commit'] ?? 'Unavailable'],
                ['GitHub commit' => $status['latest_commit'] ?? 'Unavailable'],
            ])),
            ['Checked at (UTC)' => $status['checked_at'] === null ? 'Not checked' : gmdate('Y-m-d H:i:s', $status['checked_at'])],
        ]);

        $message = $status['message'];
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
        if ($isRelease) {
            foreach (['release_url' => 'Release notes', 'package_url' => 'Package', 'manifest_url' => 'Manifest', 'signature_url' => 'Manifest signature'] as $key => $label) {
                if (($status[$key] ?? null) !== null) {
                    $io->text($label.': '.OutputFormatter::escape($status[$key]));
                }
            }
            if (($status['package_url'] ?? null) !== null) {
                $io->note('The release signature has not been verified by this check. Download the package, manifest and signature, then run:');
                $io->text('php bin/console app:updates:verify-package PACKAGE MANIFEST SIGNATURE');
                $io->text('To install it, run php bin/console app:updates:apply (it verifies the signature first).');
                $io->text('Update guide: '.$this->guide());
            }
        } elseif ($isDeployment) {
            if ($status['deployment_pending'] ?? false) {
                $io->warning(($status['deployed_at'] ?? null) === null
                    ? 'The post-deployment steps have not been recorded here yet. Run php bin/console app:updates:deployed after each deployment so database migrations run and the cache is rebuilt.'
                    : 'Files changed since the post-deployment steps last ran, so database migrations and cache rebuilds may be pending. Run php bin/console app:updates:deployed.');
            }
            if ($status['state'] === 'available') {
                $io->text('Deploy the newer commits with your deployment tool, then run php bin/console app:updates:deployed (or let the tool\'s deployment action run it).');
            }
            $io->text('Guide: '.$this->guide('updates.deployment'));
        } elseif ($status['current_commit'] !== null && $installedBranch !== $status['branch']) {
            $io->warning('The installed branch does not match updates_branch. Switch branches manually or correct config/aggregate.yaml before pulling.');
        } elseif ($status['state'] === 'available') {
            $io->text('To install it, run php bin/console app:updates:apply, which also installs dependencies, runs migrations and rebuilds assets and the cache. app:updates:pull only updates the code.');
            $io->text('Update guide: '.$this->guide());
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /** The update guide on the documentation site, or its file when documentation links are off. */
    private function guide(string $topic = 'updates'): string
    {
        return $this->documentation?->reference($topic) ?? DocumentationLinks::file($topic);
    }
}
