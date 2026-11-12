<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AggregateConfigLoader;
use App\Service\Cluster\BalancerConfig;
use App\Service\Cluster\ClusterServers;
use App\Service\Cluster\ClusterSettings;
use App\Service\DocumentationLinks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the balancer's configuration on the administration server, where
 * the cluster list is. The balancer itself runs no Aggregate. Everything the
 * configuration is built from is a YAML setting (collection_base_url, the
 * cluster list, cluster_collection_port, rate_limit_per_minute); the options
 * only describe the balancer: its software, its certificate and its modules.
 */
#[AsCommand(
    name: 'app:server:balancer-config',
    description: 'Print the Nginx or Caddy configuration for the balancer in front of the collection servers',
)]
final class ServerBalancerConfigCommand extends Command
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ClusterServers $servers,
        private readonly ?DocumentationLinks $documentation = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('nginx', null, InputOption::VALUE_NONE, 'Print an Nginx configuration')
            ->addOption('caddy', null, InputOption::VALUE_NONE, 'Print a Caddyfile')
            ->addOption('part', null, InputOption::VALUE_REQUIRED, 'Nginx: site (a whole site file), http (for the http context) or server (inside the domain\'s server block, such as a hosting panel\'s additional directives)', BalancerConfig::PART_SITE)
            ->addOption('certificate', null, InputOption::VALUE_REQUIRED, 'Nginx: the certificate file (default: Let\'s Encrypt\'s for the collection host)')
            ->addOption('certificate-key', null, InputOption::VALUE_REQUIRED, 'Nginx: the certificate\'s key file')
            ->addOption('caddy-rate-limit', null, InputOption::VALUE_NONE, 'Caddy: limit /api/receive with the caddy-ratelimit module, which stock Caddy lacks')
            ->setHelp(<<<HELP
                Run on the administration server; install the output on the balancer, which runs no
                Aggregate. It is built from YAML settings, which can also be set from the command line:

                  collection_base_url       the balancer's address (app:collection-address)
                  cluster                   the collection servers (app:cluster:server add ... --role=collection)
                  cluster_collection_port   their port on the private network, 80 by default (app:cluster:settings)
                  rate_limit_per_minute     the limit per address on /api/receive

                Run it again after changing any of them. Messages go to standard error, so the
                configuration can be redirected to a file:

                  php bin/console app:server:balancer-config --nginx > aggregate-balancer.conf

                {$this->guide()}
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $nginx = (bool) $input->getOption('nginx');
        $caddy = (bool) $input->getOption('caddy');
        try {
            if ($nginx === $caddy) {
                throw new \InvalidArgumentException('Choose the balancer\'s software: --nginx or --caddy.');
            }
            if ($caddy && ($input->getOption('part') !== BalancerConfig::PART_SITE || $input->getOption('certificate') !== null || $input->getOption('certificate-key') !== null)) {
                throw new \InvalidArgumentException('--part, --certificate and --certificate-key are for Nginx. Caddy gets its certificate itself.');
            }
            if ($nginx && $input->getOption('caddy-rate-limit')) {
                throw new \InvalidArgumentException('--caddy-rate-limit is for Caddy. The Nginx configuration always limits /api/receive.');
            }
            $balancer = $this->balancer();
            $configuration = $nginx
                ? $balancer->nginx((string) $input->getOption('part'), $input->getOption('certificate'), $input->getOption('certificate-key'))
                : $balancer->caddy((bool) $input->getOption('caddy-rate-limit'));
        } catch (\InvalidArgumentException $e) {
            $errors->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        foreach ($this->warnings($balancer) as $warning) {
            $errors->writeln('<comment>'.$warning.'</comment>');
        }
        $output->write($configuration, false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    private function balancer(): BalancerConfig
    {
        return BalancerConfig::forInstallation($this->config);
    }

    /** @return list<string> */
    private function warnings(BalancerConfig $balancer): array
    {
        $warnings = [];
        $settings = new ClusterSettings($this->config);
        $known = $this->servers->all();
        foreach ($settings->servers() as $name => $server) {
            $reported = $known[$name]['role'] ?? null;
            if ($server['role'] === null) {
                $warnings[] = sprintf('%s has no role in the cluster list, so it is left out. If it collects, add it again with --role=collection.', $name);
            } elseif ($reported !== null && $reported !== $server['role']) {
                $warnings[] = sprintf('%s is a %s server in the cluster list but reported %s when it last pulled. Check its SERVER_ROLE.', $name, $server['role'], $reported);
            }
        }
        $warnings[] = sprintf(
            'Built for %s with %d collection server(s): %s.',
            $balancer->host(),
            count($balancer->servers()),
            implode(', ', array_column($balancer->servers(), 'name')),
        );

        return $warnings;
    }

    private function guide(): string
    {
        return $this->documentation?->reference('cluster.balancer') ?? DocumentationLinks::file('cluster.balancer');
    }
}
