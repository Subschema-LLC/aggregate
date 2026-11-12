<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Cluster\ClusterSettings;
use App\Service\LocalEnvironmentFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:cluster:key',
    description: 'Create the key the servers of a cluster sign their requests with (CLUSTER_KEY in .env.local), or show it',
)]
final class ClusterKeyCommand extends Command
{
    public function __construct(
        private readonly ClusterSettings $settings,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('show', null, InputOption::VALUE_NONE, 'Print the key, to give to app:cluster:join on the other servers')
            ->addOption('rotate', null, InputOption::VALUE_NONE, 'Replace the key; every other server must join again with the new one');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = new LocalEnvironmentFile($this->projectDir, $this->environment);
        $key = $this->settings->key();
        if ($key === null || $input->getOption('rotate')) {
            if (($problem = $file->problem(ClusterSettings::KEY_VARIABLE)) !== null) {
                $output->writeln('<error>'.$problem.'</error>');

                return Command::FAILURE;
            }
            $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $file->set(ClusterSettings::KEY_VARIABLE, $key, 'Cluster requests are signed with this key (app:cluster:key)');
            $output->writeln($input->getOption('rotate') ? 'Saved a new CLUSTER_KEY in .env.local. Every other server must join again with it.' : 'Saved a new CLUSTER_KEY in .env.local.');
        } else {
            $output->writeln('CLUSTER_KEY is set.');
        }
        if ($input->getOption('show')) {
            $output->writeln($key, OutputInterface::VERBOSITY_QUIET);
        } else {
            $output->writeln('Run with --show to print it for app:cluster:join on the other servers.');
        }

        return Command::SUCCESS;
    }
}
