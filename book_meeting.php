<?php
/**
 * Standalone meeting booking endpoint.
 * Bypasses Perfex CRM framework entirely.
 * Called directly by Vapi's bookMeeting API Request tool.
 *
 * URL: https://crm.formatdesignstudios.com/modules/ai_calling/book_meeting.php?token=FormatDesign2026Secure
 *
 * Key design: ALL DB work runs BEFORE the HTTP response is sent.
 * This avoids the fastcgi_finish_request() process-termination issue on shared
 * hosting, where PHP is killed after the connection is closed.
 * The extra ~10-50ms for a local DB query is well within Vapi's timeout.
 */

// ── Security token ───────────────────────────────────────────────────────────
$token = $_GET['token'] ?? '';
if ($token !== 'FormatDesign2026Secure') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"status":"forbidden"}';
    exit;
}

// ── Log dir ──────────────────────────────────────────────────────────────────
$log_dir = __DIR__ . '/logs/';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
$log_file = $log_dir . 'book_meeting_' . date('Y-m-d') . '.log';

// ── Read input ───────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] RAW: ' . $raw . "\n", FILE_APPEND);

// ── Extract tool arguments ────────────────────────────────────────────────────
// Vapi sends function.arguments as a JSON-encoded string in the nested format,
// or as a flat root-level object. Decode both paths.
$args = $data['message']['toolCalls'][0]['function']['arguments'] ?? $data;
if (is_string($args)) {
    $args = json_decode($args, true) ?? [];
}

$client_name    = $args['clientName']     ?? $data['clientName']     ?? 'Unknown';
$client_phone   = $args['clientPhone']    ?? $data['clientPhone']    ?? '';
$meeting_detail = $args['meetingDetails'] ?? $data['meetingDetails'] ?? null;
$call_id        = $data['message']['call']['id'] ?? $data['call']['id'] ?? $data['callId'] ?? null;

// Handle "unknown" phone placeholder from Vapi
if ($client_phone === '' || strtolower((string)$client_phone) === 'unknown') {
    $client_phone = '';
}

// ── DB: define BASEPATH so CI config files don't exit() ──────────────────────
if (!defined('BASEPATH')) define('BASEPATH', 'standalone');

$db_cfg_path = __DIR__ . '/../../application/config/database.php';
if (!file_exists($db_cfg_path)) {
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ERROR: DB config not found at ' . $db_cfg_path . "\n", FILE_APPEND);
    http_response_code(200);
    header('Content-Type: application/json');
    echo '{"status":"ok","message":"Meeting noted (no db)."}';
    exit;
}

$db = [];
require($db_cfg_path);
$cfg  = $db['default'] ?? [];
$host = $cfg['hostname'] ?? 'localhost';
$user = $cfg['username'] ?? '';
$pass = $cfg['password'] ?? '';
$name = $cfg['database'] ?? '';

// ── Connect ───────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
} catch (Exception $e) {
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] DB ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(200);
    header('Content-Type: application/json');
    echo '{"status":"ok","message":"Meeting noted (db error)."}';
    exit;
}

// ── Lead lookup — 3-priority chain ────────────────────────────────────────────
// Vapi's flat BookMeeting payload has no callId, so we fall through to recency.
$lead_id          = null;
$lead_name        = $client_name;
$lead_phone       = $client_phone;
$resolved_call_id = $call_id;

