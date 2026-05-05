<?php
/**
 * @file        install.php
 * @package     Perfex CRM — AI Calling Module
 *
 * Activation script — executed once by ai_calling_activation_hook() whenever
 * a staff member activates (or re-activates) the module via Setup → Modules.
 *
 * Responsibilities:
 *  1. Add the eight AI Calling columns to tblleads (idempotent — each column
 *     is checked against information_schema before being added, so re-running
 *     this script on an existing installation is completely safe).
 *  2. Create the logs/ directory used by the session and webhook file loggers.
 *
 * Column reference:
 *
 *  ai_call_status      — Current call state for this lead.
 *                        Possible values: pending | called | interested |
 *                        not_interested | callback_scheduled
 *                        Defaults to 'pending' for all new and existing leads.
 *
 *  vapi_call_id        — UUID returned by the Vapi API when a call is dispatched.
 *                        Used as the foreign key to match incoming webhooks.
 *
 *  last_ai_call        — Datetime of the most recent call attempt. NULL means
 *                        the lead has never been called.
 *
 *  next_followup_date  — The earliest date the lead should be called again.
 *                        Set to (last_ai_call + AI_FOLLOWUP_DAYS) on dispatch.
 *
 *  followup_count      — Running total of call attempts. Leads at or above
 *                        AI_MAX_FOLLOWUPS (default 3) are excluded from sessions.
 *
 *  ai_context_notes    — Free-text field editable on the lead record. Injected
 *                        into the Vapi assistant's variableValues as "context".
 *
 *  ai_call_summary     — First 1,000 characters of the Vapi call transcript,
 *                        written back by the webhook handler.
 *
 *  call_recording_url  — URL to the Vapi-hosted call recording. May be NULL if
 *                        recording is disabled or the call did not connect.
 */

defined('BASEPATH') or exit('No direct script access allowed');

$CI = &get_instance();

// ─── Schema migration ─────────────────────────────────────────────────────────

/**
 * Column definitions to add to tblleads.
 *
 * Keys are column names; values are the SQL type + constraint fragment passed
 * verbatim to ALTER TABLE ... ADD COLUMN.
 *
 * @var array<string, string>
 */
$columns = [
    'ai_call_status'     => "VARCHAR(30)  NOT NULL DEFAULT 'pending'",
    'vapi_call_id'       => "VARCHAR(100) DEFAULT NULL",
    'last_ai_call'       => "DATETIME     DEFAULT NULL",
    'next_followup_date' => "DATE         DEFAULT NULL",
    'followup_count'     => "INT(11)      NOT NULL DEFAULT 0",
    'ai_context_notes'   => "TEXT         DEFAULT NULL",
    'ai_call_summary'    => "TEXT         DEFAULT NULL",
    'call_recording_url' => "VARCHAR(500) DEFAULT NULL",
];

foreach ($columns as $col => $definition) {
    if (!$CI->db->field_exists($col, 'tblleads')) {
        $CI->db->query("ALTER TABLE tblleads ADD COLUMN `{$col}` {$definition}");
        log_message('info', "[ai_calling] Added column tblleads.{$col}");
    }
}

// ─── Meeting bookings table ───────────────────────────────────────────────────

if (!$CI->db->table_exists('tblai_meeting_bookings')) {
    $charset = $CI->db->char_set ?: 'utf8';
    $CI->db->query("
        CREATE TABLE IF NOT EXISTS `tblai_meeting_bookings` (
            `id`          INT(11)      NOT NULL AUTO_INCREMENT,
            `lead_id`     INT(11)      DEFAULT NULL,
            `lead_name`   VARCHAR(255) NOT NULL DEFAULT '',
            `lead_phone`  VARCHAR(50)  NOT NULL DEFAULT '',
            `vapi_call_id` VARCHAR(100) DEFAULT NULL,
            `booking_notes` TEXT       DEFAULT NULL,
            `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_lead_id` (`lead_id`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset}
    ");
    log_message('info', '[ai_calling] Created table tblai_meeting_bookings');
}

// ─── Logs directory ───────────────────────────────────────────────────────────

/**
 * Ensure the logs/ directory exists.
 *
 * Written to: <module_root>/logs/
 * Contents  : session_YYYY-MM-DD.log  — per-session call results
 *             webhook_YYYY-MM-DD.log  — raw Vapi webhook payloads (JSON Lines)
 *
 * The directory is git-ignored; it must be created at runtime.
 */
$log_dir = AI_CALLING_MODULE_PATH . 'logs/';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}

log_message('info', '[ai_calling] Module activated / upgraded.');
