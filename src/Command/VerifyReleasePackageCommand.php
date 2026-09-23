<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReleasePackageVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:updates:verify-package', description: 'Verify a signed release ZIP without extracting it or changing the installation')]
final class VerifyReleasePackageCommand extends Command
{
    public function __construct(private readonly ReleasePackageVerifier $verifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('package', InputArgument::REQUIRED, 'Path to the downloaded release ZIP')
            ->addArgument('manifest', InputArgument::REQUIRED, 'Path to aggregate-release.json')
            ->addArgument('signature', InputArgument::REQUIRED, 'Path to aggregate-release.json.sig')
            ->setHelp('Checks the trusted Ed25519 signature, configured branch, PHP/extension requirements, package size/SHA-256, ZIP paths, and embedded release identity. No network, extraction, code replacement, or migrations are performed. Configure config/release-signing.pub or updates_signing_public_key independently of the downloaded package.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $release = $this->verifier->verify(
                (string) $input->getArgument('package'),
                (string) $input->getArgument('manifest'),
                (string) $input->getArgument('signature'),
            );
            $io->success('Verified Aggregate '.$release['version'].' from '.$release['branch'].'.');
            $io->text('SHA-256: '.$release['package']['sha256']);
            $io->note('Verification is complete. The installation has not been changed. Follow the release deployment instructions for backups, configuration, migrations, and runtime restarts.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            // SymfonyStyle escapes block messages; escaping again adds literal backslashes.
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
