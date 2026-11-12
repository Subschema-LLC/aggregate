<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Service\Operations\AuditTrailSettings as Area;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every settings section app:config reaches, by name.
 */
final class SettingsSections
{
    /**
     * Each section's name, and the area and action the audit trail records
     * when it is saved: the same as its dashboard page records, so a change
     * reads the same whichever way it was made.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const AUDIT_ACTIONS = [
        'general' => [Area::SETTINGS, 'general_settings_saved'],
        'page-speed' => [Area::SETTINGS, 'page_speed_saved'],
        'branding' => [Area::SETTINGS, 'branding_saved'],
        'collection' => [Area::SETTINGS, 'collection_settings_saved'],
        'bi-disclosure' => [Area::SETTINGS, 'bi_disclosure_saved'],
        'page-groups' => [Area::SETTINGS, 'page_groups_saved'],
        'reporting-timezone' => [Area::SETTINGS, 'reporting_timezone_saved'],
        'data-lifecycle' => [Area::SETTINGS, 'data_lifecycle_saved'],
        'organization-traffic' => [Area::SETTINGS, 'organization_traffic_saved'],
        'email' => [Area::INTEGRATIONS, 'email_settings_saved'],
        'feature-flags' => [Area::SETTINGS, 'feature_flags_saved'],
        'audit-trail' => [Area::SETTINGS, 'audit_settings_saved'],
        'updates' => [Area::UPDATES, 'update_settings_saved'],
        'reporting-api' => [Area::SETTINGS, 'reporting_api_settings_saved'],
        'bi-glossary' => [Area::SETTINGS, 'glossary_saved'],
        'data-model' => [Area::SETTINGS, 'data_model_saved'],
        'consent-manager' => [Area::SETTINGS, 'consent_manager_saved'],
        'tag-manager' => [Area::SETTINGS, 'tag_manager_saved'],
        'standalone-consent' => [Area::SETTINGS, 'standalone_consent_saved'],
        'bigquery' => [Area::INTEGRATIONS, 'bigquery_settings_saved'],
        'reporting-database' => [Area::INTEGRATIONS, 'reporting_database_settings_saved'],
        'forensic-database' => [Area::INTEGRATIONS, 'forensic_database_settings_saved'],
        'cluster' => [Area::SETTINGS, 'cluster_settings_saved'],
    ];

    /** @var array<string, SettingsSection> */
    private array $sections = [];

    /** @param iterable<SettingsSection> $sections */
    public function __construct(#[AutowireIterator(SettingsSection::TAG)] iterable $sections)
    {
        foreach ($sections as $section) {
            $this->sections[$section->name()] = $section;
        }
        ksort($this->sections);
    }

    /** @return array<string, SettingsSection> */
    public function all(): array
    {
        return $this->sections;
    }

    public function get(string $name): SettingsSection
    {
        return $this->sections[$name] ?? throw new \InvalidArgumentException(sprintf(
            'There are no "%s" settings. The sections are: %s.',
            $name,
            implode(', ', array_keys($this->sections)),
        ));
    }
}
