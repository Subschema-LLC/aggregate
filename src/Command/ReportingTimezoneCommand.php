<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\DocumentationLinks;
use App\Service\ReportingTimezone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:analytics:reporting-timezone',
    description: 'Show or set the reporting timezone and regenerate the BI views',
)]
final class ReportingTimezoneCommand extends Command
{
    public function __construct(
        private readonly ReportingTimezone $timezone,
        private readonly AnalyticsPrivacySettings $settings,
        private readonly AnonymousBiViewManager $views,
        private readonly ?DocumentationLinks $documentation = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('timezone', InputArgument::OPTIONAL, 'IANA time zone name, such as America/Chicago. Omit it to show the current value.')
            ->setHelp(<<<HELP
The reporting timezone is stored as reporting_timezone in the active aggregate
YAML. REPORTING_TIMEZONE takes precedence. Setting it regenerates the event
views so event_hour and the daily event_day use that zone. Goal and geography
views stay on UTC days. An invalid name, a MySQL server without time zone
tables, or a name SQL Server cannot map does not change the views. Load MySQL
or MariaDB tables with app:analytics:mysql-timezones:load, or the command that
failure prints.

{$this->guide()}
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('timezone');

        if (!is_string($argument) || $argument === '') {
            try {
                $name = $this->timezone->name();
            } catch (\Throwable $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->success('Reporting timezone: '.$name.'.');
            if ($this->timezone->hasEnvironmentOverride()) {
                $io->note('REPORTING_TIMEZONE overrides the saved value.');
            }

            return Command::SUCCESS;
        }

        try {
            $name = ReportingTimezone::validate($argument);
            $this->views->assertReportingTimezoneCanBeCompiled($name);
            if ($this->timezone->hasEnvironmentOverride()) {
                $this->views->assertReportingTimezoneCanBeCompiled($this->timezone->name());
            }
            $this->timezone->save($name);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        try {
            $thresholds = $this->settings->getMinimumCellCounts();
            $this->views->regenerate($thresholds['anonymous'], $thresholds['geo']);
        } catch (\Throwable $e) {
            $io->error('The reporting timezone was saved, but the BI views were not regenerated: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Saved the reporting timezone %s and regenerated the BI views.', $name));
        if ($this->timezone->hasEnvironmentOverride()) {
            $io->note('REPORTING_TIMEZONE overrides the saved value. The views were regenerated with the environment value.');
        }

        return Command::SUCCESS;
    }

    private function guide(): string
    {
        return $this->documentation?->reference('reporting.timezone') ?? DocumentationLinks::file('reporting.timezone');
    }
}
