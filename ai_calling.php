<?php
/**
 * @file        ai_calling.php
 * @package     Perfex CRM — AI Calling Module
 * @version     1.2.0
 * @author      Format Design
 *
 * Module bootstrap file. Loaded by Perfex CRM on every request when the module
 * is active. Responsible for:
 *  - Defining all module-wide constants
 *  - Loading Vapi credentials from config/vapi.php
 *  - Registering CSRF exclusions, language files, hooks, menu items, and
 *    staff permissions
 *
 * ─── Configuration ────────────────────────────────────────────────────────────
 * Copy  config/vapi.example.php  →  config/vapi.php  and fill in real values.
 * The vapi.php file is git-ignored and must never be committed.
 *
 * ─── Changelog ────────────────────────────────────────────────────────────────
 * 1.2.0  2026-04-15  Bangla webhook keyword detection; removed invalid
 *                    assistantOverrides (transcriber/voice/startSpeakingPlan)
 *                    that conflicted with Vapi/Twilio — dashboard settings now
 *                    used directly. Added AI_CALLING_VERSION constant.
 * 1.1.0  2026-04-15  Bangla language support: Google transcriber, Azure
 *                    bn-BD-NabanitaNeural voice, endpointing tuning.
 * 1.0.0  —           Initial release.
 */

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: AI Calling
Description: Automated Vapi AI outbound calling for Perfex CRM leads
Version: 1.2.0
Requires at least: 2.3.*
Author: Format Design
*/

// ─── Module identity ──────────────────────────────────────────────────────────

/** @var string Current module version — update this with every change. */
define('AI_CALLING_VERSION', '1.2.0');

/** @var string Slug used in routes, permissions, language keys, and hooks. */
define('AI_CALLING_MODULE_NAME', 'ai_calling');

/** @var string Absolute path to this module's root directory (with trailing slash). */
define('AI_CALLING_MODULE_PATH', dirname(__FILE__) . '/');

// ─── Vapi credentials ─────────────────────────────────────────────────────────

$_vapi_config = AI_CALLING_MODULE_PATH . 'config/vapi.php';

if (file_exists($_vapi_config)) {
    require_once($_vapi_config);
} else {
    // Safe defaults so the module does not crash the whole CRM if the config
    // file is missing (e.g. fresh clone before vapi.php has been created).
    // Calls will fail gracefully until real credentials are supplied.

    /** @var string Vapi REST API key (Bearer token). */
    define('AI_VAPI_API_KEY',      '');

    /** @var string Vapi Phone Number ID for Amarip SIP trunk. */
    define('AI_VAPI_PHONE_ID',     '');

    /** @var string Vapi Phone Number ID for Twilio (second provider). */
    define('AI_VAPI_TWILIO_PHONE_ID', '');

    /** @var string Vapi Assistant ID that handles the conversation. */
    define('AI_VAPI_ASSISTANT_ID', '');

    /** @var string Secret token that must be present in the cron URL. */
    define('AI_CRON_TOKEN',        'change-me');

    log_message('error', '[ai_calling] config/vapi.php is missing! Copy vapi.example.php → vapi.php');
}

// Ensure Twilio constant exists even on older vapi.php files that predate this feature
if (!defined('AI_VAPI_TWILIO_PHONE_ID')) {
    define('AI_VAPI_TWILIO_PHONE_ID', '');
}

// ─── API & calling behaviour constants ───────────────────────────────────────

/** @var string Full URL for the Vapi outbound-call endpoint. */
define('AI_VAPI_API_URL', 'https://api.vapi.ai/call/phone');

/** @var int Maximum number of leads to call in a single cron/manual session. */
define('AI_MAX_CALLS_PER_RUN', 2);

/** @var int Seconds to sleep between consecutive Vapi API calls. SIP trunks need time to release the previous call before a new INVITE is sent. */
define('AI_CALL_DELAY_SEC', 10);

/** @var int Days to wait before calling a lead again after a "callback_scheduled" outcome. */
define('AI_FOLLOWUP_DAYS', 5);

/** @var int Maximum total call attempts allowed per lead. Leads at this count are skipped. */
define('AI_MAX_FOLLOWUPS', 3);

// ─── CSRF: allow Vapi to POST the webhook without a CSRF token ────────────────

hooks()->add_filter('csrf_exclude_uris', 'ai_calling_csrf_exclude_uris');

/**
 * Adds the webhook endpoint to Perfex CRM's CSRF exclusion list.
 *
 * Vapi sends POST requests to the webhook URL from its own servers and cannot
 * include a CSRF token. Both the bare path and the admin-prefixed path are
 * excluded to cover all routing scenarios.
 *
 * @param  array $exclude_uris Existing list of CSRF-excluded URI patterns.
 * @return array               Updated list with the webhook URIs appended.
 */
function ai_calling_csrf_exclude_uris(array $exclude_uris): array
{
    $exclude_uris[] = 'ai_calling/webhook';
    $exclude_uris[] = 'admin/ai_calling/webhook';
    return $exclude_uris;
}

// ─── Language ─────────────────────────────────────────────────────────────────

register_language_files(AI_CALLING_MODULE_NAME, [AI_CALLING_MODULE_NAME]);

// ─── Activation / Deactivation ────────────────────────────────────────────────

register_activation_hook(AI_CALLING_MODULE_NAME, 'ai_calling_activation_hook');
register_deactivation_hook(AI_CALLING_MODULE_NAME, 'ai_calling_deactivation_hook');

/**
 * Executed once when a staff member activates the module via Setup → Modules.
 *
 * Delegates to install.php which:
 *  - Adds the required columns to tblleads (idempotent — safe to re-run).
 *  - Creates the logs/ directory.
 *
 * @return void
 */
function ai_calling_activation_hook(): void
{
    require_once(AI_CALLING_MODULE_PATH . 'install.php');
}

/**
 * Executed when the module is deactivated.
 *
 * Currently a no-op. Database columns and log files are intentionally kept so
 * that re-activating the module does not lose historical call data.
 *
 * @return void
 */
function ai_calling_deactivation_hook(): void
{
    // Nothing to clean up.
}

// ─── Admin init: sidebar menu + staff permissions ─────────────────────────────

hooks()->add_action('admin_init', 'ai_calling_module_init_menu_items');
hooks()->add_action('admin_init', 'ai_calling_permissions');

/**
 * Registers the "AI Calling" item in the Perfex CRM admin sidebar.
 *
 * Hooked into `admin_init` so it runs on every authenticated admin request.
 * Position 12 places it after the built-in "Leads" menu item.
 *
 * @return void
 */
function ai_calling_module_init_menu_items(): void
{
    $CI = &get_instance();
    $CI->app_menu->add_sidebar_menu_item('ai-calling', [
        'name'     => _l('ai_calling_menu'),
        'href'     => admin_url('ai_calling'),
        'icon'     => 'fa fa-phone',
        'position' => 12,
    ]);
}

/**
 * Registers the module's staff capability with Perfex CRM's permissions system.
 *
 * Adds a single `view` capability under the "AI Calling" group. Access can be
 * granted or revoked per staff role via Setup → Roles.
 *
 * @return void
 */
function ai_calling_permissions(): void
{
    $capabilities = [];
    $capabilities['capabilities'] = [
        'view' => _l('permission_view') . ' (' . _l('permission_global') . ')',
    ];
    register_staff_capabilities(AI_CALLING_MODULE_NAME, $capabilities, _l('ai_calling_menu'));
}
