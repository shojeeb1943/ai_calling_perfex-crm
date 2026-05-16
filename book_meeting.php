<?php
/**
 * Standalone meeting booking endpoint.
 * Bypasses Perfex CRM framework entirely.
 * Called directly by Vapi's bookMeeting API Request tool.
 *
 * URL: https://crm.formatdesignstudios.com/modules/ai_calling/book_meeting.php?token=FormatDesign2026Secure
 */

// ── Security token ───────────────────────────────────────────────────────────
$token = $_GET['token'] ?? '';
if ($token !== 'FormatDesign2026Secure') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"status":"forbidden"}';
    exit;
}

// ── Read input ───────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

// ── Respond instantly — identical pattern to notify.php ─────────────────────
http_response_code(200);
header('Content-Type: application/json');
header('Connection: close');
$resp = '{"status":"ok","message":"Meeting booked."}';
header('Content-Length: ' . strlen($resp));
echo $resp;
if (ob_get_level()) ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

// ── Everything below runs after Vapi gets 200 OK ─────────────────────────────

$log_dir = __DIR__ . '/logs/';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
file_put_contents(
    $log_dir . 'book_meeting_' . date('Y-m-d') . '.log',
    '[' . date('Y-m-d H:i:s') . '] ' . $raw . "\n",
    FILE_APPEND
);

// ── Extract client info ───────────────────────────────────────────────────────
// Vapi sends tool arguments nested under message.toolCalls[0].function.arguments
// (may be a JSON string). Fall back to flat root-level keys for other senders.
$args = $data['message']['toolCalls'][0]['function']['arguments'] ?? [];
if (is_string($args)) {
    $args = json_decode($args, true) ?? [];
}

$client_name    = $args['clientName']     ?? $data['clientName']     ?? $data['client_name']     ?? 'Unknown';
$client_phone   = $args['clientPhone']    ?? $data['clientPhone']    ?? $data['client_phone']    ?? '';
$meeting_detail = $args['meetingDetails'] ?? $data['meetingDetails'] ?? $data['meeting_details'] ?? null;
$call_id        = $data['message']['call']['id'] ?? $data['call']['id'] ?? $data['callId'] ?? null;

// ── DB: define BASEPATH so CI config files don't exit() ──────────────────────
if (!defined('BASEPATH')) define('BASEPATH', 'standalone');

// modules/ai_calling/ → modules/ → CRM root → application/config/database.php
$db_cfg_path = __DIR__ . '/../../application/config/database.php';
if (!file_exists($db_cfg_path)) {
    file_put_contents($log_dir . 'book_meeting_' . date('Y-m-d') . '.log',
        '[' . date('Y-m-d H:i:s') . '] ERROR: DB config not found' . "\n", FILE_APPEND);
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
    file_put_contents($log_dir . 'book_meeting_' . date('Y-m-d') . '.log',
        '[' . date('Y-m-d H:i:s') . '] DB ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
    exit;
}

// ── Resolve lead from vapi_call_id ────────────────────────────────────────────
$lead_id    = null;
$lead_name  = $client_name;
$lead_phone = $client_phone;

if ($call_id) {
    $st = $pdo->prepare("SELECT id, name, phonenumber FROM tblleads WHERE vapi_call_id = ? LIMIT 1");
    $st->execute([$call_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $lead_id    = (int) $row['id'];
        $lead_name  = $row['name']        ?: $client_name;
        $lead_phone = $row['phonenumber'] ?: $client_phone;
    }
}

// ── Insert booking ────────────────────────────────────────────────────────────
$notes = $meeting_detail
    ? mb_substr($meeting_detail, 0, 1000)
    : 'Client confirmed meeting.';

try {
    $pdo->prepare("
        INSERT INTO tblai_meeting_bookings
            (lead_id, lead_name, lead_phone, vapi_call_id, booking_notes, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ")->execute([$lead_id, $lead_name, $lead_phone, $call_id, $notes]);
} catch (Exception $e) {
    file_put_contents($log_dir . 'book_meeting_' . date('Y-m-d') . '.log',
        '[' . date('Y-m-d H:i:s') . '] INSERT ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
}

// ── Update lead status — look up "Meeting Booked" status ID dynamically ───────
if ($call_id) {
    // Dynamic lookup so the correct CRM status ID is used regardless of installation
    $mtg_status_id = null;
    try {
        $st2 = $pdo->prepare("SELECT id FROM tblleads_status WHERE LOWER(`name`) = 'meeting booked' LIMIT 1");
        $st2->execute();
        $sr = $st2->fetch(PDO::FETCH_ASSOC);
        if ($sr) { $mtg_status_id = (int) $sr['id']; }
    } catch (Exception $e) { /* ignore — CRM status update is non-critical */ }

    $status_sql = $mtg_status_id ? ", status = {$mtg_status_id}" : '';
    try {
        $pdo->prepare("
            UPDATE tblleads
            SET ai_call_status  = 'meeting_booked',
                ai_call_summary = ?
                {$status_sql}
            WHERE vapi_call_id  = ?
        ")->execute([
            'Meeting booked — ' . ($meeting_detail ?? 'Client confirmed.'),
            $call_id,
        ]);
    } catch (Exception $e) {
        file_put_contents($log_dir . 'book_meeting_' . date('Y-m-d') . '.log',
            '[' . date('Y-m-d H:i:s') . '] UPDATE ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
    }
}

file_put_contents($log_dir . 'book_meeting_' . date('Y-m-d') . '.log',
    '[' . date('Y-m-d H:i:s') . '] SAVED lead_id=' . $lead_id . ' name=' . $lead_name . "\n",
    FILE_APPEND);
