import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'summary',
        'conditionPreview',
        'covers',
        'source',
        'filterSection',
        'privacyMode',
        'enhancedTip',
        'eventName',
        'condition',
        'threshold',
        'window',
        'hourWarning',
    ];

    connect() {
        this.refresh();
    }

    refresh() {
        const isFailures = this.hasSourceTarget && this.sourceTarget.value === 'tracking_failures';

        if (this.hasFilterSectionTarget) {
            this.filterSectionTarget.hidden = isFailures;
        }
        if (this.hasPrivacyModeTarget) {
            this.privacyModeTarget.disabled = isFailures;
        }
        if (this.hasEventNameTarget) {
            this.eventNameTarget.disabled = isFailures;
        }

        if (this.hasConditionTarget) {
            for (const option of this.conditionTarget.options) {
                if (option.value === 'more_than') {
                    option.disabled = !isFailures;
                } else {
                    option.disabled = isFailures;
                }
            }
            if (isFailures) {
                this.conditionTarget.value = 'more_than';
            } else if (this.conditionTarget.value === 'more_than') {
                this.conditionTarget.value = 'fewer_than';
            }
        }

        const condition = this.hasConditionTarget ? this.conditionTarget.value : 'fewer_than';
        if (this.hasThresholdTarget) {
            if (condition === 'no_events') {
                this.thresholdTarget.value = '0';
                this.thresholdTarget.readOnly = true;
                this.thresholdTarget.min = '0';
            } else {
                this.thresholdTarget.readOnly = false;
                this.thresholdTarget.min = isFailures ? '0' : '1';
                if (!isFailures && Number.parseInt(this.thresholdTarget.value || '0', 10) < 1) {
                    this.thresholdTarget.value = '5';
                }
            }
        }

        const privacyMode = this.hasPrivacyModeTarget ? this.privacyModeTarget.value : 'all';
        if (this.hasEnhancedTipTarget) {
            this.enhancedTipTarget.hidden = isFailures || privacyMode !== 'enhanced';
        }

        const win = this.hasWindowTarget ? this.windowTarget.value : 'day';
        if (this.hasHourWarningTarget) {
            this.hourWarningTarget.hidden = isFailures || win !== 'hour';
        }

        this.updateSummary(isFailures, condition, privacyMode, win);
    }

    updateSummary(isFailures, condition, privacyMode, win) {
        const coversLabel = this.hasCoversTarget && this.coversTarget.selectedOptions.length > 0
            ? this.coversTarget.selectedOptions[0].text.trim()
            : 'selected websites';
        const threshold = this.hasThresholdTarget ? Math.max(0, Number.parseInt(this.thresholdTarget.value || '0', 10) || 0) : 0;
        const windowLabel = win === 'hour'
            ? '1 hour (1 completed UTC hour)'
            : win === 'week'
                ? '1 week (168 completed UTC hours)'
                : '1 day (24 completed UTC hours)';

        if (this.hasSummaryTarget) {
            if (isFailures) {
                this.summaryTarget.textContent = `Alert when ${coversLabel} records more than ${threshold} tracking failures in the last ${windowLabel}.`;
            } else {
                const modeWord = privacyMode === 'enhanced' ? 'enhanced ' : privacyMode === 'anonymous' ? 'anonymous ' : '';
                const eventName = this.hasEventNameTarget ? this.eventNameTarget.value.trim() : '';
                const eventWord = eventName !== '' ? `"${eventName}" ` : '';
                if (condition === 'no_events') {
                    this.summaryTarget.textContent = `Alert when ${coversLabel} records no ${modeWord}${eventWord}events in the last ${windowLabel}.`;
                } else {
                    this.summaryTarget.textContent = `Alert when ${coversLabel} records fewer than ${Math.max(1, threshold)} ${modeWord}${eventWord}events in the last ${windowLabel}.`;
                }
            }
        }

        if (this.hasConditionPreviewTarget) {
            if (isFailures) {
                this.conditionPreviewTarget.textContent = `more_than_${threshold}_tracking_failures_per_${win}`;
            } else if (condition === 'no_events') {
                this.conditionPreviewTarget.textContent = `no_events_per_${win}`;
            } else {
                this.conditionPreviewTarget.textContent = `fewer_than_${Math.max(1, threshold)}_per_${win}`;
            }
        }
    }
}
