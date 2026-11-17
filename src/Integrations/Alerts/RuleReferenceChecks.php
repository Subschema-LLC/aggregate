<?php

declare(strict_types=1);

namespace App\Integrations\Alerts;

use App\Entities\AlertRule\AlertRule;
use App\Entities\AlertRule\RuleReferences;
use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;

/**
 * Checks a rule's references against config/websites.yaml, the website groups
 * and the data lifecycle policy. A check whose source is not given passes.
 *
 * @phpstan-import-type RuleArray from AlertRule
 */
final class RuleReferenceChecks implements RuleReferences
{
    public function __construct(
        private readonly ?WebsiteConfigManager $websites = null,
        private readonly ?WebsiteScopeCatalog $scopes = null,
        private readonly ?AnalyticsDataLifecyclePolicy $lifecycle = null,
    ) {
    }

    /**
     * Why a loaded rule cannot be checked any more, or null when it can: the
     * website or group it covers was removed, or its window became longer than
     * the live retention. Such a rule is skipped and shown with this reason
     * while every other rule keeps running.
     *
     * @param RuleArray $rule
     */
    public function problemWith(array $rule): ?string
    {
        try {
            $this->assertCoversExist($rule['covers']);
            $this->assertWindowFitsRetention(
                $rule['source'],
                $rule['privacy_mode'],
                $rule['window'],
            );
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function assertCoversExist(string $covers): void
    {
        if (str_starts_with($covers, 'group:')) {
            $groupId = substr($covers, 6);
            if ($this->scopes !== null && !$this->scopes->hasGroup($groupId)) {
                throw new \InvalidArgumentException(
                    sprintf('Website group "%s" does not exist.', $groupId),
                );
            }

            return;
        }
        if (str_starts_with($covers, 'website:')) {
            $token = substr($covers, 8);
            if ($this->websites !== null && $this->websites->findOneByToken($token) === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Website "%s" is not registered in config/websites.yaml.',
                    $token,
                ));
            }
        }
    }

    public function assertWindowFitsRetention(
        string $source,
        string $privacyMode,
        string $window,
    ): void {
        if ($this->lifecycle === null) {
            return;
        }
        try {
            $policy = $this->lifecycle->getPolicy();
        } catch (\Throwable) {
            return;
        }

        $windowDays = (int) ceil(AlertRule::windowHours($window) / 24);
        if ($source === AlertRule::SOURCE_EVENTS && $policy['retention_enabled']) {
            $liveDays = match ($privacyMode) {
                AlertRule::PRIVACY_ANONYMOUS => $policy['anonymous_retention_days'],
                AlertRule::PRIVACY_ENHANCED => $policy['enhanced_retention_days'],
                default => min(
                    $policy['anonymous_retention_days'],
                    $policy['enhanced_retention_days'],
                ),
            };
            if ($windowDays > $liveDays) {
                throw new \InvalidArgumentException(sprintf(
                    'The %s window (%d days) is longer than the live event retention period '
                    .'(%d days). Shorten the window or increase retention on Data lifecycle.',
                    $window,
                    $windowDays,
                    $liveDays,
                ));
            }
        }

        $taskDays = $policy['processing_tasks_retention_days'];
        if ($source === AlertRule::SOURCE_TRACKING_FAILURES && $taskDays > 0
            && $windowDays > $taskDays) {
            throw new \InvalidArgumentException(sprintf(
                'The %s window (%d days) is longer than processing_tasks_retention_days (%d days).',
                $window,
                $windowDays,
                $taskDays,
            ));
        }
    }
}
