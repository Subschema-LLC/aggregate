<?php

declare(strict_types=1);

namespace App\Security\Access;

/**
 * The dashboard pages an administrator can grant to standard users, and which
 * page and access level every dashboard route needs. This is the single list:
 * DashboardAccessSubscriber enforces it on every request, the navigation and
 * the page tabs read it, and the Users page offers its pages.
 *
 * A route needs "view" to read a page and "edit" to change it. Routes marked
 * EVERY_WEBSITE also need access to every website, because they read or
 * change something shared by all of them. Website pages show and change only
 * the websites a user can use; system-wide pages reveal or change settings for
 * every website. Users, permission groups and data transfer stay with
 * administrators.
 *
 * DashboardPagesTest fails when a route under /dashboard is missing here, so
 * a new page cannot be reached by standard users by accident: an unlisted
 * dashboard route is administrator-only.
 */
final class DashboardPages
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const LEVELS = [self::VIEW, self::EDIT];

    /** A page that shows and changes only the websites the user can use. */
    public const SCOPE_WEBSITES = 'websites';
    /** A page whose settings reveal or change every website. */
    public const SCOPE_SYSTEM = 'system';
    /** A reference page that changes nothing. */
    public const SCOPE_REFERENCE = 'reference';

    /** Route requirement: administrators only. */
    public const ADMIN = 'admin';
    /** Route requirement: any signed-in user (their own profile). */
    public const SIGNED_IN = 'signed_in';
    /** Route level that follows the HTTP method: GET and HEAD view, others edit. */
    public const BY_METHOD = 'by_method';
    /** Route flag: also needs access to every website. */
    public const EVERY_WEBSITE = true;

    /**
     * In menu order.
     *
     * @var array<string, array{label: string, scope: string, description: string, levels?: list<string>}>
     */
    public const PAGES = [
        'setup' => ['label' => 'Setup', 'scope' => self::SCOPE_WEBSITES, 'description' => 'Installation steps and script downloads for each website.'],
        'websites' => ['label' => 'Websites', 'scope' => self::SCOPE_WEBSITES, 'description' => 'Install snippets, domain rules and data reception. Editing adds domain rules and removes websites; adding a website also needs every website.'],
        'website_groups' => ['label' => 'Website groups', 'scope' => self::SCOPE_SYSTEM, 'description' => 'Every group and website, and which website is in which group. Editing can move any website into a group, so treat it as every website.'],
        'tag_manager' => ['label' => 'Tag manager lite', 'scope' => self::SCOPE_WEBSITES, 'description' => 'Tags and variables for each website. Shared settings need every website.'],
        'consent_manager' => ['label' => 'Consent manager lite', 'scope' => self::SCOPE_WEBSITES, 'description' => 'The built-in consent banner for each website.'],
        'collection_controls' => ['label' => 'Collection controls', 'scope' => self::SCOPE_WEBSITES, 'description' => 'Collection settings for a website or group. Deployment settings and tracking retries need every website.'],
        'data_model' => ['label' => 'Data model', 'scope' => self::SCOPE_WEBSITES, 'description' => 'Custom properties for a website or group. The deployment model, observed properties and publishing columns need every website.'],
        'organization_traffic' => ['label' => 'Organization traffic', 'scope' => self::SCOPE_SYSTEM, 'description' => 'Marking your team’s own visits.'],
        'reporting_views' => ['label' => 'Reporting views', 'scope' => self::SCOPE_SYSTEM, 'description' => 'The SQL views BI tools read, and regenerating them.'],
        'bi_glossary' => ['label' => 'BI glossary', 'scope' => self::SCOPE_SYSTEM, 'description' => 'Labels and translations published to BI tools.'],
        'bi_disclosure' => ['label' => 'BI disclosure', 'scope' => self::SCOPE_SYSTEM, 'description' => 'Minimum counts that suppress small cells in reporting views.'],
        'page_groups' => ['label' => 'Page groups', 'scope' => self::SCOPE_SYSTEM, 'description' => 'Path patterns that group pages in reporting views.'],
        'sync' => ['label' => 'Sync', 'scope' => self::SCOPE_SYSTEM, 'description' => 'BigQuery and reporting database sync, including their connections.'],
        'general_settings' => ['label' => 'General settings', 'scope' => self::SCOPE_SYSTEM, 'description' => 'Application host, documentation link, reporting timezone and page speed.'],
        'branding' => ['label' => 'Branding', 'scope' => self::SCOPE_SYSTEM, 'description' => 'Name, logo, colors and typography.'],
        'data_lifecycle' => ['label' => 'Data lifecycle', 'scope' => self::SCOPE_SYSTEM, 'description' => 'Retention and archiving of collected events.'],
        'scheduled_tasks' => ['label' => 'Scheduled tasks', 'scope' => self::SCOPE_SYSTEM, 'description' => 'Background jobs and running them now.'],
        'audit_trail' => ['label' => 'Audit trail', 'scope' => self::SCOPE_SYSTEM, 'description' => 'Every recorded action by every user, and what is recorded.'],
        'updates' => ['label' => 'Updates', 'scope' => self::SCOPE_SYSTEM, 'description' => 'Checking for, uploading and installing application updates. Editing installs code, as much as an administrator can do.'],
        'feature_flags' => ['label' => 'Feature flags', 'scope' => self::SCOPE_SYSTEM, 'description' => 'Turning features on and off for everyone.'],
        'event_examples' => ['label' => 'Event examples', 'scope' => self::SCOPE_REFERENCE, 'description' => 'Example events to copy into a website.', 'levels' => [self::VIEW]],
    ];

    /**
     * Route => [page or ADMIN or SIGNED_IN, level (VIEW, EDIT or BY_METHOD), EVERY_WEBSITE?].
     *
     * @var array<string, array{0: string, 1?: string, 2?: bool}>
     */
    public const ROUTES = [
        // Setup.
        'app_setup' => ['setup', self::VIEW],
        'app_setup_download' => ['setup', self::VIEW],
        'app_standalone_consent' => ['setup', self::BY_METHOD],
        'app_setup_build_scripts' => ['setup', self::EDIT, self::EVERY_WEBSITE],
        // Websites and groups.
        'app_dashboard' => ['websites', self::VIEW],
        'app_website_activity' => ['websites', self::VIEW],
        'app_website_create' => ['websites', self::EDIT, self::EVERY_WEBSITE],
        'app_website_domains' => ['websites', self::EDIT],
        'app_website_delete' => ['websites', self::EDIT],
        'app_website_groups' => ['website_groups', self::VIEW],
        'app_website_groups_label' => ['website_groups', self::EDIT],
        'app_website_groups_create' => ['website_groups', self::EDIT],
        'app_website_groups_rename' => ['website_groups', self::EDIT],
        'app_website_groups_delete' => ['website_groups', self::EDIT],
        'app_website_groups_assign' => ['website_groups', self::EDIT],
        // Collection.
        'app_tag_manager' => ['tag_manager', self::VIEW],
        'app_tag_manager_save' => ['tag_manager', self::EDIT],
        'app_tag_manager_download' => ['tag_manager', self::VIEW],
        'app_consent_manager' => ['consent_manager', self::VIEW],
        'app_consent_manager_save' => ['consent_manager', self::EDIT],
        'app_collection_settings' => ['collection_controls', self::VIEW],
        'app_anonymous_settings_save' => ['collection_controls', self::EDIT],
        'app_tracking_failure_status' => ['collection_controls', self::VIEW, self::EVERY_WEBSITE],
        'app_tracking_retry_now' => ['collection_controls', self::EDIT, self::EVERY_WEBSITE],
        'app_data_model' => ['data_model', self::VIEW],
        'app_data_model_save' => ['data_model', self::EDIT],
        'app_data_model_discovery' => ['data_model', self::VIEW, self::EVERY_WEBSITE],
        'app_data_model_download' => ['data_model', self::VIEW, self::EVERY_WEBSITE],
        'app_data_model_publish' => ['data_model', self::EDIT, self::EVERY_WEBSITE],
        'app_event_examples' => ['event_examples', self::VIEW],
        'app_event_examples_ecommerce' => ['event_examples', self::VIEW],
        'app_event_examples_download' => ['event_examples', self::VIEW],
        'app_internal_traffic' => ['organization_traffic', self::VIEW],
        'app_internal_traffic_save' => ['organization_traffic', self::EDIT],
        'app_internal_traffic_download' => ['organization_traffic', self::VIEW],
        // Reporting.
        'app_data_model_reporting' => ['reporting_views', self::VIEW],
        'app_data_model_regenerate' => ['reporting_views', self::EDIT],
        'app_bi_glossary' => ['bi_glossary', self::VIEW],
        'app_bi_glossary_download' => ['bi_glossary', self::VIEW],
        'app_bi_glossary_missing' => ['bi_glossary', self::VIEW],
        // Returns suggested labels; changes nothing.
        'app_bi_glossary_suggestions' => ['bi_glossary', self::VIEW],
        'app_bi_glossary_save' => ['bi_glossary', self::EDIT],
        'app_bi_glossary_sync' => ['bi_glossary', self::EDIT],
        'app_privacy_settings' => ['bi_disclosure', self::VIEW],
        'app_analytics_privacy_settings_save' => ['bi_disclosure', self::EDIT],
        'app_bi_page_groups' => ['page_groups', self::VIEW],
        'app_bi_page_groups_save' => ['page_groups', self::EDIT],
        'app_sync' => ['sync', self::VIEW],
        'app_bigquery' => ['sync', self::VIEW],
        'app_bigquery_status' => ['sync', self::VIEW],
        'app_bigquery_connection' => ['sync', self::EDIT],
        'app_bigquery_views' => ['sync', self::EDIT],
        'app_bigquery_schedule' => ['sync', self::EDIT],
        'app_bigquery_key_remove' => ['sync', self::EDIT],
        'app_bigquery_test' => ['sync', self::EDIT],
        'app_bigquery_sync' => ['sync', self::EDIT],
        'app_bigquery_google_start' => ['sync', self::EDIT],
        // Google's sign-in returns here with a GET that stores the sign-in.
        'app_bigquery_google_callback' => ['sync', self::EDIT],
        'app_bigquery_google_disconnect' => ['sync', self::EDIT],
        'app_reporting_database' => ['sync', self::VIEW],
        'app_reporting_database_status' => ['sync', self::VIEW],
        'app_reporting_database_connection' => ['sync', self::EDIT],
        'app_reporting_database_connection_remove' => ['sync', self::EDIT],
        'app_reporting_database_views' => ['sync', self::EDIT],
        'app_reporting_database_schedule' => ['sync', self::EDIT],
        'app_reporting_database_test' => ['sync', self::EDIT],
        'app_reporting_database_sync' => ['sync', self::EDIT],
        // Administration.
        'app_application_settings' => ['general_settings', self::VIEW],
        'app_settings_save' => ['general_settings', self::EDIT],
        'app_page_speed_save' => ['general_settings', self::EDIT],
        'app_reporting_timezone' => ['general_settings', self::VIEW],
        'app_reporting_timezone_save' => ['general_settings', self::EDIT],
        'app_branding_settings' => ['branding', self::VIEW],
        'app_branding_settings_save' => ['branding', self::EDIT],
        'app_data_lifecycle' => ['data_lifecycle', self::VIEW],
        'app_data_lifecycle_save' => ['data_lifecycle', self::EDIT],
        'app_scheduled_tasks' => ['scheduled_tasks', self::VIEW],
        'app_scheduled_tasks_status' => ['scheduled_tasks', self::VIEW],
        'app_scheduled_tasks_run' => ['scheduled_tasks', self::EDIT],
        'app_audit_trail' => ['audit_trail', self::VIEW],
        'app_audit_trail_export' => ['audit_trail', self::VIEW],
        'app_audit_trail_settings' => ['audit_trail', self::EDIT],
        'app_updates' => ['updates', self::VIEW],
        'app_updates_refresh' => ['updates', self::EDIT],
        'app_updates_install' => ['updates', self::EDIT],
        'app_updates_upload' => ['updates', self::EDIT],
        'app_updates_method' => ['updates', self::EDIT],
        'app_updates_settings' => ['updates', self::EDIT],
        'app_feature_flags' => ['feature_flags', self::VIEW],
        'app_feature_flags_save' => ['feature_flags', self::EDIT],
        // Data transfer can import accounts and copies every website's events.
        'app_data_transfer' => [self::ADMIN],
        'app_data_transfer_export' => [self::ADMIN],
        'app_data_transfer_import' => [self::ADMIN],
        'app_data_transfer_copy' => [self::ADMIN],
        // Users and permission groups stay with administrators.
        'app_users' => [self::ADMIN],
        'app_user_create' => [self::ADMIN],
        'app_user_update' => [self::ADMIN],
        'app_user_password_update' => [self::ADMIN],
        'app_user_delete' => [self::ADMIN],
        'app_permission_groups' => [self::ADMIN],
        'app_permission_groups_create' => [self::ADMIN],
        'app_permission_groups_update' => [self::ADMIN],
        'app_permission_groups_delete' => [self::ADMIN],
        // A reporting API token grants data access outside the dashboard's own
        // permissions, so issuing one stays with administrators.
        'app_api_tokens' => [self::ADMIN],
        'app_api_tokens_create' => [self::ADMIN],
        'app_api_tokens_revoke' => [self::ADMIN],
        'app_api_tokens_settings' => [self::ADMIN],
        'app_mcp_connection_revoke' => [self::ADMIN],
        // Approving an AI assistant grants data access the same way.
        'app_mcp_authorize' => [self::ADMIN],
        'app_mcp_authorize_decide' => [self::ADMIN],
        // An email credential sends mail as the organization, and email
        // carries sign-in codes, so the service stays with administrators.
        'app_email' => [self::ADMIN],
        'app_email_save' => [self::ADMIN],
        'app_email_test' => [self::ADMIN],
        // Everyone manages their own profile.
        'app_profile' => [self::SIGNED_IN],
        'app_profile_save' => [self::SIGNED_IN],
        'app_profile_password' => [self::SIGNED_IN],
    ];

    /** Paths whose routes need an entry here; anything unlisted below them is administrator-only. */
    public const PATH_PREFIX = '/dashboard';

    public static function hasPage(mixed $page): bool
    {
        return is_string($page) && array_key_exists($page, self::PAGES);
    }

    public static function label(string $page): string
    {
        return self::PAGES[$page]['label'] ?? $page;
    }

    /** @return list<string> the levels a page can be granted at */
    public static function levels(string $page): array
    {
        return self::PAGES[$page]['levels'] ?? self::LEVELS;
    }

    public static function isSystemWide(string $page): bool
    {
        return (self::PAGES[$page]['scope'] ?? null) === self::SCOPE_SYSTEM;
    }

    /**
     * What a route needs for a request method. An unlisted route is treated as
     * administrator-only; callers decide whether it is a dashboard route.
     *
     * @return array{kind: string, page: ?string, level: string, every_website: bool}
     */
    public static function requirement(string $route, string $method = 'GET'): array
    {
        $entry = self::ROUTES[$route] ?? [self::ADMIN];
        if ($entry[0] === self::ADMIN || $entry[0] === self::SIGNED_IN) {
            return ['kind' => $entry[0], 'page' => null, 'level' => self::VIEW, 'every_website' => false];
        }
        $level = $entry[1];
        if ($level === self::BY_METHOD) {
            $level = in_array(strtoupper($method), ['GET', 'HEAD'], true) ? self::VIEW : self::EDIT;
        }

        return ['kind' => 'page', 'page' => $entry[0], 'level' => $level, 'every_website' => ($entry[2] ?? false) === true];
    }

    /** The page's main route, where a refused change returns to. */
    public static function mainRoute(string $page): ?string
    {
        foreach (self::ROUTES as $route => $entry) {
            if ($entry[0] === $page && ($entry[1] ?? null) === self::VIEW && !($entry[2] ?? false)) {
                return $route;
            }
        }

        return null;
    }

    /** The higher of two levels; null is no access. */
    public static function higher(?string $a, ?string $b): ?string
    {
        if ($a === self::EDIT || $b === self::EDIT) {
            return self::EDIT;
        }

        return $a === self::VIEW || $b === self::VIEW ? self::VIEW : null;
    }

    public static function satisfies(?string $granted, string $required): bool
    {
        return $granted === self::EDIT || ($granted === self::VIEW && $required === self::VIEW);
    }
}
