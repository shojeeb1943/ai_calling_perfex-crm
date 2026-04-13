<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * install.php — Runs on module activation.
 * Adds required columns to tblleads and creates the logs directory.
 */

$CI = &get_instance();

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
    // Only add the column if it doesn't already exist
    $exists = $CI->db->query(
        "SELECT COUNT(*) AS cnt
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'tblleads'
           AND COLUMN_NAME  = ?",
        [$col]
    )->row()->cnt;

    if (!$exists) {
        $CI->db->query("ALTER TABLE tblleads ADD COLUMN `{$col}` {$definition}");
        log_message('info', "[ai_calling] Added column tblleads.{$col}");
    }
}

// Create logs directory
$log_dir = AI_CALLING_MODULE_PATH . 'logs/';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}

log_message('info', '[ai_calling] Module activated / upgraded.');
