<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\ReportingTimezone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:analytics:bi-views:regenerate',
    description: 'Regenerate the approved bi_anonymous_* views from the configured disclosure thresholds, page groups and reporting timezone',
)]
final class RegenerateBiViewsCommand extends Command
{
    public function __construct(
        private readonly AnalyticsPrivacySettings $settings,
        private readonly AnonymousBiViewManager $views,
        private readonly ?ReportingTimezone $timezone = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the SQL without changing the database')
            ->setHelp(<<<'HELP'
Reads anonymous_min_cell_count, anonymous_geo_min_cell_count, bi_page_groups
and reporting_timezone from the active aggregate YAML (ANONYMOUS_MIN_CELL_COUNT,
ANONYMOUS_GEO_MIN_CELL_COUNT and REPORTING_TIMEZONE take precedence) and rebuilds
bi_anonymous_events_v1, bi_anonymous_goals_v1, bi_anonymous_geo_events_v1,
bi_anonymous_daily_v1, bi_anonymous_daily_rollup_v1 and bi_anonymous_coverage_v1
with them. Run it after editing those settings in YAML, and once after updating
to a release that adds a view; saving the BI disclosure, page groups or reporting
timezone page in the dashboard does the same. Invalid settings stop it before
any view changes. A reporting timezone other than UTC also needs MySQL time zone
tables, or a Windows time zone name on SQL Server; if those are missing the
existing views stay in place. Load MySQL or MariaDB tables with
app:analytics:mysql-timezones:load, or the command that failure prints.

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
            'Reporting timezone: '.($this->timezone?->name() ?? ReportingTimezone::DEFAULT).'.',
        ]);
        if ($this->settings->hasAnonymousMinimumEnvironmentOverride() || $this->settings->hasGeoMinimumEnvironmentOverride() || $this->timezone?->hasEnvironmentOverride()) {
            $io->note('An environment override set at least one threshold or the reporting timezone; it takes precedence over YAML.');
        }

        return Command::SUCCESS;
    }
}
