<?php

namespace App\Command;

use App\Service\WebsiteConfigManager;
use App\Service\WebsiteDomainPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:create-website',
    description: 'Create a new website for analytics tracking',
)]
class CreateWebsiteCommand extends Command
{
    public function __construct(
        private readonly WebsiteConfigManager $websiteManager,
        private readonly WebsiteDomainPolicy $domainPolicy = new WebsiteDomainPolicy(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Website name; asked for when left out')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Primary domain, such as example.com; asked for when left out')
            ->addOption('allow-all-domains', null, InputOption::VALUE_NONE, 'Accept events from any source using this website token.')
            ->addOption('allowed-domain', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'An exact hostname or *.subdomain suffix; repeat for multiple rules.')
            ->setHelp('Asks for the website name and primary domain, or takes them from --name and --domain, which a run without a terminal (-n, a script or a pipeline) needs. By default, new websites accept events only from their primary hostname. Use repeated --allowed-domain options for exact hosts and wildcard subdomains, or --allow-all-domains to remove origin restrictions. Existing websites can be edited in config/websites.yaml or on the Websites page.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $allowAll = $input->getOption('allow-all-domains');
        $allowedDomains = $input->getOption('allowed-domain');
        if ($allowAll && $allowedDomains !== []) {
            $io->error('Choose either --allow-all-domains or --allowed-domain.');
            return Command::INVALID;
        }

        $io->title('Create New Website');

        $nameOption = $input->getOption('name');
        $domainOption = $input->getOption('domain');
        $missing = array_keys(array_filter(['--name' => $nameOption, '--domain' => $domainOption], static fn (mixed $value): bool => $value === null));
        if (!$input->isInteractive() && $missing !== []) {
            // Without a terminal the questions have no answer.
            $io->error('Pass '.implode(' and ', $missing).' when running without a terminal, for example: app:create-website --name="Shop" --domain=shop.example.com');
            return Command::INVALID;
        }

        $validName = static function (mixed $answer): string {
            $answer = is_string($answer) ? trim($answer) : '';
            if ($answer === '') {
                throw new \RuntimeException('Website name cannot be empty.');
            }
            return $answer;
        };
        $validDomain = function (mixed $answer): string {
            if (!is_string($answer) || trim($answer) === '') {
                throw new \RuntimeException('Domain cannot be empty.');
            }
            return $this->domainPolicy->normalizePrimaryDomain($answer);
        };

        try {
            if ($nameOption !== null) {
                $name = $validName($nameOption);
            } else {
                $nameQuestion = new Question('Website name: ');
                $nameQuestion->setValidator($validName);
                $name = $helper->ask($input, $output, $nameQuestion);
            }

            if ($domainOption !== null) {
                $domain = $validDomain($domainOption);
            } else {
                $domainQuestion = new Question('Domain (e.g., example.com): ');
                $domainQuestion->setValidator($validDomain);
                $domain = $helper->ask($input, $output, $domainQuestion);
            }
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        // Generate token
        $token = bin2hex(random_bytes(16));

        try {
            $policy = $this->domainPolicy->normalize([
                'mode' => $allowAll ? 'all' : 'restricted',
                'domains' => $allowAll ? [] : ($allowedDomains !== [] ? $allowedDomains : [$domain]),
            ]);
            if (!$this->websiteManager->addWebsite($name, $domain, $token, $policy)) {
                $io->error('Could not save the website to config/websites.yaml. Check the configuration and its permissions.');
                return Command::FAILURE;
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        } catch (\Exception) {
            $io->error('Could not save the website to config/websites.yaml. Check the configuration and its permissions.');
            return Command::FAILURE;
        }

        $io->success('Website created successfully (saved to config/websites.yaml)!');
        $io->note($allowAll
            ? 'Domain restrictions are disabled. Any website or client using this token can submit events.'
            : 'Allowed domains: '.implode(', ', $policy['domains']));

        $io->section('Integration Code');
        $io->writeln('Add this code to your website:');
        $io->writeln('');
        $io->writeln('<script>');
        $io->writeln('  window.Aggregate = {');
        $io->writeln("    endpoint: 'https://your-analytics-host.com/api/receive',");
        $io->writeln("    websiteToken: '$token'");
        $io->writeln('  };');
        $io->writeln('</script>');
        $io->writeln('<script src="https://your-analytics-host.com/aggregate.js" async referrerpolicy="no-referrer"></script>');
        $io->writeln('');

        $io->note('Replace "your-analytics-host.com" with your actual domain.');

        return Command::SUCCESS;
    }
}
