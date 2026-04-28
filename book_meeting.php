<?php
/**
 * Standalone meeting booking endpoint.
 * Bypasses Perfex CRM framework entirely — responds in < 1s.
 * Called directly by Vapi's bookMeeting API Request tool.
 *
 * URL: https://crm.formatdesignstudios.com/modules/ai_calling/book_meeting.php
 * Security: token query param must match the secret below.
 */

// ── Security token check ────────────────────────────────────────────────────
$token = $_GET['token'] ?? '';
if ($token !== 'FormatDesign2026Secure') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"status":"forbidden"}';
    exit;
}

// ── Read input immediately (before any output) ──────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

// ── Respond instantly so Vapi doesn't timeout ───────────────────────────────
http_response_code(200);
header('Content-Type: application/json');
header('Connection: close');
$resp = '{"status":"ok","message":"Meeting booked successfully."}';
header('Content-Length: ' . strlen($resp));
echo $resp;
if (ob_get_level()) ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

// ── Everything below runs after Vapi gets its 200 OK ────────────────────────

$log_dir = __DIR__ . '/logs/';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
file_put_contents(
    $log_dir . 'book_meeting_' . date('Y-m-d') . '.log',
    '[' . date('Y-m-d H:i:s') . '] ' . $raw . "\n",
    FILE_APPEND
);

// ── Extract client info from Vapi payload ───────────────────────────────────
$client_name    = $data['clientName']    ?? $data['client_name']    ?? 'Unknown';
$client_phone   = $data['clientPhone']   ?? $data['client_phone']   ?? '';
$meeting_detail = $data['meetingDetails'] ?? $data['meeting_details'] ?? null;

// ── Load DB credentials from Perfex CRM config ──────────────────────────────
$db_config_path = __DIR__ . '/../../../application/config/database.php';
if (!file_exists($db_config_path)) {
    file_put_contents($log_dir . 'book_meeting_' . date('Y-m-d') . '.log',
        '[' . date('Y-m-d H:i:s') . '] ERROR: DB config not found at ' . $db_config_path . "\n",
        FILE_APPEND);
    exit;
}

$db = [];
require($db_config_path);
// CodeIgniter stores config in $db['default']
$cfg = $db['default'] ?? [];
$host = $cfg['hostname'] ?? 'localhost';
$user = $cfg['username'] ?? '';
$pass = $cfg['password'] ?? '';
$name = $cfg['database'] ?? '';

// ── Connect via PDO ──────────────────────────────────────────────────────────
try {
    $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Exception $e) {
    file_put_contents($log_dir . 'book_meeting_' . date('Y-m-d') . '.log',
        '[' . date('Y-m-d H:i:s') . '] DB ERROR: ' . $e->getMessage() . "\n",
        FILE_APPEND);
    exit;
}

// ── Resolve lead_id from vapi_call_id if available ──────────────────────────
$call_id  = $data['call']['id'] ?? $data['callId'] ?? null;
$lead_id  = null;
$lead_name  = $client_name;
$lead_phone = $client_phone;

if ($call_id) {
    $stmt = $pdo->prepare("SELECT id, name, phonenumber FROM tblleads WHERE vapi_call_id = ? LIMIT 1");
    $stmt->execute([$call_id]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lead) {
        $lead_id    = $lead['id'];
        $lead_name  = $lead['name']        ?: $client_name;
        $lead_phone = $lead['phonenumber'] ?: $client_phone;
    }
}

// ── Insert meeting booking ───────────────────────────────────────────────────
$notes = $meeting_detail
    ? mb_substr($meeting_detail, 0, 1000)
    : ($raw ? mb_substr($raw, 0, 500) : null);

$stmt = $pdo->prepare("
    INSERT INTO tblai_meeting_bookings
        (lead_id, lead_name, lead_phone, vapi_call_id, booking_notes, created_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");
$stmt->execute([$lead_id, $lead_name, $lead_phone, $call_id, $notes]);

// ── Update lead status ───────────────────────────────────────────────────────
if ($call_id) {
    $stmt = $pdo->prepare("
        UPDATE tblleads
        SET ai_call_status = 'meeting_booked',
            ai_call_summary = ?
        WHERE vapi_call_id = ?
    ");
    $stmt->execute([
        'Meeting booked — ' . ($meeting_detail ?? 'Client confirmed meeting.'),
        $call_id,
    ]);
}

file_put_contents($log_dir . 'book_meeting_' . date('Y-m-d') . '.log',
    '[' . date('Y-m-d H:i:s') . '] SAVED — lead_id=' . $lead_id . ' name=' . $lead_name . ' phone=' . $lead_phone . "\n",
    FILE_APPEND);
