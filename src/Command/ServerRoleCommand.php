<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Cluster\ServerRoleReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:server:role',
    description: 'Show this server\'s role, release, queue and database connections, and what its role still needs',
)]
final class ServerRoleCommand extends Command
{
    public function __construct(private readonly ServerRoleReport $report)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON, with a schema_version')
            ->setHelp(<<<'HELP'
                The role is server_role in config/aggregate.yaml, or SERVER_ROLE, which takes precedence:
                all (the default), administration, collection or worker. app:cluster:join sets it and removes the
                cache built for the previous role; after changing it another way, run rm -rf var/cache/prod.
                Exits with 1 when a check the role needs has failed. The balancer runs no Aggregate: see
                app:server:balancer-config on the administration server.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->report->report();
        $status = ServerRoleReport::hasFailures($report) ? Command::FAILURE : Command::SUCCESS;

        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return $status;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Server role: '.$report['role']);
        if ($report['problem'] !== null) {
            $io->error($report['problem']);
        }
        $serves = array_keys(array_filter($report['serves']));
        $names = ['dashboard' => 'the dashboard and configuration', 'collection' => 'collection', 'scheduled_task' => 'the scheduled task', 'consumers' => 'queue consumers'];
        $io->definitionList(
            ['Role' => $report['role'].($report['role_source'] === 'default' ? ' (default)' : ' (from '.$report['role_source'].')')],
            ['Runs here' => $serves === [] ? 'the health check only' : implode(', ', array_map(static fn (string $s): string => $names[$s], $serves))],
            ['Release' => $report['release']['version'] ?? ($report['release']['error'] ?? 'not a release package')],
            ['Administration server' => $report['administration_url'] ?? 'not set'],
            ['Collection address' => $report['collection_base_url'] ?? 'the application host'],
            ['Queue' => $report['queue']['scheme'].'://: '.$report['queue']['note']],
            ['Database' => $report['database']['engine']
                .($report['database']['max_connections'] !== null ? sprintf(', %d of %d connections open', $report['database']['open_connections'] ?? 0, $report['database']['max_connections']) : '')
                .($report['database']['persistent_connections'] ? ', persistent connections' : '')],
        );

        $rows = [];
        foreach ($report['requirements'] as $check) {
            $rows[] = [
                match ($check['status']) { 'ok' => '<info>ok</info>', 'warning' => '<comment>warning</comment>', default => '<error>error</error>' },
                $check['message'].($check['fix'] !== null ? "\nFix: ".$check['fix'] : ''),
            ];
        }
        $io->table(['Check', 'Result'], $rows);

        return $status;
    }
}
