<?php

namespace App\Command;

use App\Entity\Website;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        $io->title('Create New Website');

        // Ask for website name
        $nameQuestion = new Question('Website name: ');
        $nameQuestion->setValidator(function ($answer) {
            if (empty(trim($answer))) {
                throw new \RuntimeException('Website name cannot be empty.');
            }
            return trim($answer);
        });
        $name = $helper->ask($input, $output, $nameQuestion);

        // Ask for domain
        $domainQuestion = new Question('Domain (e.g., example.com): ');
        $domainQuestion->setValidator(function ($answer) {
            if (empty(trim($answer))) {
                throw new \RuntimeException('Domain cannot be empty.');
            }
            $domain = strtolower(trim($answer));
            // Remove protocol if present
            $domain = preg_replace('#^https?://#', '', $domain);
            // Remove trailing slash
            $domain = rtrim($domain, '/');
            return $domain;
        });
        $domain = $helper->ask($input, $output, $domainQuestion);

        // Generate token
        $token = bin2hex(random_bytes(16));

        // Create website entity
        $website = new Website();
        $website->setName($name);
        $website->setDomain($domain);
        $website->setPublicToken($token);

        $this->em->persist($website);
        $this->em->flush();

        $io->success('Website created successfully!');

        $io->section('Integration Code');
        $io->writeln('Add this code to your website:');
        $io->writeln('');
        $io->writeln('<script>');
        $io->writeln('  window.Aggregate = {');
        $io->writeln("    endpoint: 'https://your-analytics-host.com/api/receive',");
        $io->writeln("    websiteToken: '$token'");
        $io->writeln('  };');
        $io->writeln('</script>');
        $io->writeln('<script src="https://your-analytics-host.com/aggregate.js" async></script>');
        $io->writeln('');

        $io->note('Replace "your-analytics-host.com" with your actual domain.');

        return Command::SUCCESS;
    }
}
