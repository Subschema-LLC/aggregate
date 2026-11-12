<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WebsiteConfigManager;
use App\Service\WebsiteDomainPolicy;
use App\Service\WebsiteScopeCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The Websites page's other actions: list the websites, change a website's
 * domain rules, or delete it. Websites are created with app:create-website.
 */
#[AsCommand(
    name: 'app:website',
    description: 'List websites, change a website\'s domain rules, or delete a website, as the Websites page does',
)]
final class WebsiteCommand extends Command
{
    public function __construct(
        private readonly WebsiteConfigManager $websites,
        private readonly WebsiteDomainPolicy $domainPolicy,
        private readonly ?WebsiteScopeCatalog $scopes = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'list, domains or delete', 'list')
            ->addArgument('token', InputArgument::OPTIONAL, 'With domains or delete: the website\'s token, as list shows it')
            ->addOption('allow-all-domains', null, InputOption::VALUE_NONE, 'With domains: accept events from any source using this website token')
            ->addOption('allowed-domain', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'With domains: an exact hostname or *.subdomain suffix; repeat for several')
            ->addOption('json', null, InputOption::VALUE_NONE, 'With list: print JSON')
            ->setHelp(<<<'HELP'
                  php bin/console app:website list
                  php bin/console app:website domains <token> --allowed-domain=example.com --allowed-domain=*.example.com
                  php bin/console app:website domains <token> --allow-all-domains
                  php bin/console app:website delete <token>

                Changing the domain rules keeps the website's token. Deleting a website stops collection for
                its token at once and removes it from its group; its collected events stay.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        $token = $input->getArgument('token');
        try {
            if ($action === 'list') {
                return $this->list($io, $output, (bool) $input->getOption('json'));
            }
            if (!in_array($action, ['domains', 'delete'], true)) {
                throw new \InvalidArgumentException('Use list, domains or delete.');
            }
            if (!is_string($token) || $token === '' || $this->websites->findOneByToken($token) === null) {
                throw new \InvalidArgumentException('Name a website by its token; app:website list shows them.');
            }
            if ($action === 'domains') {
                $allowAll = (bool) $input->getOption('allow-all-domains');
                $domains = array_values(array_filter(array_map('trim', (array) $input->getOption('allowed-domain')), static fn (string $domain): bool => $domain !== ''));
                if ($allowAll && $domains !== []) {
                    throw new \InvalidArgumentException('Use --allow-all-domains or --allowed-domain, not both.');
                }
                if (!$allowAll && $domains === []) {
                    throw new \InvalidArgumentException('Give the allowed domains with --allowed-domain, or use --allow-all-domains.');
                }
                $policy = $this->domainPolicy->normalize(['mode' => $allowAll ? 'all' : 'restricted', 'domains' => $domains]);
                if (!$this->websites->updateDomainPolicy($token, $policy)) {
                    throw new \RuntimeException('Website not found or config/websites.yaml could not be saved.');
                }
                $io->success('Website domain rules saved. The existing website token is unchanged.');
                $io->note($allowAll
                    ? 'Domain restrictions are disabled. Any website or client using this token can submit events.'
                    : 'Allowed domains: '.implode(', ', $policy['domains']));

                return Command::SUCCESS;
            }
            if (!$this->websites->removeWebsite($token)) {
                throw new \RuntimeException('Website not found or could not be deleted.');
            }
            try {
                $this->scopes?->forgetWebsite($token);
            } catch (\Throwable) {
                $io->warning('The website was deleted, but its group membership could not be updated. Correct config/website-scopes.yaml.');
            }
            $io->success('Website deleted.');

            return Command::SUCCESS;
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function list(SymfonyStyle $io, OutputInterface $output, bool $json): int
    {
        $rows = [];
        $ids = array_column($this->websites->sites(), 'id', 'token');
        foreach ($this->websites->getWebsites() as $website) {
            $policy = $website['domain_policy'] ?? null;
            $rows[] = [
                'name' => (string) ($website['name'] ?? ''),
                'domain' => (string) ($website['domain'] ?? ''),
                'token' => (string) ($website['token'] ?? ''),
                'id' => $ids[(string) ($website['token'] ?? '')] ?? null,
                'allowed_domains' => is_array($policy) && ($policy['mode'] ?? null) === 'all' ? 'all' : (is_array($policy['domains'] ?? null) ? $policy['domains'] : []),
            ];
        }
        if ($json) {
            $output->writeln(json_encode(['websites' => $rows], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        if ($rows === []) {
            $io->writeln('No website is registered. Add one with: php bin/console app:create-website');

            return Command::SUCCESS;
        }
        $io->table(['Website', 'Domain', 'Token', 'Script id', 'Allowed domains'], array_map(static fn (array $row): array => [
            $row['name'], $row['domain'], $row['token'], $row['id'] ?? '-', is_array($row['allowed_domains']) ? implode(', ', $row['allowed_domains']) : 'any',
        ], $rows));

        return Command::SUCCESS;
    }
}
