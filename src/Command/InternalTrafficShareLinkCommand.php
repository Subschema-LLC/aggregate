<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AggregateConfigLoader;
use App\Service\CollectionAddress;
use App\Service\InternalTrafficSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The Organization traffic page's share link: shown, replaced by a new one
 * (which stops the old link working) or revoked. Browsers already marked
 * stay marked. The marker itself is set with app:config.
 */
#[AsCommand(
    name: 'app:internal-traffic:share-link',
    description: 'Show, replace or revoke the link that marks a browser as organization traffic',
)]
final class InternalTrafficShareLinkCommand extends Command
{
    public function __construct(
        private readonly InternalTrafficSettings $settings,
        private readonly AggregateConfigLoader $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'show, rotate or revoke', 'show')
            ->setHelp(<<<'HELP'
                  php bin/console app:internal-traffic:share-link          the link, when there is one
                  php bin/console app:internal-traffic:share-link rotate   a new link; the old one stops working
                  php bin/console app:internal-traffic:share-link revoke   no link; marked browsers stay marked

                The marker's name, value and storage are set with: php bin/console app:config set organization-traffic.<setting> <value>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $action = (string) $input->getArgument('action');
            if ($action === 'rotate') {
                $this->settings->rotateShareToken();
                $io->success('A new share link was created. Previous links no longer work.');
            } elseif ($action === 'revoke') {
                $this->settings->revokeShareToken();
                $io->success('The share link was revoked. Existing browser markers remain in place.');

                return Command::SUCCESS;
            } elseif ($action !== 'show') {
                throw new \InvalidArgumentException('Use show, rotate or revoke.');
            }
            $token = $this->settings->getShareToken();
            if ($token === '') {
                $io->writeln('There is no share link. Create one with: php bin/console app:internal-traffic:share-link rotate');

                return Command::SUCCESS;
            }
            $base = (new CollectionAddress($this->config))->baseUrl();
            $io->writeln($base !== null ? $base.'/internal-traffic/'.rawurlencode($token) : 'The share link needs app_host or collection_base_url to be set.');

            return Command::SUCCESS;
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