// Priority 1: explicit leadId in payload (future-proofing if Vapi sends it)
if (!empty($args['leadId'])) {
    try {
        $st = $pdo->prepare("SELECT id, name, phonenumber, vapi_call_id FROM tblleads WHERE id = ? LIMIT 1");
        $st->execute([(int)$args['leadId']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $lead_id          = (int) $row['id'];
            $lead_name        = $row['name']        ?: $client_name;
            $lead_phone       = ($row['phonenumber'] && strtolower($row['phonenumber']) !== 'unknown') ? $row['phonenumber'] : $client_phone;
            $resolved_call_id = $resolved_call_id ?: ($row['vapi_call_id'] ?: null);
        }
    } catch (Exception $e) {
        file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] LOOKUP1 ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// Priority 2: callId from payload → look up by vapi_call_id
if (!$lead_id && $call_id) {
    try {
        $st = $pdo->prepare("SELECT id, name, phonenumber FROM tblleads WHERE vapi_call_id = ? LIMIT 1");
        $st->execute([$call_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $lead_id    = (int) $row['id'];
            $lead_name  = $row['name']        ?: $client_name;
            $lead_phone = ($row['phonenumber'] && strtolower($row['phonenumber']) !== 'unknown') ? $row['phonenumber'] : $client_phone;
        }
    } catch (Exception $e) {
        file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] LOOKUP2 ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// Priority 3: most recently called lead in the last 30 minutes
// This handles Vapi's flat payload which contains no callId.
if (!$lead_id) {
    try {
        $st = $pdo->prepare("
            SELECT id, name, phonenumber, vapi_call_id
            FROM tblleads
            WHERE ai_call_status IN ('called', 'callback_scheduled')
              AND last_ai_call >= NOW() - INTERVAL 30 MINUTE
            ORDER BY last_ai_call DESC
            LIMIT 1
        ");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $lead_id          = (int) $row['id'];
            $lead_name        = $row['name']        ?: $client_name;
            $lead_phone       = ($row['phonenumber'] && strtolower($row['phonenumber']) !== 'unknown') ? $row['phonenumber'] : $client_phone;
            $resolved_call_id = $resolved_call_id ?: ($row['vapi_call_id'] ?: null);
        } else {
            file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] WARN: recency fallback found no lead' . "\n", FILE_APPEND);
        }
    } catch (Exception $e) {
        file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] LOOKUP3 ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// ── Insert booking ────────────────────────────────────────────────────────────
$notes = $meeting_detail
    ? mb_substr((string)$meeting_detail, 0, 1000)
    : 'Client confirmed meeting.';

try {
    $pdo->prepare("
        INSERT INTO tblai_meeting_bookings
            (lead_id, lead_name, lead_phone, vapi_call_id, booking_notes, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ")->execute([$lead_id, $lead_name, $lead_phone, $resolved_call_id, $notes]);
} catch (Exception $e) {
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] INSERT ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
}

// ── Update lead status ────────────────────────────────────────────────────────
if ($lead_id || $resolved_call_id) {
    $mtg_status_id = null;
    try {
        $st2 = $pdo->prepare("SELECT id FROM tblleads_status WHERE LOWER(`name`) = 'meeting booked' LIMIT 1");
        $st2->execute();
        $sr = $st2->fetch(PDO::FETCH_ASSOC);
        if ($sr) { $mtg_status_id = (int) $sr['id']; }
    } catch (Exception $e) { /* non-critical */ }

    $status_sql = $mtg_status_id ? ", `status` = {$mtg_status_id}" : '';
    $summary    = 'Meeting booked — ' . ($meeting_detail ?? 'Client confirmed.');

    try {
        if ($lead_id) {
            $pdo->prepare("
                UPDATE tblleads
                SET ai_call_status  = 'meeting_booked',
                    ai_call_summary = ?
                    {$status_sql}
                WHERE id = ?
            ")->execute([$summary, $lead_id]);
        } elseif ($resolved_call_id) {
            $pdo->prepare("
                UPDATE tblleads
                SET ai_call_status  = 'meeting_booked',
                    ai_call_summary = ?
                    {$status_sql}
                WHERE vapi_call_id = ?
            ")->execute([$summary, $resolved_call_id]);
        }
    } catch (Exception $e) {
        file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] UPDATE ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
    }

    // Also update call history row if it exists
    if ($resolved_call_id) {
        try {
            $pdo->prepare("
                UPDATE tblai_call_history
                SET status     = 'meeting_booked',
                    updated_at = NOW()
                WHERE vapi_call_id = ?
            ")->execute([$resolved_call_id]);
        } catch (Exception $e) { /* non-critical — history table may not exist yet */ }
    }
}

file_put_contents($log_file,
    '[' . date('Y-m-d H:i:s') . '] SAVED lead_id=' . $lead_id . ' call_id=' . $resolved_call_id . ' name=' . $lead_name . "\n",
    FILE_APPEND);

// ── Send 200 response — AFTER all DB work is done ────────────────────────────
http_response_code(200);
header('Content-Type: application/json');
echo '{"status":"ok","message":"Meeting booked."}';
