<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Service\Operations\AdminActions;
use App\Service\Settings\SettingsSections;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Everything a dashboard page changes can also be done from the command
 * line (and lives in YAML or the database, never only in the page). Each
 * page action the audit trail knows maps to its command or app:config
 * section here, so a new page action fails this test until it has one.
 * The few that must happen in a browser say why.
 */
final class SettingsParityTest extends TestCase
{
    /** Page action => the command line that does the same, or "browser: why". */
    private const PARITY = [
        'app_settings_save' => 'app:config general',
        'app_page_speed_save' => 'app:config page-speed',
        'app_anonymous_settings_save' => 'app:config collection',
        'app_analytics_privacy_settings_save' => 'app:config bi-disclosure',
        'app_branding_settings_save' => 'app:config branding',
        'app_consent_manager_save' => 'app:config consent-manager',
        'app_standalone_consent' => 'app:config standalone-consent',
        'app_tag_manager_save' => 'app:config tag-manager',
        'app_internal_traffic_save' => 'app:config organization-traffic',
        'app_feature_flags_save' => 'app:config feature-flags',
        'app_data_lifecycle_save' => 'app:config data-lifecycle',
        'app_data_model_save' => 'app:config data-model',
        'app_bi_glossary_save' => 'app:config bi-glossary',
        'app_bi_page_groups_save' => 'app:config page-groups',
        'app_reporting_timezone_save' => 'app:config reporting-timezone',
        'app_api_tokens_settings' => 'app:config reporting-api',
        'app_audit_trail_settings' => 'app:config audit-trail',
        'app_alerts_rule_save' => 'app:alerts:rule',
        'app_alerts_rule_delete' => 'app:alerts:rule',
        'app_install_execute' => 'app:install',
        'app_user_create' => 'app:user:create',
        'app_user_password_update' => 'app:user:reset-password',
        'app_user_update' => 'app:user:profile',
        'app_user_delete' => 'app:user:delete',
        'app_permission_groups_create' => 'app:permission-groups',
        'app_permission_groups_update' => 'app:permission-groups',
        'app_permission_groups_delete' => 'app:permission-groups',
        'app_profile_save' => 'app:user:profile',
        'app_profile_password' => 'app:user:reset-password',
        'app_website_create' => 'app:create-website',
        'app_website_domains' => 'app:website',
        'app_website_delete' => 'app:website',
        'app_website_groups_label' => 'app:website-scopes',
        'app_website_groups_create' => 'app:website-scopes',
        'app_website_groups_rename' => 'app:website-scopes',
        'app_website_groups_delete' => 'app:website-scopes',
        'app_website_groups_assign' => 'app:website-scopes',
        'app_bigquery_connection' => 'app:config bigquery',
        'app_bigquery_views' => 'app:config bigquery',
        'app_bigquery_schedule' => 'app:config bigquery',
        'app_bigquery_key_remove' => 'app:config bigquery',
        'app_bigquery_test' => 'app:bigquery:check',
        'app_bigquery_google_start' => 'browser: signing in with Google is an OAuth consent in a browser; a service account key (app:config secret bigquery.service_account_key) does the same job',
        'app_bigquery_google_callback' => 'browser: the second half of signing in with Google',
        'app_bigquery_google_disconnect' => 'app:config bigquery',
        'app_reporting_database_connection' => 'app:reporting-database:configure',
        'app_reporting_database_connection_remove' => 'app:reporting-database:configure',
        'app_reporting_database_views' => 'app:config reporting-database',
        'app_reporting_database_schedule' => 'app:config reporting-database',
        'app_reporting_database_test' => 'app:reporting-database:check',
        'app_forensic_database_access' => 'app:config forensic-database',
        'app_forensic_database_connection' => 'app:forensic-database:configure',
        'app_forensic_database_connection_remove' => 'app:forensic-database:configure',
        'app_forensic_database_views' => 'app:config forensic-database',
        'app_forensic_database_schedule' => 'app:config forensic-database',
        'app_forensic_database_test' => 'app:forensic-database:check',
        'app_api_tokens_create' => 'app:api:token:create',
        'app_api_tokens_revoke' => 'app:api:token:revoke',
        'app_mcp_connection_revoke' => 'app:mcp:connection:revoke',
        'app_mcp_authorize_decide' => 'browser: an administrator approves an AI assistant\'s sign-in in the browser it signs in from; app:api:token:create makes an assistant token instead',
        'app_email_save' => 'app:config email',
        'app_alerts_destination_save' => 'app:alerts:destination',
        'app_alerts_destination_delete' => 'app:alerts:destination',
        'app_alerts_secret_save' => 'app:alerts:destination',
        'app_updates_refresh' => 'app:updates:check',
        'app_updates_install' => 'app:updates:apply',
        'app_updates_upload' => 'app:updates:apply',
        'app_updates_method' => 'app:updates:method',
        'app_updates_settings' => 'app:config updates',
        'app_updates_cluster_settings' => 'app:config cluster',
        'app_updates_cluster_server_add' => 'app:cluster:server',
        'app_updates_cluster_server_remove' => 'app:cluster:server',
        'app_bigquery_sync' => 'app:bigquery:sync',
        'app_reporting_database_sync' => 'app:reporting-database:sync',
        'app_forensic_database_sync' => 'app:forensic-database:sync',
        'app_scheduled_tasks_run' => 'app:scheduler:run',
        'app_data_model_publish' => 'app:website-scopes',
        'app_data_model_regenerate' => 'app:analytics:views:regenerate',
        'app_bi_glossary_sync' => 'app:analytics:glossary:sync',
        'app_setup_build_scripts' => 'app:assets:build-js',
        'app_tracking_retry_now' => 'app:tracking:retry-failed',
        'app_email_test' => 'app:email:test',
        'app_alerts_test' => 'app:alerts:test',
        'app_data_transfer_export' => 'app:data:export',
        'app_data_transfer_import' => 'app:data:import',
        'app_data_transfer_copy' => 'app:data:copy',
        'app_audit_trail_export' => 'app:audit:list',
    ];

