<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertSettings;
use App\Service\Cluster\ClusterSettings;
use App\Service\DocumentationLinks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows or sets the cluster's YAML settings on the administration server,
 * with the same validation the settings get when they are read. The server
 * list has its own command (app:cluster:server), the shared key too
 * (app:cluster:key), and each server's own role and name are set where it
 * runs (app:cluster:join).
 */
#[AsCommand(
    name: 'app:cluster:settings',
    description: 'Show or set the cluster\'s settings: pause limit, alert destinations and the collection servers\' port',
)]
final class ClusterSettingsCommand extends Command
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AlertSettings $alerts,
        private readonly ?DocumentationLinks $documentation = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-age-minutes', null, InputOption::VALUE_REQUIRED, 'cluster_config_max_age_minutes: minutes without the newest configuration before a server pauses collection, 1 to 60')
            ->addOption('alert-destinations', null, InputOption::VALUE_REQUIRED, 'cluster_alert_destinations: destination ids separated by commas, "all" for every destination, or "none"')
            ->addOption('collection-port', null, InputOption::VALUE_REQUIRED, 'cluster_collection_port: the port the balancer reaches the collection servers at')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the settings as JSON')
            ->setHelp(<<<HELP
                Without options, shows the settings. Each option saves one setting in config/aggregate.yaml,
                where it can also be edited by hand:

                  php bin/console app:cluster:settings --max-age-minutes=10
                  php bin/console app:cluster:settings --alert-destinations=ops-webhook,ops-email
                  php bin/console app:cluster:settings --collection-port=8080

                The servers that may pull are set with app:cluster:server, the shared key with app:cluster:key,
                and the collection address with app:collection-address.

                {$this->guide()}
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $changes = [];
        try {
            $maxAge = $input->getOption('max-age-minutes');
            if ($maxAge !== null) {
                $changes[ClusterSettings::MAX_AGE_KEY] = ClusterSettings::validMaxAgeMinutes($maxAge);
            }
            $port = $input->getOption('collection-port');
            if ($port !== null) {
                $changes[ClusterSettings::COLLECTION_PORT_KEY] = ClusterSettings::validCollectionPort($port);
            }
            $destinations = $input->getOption('alert-destinations');
            if ($destinations !== null) {
                $changes[ClusterSettings::ALERT_DESTINATIONS_KEY] = ClusterSettings::validAlertDestinations((string) $destinations, array_keys($this->alerts->destinations()));
            }
            if ($changes !== []) {
                $this->config->updateMany(static fn (): array => $changes);
            }
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $settings = new ClusterSettings($this->config);
        $shown = [
            ClusterSettings::MAX_AGE_KEY => $settings->maxAgeMinutes(),
            ClusterSettings::ALERT_DESTINATIONS_KEY => $settings->alertDestinations(),
            ClusterSettings::COLLECTION_PORT_KEY => $settings->collectionPort(),
            'servers' => count($settings->servers()),
            'cluster_key_set' => $settings->key() !== null,
        ];
        if ($input->getOption('json')) {
            $output->writeln(json_encode($shown, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        if ($changes !== []) {
            $io->success('Saved '.implode(', ', array_keys($changes)).'.');
        }
        $io->definitionList(
            [ClusterSettings::MAX_AGE_KEY => $shown[ClusterSettings::MAX_AGE_KEY].' minutes'],
            [ClusterSettings::ALERT_DESTINATIONS_KEY => match (true) {
                $shown[ClusterSettings::ALERT_DESTINATIONS_KEY] === null => 'every destination',
                $shown[ClusterSettings::ALERT_DESTINATIONS_KEY] === [] => 'none',
                default => implode(', ', $shown[ClusterSettings::ALERT_DESTINATIONS_KEY]),
            }],
            [ClusterSettings::COLLECTION_PORT_KEY => (string) $shown[ClusterSettings::COLLECTION_PORT_KEY]],
            ['Servers that may pull' => $shown['servers'].' (app:cluster:server list)'],
            ['CLUSTER_KEY' => $shown['cluster_key_set'] ? 'set' : 'not set (app:cluster:key)'],
        );

        return Command::SUCCESS;
    }

    private function guide(): string
    {
        return $this->documentation?->reference('cluster.configuration') ?? DocumentationLinks::file('cluster.configuration');
    }
}
