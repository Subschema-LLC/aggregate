<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\ReportingTimezone;
use App\Service\Settings\AbstractSection;

/**
 * The reporting timezone, as the Reporting views page and
 * app:analytics:reporting-timezone save it: the database must be able to
 * compile it, and saving regenerates the BI views.
 */
final class ReportingTimezoneSection extends AbstractSection
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ReportingTimezone $timezone,
        private readonly AnonymousBiViewManager $views,
        private readonly AnalyticsPrivacySettings $thresholds,
    ) {
    }

    public function name(): string
    {
        return 'reporting-timezone';
    }

    public function page(): string
    {
        return 'Administration > General settings > Reporting timezone';
    }

    public function keys(): array
    {
        return [ReportingTimezone::KEY];
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return [ReportingTimezone::KEY => $this->timezone->name()];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $name = ReportingTimezone::validate((string) $changes[ReportingTimezone::KEY]);
        $this->views->assertReportingTimezoneCanBeCompiled($name);
        if ($this->timezone->hasEnvironmentOverride()) {
            $this->views->assertReportingTimezoneCanBeCompiled($this->timezone->name());
        }
        if ($check) {
            return [self::done(true, '')];
        }
        $this->timezone->save($name);
        $notes = [];
        try {
            $thresholds = $this->thresholds->getMinimumCellCounts();
            $this->views->regenerate($thresholds['anonymous'], $thresholds['geo']);
            $notes[] = sprintf('Saved the reporting timezone %s and regenerated the BI views.', $name);
        } catch (\Throwable $e) {
            $notes[] = 'The reporting timezone was saved, but the BI views were not regenerated: '.$e->getMessage();
        }
        if ($this->timezone->hasEnvironmentOverride()) {
            $notes[] = 'REPORTING_TIMEZONE overrides the saved value. The views were regenerated with the environment value.';
        }

        return $notes;
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
