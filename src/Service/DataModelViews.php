<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Glossary\GlossarySync;
use Psr\Log\LoggerInterface;

/**
 * What saving or publishing the data model does to the reporting views, for
 * the Data model page and app:config alike: views that already match are
 * left alone, a removed column is never dropped silently, and otherwise the
 * views are regenerated and the BI glossary synced.
 */
final class DataModelViews
{
    public function __construct(
        private readonly CustomDataSettings $settings,
        private readonly ReportingViewManager $views,
        private readonly GlossarySync $glossary,
        private readonly LoggerInterface $logger,
        private readonly ?AnonymousBiViewManager $biViews = null,
        private readonly ?AnalyticsPrivacySettings $privacySettings = null,
    ) {
    }

    /**
     * @param string $saved the first sentence, about what was saved
     *
     * @return list<array{0: 'success'|'warning', 1: string}> messages for the operator, in order
     */
    public function apply(string $saved): array
    {
        try {
            $status = $this->views->status();
            if ($status['current']) {
                return [['success', $saved.' The reporting views already match it.']];
            }
            if ($status['removed'] !== []) {
                return [
                    ['success', $saved],
                    ['warning', 'The reporting views were not updated: '.implode(', ', $status['removed']).(count($status['removed']) === 1 ? ' is' : ' are').' no longer in the model. Removing a deployed column drops the views and their grants, so do it on purpose with Force recreate views on the Reporting views page, or restore the column.'],
                ];
            }
            $this->views->regenerate();
            if ($this->biViews !== null && $this->privacySettings !== null && $this->settings->websiteGroupColumn() !== null) {
                $thresholds = $this->privacySettings->getMinimumCellCounts();
                $this->biViews->regenerate($thresholds['anonymous'], $thresholds['geo']);
            }
        } catch (\InvalidArgumentException $e) {
            return [['success', $saved], ['warning', 'The reporting views were not updated: '.$e->getMessage()]];
        } catch (\Throwable $e) {
            $this->logFailure('regenerate', $e);

            return [['success', $saved], ['warning', 'The reporting views could not be updated. Check database privileges, migrations and JSON support, then use Regenerate on the Reporting views page.']];
        }
        $messages = [['success', $saved.' '.($status['missing'] === ReportingViewManager::VIEW_NAMES
            ? 'The reporting views were created.'
            : 'The reporting views were updated'.($status['added'] === [] ? '.' : ': added '.implode(', ', $status['added']).'.'))]];
        try {
            $this->glossary->sync();
        } catch (\Throwable $error) {
            $this->logFailure('glossary_sync', $error);
            $messages[] = ['warning', 'The reporting views were updated, but the BI glossary was not synced. Run php bin/console app:analytics:glossary:sync after correcting its configuration or database issue.'];
        }

        return $messages;
    }

    private function logFailure(string $operation, \Throwable $error): void
    {
        $this->logger->error('Custom data model operation failed.', ['operation' => $operation, 'exception_class' => $error::class]);
    }
}
