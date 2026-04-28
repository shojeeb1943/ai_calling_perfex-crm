<?php
/**
 * Standalone expert notification endpoint.
 * Bypasses Perfex CRM framework entirely — responds in < 1s.
 * Called directly by Vapi's notifyExpert API Request tool.
 *
 * URL: https://crm.formatdesignstudios.com/modules/ai_calling/notify.php
 * Security: token query param must match AI_CRON_TOKEN
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
$resp = '{"status":"ok"}';
header('Content-Length: ' . strlen($resp));
echo $resp;
if (ob_get_level()) ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

// ── Everything below runs after Vapi gets its 200 OK ────────────────────────

// Telegram credentials (from config/vapi.php values)
define('TG_BOT_TOKEN', '8211224055:AAEXeWH80cpCevoim-x647cb9ZrxwVWsvCw');
define('TG_CHAT_ID',   '7141390741');

// Extract client info — try all possible payload locations
$client_name  = $data['clientName']  ?? $data['leadName']  ?? 'Unknown';
$client_phone = $data['clientPhone'] ?? $data['leadPhone']  ?? '';

// Log raw payload for debugging
$log_dir = __DIR__ . '/logs/';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
file_put_contents(
    $log_dir . 'notify_expert_' . date('Y-m-d') . '.log',
    '[' . date('Y-m-d H:i:s') . '] name=' . $client_name . ' phone=' . $client_phone . ' raw=' . $raw . "\n",
    FILE_APPEND
);

// Send Telegram notification
$time = date('d M, h:i A');
$text = "\xF0\x9F\x94\x94 *\u{09A8}\u{09A4}\u{09C1}\u{09A8} Expert Request!*\n\n"
      . "\xF0\x9F\x91\xA4 Client: {$client_name}\n"
      . "\xF0\x9F\x93\x9E Number: {$client_phone}\n"
      . "\xF0\x9F\x95\x90 Time: {$time}\n\n"
      . "Client \u{098F}\u{0996}\u{09A8}\u{0987} \u{0995}\u{09A5}\u{09BE} \u{09AC}\u{09B2}\u{09A4}\u{09C7} \u{099A}\u{09BE}\u{0987}\u{099B}\u{09C7}\u{09A8}\u{0964}\nPlease call \u{0995}\u{09B0}\u{09C1}\u{09A8}\u{0964}";

$ch = curl_init('https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'chat_id'    => TG_CHAT_ID,
        'text'       => $text,
        'parse_mode' => 'Markdown',
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);
curl_exec($ch);
curl_close($ch);
