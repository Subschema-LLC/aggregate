<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\ReportingApi\ReportingApiSettings;
use App\Service\Settings\AbstractSection;

/** The reporting API's settings, as the API tokens page saves them. */
final class ReportingApiSection extends AbstractSection
{
    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    public function name(): string
    {
        return 'reporting-api';
    }

    public function page(): string
    {
        return 'Reporting > API tokens > Settings';
    }

    public function keys(): array
    {
        return array_keys(ReportingApiSettings::DEFAULTS);
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return (new ReportingApiSettings($this->config))->toArray();
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $settings = new ReportingApiSettings($this->config);
        $overridden = array_values(array_intersect(array_keys($changes), $this->environmentOverrides()));
        if ($check) {
            ReportingApiSettings::validate([...$settings->toArray(), ...$changes]);
        } else {
            $settings->save($changes);
        }

        return array_values(array_filter([self::overriddenNote($overridden), self::done($check, 'Reporting API settings saved.')]));
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
