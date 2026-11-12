<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AggregateConfigLoader;
use App\Service\Cluster\ClusterServers;
use App\Service\Cluster\ClusterSettings;
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
        private readonly ClusterServers $servers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'list, add or remove', 'list')
            ->addArgument('name', InputArgument::OPTIONAL, 'The server\'s name, as it joined (CLUSTER_SERVER_NAME)')
            ->addArgument('address', InputArgument::OPTIONAL, 'The address it connects from: an IP address or network')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the list as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        $name = $input->getArgument('name');
        try {
            if ($action === 'add') {
                $server = ClusterSettings::server($name, $input->getArgument('address'));
                $this->config->updateMany(static function (array $current) use ($server): array {
                    $list = array_values(array_filter(is_array($current[ClusterSettings::SERVERS_KEY] ?? null) ? $current[ClusterSettings::SERVERS_KEY] : [],
                        static fn ($entry): bool => !is_array($entry) || ($entry['name'] ?? null) !== $server['name']));
                    $list[] = $server;

                    return [ClusterSettings::SERVERS_KEY => $list];
                });
                $io->success(sprintf('%s may now pull configuration from %s.', $server['name'], $server['address']));

                return Command::SUCCESS;
            }
            if ($action === 'remove') {
                if (!is_string($name)) {
                    throw new \InvalidArgumentException('Name the server to remove.');
                }
                $this->config->updateMany(static fn (array $current): array => [ClusterSettings::SERVERS_KEY => array_values(array_filter(
                    is_array($current[ClusterSettings::SERVERS_KEY] ?? null) ? $current[ClusterSettings::SERVERS_KEY] : [],
                    static fn ($entry): bool => !is_array($entry) || ($entry['name'] ?? null) !== $name,
                ))]);
                $io->success($name.' may no longer pull configuration.');

                return Command::SUCCESS;
            }
            if ($action !== 'list') {
                throw new \InvalidArgumentException('Use list, add or remove.');
            }
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $known = $this->servers->all();
        $rows = [];
        foreach ((new ClusterSettings($this->config))->servers() as $server) {
            $record = $known[$server['name']] ?? null;
            $rows[] = [
                'name' => $server['name'],
                'address' => $server['address'],
                'role' => $record['role'] ?? null,
                'release' => $record['release'] ?? null,
                'last_pull_at' => isset($record['last_pull_at']) ? gmdate(\DATE_ATOM, $record['last_pull_at']) : null,
                'version' => $record['version'] ?? null,
                'alerting_since' => isset($record['alert_since']) ? gmdate(\DATE_ATOM, $record['alert_since']) : null,
            ];
        }
        if ($input->getOption('json')) {
            $output->writeln(json_encode(['servers' => $rows], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        if ($rows === []) {
            $io->writeln('No server may pull configuration yet. Add one with: php bin/console app:cluster:server add <name> <address>');

            return Command::SUCCESS;
        }
        $io->table(['Server', 'Address', 'Role', 'Release', 'Last pull (UTC)', 'Alert'], array_map(static fn (array $row): array => [
            $row['name'], $row['address'], $row['role'] ?? '-', $row['release'] ?? '-', $row['last_pull_at'] ?? 'never', $row['alerting_since'] !== null ? 'since '.$row['alerting_since'] : '-',
        ], $rows));

        return Command::SUCCESS;
    }
}
