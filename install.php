<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * install.php — Runs on module activation.
 * Creates the logs directory.
 */

$log_dir = AI_CALLING_MODULE_PATH . 'logs/';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}

log_message('info', '[ai_calling] Module activated. Logs dir: ' . $log_dir);
