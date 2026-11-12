<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AggregateConfigLoader;
use App\Service\Cluster\ClusterOverview;
use App\Service\Cluster\ClusterServerList;
use App\Service\Cluster\ClusterSettings;
use App\Service\Cluster\ServerRole;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cluster:server',
    description: 'List, add or remove the servers that may pull configuration from this administration server',
)]
final class ClusterServerCommand extends Command
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ClusterOverview $overview,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'list, add or remove', 'list')
            ->addArgument('name', InputArgument::OPTIONAL, 'The server\'s name, as it joined (CLUSTER_SERVER_NAME)')
            ->addArgument('address', InputArgument::OPTIONAL, 'The address it connects from: an IP address or network. For a collection server, the one address the balancer reaches it at')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'With add: collection or worker, as the server joined')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'With add, for a collection server: the port the balancer reaches it at, when not cluster_collection_port')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the list as JSON')
            ->setHelp(<<<'HELP'
                Examples:
                  php bin/console app:cluster:server add collect-1 10.0.0.11 --role=collection
                  php bin/console app:cluster:server add worker-1 10.0.0.21 --role=worker
                  php bin/console app:cluster:server remove collect-1

                The balancer configuration (app:server:balancer-config) is built from the collection servers
                here, so run it again after adding or removing one.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        $name = $input->getArgument('name');
        try {
            if ($action === 'add') {
                $role = $input->getOption('role');
                $server = (new ClusterServerList($this->config))->add($name, $input->getArgument('address'), $role, $input->getOption('port'));
                $io->success(sprintf('%s (%s) may now pull configuration from %s.', $server['name'], $role, $server['address']));
                if ($role === ServerRole::COLLECTION) {
                    $io->note('Run app:server:balancer-config again and install its output on the balancer, so it sends traffic to '.$server['name'].' too.');
                }

                return Command::SUCCESS;
            }
            if ($action === 'remove') {
                (new ClusterServerList($this->config))->remove($name);
                $io->success($name.' may no longer pull configuration. If it was a collection server, run app:server:balancer-config again and install its output on the balancer.');

                return Command::SUCCESS;
            }
            if ($action !== 'list') {
                throw new \InvalidArgumentException('Use list, add or remove.');
            }
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $overview = $this->overview->overview();
        $rows = array_map(static fn (array $row): array => [
            ...$row,
            'last_pull_at' => $row['last_pull_at'] !== null ? gmdate(\DATE_ATOM, $row['last_pull_at']) : null,
            'alerting_since' => $row['alerting_since'] !== null ? gmdate(\DATE_ATOM, $row['alerting_since']) : null,
        ], $overview['servers']);
        if ($input->getOption('json')) {
            $output->writeln(json_encode(['release' => $overview['release'], 'configuration_version' => $overview['version'], 'servers' => $rows], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        if ($rows === []) {
            $io->writeln('No server may pull configuration yet. Add one with: php bin/console app:cluster:server add <name> <address>');

            return Command::SUCCESS;
        }
        $io->table(['Server', 'Address', 'Role', 'Release', 'Configuration', 'Last pull (UTC)', 'Alert'], array_map(static fn (array $row): array => [
            $row['name'],
            $row['address'],
            self::roleCell($row['role'], $row['reported_role']),
            ($row['release'] ?? '-').($row['same_release'] === false ? ' (this server: '.$overview['release'].')' : ''),
            match ($row['newest_configuration']) {
                true => 'newest',
                false => 'behind',
                null => '-',
            },
            $row['last_pull_at'] ?? 'never',
            $row['alerting_since'] !== null ? 'since '.$row['alerting_since'] : '-',
        ], $rows));

        return Command::SUCCESS;
    }

    /** The role in the list, and the one the server reported when they differ. */
    public static function roleCell(?string $role, ?string $reported): string
    {
        return match (true) {
            $role === null && $reported === null => 'not set',
            $role === null => 'not set (reports '.$reported.')',
            $reported !== null && $reported !== $role => $role.' (reports '.$reported.')',
            default => $role,
        };
    }
}
