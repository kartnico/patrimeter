<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Classification of every route of the application, for the access control and isolation tests. A new route must
 * be classified here, otherwise RouteCatalogTest fails: no page can be added without deciding who can reach it and
 * without a robot going through it.
 */
final class RouteCatalog
{
    /** Reachable without being signed in. */
    public const array PUBLIC = [
        'app_login', 'app_theme', 'app_locale',
        'app_register', 'app_register_submit', 'app_register_sent', 'app_register_confirm', 'app_register_confirm_submit',
        'app_password_forgot', 'app_password_forgot_submit', 'app_password_forgot_sent', 'app_password_reset', 'app_password_reset_submit',
        'app_passkey_login_options', 'app_passkey_login', 'app_demo_start',
        'app_reminder_unsubscribe', 'app_reminder_unsubscribe_confirm',
        'app_setup', 'app_setup_submit',
    ];

    /** Reachable by everyone, but refused without a valid CSRF token. */
    public const array PUBLIC_WITH_CSRF = ['app_logout'];

    /** Second factor in progress (password checked, code expected). */
    public const array SECOND_FACTOR = ['app_2fa_login', 'app_2fa_login_check', 'app_2fa_key_options'];

    /** Mandatory steps of access to the account: TOTP, unlocking, recovery key. */
    public const array ENROLLMENT = ['app_totp_setup', 'app_totp_enable', 'app_vault_unlock', 'app_vault_unlock_submit', 'app_recovery_key', 'app_recovery_key_confirm'];

    /** Security settings of the signed-in user: no parameter points to another account. */
    public const array OWN_SETTINGS = [
        'app_security', 'app_security_signin', 'app_security_agents', 'app_security_journal', 'app_security_reauthenticate', 'app_security_password', 'app_security_backup_codes', 'app_security_sessions', 'app_security_session_revoke', 'app_security_trusted_browser_revoke', 'app_security_trusted_browsers_revoke', 'app_security_delete_account', 'app_recovery_key_renew',
        'app_passkey_options', 'app_passkey_register', 'app_passkey_rename', 'app_passkey_delete',
        'app_oauth_authorize', 'app_oauth_authorize_submit', 'app_access_token_create', 'app_access_token_revoke',
        'app_data', 'app_data_export', 'app_data_import', 'app_data_import_csv', 'app_data_csv_model', 'app_demo_tour', 'app_reminder_save',
        'app_security_email_locale', 'app_security_appearance', 'app_challenges_style', 'app_challenges_settings',
    ];

    /**
     * Bearer token, without session: without a token, a 401 answer and not a redirection to the sign-in page. The
     * routes that read or write data are gone through by the robot of ApiIsolationTest.
     */
    public const array BEARER = [
        'app_api_me', 'app_api_account_types', 'app_api_net_worth', 'app_api_history', 'app_api_properties', 'app_api_goals', 'app_api_budget', 'app_api_challenges',
        'app_api_accounts', 'app_api_account_create', 'app_api_account', 'app_api_account_update', 'app_api_account_delete',
        'app_api_account_archive', 'app_api_account_unarchive', 'app_api_account_link',
        'app_api_statements', 'app_api_statement_save', 'app_api_statement_delete',
        'app_api_shares', 'app_api_share_save', 'app_api_share_delete',
    ];

    /** Called directly by the OAuth clients, without session nor token. */
    public const array OAUTH_ENDPOINTS = [
        'app_oauth_server_metadata', 'app_oauth_resource_metadata', 'app_oauth_resource_metadata_mcp', 'app_oauth_resource_metadata_api',
        'app_oauth_register', 'app_oauth_token', 'app_oauth_revoke',
    ];

    /** Without session nor authentication: CSP reports sent by the browsers, monitoring. */
    public const array SERVICE_ENDPOINTS = ['app_csp_report', 'app_health', 'app_health_metrics'];

    /** Wealth data: gone through by the isolation robot (IsolationTest). */
    public const array OWNED_DATA = [
        'app_dashboard', 'app_report', 'app_history', 'app_entry', 'app_entry_save',
        'app_accounts', 'app_account_new', 'app_account_create', 'app_account_edit', 'app_account_update',
        'app_account_archive', 'app_account_unarchive', 'app_account_delete', 'app_statement_save', 'app_statement_file', 'app_statement_delete',
        'app_indicators', 'app_indicator_create', 'app_indicator_edit', 'app_indicator_update', 'app_indicator_delete',
        'app_properties', 'app_property_new', 'app_property_create', 'app_share_save', 'app_share_delete', 'app_account_link',
        'app_budget', 'app_budget_entry', 'app_budget_entry_save', 'app_budget_month_delete',
        'app_budget_household', 'app_budget_household_save', 'app_budget_category_create', 'app_budget_category_edit',
        'app_budget_category_update', 'app_budget_category_delete', 'app_budget_line_create', 'app_budget_line_edit',
        'app_budget_line_update', 'app_budget_line_delete', 'app_budget_category_move', 'app_budget_line_move',
        'app_family', 'app_family_member_add', 'app_family_member_update', 'app_family_member_delete',
        'app_budget_carbure', 'app_budget_carbure_save', 'app_budget_carbure_mapping', 'app_budget_carbure_delete',
        'app_goals', 'app_goal_entry', 'app_goal_new', 'app_goal_create', 'app_goal_edit', 'app_goal_update', 'app_goal_delete',
        'app_welcome_pyramid', 'app_welcome_level', 'app_welcome_express', 'app_welcome_accounts', 'app_welcome_questions',
        'app_welcome_questions_save', 'app_challenges',
    ];

    private const array SAMPLE_PARAMETERS = [
        'id' => '1',
        'credential' => 'AAAAAAAAAAAAAAAAAAAAAA',
        'month' => '2026-01',
        'level' => 'precaution',
        'session' => '0123456789abcdef0123456789abcdef',
        'browser' => '0123456789abcdef0123456789abcdef',
        'token' => 'AAAAAAAAAAAAAAAAAAAAAA.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
    ];

    /** @return array<string, Route> routes of the application (app_ prefix), by name */
    public static function routes(RouterInterface $router): array
    {
        $routes = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($name, 'app_')) {
                $routes[$name] = $route;
            }
        }

        return $routes;
    }

    /** @return list<string> categories the route belongs to */
    public static function categoriesOf(string $name): array
    {
        $categories = [];
        foreach (['PUBLIC' => self::PUBLIC, 'PUBLIC_WITH_CSRF' => self::PUBLIC_WITH_CSRF, 'SECOND_FACTOR' => self::SECOND_FACTOR,
            'ENROLLMENT' => self::ENROLLMENT, 'OWN_SETTINGS' => self::OWN_SETTINGS, 'OWNED_DATA' => self::OWNED_DATA,
            'BEARER' => self::BEARER, 'OAUTH_ENDPOINTS' => self::OAUTH_ENDPOINTS, 'SERVICE_ENDPOINTS' => self::SERVICE_ENDPOINTS] as $category => $names) {
            if (in_array($name, $names, true)) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    /**
     * Address of the route with its parameters (example values by default).
     *
     * @param array<string, string> $parameters
     */
    public static function path(Route $route, array $parameters = []): string
    {
        $parameters += self::SAMPLE_PARAMETERS;

        return (string) preg_replace_callback('/\{(\w+)\}/', static fn(array $m): string => $parameters[$m[1]] ?? $m[0], $route->getPath());
    }
}
