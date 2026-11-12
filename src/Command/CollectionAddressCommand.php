<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AggregateConfigLoader;
use App\Service\CollectionAddress;
use App\Service\DocumentationLinks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows or sets the collection address (collection_base_url), as General
 * settings does, with the same validation: where websites send events and
 * load the scripts, when that is not app_host.
 */
#[AsCommand(
    name: 'app:collection-address',
    description: 'Show or set the collection address (collection_base_url) websites send events to',
)]
final class CollectionAddressCommand extends Command
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ?DocumentationLinks $documentation = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::OPTIONAL, 'A full http or https URL, such as https://collect.example.com. Omit it to show the current value.')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Use the application host (app_host) again')
            ->setHelp(<<<HELP
                Saves collection_base_url in config/aggregate.yaml. COLLECTION_BASE_URL takes precedence.
                Empty means the application host. Set it to a tracking subdomain, or to the balancer in front
                of several collection servers, then copy the tracking code again.

                {$this->guide()}
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $collection = new CollectionAddress($this->config);
        $url = $input->getArgument('url');
        $clear = (bool) $input->getOption('clear');

        if ($clear || (is_string($url) && $url !== '')) {
            if ($clear && is_string($url) && $url !== '') {
                $io->error('Give a URL or --clear, not both.');

                return Command::FAILURE;
            }
            try {
                $value = $clear ? '' : CollectionAddress::normalize($url);
                $this->config->updateMany(static fn (): array => [CollectionAddress::CONFIG_KEY => $value]);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->success($value === ''
                ? 'Cleared the collection address: websites send events to the application host. Copy the tracking code again.'
                : 'Saved the collection address '.$value.'. Copy the tracking code again.');
        } else {
            $configured = $collection->configuredValue();
            $io->writeln($configured !== ''
                ? 'Collection address: '.$configured
                : 'Collection address: not set, so websites send events to the application host ('.($collection->baseUrl() ?? 'not set').').');
        }
        if ($collection->hasEnvironmentOverride()) {
            $io->note('COLLECTION_BASE_URL overrides the saved value: '.$collection->configuredValue());
        }

        return Command::SUCCESS;
    }

    private function guide(): string
    {
        return $this->documentation?->reference('configuration.application') ?? DocumentationLinks::file('configuration.application');
    }
}
