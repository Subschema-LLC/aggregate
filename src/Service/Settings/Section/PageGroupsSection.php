<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\BiPageGroups;
use App\Service\Settings\AbstractSection;

/**
 * The BI page groups, as the Page groups page saves them: the rules are
 * checked together, and saving regenerates the BI views. The value is the
 * YAML list of rules, or the page's text with one rule per line.
 */
final class PageGroupsSection extends AbstractSection
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly BiPageGroups $pageGroups,
        private readonly AnalyticsPrivacySettings $thresholds,
        private readonly AnonymousBiViewManager $views,
    ) {
    }

    public function name(): string
    {
        return 'page-groups';
    }

    public function page(): string
    {
        return 'Reporting > Page groups';
    }

    public function keys(): array
    {
        return [BiPageGroups::KEY];
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return [BiPageGroups::KEY => $this->pageGroups->rules()];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $value = $changes[BiPageGroups::KEY];
        $rules = is_string($value) ? BiPageGroups::parseLines($value) : BiPageGroups::validate($value ?? []);
        if ($check) {
            return [self::done(true, '')];
        }
        $this->pageGroups->save($rules);
        try {
            $thresholds = $this->thresholds->getMinimumCellCounts();
            $this->views->regenerate($thresholds['anonymous'], $thresholds['geo']);
        } catch (\Throwable $e) {
            return ['The page groups were saved, but the BI views were not regenerated: '.$e->getMessage().' Run php bin/console app:analytics:bi-views:regenerate after correcting the problem.'];
        }

        return [sprintf('Saved %d page group %s and regenerated the BI views.', count($rules), count($rules) === 1 ? 'rule' : 'rules')];
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