    public function testEveryPageActionHasACommandOrSaysWhyItNeedsABrowser(): void
    {
        $commands = self::commands();
        $missing = array_diff(array_keys(AdminActions::ROUTES), array_keys(self::PARITY));
        self::assertSame([], array_values($missing), 'Give these page actions a command (or an app:config section) and list them here.');

        foreach (self::PARITY as $route => $cli) {
            self::assertArrayHasKey($route, AdminActions::ROUTES, $route.' is no longer a page action; remove it here.');
            if (str_starts_with($cli, 'browser: ')) {
                continue;
            }
            if (str_starts_with($cli, 'app:config ')) {
                self::assertArrayHasKey(substr($cli, strlen('app:config ')), SettingsSections::AUDIT_ACTIONS, $route.' names a section app:config does not have.');
                continue;
            }
            self::assertContains($cli, $commands, $route.' names a command that does not exist.');
        }
    }

    public function testEverySectionRecordsTheAuditActionOfAPageThatChangesTheSameSettings(): void
    {
        $pageActions = array_column(AdminActions::ROUTES, 1);
        foreach (SettingsSections::AUDIT_ACTIONS as $section => [, $action]) {
            if (in_array($section, ['bigquery', 'reporting-database', 'forensic-database'], true)) {
                // Their pages record each form separately (connection, views, schedule).
                continue;
            }
            self::assertContains($action, $pageActions, $section.' should record the action its page records.');
        }
    }

    /** @return list<string> every command name in src/Command */
    private static function commands(): array
    {
        $names = [];
        foreach (glob(dirname(__DIR__, 2).'/src/Command/*.php') ?: [] as $file) {
            $class = 'App\\Command\\'.basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getAttributes(AsCommand::class) as $attribute) {
                array_push($names, ...explode('|', $attribute->newInstance()->name));
            }
        }

        return $names;
    }
}
