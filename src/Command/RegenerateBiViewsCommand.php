<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:analytics:bi-views:regenerate',
    description: 'Regenerate the approved bi_anonymous_* views from the configured disclosure thresholds and page groups',
)]
final class RegenerateBiViewsCommand extends Command
{
    public function __construct(
        private readonly AnalyticsPrivacySettings $settings,
        private readonly AnonymousBiViewManager $views,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the SQL without changing the database')
            ->setHelp(<<<'HELP'
Reads anonymous_min_cell_count, anonymous_geo_min_cell_count and bi_page_groups
from the active aggregate YAML (ANONYMOUS_MIN_CELL_COUNT and
ANONYMOUS_GEO_MIN_CELL_COUNT take precedence) and rebuilds bi_anonymous_events_v1,
bi_anonymous_goals_v1, bi_anonymous_geo_events_v1 and bi_anonymous_daily_v1 with
them. Run it after editing those settings in YAML, and once after updating to a
release that adds a view; saving the BI disclosure or page groups page in the
dashboard does the same. Invalid settings stop it before any view changes.

View names and columns do not change. PostgreSQL, MySQL, MariaDB and SQL Server
replace each view in place, so grants to the reporting account are kept.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $thresholds = $this->settings->getMinimumCellCounts();
            if ($input->getOption('dry-run')) {
                foreach ($this->views->previewSql($thresholds['anonymous'], $thresholds['geo']) as $sql) {
                    $output->writeln($sql.";\n", OutputInterface::OUTPUT_RAW);
                }

                return Command::SUCCESS;
            }

            $this->views->regenerate($thresholds['anonymous'], $thresholds['geo']);
        } catch (\Throwable $e) {
            $io->error('The BI views were not regenerated: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success([
            'Regenerated '.implode(', ', AnonymousBiViewManager::VIEW_NAMES).'.',
            sprintf('Minimum cell counts: %d for events and goals, %d for geography.', $thresholds['anonymous'], $thresholds['geo']),
        ]);
        if ($this->settings->hasAnonymousMinimumEnvironmentOverride() || $this->settings->hasGeoMinimumEnvironmentOverride()) {
            $io->note('An environment override set at least one threshold; it takes precedence over YAML.');
        }

        return Command::SUCCESS;
    }
}
