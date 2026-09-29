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
    description: 'Check the configured branch for source or release updates from Subschema-LLC/aggregate',
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
            ->setHelp('Checks updates_branch (default: master) of updates_repository (default: the official repository) for the updates_source in config/aggregate.yaml: Git pulls or packaged releases. This command does not change application code. Results are cached for one hour.');
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
        $installedBranch = $status['installed_branch'] ?? null;
        $io->definitionList(...[
            ['Repository' => OutputFormatter::escape((string) ($status['repository'] ?? ApplicationUpdateService::REPOSITORY))],
            ['Update source' => ($isRelease ? 'Release packages' : 'Git pull').(isset($status['source_setting']) ? ' (updates_source: '.$status['source_setting'].')' : '')],
            ['Configured branch' => OutputFormatter::escape($status['branch'] ?? 'Unavailable')],
            ...($isRelease ? [
                ['Installed version' => OutputFormatter::escape($status['current_version'] ?? (($status['adopting'] ?? false) ? 'Unknown (no release.json)' : 'Unavailable'))],
                ['Latest release version' => OutputFormatter::escape($status['latest_version'] ?? 'Unavailable')],
            ] : [
                ['Installed branch' => OutputFormatter::escape($installedBranch ?? ($status['current_commit'] !== null ? 'Detached HEAD' : 'Unavailable'))],
            ]),
            ['Installed commit' => $status['current_commit'] ?? 'Unavailable'],
            ['GitHub commit' => $status['latest_commit'] ?? 'Unavailable'],
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
                $io->text('See DEPLOYMENT.md#updates to install a verified package. Updates are not applied automatically.');
            }
        } elseif ($status['current_commit'] !== null && $installedBranch !== $status['branch']) {
            $io->warning('The installed branch does not match updates_branch. Switch branches manually or correct config/aggregate.yaml before pulling.');
        } elseif ($status['state'] === 'available') {
            $io->text('After preparing your deployment, run: php bin/console app:updates:pull');
            $io->text('See DEPLOYMENT.md#updates for backups, dependencies, migrations, assets, and worker restarts.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
