<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CustomDataSettings;
use App\Service\Glossary\GlossarySync;
use App\Service\ReportingViewManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:analytics:views:regenerate',
    description: 'Regenerate private retained-event reporting views from the custom data model',
)]
final class RegenerateReportingViewsCommand extends Command
{
    public function __construct(
        private readonly ReportingViewManager $views,
        private readonly CustomDataSettings $settings,
        private readonly GlossarySync $glossary,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the SQL without changing the database')
            ->addOption('discover', null, InputOption::VALUE_NONE, 'List property names, types and occurrence counts from a bounded event sample; do not regenerate')
            ->addOption('sample-size', null, InputOption::VALUE_REQUIRED, 'Maximum retained events with custom data to sample with --discover (1–10000)', '1000')
            ->addOption('export-model', null, InputOption::VALUE_NONE, 'Write the shareable YAML model to stdout; do not regenerate')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Drop and recreate views without column-preservation checks (planned schema migration)')
            ->setHelp('The private analytics_custom_* views contain retained raw events and configured scalar JSON columns. They are unsuppressed and require controlled access. Archives do not retain these properties. Existing bi_anonymous_* views are unchanged. Add new aliases at the end of the model to preserve deployed view columns and grants.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $modes = array_filter(['dry-run', 'discover', 'export-model'], static fn (string $option): bool => (bool) $input->getOption($option));
        if (count($modes) > 1) {
            $io->error('Choose only one of --dry-run, --discover, or --export-model.');

            return Command::INVALID;
        }
        if ($input->hasParameterOption('--sample-size') && !$input->getOption('discover')) {
            $io->error('--sample-size requires --discover. No views were regenerated.');

            return Command::INVALID;
        }

        try {
            if ($input->getOption('export-model')) {
                $output->write($this->settings->exportYaml(), false, OutputInterface::OUTPUT_RAW);

                return Command::SUCCESS;
            }
            if ($input->getOption('discover')) {
                $sample = $input->getOption('sample-size');
                if (!is_string($sample) || preg_match('/^[1-9][0-9]{0,4}$/D', $sample) !== 1 || (int) $sample > 10000) {
                    throw new \InvalidArgumentException('The discovery sample size must be between 1 and 10000 events.');
                }
                $properties = $this->views->discoverProperties((int) $sample);
                $io->table(['JSON key', 'Observed types', 'Events in sample'], array_map(
                    static fn (array $property): array => [
                        OutputFormatter::escape($property['key']),
                        implode(', ', $property['types']),
                        (string) $property['event_count'],
                    ],
                    $properties,
                ));
                $io->note('Sampled up to '.$sample.' latest retained events with custom data. No captured values are displayed.');

                return Command::SUCCESS;
            }
            if ($input->getOption('dry-run')) {
                foreach ($this->views->previewSql() as $sql) {
                    $output->writeln($sql."\n", OutputInterface::OUTPUT_RAW);
                }

                return Command::SUCCESS;
            }

            $force = (bool) $input->getOption('force');
            $names = $this->views->regenerate($force);
            try {
                $this->glossary->sync();
            } catch (\Throwable $error) {
                $io->warning('Reporting views were regenerated, but the BI glossary was not synced. Run app:analytics:glossary:sync after correcting the issue.');
                throw $error;
            }
            $io->success(($force ? 'Dropped and regenerated ' : 'Regenerated ').implode(', ', $names).'.');
            $io->note('These private views contain unsuppressed retained events. Grant access only to authorized reporting users. Custom properties are unavailable once raw rows are deleted.');

            return Command::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
