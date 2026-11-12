<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\Settings\AbstractSection;

/**
 * The BI disclosure thresholds, as the BI disclosure page saves them: both
 * are whole numbers in their ranges, and saving regenerates the BI views
 * with the thresholds in effect.
 */
final class BiDisclosureSection extends AbstractSection
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AnalyticsPrivacySettings $settings,
        private readonly AnonymousBiViewManager $views,
    ) {
    }

    public function name(): string
    {
        return 'bi-disclosure';
    }

    public function page(): string
    {
        return 'Reporting > BI disclosure';
    }

    public function keys(): array
    {
        return [AnalyticsPrivacySettings::ANONYMOUS_MINIMUM_KEY, AnalyticsPrivacySettings::GEO_MINIMUM_KEY];
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);
        $counts = $this->settings->getMinimumCellCounts();

        return [AnalyticsPrivacySettings::ANONYMOUS_MINIMUM_KEY => $counts['anonymous'], AnalyticsPrivacySettings::GEO_MINIMUM_KEY => $counts['geo']];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $saved = [];
        foreach ($this->keys() as $key) {
            $configured = $this->config->get($key);
            $saved[$key] = array_key_exists($key, $changes) ? $changes[$key] : ($configured ?? $this->values()[$key]);
        }
        $minimum = self::integer($saved[AnalyticsPrivacySettings::ANONYMOUS_MINIMUM_KEY]);
        $geo = self::integer($saved[AnalyticsPrivacySettings::GEO_MINIMUM_KEY]);
        if ($minimum === null || $geo === null) {
            throw new \InvalidArgumentException('Both BI disclosure thresholds must be whole numbers.');
        }
        if ($minimum < AnalyticsPrivacySettings::MINIMUM_CELL_COUNT || $minimum > AnalyticsPrivacySettings::MAXIMUM_CELL_COUNT) {
            throw new \InvalidArgumentException('Minimum BI cell count must be between 2 and 1000.');
        }
        if ($geo < AnalyticsPrivacySettings::GEO_MINIMUM_CELL_COUNT || $geo > AnalyticsPrivacySettings::MAXIMUM_CELL_COUNT) {
            throw new \InvalidArgumentException('Geography minimum BI cell count must be between 10 and 1000.');
        }
        if ($check) {
            return [self::done(true, '')];
        }
        $this->settings->saveMinimumCellCounts($minimum, $geo);
        $effective = $this->settings->getMinimumCellCounts();
        $notes = [];
        try {
            $this->views->regenerate($effective['anonymous'], $effective['geo']);
            $notes[] = 'BI disclosure thresholds were saved to configuration and BI views were regenerated.';
        } catch (\Throwable $e) {
            $notes[] = 'The thresholds were saved, but the BI views were not regenerated: '.$e->getMessage().' Run php bin/console app:analytics:bi-views:regenerate after correcting the problem.';
        }
        if ($this->settings->hasAnonymousMinimumEnvironmentOverride() || $this->settings->hasGeoMinimumEnvironmentOverride()) {
            $notes[] = 'Environment variables override one or more saved BI threshold values.';
        }

        return $notes;
    }

    /** A whole number, as the page reads one. */
    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?[0-9]+$/D', trim($value)) === 1 ? (int) $value : null;
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
