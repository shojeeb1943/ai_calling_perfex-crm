<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: AI Calling
Description: Automated Vapi AI outbound calling for Perfex CRM leads
Version: 1.0.0
Requires at least: 2.3.*
Author: Format Design
*/

// ─── Module constants ─────────────────────────────────────────────────────────
define('AI_CALLING_MODULE_NAME',    'ai_calling');
define('AI_CALLING_MODULE_PATH',    dirname(__FILE__) . '/');

// Credentials — loaded from config/vapi.php (git-ignored).
// Copy config/vapi.example.php → config/vapi.php and fill in your values.
$_vapi_config = AI_CALLING_MODULE_PATH . 'config/vapi.php';
if (file_exists($_vapi_config)) {
    require_once($_vapi_config);
} else {
    // Define safe defaults so the module doesn't crash the whole CRM.
    // Fix: upload config/vapi.php with your real credentials.
    define('AI_VAPI_API_KEY',      '');
    define('AI_VAPI_PHONE_ID',     '');
    define('AI_VAPI_ASSISTANT_ID', '');
    define('AI_CRON_TOKEN',        'change-me');
    log_message('error', '[ai_calling] config/vapi.php is missing! Copy vapi.example.php → vapi.php');
}

define('AI_VAPI_API_URL',           'https://api.vapi.ai/call/phone');
define('AI_MAX_CALLS_PER_RUN',      50);
define('AI_CALL_DELAY_SEC',         2);
define('AI_FOLLOWUP_DAYS',          5);
define('AI_MAX_FOLLOWUPS',          3);

// ─── CSRF: exclude webhook from CSRF protection (Vapi POSTs without token) ────
hooks()->add_filter('csrf_exclude_uris', 'ai_calling_csrf_exclude_uris');

function ai_calling_csrf_exclude_uris($exclude_uris)
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

function ai_calling_activation_hook()
{
    require_once(AI_CALLING_MODULE_PATH . 'install.php');
}

function ai_calling_deactivation_hook()
{
    // Nothing to clean up
}

// ─── Admin init: sidebar menu + permissions ───────────────────────────────────
hooks()->add_action('admin_init', 'ai_calling_module_init_menu_items');
hooks()->add_action('admin_init', 'ai_calling_permissions');

function ai_calling_module_init_menu_items()
{
    $CI = &get_instance();
    $CI->app_menu->add_sidebar_menu_item('ai-calling', [
        'name'     => _l('ai_calling_menu'),
        'href'     => admin_url('ai_calling'),
        'icon'     => 'fa fa-phone',
        'position' => 12,
    ]);
}

function ai_calling_permissions()
{
    $capabilities = [];
    $capabilities['capabilities'] = [
        'view' => _l('permission_view') . ' (' . _l('permission_global') . ')',
    ];
    register_staff_capabilities(AI_CALLING_MODULE_NAME, $capabilities, _l('ai_calling_menu'));
}
