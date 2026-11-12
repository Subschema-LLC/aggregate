<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AggregateConfigLoader;
use App\Service\Cluster\ServerRole;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns the dashboard on or off (`dashboard_enabled`). It has no dashboard
 * control: a dashboard turned off could not turn itself back on. The switch
 * is compiled into the cache, so the cache is removed as the command ends.
 */
#[AsCommand(
    name: 'app:dashboard',
    description: 'Show whether the dashboard is on, or turn it on or off (dashboard_enabled)',
)]
final class DashboardCommand extends Command
{
    public const KEY = 'dashboard_enabled';
    public const VARIABLE = 'DASHBOARD_ENABLED';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ServerRole $role,
        #[Autowire('%kernel.cache_dir%')]
        private readonly ?string $cacheDir = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('state', InputArgument::OPTIONAL, 'on or off; omit it to show the state')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the state as JSON')
            ->setHelp(<<<'HELP'
                With the dashboard off, the dashboard, sign-in and installation pages are gone; collection,
                the reporting API, MCP, health and every command keep working, and YAML and app:config
                change every setting. DASHBOARD_ENABLED takes precedence over the saved value. Only the
                all and administration roles have a dashboard.

                The switch is compiled into the cache, so this command removes the cache as it ends; reload
                PHP-FPM afterwards.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $state = $input->getArgument('state');
        if ($state === null) {
            return $this->show($io, $output, (bool) $input->getOption('json'));
        }
        if (!in_array($state, ['on', 'off'], true)) {
            $io->error('Use on or off.');

            return Command::FAILURE;
        }
        try {
            $this->role->assertWritesConfiguration();
            $this->config->updateMany(static fn (): array => [self::KEY => $state === 'on']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ($this->cacheDir !== null) {
            ClusterJoinCommand::discardCacheAtExit($this->cacheDir);
        }
        $io->success(sprintf('The dashboard is now %s in the configuration. The cache is removed as this command ends; reload PHP-FPM.', $state));
        if ($this->config->hasEnvironmentOverride(self::KEY)) {
            $io->note(self::VARIABLE.' is set and takes precedence over the saved value.');
        }

        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, OutputInterface $output, bool $json): int
    {
        $enabled = $this->config->isDashboardEnabled();
        $report = [
            self::KEY => $enabled,
            'environment_override' => $this->config->hasEnvironmentOverride(self::KEY),
        ];
        if ($json) {
            $output->writeln(json_encode($report, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        $io->writeln('The dashboard is '.($enabled ? 'on' : 'off').'.');
        if ($report['environment_override']) {
            $io->writeln(self::VARIABLE.' sets it, over the saved value.');
        }

        return Command::SUCCESS;
    }
}
