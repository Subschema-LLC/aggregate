<?php

declare(strict_types=1);

namespace App\Service\Operations;

use App\Service\Operations\AuditTrailSettings as Area;
use App\Service\Settings\SettingsSections;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Which dashboard routes and console commands are administrator actions, and
 * what each records in the audit trail: its area (AuditTrailSettings::AREAS)
 * and an action code. AdminActionsTest fails when a route that accepts POST
 * is neither listed here nor in NOT_AUDITED, so a new form cannot be forgotten.
 *
 * Commands that the application starts itself (a dashboard "Sync now", an
 * update's own steps) carry CHILD_PROCESS in their environment and are not
 * recorded again: the action that started them already was.
 */
final class AdminActions
{
    /** Set in the environment of commands the application starts. */
    public const CHILD_PROCESS = 'AGGREGATE_CHILD_PROCESS';

    /**
     * Route => [area, action, methods that record it (POST unless listed)].
     *
     * @var array<string, array{0: string, 1: string, 2?: list<string>}>
     */
    public const ROUTES = [
        // Settings pages.
        'app_settings_save' => [Area::SETTINGS, 'general_settings_saved'],
        'app_page_speed_save' => [Area::SETTINGS, 'page_speed_saved'],
        'app_anonymous_settings_save' => [Area::SETTINGS, 'collection_settings_saved'],
        'app_analytics_privacy_settings_save' => [Area::SETTINGS, 'bi_disclosure_saved'],
        'app_branding_settings_save' => [Area::SETTINGS, 'branding_saved'],
        'app_consent_manager_save' => [Area::SETTINGS, 'consent_manager_saved'],
        'app_standalone_consent' => [Area::SETTINGS, 'standalone_consent_saved'],
        'app_tag_manager_save' => [Area::SETTINGS, 'tag_manager_saved'],
        'app_internal_traffic_save' => [Area::SETTINGS, 'organization_traffic_saved'],
        'app_feature_flags_save' => [Area::SETTINGS, 'feature_flags_saved'],
        'app_data_lifecycle_save' => [Area::SETTINGS, 'data_lifecycle_saved'],
        'app_data_model_save' => [Area::SETTINGS, 'data_model_saved'],
        'app_bi_glossary_save' => [Area::SETTINGS, 'glossary_saved'],
        'app_bi_page_groups_save' => [Area::SETTINGS, 'page_groups_saved'],
        'app_goals_save' => [Area::SETTINGS, 'goals_saved'],
        'app_reporting_timezone_save' => [Area::SETTINGS, 'reporting_timezone_saved'],
        'app_api_tokens_settings' => [Area::SETTINGS, 'reporting_api_settings_saved'],
        'app_audit_trail_settings' => [Area::SETTINGS, 'audit_settings_saved'],
        'app_alerts_rule_save' => [Area::SETTINGS, 'alert_rule_saved'],
        'app_alerts_rule_delete' => [Area::SETTINGS, 'alert_rule_deleted'],
        // Users.
        'app_install_execute' => [Area::USERS, 'installed'],
        'app_user_create' => [Area::USERS, 'user_created'],
        'app_user_password_update' => [Area::USERS, 'password_changed'],
        'app_user_update' => [Area::USERS, 'user_updated'],
        'app_user_delete' => [Area::USERS, 'user_deleted'],
        'app_permission_groups_create' => [Area::USERS, 'permission_group_created'],
        'app_permission_groups_update' => [Area::USERS, 'permission_group_updated'],
        'app_permission_groups_delete' => [Area::USERS, 'permission_group_deleted'],
        'app_profile_save' => [Area::USERS, 'profile_updated'],
        'app_profile_password' => [Area::USERS, 'password_changed'],
        // Websites and groups.
        'app_website_create' => [Area::WEBSITES, 'website_created'],
        'app_website_domains' => [Area::WEBSITES, 'domain_rules_saved'],
        'app_website_delete' => [Area::WEBSITES, 'website_deleted'],
        'app_website_groups_label' => [Area::WEBSITES, 'group_label_saved'],
        'app_website_groups_create' => [Area::WEBSITES, 'group_created'],
        'app_website_groups_rename' => [Area::WEBSITES, 'group_renamed'],
        'app_website_groups_delete' => [Area::WEBSITES, 'group_deleted'],
        'app_website_groups_assign' => [Area::WEBSITES, 'groups_assigned'],
        // Integrations.
        'app_bigquery_connection' => [Area::INTEGRATIONS, 'bigquery_connection_saved'],
        'app_bigquery_views' => [Area::INTEGRATIONS, 'bigquery_views_saved'],
        'app_bigquery_schedule' => [Area::INTEGRATIONS, 'bigquery_schedule_saved'],
        'app_bigquery_key_remove' => [Area::INTEGRATIONS, 'bigquery_key_removed'],
        'app_bigquery_test' => [Area::INTEGRATIONS, 'bigquery_connection_tested'],
        'app_bigquery_google_start' => [Area::INTEGRATIONS, 'bigquery_sign_in_started'],
        'app_bigquery_google_callback' => [Area::INTEGRATIONS, 'bigquery_signed_in', ['GET']],
        'app_bigquery_google_disconnect' => [Area::INTEGRATIONS, 'bigquery_sign_in_removed'],
        'app_reporting_database_connection' => [Area::INTEGRATIONS, 'reporting_database_connection_saved'],
        'app_reporting_database_connection_remove' => [Area::INTEGRATIONS, 'reporting_database_connection_removed'],
        'app_reporting_database_views' => [Area::INTEGRATIONS, 'reporting_database_views_saved'],
        'app_reporting_database_schedule' => [Area::INTEGRATIONS, 'reporting_database_schedule_saved'],
        'app_reporting_database_test' => [Area::INTEGRATIONS, 'reporting_database_connection_tested'],
        'app_forensic_database_access' => [Area::INTEGRATIONS, 'forensic_database_access_saved'],
        'app_forensic_database_connection' => [Area::INTEGRATIONS, 'forensic_database_connection_saved'],
        'app_forensic_database_connection_remove' => [Area::INTEGRATIONS, 'forensic_database_connection_removed'],
        'app_forensic_database_views' => [Area::INTEGRATIONS, 'forensic_database_tables_saved'],
        'app_forensic_database_schedule' => [Area::INTEGRATIONS, 'forensic_database_schedule_saved'],
        'app_forensic_database_test' => [Area::INTEGRATIONS, 'forensic_database_connection_tested'],
        'app_api_tokens_create' => [Area::INTEGRATIONS, 'api_token_created'],
        'app_api_tokens_revoke' => [Area::INTEGRATIONS, 'api_token_revoked'],
        'app_mcp_connection_revoke' => [Area::INTEGRATIONS, 'assistant_connection_revoked'],
        'app_mcp_authorize_decide' => [Area::INTEGRATIONS, 'assistant_sign_in_decided'],
        'app_email_save' => [Area::INTEGRATIONS, 'email_settings_saved'],
        'app_alerts_destination_save' => [Area::INTEGRATIONS, 'alert_destination_saved'],
        'app_alerts_destination_delete' => [Area::INTEGRATIONS, 'alert_destination_deleted'],
        'app_alerts_secret_save' => [Area::INTEGRATIONS, 'alert_secret_saved'],
        // Updates.
        'app_updates_refresh' => [Area::UPDATES, 'update_check_refreshed'],
        'app_updates_install' => [Area::UPDATES, 'update_started'],
        'app_updates_upload' => [Area::UPDATES, 'update_package_uploaded'],
        'app_updates_method' => [Area::UPDATES, 'update_method_changed'],
        'app_updates_settings' => [Area::UPDATES, 'update_settings_saved'],
        'app_updates_cluster_settings' => [Area::SETTINGS, 'cluster_settings_saved'],
        'app_updates_cluster_server_add' => [Area::SETTINGS, 'cluster_server_added'],
        'app_updates_cluster_server_remove' => [Area::SETTINGS, 'cluster_server_removed'],
        // Manual runs.
        'app_bigquery_sync' => [Area::OPERATIONS, 'bigquery_sync_started'],
        'app_reporting_database_sync' => [Area::OPERATIONS, 'reporting_database_sync_started'],
        'app_forensic_database_sync' => [Area::OPERATIONS, 'forensic_database_sync_started'],
        'app_scheduled_tasks_run' => [Area::OPERATIONS, 'scheduled_jobs_started'],
        'app_data_model_publish' => [Area::OPERATIONS, 'reporting_columns_published'],
        'app_data_model_regenerate' => [Area::OPERATIONS, 'reporting_views_regenerated'],
        'app_bi_glossary_sync' => [Area::OPERATIONS, 'glossary_synced'],
        'app_setup_build_scripts' => [Area::OPERATIONS, 'browser_scripts_built'],
        'app_tracking_retry_now' => [Area::OPERATIONS, 'tracking_retry_started'],
        'app_email_test' => [Area::OPERATIONS, 'test_email_requested'],
        'app_alerts_test' => [Area::OPERATIONS, 'test_alert_sent'],
        'app_data_transfer_export' => [Area::OPERATIONS, 'data_export_started'],
        'app_data_transfer_import' => [Area::OPERATIONS, 'data_import_started'],
        'app_data_transfer_copy' => [Area::OPERATIONS, 'data_copy_started'],
        'app_audit_trail_export' => [Area::OPERATIONS, 'audit_trail_exported', ['GET']],
    ];

    /** Form routes that are not administrator actions, and why. */
    public const NOT_AUDITED = [
        'api_receive' => 'Collection requests from websites.',
        'app_login' => 'Sign-ins are recorded from the security events, under sign_ins.',
        'app_logout' => 'Sign-outs are recorded from the security events, under sign_ins.',
        'app_login_code_request' => 'Asking for a sign-in code is recorded by SignInCodeRequests, under sign_ins.',
        'app_login_code_verify' => 'Signing in with a code is recorded from the security events, under sign_ins.',
        'app_bi_glossary_suggestions' => 'Returns suggested labels; changes nothing.',
        'app_tag_manager_preview' => 'Tests a tag from the editor without saving it; changes nothing but the user\'s own session.',
        'app_home' => 'The home page accepts any method and changes nothing.',
        'mcp' => 'AI assistant requests; each tool call is recorded by McpUsageLog under assistant.',
        'mcp_oauth_register' => 'An assistant registering itself before sign-in; an administrator\'s approval is recorded under integrations.',
        'mcp_oauth_token' => 'An assistant exchanging an approved code or renewing its tokens.',
        'mcp_oauth_revoke' => 'An assistant revoking its own tokens.',
    ];

    /** Actions recorded even while their area is off: turning auditing off must leave a trace. */
    public const ALWAYS = ['audit_settings_saved'];

    /** Words in action codes that are written differently in a label. */
    private const LABEL_WORDS = ['api' => 'API', 'bi' => 'BI', 'bigquery' => 'BigQuery', 'csv' => 'CSV', 'js' => 'JS', 'mcp' => 'MCP', 'mysql' => 'MySQL', 'oauth' => 'OAuth'];

    /** A readable label for an action code: bigquery_sync_started is "BigQuery sync started". */
    public static function label(string $code): string
    {
        $words = array_map(static fn (string $word): string => self::LABEL_WORDS[$word] ?? $word, explode('_', $code));
        return ucfirst(implode(' ', $words));
    }

    /**
     * What a dashboard request records, or null when it records nothing.
     *
     * @return array{area: string, action: string}|null
     */
    public static function forRoute(string $route, string $method): ?array
    {
        $entry = self::ROUTES[$route] ?? null;
        if ($entry === null || !in_array($method, $entry[2] ?? ['POST'], true)) {
            return null;
        }

        return ['area' => $entry[0], 'action' => $entry[1]];
    }

    /**
     * What app:config records: the same area and action as the section's
     * dashboard page, with the section as the subject; nothing when it only
     * shows settings.
     *
     * @param callable(string, string, ?string): array{area: string, action: string, at: 'end', subject: ?string} $end
     *
     * @return array{area: string, action: string, at: 'end', subject: ?string}|null
     */
    private static function forConfig(mixed $action, mixed $name, callable $end): ?array
    {
        if (!in_array($action, ['set', 'unset', 'import', 'secret'], true) || !is_string($name) || $name === '') {
            return null;
        }
        $section = explode('.', $name, 2)[0];
        $entry = SettingsSections::AUDIT_ACTIONS[$section] ?? null;

        return $entry === null ? null : $end($entry[0], $entry[1], $name);
    }

    /** Whether app:user:access was asked to change anything, rather than only show access. */
    private static function changesAccess(InputInterface $input): bool
    {
        foreach (['admin', 'standard', 'group', 'remove-group', 'website', 'website-group', 'all-websites', 'selected-websites', 'page', 'clear'] as $name) {
            if (!$input->hasOption($name)) {
                continue;
            }
            $value = $input->getOption($name);
            if ($value === true || (is_array($value) && $value !== []) || (is_string($value) && $value !== '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a console command records, or null when it is not an
     * administrator action or only reads. "start" records it when it begins:
     * an update replaces this process's files, so nothing may run after it.
     *
     * @return array{area: string, action: string, at: 'start'|'end', subject: ?string}|null
     */
    public static function forCommand(string $command, InputInterface $input): ?array
    {
        $option = static fn (string $name): mixed => $input->hasOption($name) ? $input->getOption($name) : null;
        $argument = static fn (string $name): mixed => $input->hasArgument($name) ? $input->getArgument($name) : null;
        $text = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $end = static fn (string $area, string $action, ?string $subject = null): array => ['area' => $area, 'action' => $action, 'at' => 'end', 'subject' => $subject];
        $start = static fn (string $area, string $action): array => ['area' => $area, 'action' => $action, 'at' => 'start', 'subject' => null];
        if ($option('dry-run') === true || $option('check') === true) {
            return null;
        }

        return match ($command) {
            'app:install' => $end(Area::USERS, 'installed', $text($option('username'))),
            'app:user:create' => $end(Area::USERS, 'user_created', $text($argument('username'))),
            'app:user:reset-password' => $end(Area::USERS, 'password_changed', $text($argument('username'))),
            'app:user:access' => self::changesAccess($input) ? $end(Area::USERS, 'user_updated', $text($argument('username'))) : null,
            'app:user:delete' => $end(Area::USERS, 'user_deleted', $text($argument('username'))),
            'app:user:profile' => $option('display-name') !== null || $option('email') !== null || $option('rename') !== null
                ? $end(Area::USERS, 'user_updated', $text($argument('username'))) : null,
            'app:permission-groups' => match ($argument('action')) {
                'create' => $end(Area::USERS, 'permission_group_created', $text($option('name'))),
                'update' => $end(Area::USERS, 'permission_group_updated', $text($option('id'))),
                'delete' => $end(Area::USERS, 'permission_group_deleted', $text($option('id'))),
                default => null,
            },
            'app:create-website' => $end(Area::WEBSITES, 'website_created'),
            'app:website' => match ($argument('action')) {
                'domains' => $end(Area::WEBSITES, 'domain_rules_saved'),
                'delete' => $end(Area::WEBSITES, 'website_deleted'),
                default => null,
            },
            'app:internal-traffic:share-link' => in_array($argument('action'), ['rotate', 'revoke'], true) ? $end(Area::SETTINGS, 'organization_traffic_saved') : null,
            'app:website-scopes' => match ($argument('action')) {
                'label' => $end(Area::WEBSITES, 'group_label_saved'),
                'create-group' => $end(Area::WEBSITES, 'group_created', $text($option('name'))),
                'rename-group' => $end(Area::WEBSITES, 'group_renamed', $text($option('id'))),
                'delete-group' => $end(Area::WEBSITES, 'group_deleted', $text($option('id'))),
                'assign' => $end(Area::WEBSITES, 'groups_assigned'),
                'publish-model' => $end(Area::OPERATIONS, 'reporting_columns_published'),
                default => null,
            },
            'app:api:token:create' => $end(Area::INTEGRATIONS, 'api_token_created', $text($argument('name'))),
            'app:api:token:revoke' => $end(Area::INTEGRATIONS, 'api_token_revoked', $text($argument('id'))),
            'app:mcp:connection:revoke' => $end(Area::INTEGRATIONS, 'assistant_connection_revoked', $text($argument('id'))),
            'app:reporting-database:configure' => $end(Area::INTEGRATIONS, $option('remove') === true ? 'reporting_database_connection_removed' : 'reporting_database_connection_saved'),
            'app:forensic-database:configure' => $end(Area::INTEGRATIONS, $option('remove') === true ? 'forensic_database_connection_removed' : 'forensic_database_connection_saved'),
            'app:analytics:reporting-timezone' => $text($argument('timezone')) !== null ? $end(Area::SETTINGS, 'reporting_timezone_saved') : null,
            'app:analytics:mysql-timezones:load' => $end(Area::OPERATIONS, 'mysql_time_zones_loaded'),
            'app:analytics:bi-views:regenerate' => $end(Area::OPERATIONS, 'reporting_views_regenerated'),
            'app:analytics:views:regenerate' => $option('discover') === true || $option('export-model') === true ? null : $end(Area::OPERATIONS, 'reporting_views_regenerated'),
            'app:analytics:glossary:sync' => $option('export') === true ? null : $end(Area::OPERATIONS, 'glossary_synced'),
            'app:assets:build-js' => $end(Area::OPERATIONS, 'browser_scripts_built'),
            'app:tracking:retry-failed' => $end(Area::OPERATIONS, 'tracking_retry_started'),
            'app:email:test' => $end(Area::OPERATIONS, 'test_email_requested'),
            'app:alerts:rule', 'app:alerts:rules' => match ($argument('action')) {
                'create', 'add', 'edit', 'update' => $end(Area::SETTINGS, 'alert_rule_saved', $text($option('id'))),
                'delete', 'remove' => $end(Area::SETTINGS, 'alert_rule_deleted', $text($option('id'))),
                default => null,
            },
            'app:alerts:destination', 'app:alerts:destinations' => match ($argument('action')) {
                'create', 'add', 'edit', 'update' => $end(Area::INTEGRATIONS, 'alert_destination_saved', $text($option('id'))),
                'delete', 'remove' => $end(Area::INTEGRATIONS, 'alert_destination_deleted', $text($option('id'))),
                'secret' => $end(Area::INTEGRATIONS, 'alert_secret_saved', $text($option('id'))),
                default => null,
            },
            'app:alerts:test' => $end(Area::OPERATIONS, 'test_alert_sent', $text($argument('destination'))),
            'app:alerts:evaluate', 'app:alerts:run' => $end(Area::OPERATIONS, 'alerts_evaluated'),
            'app:updates:method' => $text($argument('method')) !== null ? $end(Area::UPDATES, 'update_method_changed') : null,
            'app:config' => self::forConfig($argument('action'), $argument('name'), $end),
            'app:collection-address' => $text($argument('url')) !== null || $option('clear') === true ? $end(Area::SETTINGS, 'general_settings_saved') : null,
            'app:queue:retention' => $text($argument('days')) !== null ? $end(Area::SETTINGS, 'data_lifecycle_saved') : null,
            'app:dashboard' => $text($argument('state')) !== null ? $end(Area::SETTINGS, 'dashboard_switched', $text($argument('state'))) : null,
            'app:cluster:settings' => $option('max-age-minutes') !== null || $option('alert-destinations') !== null || $option('collection-port') !== null
                ? $end(Area::SETTINGS, 'cluster_settings_saved') : null,
            'app:cluster:server' => match ($argument('action')) {
                'add' => $end(Area::SETTINGS, 'cluster_server_added', $text($argument('name'))),
                'remove' => $end(Area::SETTINGS, 'cluster_server_removed', $text($argument('name'))),
                default => null,
            },
            'app:cluster:key' => $option('show') === true && $option('rotate') !== true ? null : $end(Area::SETTINGS, $option('rotate') === true ? 'cluster_key_rotated' : 'cluster_key_created'),
            'app:cluster:join' => $end(Area::SETTINGS, 'cluster_joined'),
            'app:updates:maintenance' => match ($argument('action')) {
                'on' => $end(Area::UPDATES, 'maintenance_turned_on'),
                'off' => $end(Area::UPDATES, 'maintenance_turned_off'),
                default => null,
            },
            'app:updates:apply' => $option('status') === true || $option('preflight') === true ? null
                : $start(Area::UPDATES, $option('resume') === true ? 'update_resumed' : 'update_started'),
            'app:updates:pull' => $start(Area::UPDATES, 'update_started'),
            'app:updates:rollback' => $start(Area::UPDATES, 'update_rolled_back'),
            'app:updates:deployed' => $option('show-action') === true ? null : $start(Area::UPDATES, 'deployment_recorded'),
            default => null,
        };
    }
}
