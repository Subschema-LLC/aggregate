<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\Operations\AuditTrailSettings;
use App\Service\Settings\AbstractSection;

/**
 * What the audit trail records, area by area, as the Audit trail page's
 * switches save it. Changing a switch is always recorded.
 */
final class AuditTrailSection extends AbstractSection
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AuditTrailSettings $settings,
    ) {
    }

    public function name(): string
    {
        return 'audit-trail';
    }

    public function page(): string
    {
        return 'Administration > Audit trail > What is recorded';
    }

    public function keys(): array
    {
        return array_keys(AuditTrailSettings::AREAS);
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);
        try {
            return $this->settings->all();
        } catch (\InvalidArgumentException) {
            return AuditTrailSettings::defaults();
        }
    }

    public function environmentOverrides(?string $target = null): array
    {
        $this->assertTarget($target);

        return [];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        if ($check) {
            AuditTrailSettings::validate($changes);
        } else {
            $this->settings->save($changes);
        }

        return [self::done($check, 'Saved what the audit trail records. Changes to these switches are always recorded.')];
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
