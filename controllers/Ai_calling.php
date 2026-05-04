<?php
/**
 * @file        controllers/Ai_calling.php
 * @package     Perfex CRM — AI Calling Module
 *
 * Main controller. Handles every HTTP request routed to this module:
 *
 *  GET  admin/ai_calling                        → index()         Dashboard
 *  POST admin/ai_calling/start_calling          → start_calling() Manual trigger (AJAX)
 *  GET  admin/ai_calling/cron/{token}           → cron()          Scheduler endpoint
 *  POST admin/ai_calling/webhook                → webhook()       Vapi callback receiver
 *  GET  admin/ai_calling/migrate                → migrate()       One-time DB migration
 *  GET  admin/ai_calling/test_api               → test_api()      Debug helper
 *
 * All staff-facing actions require the `view` capability registered by the
 * module. The webhook action intentionally has no CSRF protection (excluded in
 * ai_calling.php) because Vapi posts from external servers.
 */

defined('BASEPATH') or exit('No direct script access allowed');

class Ai_calling extends AdminController
{
    // ─── Bootstrap ────────────────────────────────────────────────────────────

    /**
     * Loads the model on construction.
     *
     * The model is accessed via `$this->ai_calling_model` throughout this class.
     */
    public function __construct()
    {
        parent::__construct();
        $this->load->model('ai_calling/ai_calling_model');
    }

    // ─── Public endpoints ─────────────────────────────────────────────────────

    /**
     * Renders the AI Calling dashboard.
     *
     * Displays six stat cards (pending, called today, interested, callback,
     * not-interested, total) and a table of the 20 most recent calls.
     *
     * @route  GET admin/ai_calling
     * @return void  Renders ai_calling/manage view.
     */
    public function index(): void
    {
        if (!staff_can('view', AI_CALLING_MODULE_NAME)) {
            access_denied(AI_CALLING_MODULE_NAME);
        }

        $data['stats']           = $this->ai_calling_model->get_stats();
        $data['recent_calls']    = $this->ai_calling_model->get_recent_calls(20);
        $data['active_provider'] = get_option('ai_calling_provider') ?: 'amarip';
        $data['title']           = _l('ai_calling_dashboard');

        $data['setting_hour_start']   = (int)(get_option('ai_calling_hour_start') ?: AI_CALLING_HOUR_START);
        $data['setting_hour_end']     = (int)(get_option('ai_calling_hour_end')   ?: AI_CALLING_HOUR_END);
        $data['setting_max_per_run']  = (int)(get_option('ai_calling_max_per_run')  ?: AI_MAX_CALLS_PER_RUN);
        $data['setting_delay_sec']    = (int)(get_option('ai_calling_delay_sec')    ?: AI_CALL_DELAY_SEC);
        $data['setting_followup_days']= (int)(get_option('ai_calling_followup_days') ?: AI_FOLLOWUP_DAYS);
        $data['setting_max_followups']= (int)(get_option('ai_calling_max_followups') ?: AI_MAX_FOLLOWUPS);

        $this->load->view('ai_calling/manage', $data);
    }

    /**
     * Meeting bookings list page.
     *
     * @route  GET admin/ai_calling/meetings
     * @return void  Renders ai_calling/meetings view.
     */
    public function meetings(): void
    {
        if (!staff_can('view', AI_CALLING_MODULE_NAME)) {
            access_denied(AI_CALLING_MODULE_NAME);
        }

        $data['meetings']       = $this->ai_calling_model->get_all_meetings(200);
        $data['meeting_stats']  = $this->ai_calling_model->get_meeting_stats();
        $data['title']          = _l('ai_calling_meetings');

        $this->load->view('ai_calling/meetings', $data);
    }

    /**
     * Triggers a calling session on demand and returns JSON.
     *
     * Called via AJAX from the "Start Calling Now" button on the dashboard.
     * Runs the same session logic as the cron endpoint but responds with JSON
     * so the dashboard can show progress without a full page reload.
     *
     * @route  POST admin/ai_calling/start_calling
     * @return void  Outputs JSON: { success, total, called, failed, log[] }
     */
    public function start_calling(): void
    {
        header('Content-Type: application/json');

        if (!staff_can('view', AI_CALLING_MODULE_NAME)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            return;
        }

        $result = $this->_run_calling_session();
        echo json_encode($result);
    }

    /**
     * Token-authenticated endpoint for scheduled cron jobs.
     *
     * The token in the URL must match AI_CRON_TOKEN; requests with a wrong or
     * missing token are rejected with HTTP 403. On success the session report
     * is written as plain text — suitable for Hostinger's cron job output log.
     *
     * @route  GET admin/ai_calling/cron/{token}
     * @param  string $token  Secret token from AI_CRON_TOKEN constant.
     * @return void           Outputs plain-text session report or dies with 403.
     */
    public function cron(string $token = ''): void
    {
        if ($token !== AI_CRON_TOKEN) {
            http_response_code(403);
            die('Forbidden');
        }

        $hour_start = (int)(get_option('ai_calling_hour_start') ?: AI_CALLING_HOUR_START);
        $hour_end   = (int)(get_option('ai_calling_hour_end')   ?: AI_CALLING_HOUR_END);
        $hour       = (int) date('H');
        if ($hour < $hour_start || $hour >= $hour_end) {
            echo "Skipped: outside calling hours ({$hour_start}:00–{$hour_end}:00). Current hour: {$hour}.\n";
            return;
        }

        $result = $this->_run_calling_session();

        echo "=== AI Calling Session ===\n";
        echo "Time   : " . date('Y-m-d H:i:s') . "\n";
        echo "Total  : {$result['total']}\n";
        echo "Called : {$result['called']}\n";
        echo "Failed : {$result['failed']}\n";
        if (!empty($result['log'])) {
            echo "\nLog:\n" . implode("\n", $result['log']) . "\n";
        }
    }

    /**
     * Receives and processes call-outcome webhooks from Vapi.
     *
     * Vapi POST this endpoint after every call ends. The payload is raw JSON
     * and may arrive in two different shapes (nested under `message` or flat at
     * root level); both are handled via null-coalescing.
     *
     * Processing steps:
     *  1. Decode and validate the raw JSON body.
     *  2. Append the full payload to the daily webhook log file.
     *  3. Extract the Vapi call ID, transcript, and recording URL.
     *  4. Detect the call outcome by scanning the transcript for keywords.
     *  5. Update the matching lead record via vapi_call_id.
     *
     * Outcome detection priority (first match wins):
     *  - "interested" (without "not") → interested
     *  - "not interested" / "no interest" → not_interested
     *  - "callback" / "call back" / "call me later" → callback_scheduled
     *  - (no match) → called
     *
     * @route  POST admin/ai_calling/webhook  (CSRF-excluded)
     * @return void  Outputs HTTP 200 + JSON { status: "received" }, or 400 on invalid payload.
     */
    /**
     * Dedicated endpoint for the notifyExpert Vapi API Request tool.
     *
     * Vapi calls this URL directly when the AI triggers the notifyExpert tool.
     * Accepts any payload format, extracts client info, sends Telegram notification,
     * and returns a 200 response immediately.
     *
     * @route  POST admin/ai_calling/notify_expert  (CSRF-excluded)
     * @return void
     */
    public function notify_expert(): void
    {
        // Read input BEFORE sending response (php://input closes after fastcgi_finish_request)
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? [];

        // Respond immediately so Vapi doesn't wait
        ob_start();
        echo json_encode(['status' => 'ok']);
        header('Connection: close');
        header('Content-Length: ' . ob_get_length());
        header('Content-Type: application/json');
        ob_end_flush();
        ob_flush();
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Log the raw payload for debugging
        $log_dir = dirname(__FILE__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
        if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
        file_put_contents(
            $log_dir . 'notify_expert_' . date('Y-m-d') . '.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $raw . "\n",
            FILE_APPEND
        );

        // Extract client info from any possible payload structure
        $client_name  = $data['clientName']
            ?? $data['message']['call']['customer']['name']
            ?? $data['call']['customer']['name']
            ?? 'Unknown';

        $client_phone = $data['clientPhone']
            ?? $data['message']['call']['customer']['number']
            ?? $data['call']['customer']['number']
            ?? '';

        $this->_send_whatsapp_expert_request($client_name, $client_phone);

        // Update lead status if call_id is available
        $call_id = $data['message']['call']['id'] ?? $data['call']['id'] ?? null;
        if ($call_id) {
            $this->ai_calling_model->update_lead_from_webhook($call_id, [
                'ai_call_status'  => 'expert_requested',
                'ai_call_summary' => 'Client requested expert — Telegram notification sent.',
            ]);
        }
    }

    /**
     * Dedicated endpoint for the bookMeeting Vapi API Request tool.
     *
     * Vapi calls this URL when the AI confirms a meeting booking with a client.
     * Saves the booking to tblai_meeting_bookings and updates the lead status.
     *
     * @route  POST admin/ai_calling/book_meeting  (CSRF-excluded)
     * @return void
     */
    public function book_meeting(): void
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? [];

        // Respond immediately so Vapi doesn't wait
        $tool_call_id = $data['message']['toolCalls'][0]['id']
            ?? $data['toolCallId']
            ?? null;

        $response = $tool_call_id
            ? ['results' => [['toolCallId' => $tool_call_id, 'result' => 'Meeting booked successfully.']]]
            : ['status' => 'ok'];

        ob_start();
        echo json_encode($response);
        header('Connection: close');
        header('Content-Length: ' . ob_get_length());
        header('Content-Type: application/json');
        ob_end_flush();
        ob_flush();
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Log raw payload
        $log_dir = dirname(__FILE__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
        if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
        file_put_contents(
            $log_dir . 'book_meeting_' . date('Y-m-d') . '.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $raw . "\n",
            FILE_APPEND
        );

        // Extract tool arguments (clientName, clientPhone, meetingDetails)
        $args = $data['message']['toolCalls'][0]['function']['arguments']
            ?? $data['arguments']
            ?? [];

        $call         = $data['message']['call'] ?? $data['call'] ?? [];
        $call_id      = $call['id'] ?? null;

        $client_name  = $args['clientName']
            ?? $call['customer']['name']
            ?? 'Unknown';

        $client_phone = $args['clientPhone']
            ?? $call['customer']['number']
            ?? '';

        $meeting_notes = $args['meetingDetails'] ?? null;

        // Resolve lead_id from DB using the call_id
        $lead_row = $call_id ? $this->db->query(
            "SELECT id, name, phonenumber FROM tblleads WHERE vapi_call_id = ?", [$call_id]
        )->row_array() : [];

        $lead_id    = (int) ($lead_row['id'] ?? 0);
        $lead_name  = $lead_row['name']        ?? $client_name;
        $lead_phone = $lead_row['phonenumber'] ?? $client_phone;

        // Insert meeting booking record
        $this->ai_calling_model->insert_meeting_booking(
            $lead_id, $lead_name, $lead_phone, (string) $call_id, $meeting_notes
        );

        // Update lead status
        if ($call_id) {
            $this->ai_calling_model->update_lead_from_webhook($call_id, [
                'ai_call_status'  => 'meeting_booked',
                'ai_call_summary' => 'Meeting booked — ' . ($meeting_notes ?? 'Client confirmed meeting.'),
            ]);
        }
    }

    public function webhook(): void
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (empty($data)) {
            http_response_code(400);
            die('Invalid payload');
        }

        // --- EARLY RETURN (PREVENT WEBHOOK TRAP) ---
        // Instantly respond to Vapi so the AI doesn't pause waiting for the database.
        $toolCallId = $data['message']['toolCalls'][0]['id'] ?? null;
        $response = $toolCallId 
            ? ["results" => [["toolCallId" => $toolCallId, "result" => "Success."]]]
            : ["status" => "received"];

        ob_start();
        echo json_encode($response);
        header('Connection: close');
        header('Content-Length: ' . ob_get_length());
        header('Content-Type: application/json');
        ob_end_flush();
        ob_flush();
        flush();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        // -------------------------------------------

        $this->_log_webhook($data);

        // Vapi sends different event shapes; handle both nested and flat layouts.
        $msg_type     = $data['message']['type']          ?? $data['type']          ?? null;
        $call         = $data['message']['call']          ?? $data['call']          ?? [];
        $call_id      = $call['id']                       ?? null;
        $transcript   = $data['message']['transcript']    ?? $data['transcript']    ?? '';
        $recording    = $data['message']['recordingUrl']  ?? $call['recordingUrl']  ?? null;
        $ended_reason = $data['message']['endedReason']   ?? $data['endedReason']   ?? null;

        // ── Tool call: notifyExpert ──────────────────────────────────────────────
        // Vapi fires this when AI calls the notifyExpert tool during a live call.
        // We send a WhatsApp notification to the owner and update the lead status.
        if ($msg_type === 'tool-calls') {
            $tool_calls = $data['message']['toolCalls'] ?? [];
            foreach ($tool_calls as $tool) {
                if (($tool['function']['name'] ?? '') === 'notifyExpert') {
                    $client_name   = $call['customer']['name']   ?? ($tool['function']['arguments']['clientName'] ?? 'Unknown');
                    $client_phone  = $call['customer']['number'] ?? ($tool['function']['arguments']['clientPhone'] ?? '');
                    $this->_send_whatsapp_expert_request($client_name, $client_phone);
                    if ($call_id) {
                        $this->ai_calling_model->update_lead_from_webhook($call_id, [
                            'ai_call_status'  => 'expert_requested',
                            'ai_call_summary' => 'Client requested expert — WhatsApp sent to owner.',
                        ]);
                    }
                    return; // early exit — no further processing needed for tool calls
                }
            }
        }

        // Exact endedReason values that mean the human was never reached.
        $failed_reasons = [
            'customer-did-not-answer',
            'no-answer',
            'busy',
            'voicemail',
            'failed',
            'error',
        ];

        // Detect any failure: exact match OR anything starting with "error-"
        // (e.g. "error-providerfault-outbound-sip-408-request-timeout").
        $is_failed = $ended_reason && (
            in_array($ended_reason, $failed_reasons, true)
            || strncmp($ended_reason, 'error-', 6) === 0
        );

        // SIP/infrastructure errors (never reached network) — refund the
        // followup_count slot since no real conversation attempt occurred.
        $is_sip_error = $ended_reason && strpos($ended_reason, 'sip') !== false;

        if ($call_id) {
            if ($is_failed) {
                // Call was dispatched but the human was never reached — mark as
                // failed so the lead is retried in the next calling session.
                $this->ai_calling_model->mark_lead_failed($call_id, $ended_reason, $is_sip_error);
            } else {
                $status = 'called'; // default when a call ends with no clear signal
                $lower  = strtolower($transcript);

                // ── Interested keywords (English + Bangla) ────────────────────
                $is_interested = (
                    (strpos($lower, 'interested') !== false && strpos($lower, 'not interested') === false)
                    || strpos($transcript, 'আগ্রহী') !== false
                    || strpos($transcript, 'আগ্রহ আছে') !== false
                    || strpos($transcript, 'জানতে চাই') !== false
                    || strpos($transcript, 'হ্যাঁ') !== false
                );

                // ── Not interested keywords (English + Bangla) ────────────────
                $is_not_interested = (
                    strpos($lower, 'not interested') !== false
                    || strpos($lower, 'no interest') !== false
                    || strpos($transcript, 'আগ্রহী না') !== false
                    || strpos($transcript, 'দরকার নেই') !== false
                    || strpos($transcript, 'লাগবে না') !== false
                    || strpos($transcript, 'না ধন্যবাদ') !== false
                );

                // ── Callback keywords (English + Bangla) ──────────────────────
                $is_callback = (
                    strpos($lower, 'callback') !== false
                    || strpos($lower, 'call back') !== false
                    || strpos($lower, 'call me later') !== false
                    || strpos($transcript, 'পরে কল') !== false
                    || strpos($transcript, 'আবার ফোন') !== false
                    || strpos($transcript, 'পরে যোগাযোগ') !== false
                );

                // ── Meeting booking keywords (English + Bangla) ───────────────
                $is_meeting_booked = (
                    strpos($lower, 'book a meeting') !== false
                    || strpos($lower, 'schedule a meeting') !== false
                    || strpos($lower, 'set up a meeting') !== false
                    || strpos($lower, 'book an appointment') !== false
                    || strpos($lower, 'schedule an appointment') !== false
                    || strpos($lower, 'meeting booked') !== false
                    || strpos($lower, 'appointment confirmed') !== false
                    || strpos($transcript, 'মিটিং বুক') !== false
                    || strpos($transcript, 'মিটিং নিশ্চিত') !== false
                    || strpos($transcript, 'অ্যাপয়েন্টমেন্ট') !== false
                    || strpos($transcript, 'মিটিং করব') !== false
                    || strpos($transcript, 'দেখা করব') !== false
                    || strpos($transcript, 'সাক্ষাৎ করব') !== false
                );

                // CRM lead status IDs (tblleads_status)
                // 2 = FOLLOWUP CLIENT  |  8 = CLOSE CLIENT
                $crm_status = null;

                // ── Expert request keywords (English + Bangla) ───────────────
                $is_expert_request = (
                    strpos($lower, 'expert') !== false
                    || strpos($lower, 'manager') !== false
                    || strpos($lower, 'human') !== false
                    || strpos($transcript, 'এক্সপার্ট') !== false
                    || strpos($transcript, 'ম্যানেজার') !== false
                    || strpos($transcript, 'কাউকে দিন') !== false
                    || strpos($transcript, 'মানুষের সাথে') !== false
                );

                if ($is_not_interested) {
                    // Not interested → permanently close the lead, stop all calls
                    $status     = 'not_interested';
                    $crm_status = 8; // CLOSE CLIENT
                } elseif ($is_meeting_booked) {
                    // Client confirmed a meeting during the call → store booking record
                    $status = 'meeting_booked';
                    $lead_row     = $call_id ? $this->db->query(
                        "SELECT id, phonenumber, name FROM tblleads WHERE vapi_call_id = ?", [$call_id]
                    )->row_array() : [];
                    $book_lead_id = (int) ($lead_row['id'] ?? 0);
                    $book_name    = $lead_row['name']        ?? ($call['customer']['name']   ?? 'Unknown');
                    $book_phone   = $lead_row['phonenumber'] ?? ($call['customer']['number'] ?? '');
                    $this->ai_calling_model->insert_meeting_booking(
                        $book_lead_id, $book_name, $book_phone, $call_id,
                        mb_substr($transcript, 0, 1000)
                    );
                } elseif ($is_expert_request) {
                    // Client requested human expert — notify owner via Telegram
                    $status = 'expert_requested';
                    // Look up real phone from DB (Vapi payload may have unresolved variables)
                    $lead_row     = $call_id ? $this->db->query(
                        "SELECT phonenumber, name FROM tblleads WHERE vapi_call_id = ?", [$call_id]
                    )->row_array() : [];
                    $notify_name  = $lead_row['name']        ?? ($call['customer']['name']   ?? 'Unknown');
                    $notify_phone = $lead_row['phonenumber']  ?? ($call['customer']['number'] ?? '');
                    $this->_send_whatsapp_expert_request($notify_name, $notify_phone);
                } elseif ($is_interested) {
                    // Interested → move to FOLLOWUP CLIENT queue, schedule next call
                    $status     = 'callback_scheduled';
                    $crm_status = 2; // FOLLOWUP CLIENT
                } elseif ($is_callback) {
                    $status = 'callback_scheduled';
                }

                $fields = [
                    'ai_call_status'     => $status,
                    'ai_call_summary'    => mb_substr($transcript, 0, 1000),
                    'call_recording_url' => $recording,
                ];

                // Schedule followup date when customer wants a callback or is interested
                if ($status === 'callback_scheduled') {
                    $fields['next_followup_date'] = date('Y-m-d', strtotime('+' . AI_FOLLOWUP_DAYS . ' days'));
                }

                // Update CRM lead status if outcome requires it
                if ($crm_status !== null) {
                    $fields['status'] = $crm_status;
                }

                $this->ai_calling_model->update_lead_from_webhook($call_id, $fields);
            }
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'received']);
    }

    /**
     * Idempotent migration that adds any missing AI Calling columns to tblleads.
     *
     * Safe to run multiple times — existing columns are detected via
     * information_schema and skipped. Use this if install.php failed silently
     * or if the module is being upgraded on an existing installation.
     *
     * @route  GET admin/ai_calling/migrate
     * @return void  Outputs JSON: { success, added[], skipped[], message }
     */
    public function migrate(): void
    {
        header('Content-Type: application/json');

        if (!staff_can('view', AI_CALLING_MODULE_NAME)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            return;
        }

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

        $added   = [];
        $skipped = [];

        foreach ($columns as $col => $definition) {
            $exists = $this->db->query(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'tblleads'
                   AND COLUMN_NAME  = ?",
                [$col]
            )->row()->cnt;

            if (!$exists) {
                $this->db->query("ALTER TABLE tblleads ADD COLUMN `{$col}` {$definition}");
                $added[] = $col;
            } else {
                $skipped[] = $col;
            }
        }

        // Also create the meetings table if it doesn't exist
        $meetings_table = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblai_meeting_bookings'"
        )->row()->cnt;

        if (!$meetings_table) {
            $this->db->query("
                CREATE TABLE `tblai_meeting_bookings` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $added[] = 'tblai_meeting_bookings (table)';
        } else {
            $skipped[] = 'tblai_meeting_bookings (table)';
        }

        echo json_encode([
            'success' => true,
            'added'   => $added,
            'skipped' => $skipped,
            'message' => count($added) > 0
                ? 'Migration complete. Added: ' . implode(', ', $added)
                : 'All columns already exist. No changes needed.',
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Triggers a test call to a specific phone number using the active provider.
     *
     * Unlike test_api() which picks the first pending lead, this endpoint
     * calls an exact number you provide — useful for verifying a new provider
     * (e.g. Twilio) before running it against real leads.
     *
     * @route  POST admin/ai_calling/test_call_number
     * @return void  Outputs JSON with call result and Vapi call ID.
     */
    public function test_call_number(): void
    {
        header('Content-Type: application/json');

        if (!staff_can('view', AI_CALLING_MODULE_NAME)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            return;
        }

        $raw_phone = trim($this->input->post('phone'));
        if (empty($raw_phone)) {
            echo json_encode(['success' => false, 'message' => 'Phone number is required.']);
            return;
        }

        $provider = get_option('ai_calling_provider') ?: 'amarip';
        $phone_id = ($provider === 'twilio') ? AI_VAPI_TWILIO_PHONE_ID : AI_VAPI_PHONE_ID;
        $phone    = $this->_format_phone($raw_phone);

        $payload = [
            'phoneNumberId' => $phone_id,
            'assistantId'   => AI_VAPI_ASSISTANT_ID,
            'customer'      => [
                'number'                 => $phone,
                'numberE164CheckEnabled' => false,
            ],
            'assistantOverrides' => [
                'variableValues' => [
                    'leadName'  => 'Test Call',
                    'leadId'    => '0',
                    'callTime'  => date('Y-m-d H:i:s'),
                    'leadPhone' => $phone,
                    'context'   => 'এটি একটি টেস্ট কল — calling provider যাচাই করা হচ্ছে।',
                ],
            ],
        ];

        $ch = curl_init(AI_VAPI_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . AI_VAPI_API_KEY,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            echo json_encode(['success' => false, 'message' => 'cURL error: ' . $curl_err]);
            return;
        }

        $body = json_decode($response, true);

        if ($http_code >= 200 && $http_code < 300 && !empty($body['id'])) {
            echo json_encode([
                'success'   => true,
                'call_id'   => $body['id'],
                'provider'  => $provider,
                'phone'     => $phone,
                'message'   => 'Call dispatched via ' . ($provider === 'twilio' ? 'Twilio' : 'Amarip') . '. Check your phone.',
            ]);
        } else {
            $err = $body['message'] ?? $body['error'] ?? ("HTTP {$http_code}: {$response}");
            if (is_array($err)) {
                $err = implode('; ', $err);
            }
            echo json_encode([
                'success'  => false,
                'provider' => $provider,
                'phone'    => $phone,
                'message'  => (string) $err,
                'raw'      => $body,
            ]);
        }
    }

    /**
     * Switches the active calling provider between Amarip and Twilio.
     *
     * Saves the choice to Perfex CRM's options table so it persists across
     * sessions. The dashboard reads this value to show the active provider
     * and the calling session uses it to pick the correct Vapi phoneNumberId.
     *
     * @route  POST admin/ai_calling/switch_provider
     * @return void  Outputs JSON: { success, provider, label }
     */
    public function switch_provider(): void
    {
        header('Content-Type: application/json');

        if (!staff_can('view', AI_CALLING_MODULE_NAME)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            return;
        }

        $requested = $this->input->post('provider');

        if (!in_array($requested, ['amarip', 'twilio'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid provider. Use "amarip" or "twilio".']);
            return;
        }

        // Validate that the Twilio phone ID is configured before switching to it
        if ($requested === 'twilio' && empty(AI_VAPI_TWILIO_PHONE_ID)) {
            echo json_encode([
                'success' => false,
                'message' => 'Twilio Phone ID is not configured in config/vapi.php. '
                           . 'Add your Vapi Twilio Phone Number ID as AI_VAPI_TWILIO_PHONE_ID.',
            ]);
            return;
        }

        update_option('ai_calling_provider', $requested);

        $labels = ['amarip' => 'Amarip SIP Trunk', 'twilio' => 'Twilio'];
        echo json_encode([
            'success'  => true,
            'provider' => $requested,
            'label'    => $labels[$requested],
        ]);
    }

    /**
     * Saves calling behaviour settings from the dashboard form.
     *
     * @route  POST admin/ai_calling/save_settings
     * @return void  Outputs JSON: { success, message }
     */
    public function save_settings(): void
    {
        header('Content-Type: application/json');

        if (!staff_can('view', AI_CALLING_MODULE_NAME)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            return;
        }

        $hour_start    = (int) $this->input->post('hour_start');
        $hour_end      = (int) $this->input->post('hour_end');
        $max_per_run   = (int) $this->input->post('max_per_run');
        $delay_sec     = (int) $this->input->post('delay_sec');
        $followup_days = (int) $this->input->post('followup_days');
        $max_followups = (int) $this->input->post('max_followups');

        if ($hour_start < 0 || $hour_start > 23 || $hour_end < 1 || $hour_end > 24 || $hour_start >= $hour_end) {
            echo json_encode(['success' => false, 'message' => 'Hour start must be before hour end (0–23 / 1–24).']);
            return;
        }

        update_option('ai_calling_hour_start',   $hour_start);
        update_option('ai_calling_hour_end',     $hour_end);
        update_option('ai_calling_max_per_run',  max(1, $max_per_run));
        update_option('ai_calling_delay_sec',    max(0, $delay_sec));
        update_option('ai_calling_followup_days', max(1, $followup_days));
        update_option('ai_calling_max_followups', max(1, $max_followups));

        echo json_encode(['success' => true, 'message' => 'Settings saved.']);
    }

    /**
     * Resets all "called" leads from today that have no transcript back to
     * "pending" so they can be retried immediately.
     *
     * Use this once after a batch of SIP failures to unblock the queue without
     * manually editing the database. Safe to run multiple times.
     *
     * @route  GET admin/ai_calling/reset_stuck_calls
     * @return void  Outputs JSON: { success, reset_count }
     */
    public function reset_stuck_calls(): void
    {
        header('Content-Type: application/json');

        if (!staff_can('view', AI_CALLING_MODULE_NAME)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            return;
        }

        // Reset leads marked "called" today that have no summary —
        // these are SIP failures where the webhook never set a real outcome.
        // Mark as 'failed' (not pending) so the dashboard counter is accurate
        // and the ordering query puts them at the front of the next session.
        // Decrement followup_count so the failed attempt is not penalised.
        $this->db->query("
            UPDATE tblleads
            SET    ai_call_status     = 'failed',
                   ai_call_summary    = 'SIP connection failed (auto-reset)',
                   next_followup_date = NULL,
                   followup_count     = GREATEST(0, followup_count - 1)
            WHERE  ai_call_status    = 'called'
              AND  DATE(last_ai_call) = CURDATE()
              AND  (ai_call_summary IS NULL OR ai_call_summary = '')
        ");

        $affected = $this->db->affected_rows();

        echo json_encode([
            'success'     => true,
            'reset_count' => $affected,
            'message'     => "{$affected} stuck leads reset to pending.",
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Debug helper — initiates a live Vapi API call against the first callable lead.
     *
     * Returns the full request payload, HTTP response code, cURL timing, and
     * Vapi's raw response body. Useful for verifying credentials and phone number
     * formatting before enabling the scheduled cron job.
     *
     * WARNING: This triggers a real phone call. Use with caution in production.
     *
     * @route  GET admin/ai_calling/test_api
     * @return void  Outputs pretty-printed JSON with call diagnostics.
     */
    public function test_api(): void
    {
        header('Content-Type: application/json');

        if (!staff_can('view', AI_CALLING_MODULE_NAME)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            return;
        }

        $leads = $this->ai_calling_model->get_leads_to_call(1);

        if (empty($leads)) {
            echo json_encode(['success' => false, 'message' => 'No pending leads found to test with.']);
            return;
        }

        $lead  = $leads[0];
        $phone = $this->_format_phone($lead['phonenumber']);

        $payload = [
            'phoneNumberId' => AI_VAPI_PHONE_ID,
            'assistantId'   => AI_VAPI_ASSISTANT_ID,
            'customer'      => [
                'number'                 => $phone,
                'numberE164CheckEnabled' => false,
            ],
            'assistantOverrides' => [
                'variableValues' => [
                    'leadName' => $lead['name'],
                    'leadId'   => (string) $lead['id'],
                    'callTime' => date('Y-m-d H:i:s'),
                    'context'  => $lead['ai_context_notes'] ?? '',
                ],
            ],
        ];

        $ch = curl_init(AI_VAPI_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . AI_VAPI_API_KEY,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        $curl_info = curl_getinfo($ch);
        curl_close($ch);

        echo json_encode([
            'lead_id'         => $lead['id'],
            'lead_name'       => $lead['name'],
            'phone_raw'       => $lead['phonenumber'],
            'phone_formatted' => $phone,
            'payload_sent'    => $payload,
            'http_code'       => $http_code,
            'curl_error'      => $curl_err ?: null,
            'curl_connect_ms' => round($curl_info['connect_time'] * 1000),
            'response_body'   => json_decode($response, true) ?? $response,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sends a SIP OPTIONS ping to Amarip's SIP server from THIS web server.
     *
     * This lets you verify whether Amarip's SIP server (103.170.231.10:5060)
     * responds to SIP traffic from an EXTERNAL IP (your Hostinger server).
     *
     * Result interpretation:
     *  - Got response (200 OK / 401 / 403 / 405) → Amarip accepts external SIP;
     *    the 408 error is specific to Vapi's IPs being blocked upstream.
     *  - No response / timeout → Amarip only accepts from registered/whitelisted
     *    IPs, which means Vapi's IPs also need to be registered/whitelisted.
     *
     * @route  GET admin/ai_calling/test_sip
     * @return void  Outputs JSON with SIP response or timeout info.
     */
    public function test_sip(): void
    {
        header('Content-Type: application/json');

        if (!staff_can('view', AI_CALLING_MODULE_NAME)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            return;
        }

        $sip_host = '103.170.231.10';
        $sip_port = 5060;
        $timeout  = 5; // seconds to wait for a response

        // Get this server's outbound IP (what Amarip will see as the source)
        $my_ip = gethostbyname(gethostname());
        // Fallback: try to detect real outbound IP via an external lookup
        $ext_ip_raw = @file_get_contents('https://api.ipify.org');
        $ext_ip = ($ext_ip_raw && filter_var(trim($ext_ip_raw), FILTER_VALIDATE_IP))
            ? trim($ext_ip_raw)
            : $my_ip;

        $branch  = 'z9hG4bK' . bin2hex(random_bytes(8));
        $call_id = bin2hex(random_bytes(12)) . '@' . $ext_ip;
        $tag     = bin2hex(random_bytes(6));

        // Minimal SIP OPTIONS request — standard RFC 3261 probe packet
        $packet  = "OPTIONS sip:{$sip_host} SIP/2.0\r\n"
                 . "Via: SIP/2.0/UDP {$ext_ip}:5060;branch={$branch}\r\n"
                 . "Max-Forwards: 70\r\n"
                 . "From: <sip:probe@{$ext_ip}>;tag={$tag}\r\n"
                 . "To: <sip:{$sip_host}>\r\n"
                 . "Call-ID: {$call_id}\r\n"
                 . "CSeq: 1 OPTIONS\r\n"
                 . "Contact: <sip:probe@{$ext_ip}:5060>\r\n"
                 . "Content-Length: 0\r\n\r\n";

        $result = [
            'test'          => 'SIP OPTIONS ping',
            'target'        => "{$sip_host}:{$sip_port}",
            'source_ip'     => $ext_ip,
            'timeout_sec'   => $timeout,
            'packet_bytes'  => strlen($packet),
        ];

        $result['tests'] = [];

        // ── Test 1: TCP port reachability (fsockopen — allowed on shared hosting) ──
        // SIP can run over TCP too. This confirms basic network reachability.
        $tcp_start = microtime(true);
        $tcp_fp    = @fsockopen('tcp://' . $sip_host, $sip_port, $tcp_errno, $tcp_errstr, $timeout);
        $tcp_ms    = round((microtime(true) - $tcp_start) * 1000);

        if ($tcp_fp) {
            fclose($tcp_fp);
            $result['tests']['tcp_5060'] = [
                'status'      => 'OPEN',
                'connect_ms'  => $tcp_ms,
                'conclusion'  => 'TCP port 5060 is reachable from this server. Network path exists.',
            ];
        } else {
            $result['tests']['tcp_5060'] = [
                'status'      => 'FAILED',
                'connect_ms'  => $tcp_ms,
                'errno'       => $tcp_errno,
                'error'       => $tcp_errstr,
                'conclusion'  => 'TCP port 5060 is NOT reachable. Could be firewall or TCP not enabled on Amarip.',
            ];
        }

        // ── Test 2: UDP via fsockopen (lighter than raw socket, sometimes works) ──
        $udp_start = microtime(true);
        $udp_fp    = @fsockopen('udp://' . $sip_host, $sip_port, $udp_errno, $udp_errstr, $timeout);
        $udp_ms    = round((microtime(true) - $udp_start) * 1000);

        if ($udp_fp) {
            stream_set_timeout($udp_fp, $timeout);
            @fwrite($udp_fp, $packet);
            $udp_response = @fread($udp_fp, 65535);
            fclose($udp_fp);

            if ($udp_response) {
                $first_line = strtok($udp_response, "\r\n");
                $result['tests']['udp_5060'] = [
                    'status'       => 'RESPONDED',
                    'connect_ms'   => $udp_ms,
                    'sip_status'   => $first_line,
                    'raw_response' => substr($udp_response, 0, 400),
                    'conclusion'   => 'Amarip SIP responded via UDP from this server IP (' . $ext_ip . '). '
                                   . 'The 408 error is specific to Vapi IPs. Whitelist 44.229.228.186 and 44.238.177.138 on Amarip.',
                ];
            } else {
                $result['tests']['udp_5060'] = [
                    'status'      => 'NO RESPONSE',
                    'connect_ms'  => $udp_ms,
                    'conclusion'  => 'UDP socket opened but Amarip returned no response within ' . $timeout . 's.',
                ];
            }
        } else {
            $result['tests']['udp_5060'] = [
                'status'  => 'BLOCKED',
                'errno'   => $udp_errno,
                'error'   => $udp_errstr,
                'conclusion' => 'Hosting blocks outbound UDP entirely. Use the online tool below.',
            ];
        }

        // ── Test 3: ICMP-style — see if host is alive via DNS reverse ──
        $ptr = @gethostbyaddr($sip_host);
        $result['tests']['reverse_dns'] = [
            'ip'      => $sip_host,
            'ptr'     => ($ptr !== $sip_host) ? $ptr : '(no PTR record)',
        ];

        // ── Overall conclusion ──
        $tcp_open = ($result['tests']['tcp_5060']['status'] === 'OPEN');
        $udp_resp = isset($result['tests']['udp_5060']['status']) &&
                    $result['tests']['udp_5060']['status'] === 'RESPONDED';

        if ($udp_resp) {
            $result['conclusion'] = 'Amarip SIP is REACHABLE and RESPONDS from external IPs. '
                                  . 'Root cause: Vapi IPs (44.229.228.186, 44.238.177.138) are not whitelisted.';
        } elseif ($tcp_open) {
            $result['conclusion'] = 'Amarip server is REACHABLE on port 5060 (TCP), but UDP gave no response. '
                                  . 'SIP typically uses UDP. Amarip may require IP registration/whitelist for UDP.';
        } else {
            $result['conclusion'] = 'Amarip port 5060 is NOT reachable from this Hostinger server at all. '
                                  . 'This could be a Hostinger outbound firewall, or Amarip restricts SIP to known IPs only.';
        }

        // ── Online test fallback ──
        $result['online_sip_test'] = 'Visit this URL from your browser to run a SIP OPTIONS probe from a neutral server: '
                                   . 'https://sip.school/tools/sip-options/ — enter host: ' . $sip_host . '  port: ' . $sip_port;

        echo json_encode($result, JSON_PRETTY_PRINT);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Fetches callable leads and dispatches a Vapi call for each one.
     *
     * This is the core session logic shared by both start_calling() (manual)
     * and cron() (automated). It:
     *  1. Removes the PHP time limit to prevent timeout on large batches.
     *  2. Queries up to AI_MAX_CALLS_PER_RUN leads from the model.
     *  3. Calls each lead via _call_lead() and marks successes in the DB.
     *  4. Sleeps AI_CALL_DELAY_SEC between calls to avoid Vapi rate limits.
     *  5. Writes a summary entry to the daily session log file.
     *
     * @return array {
     *     bool   $success  Always true (individual failures are counted, not thrown).
     *     int    $total    Number of leads fetched.
     *     int    $called   Number of calls successfully dispatched.
     *     int    $failed   Number of calls that returned an error.
     *     string $message  Optional human-readable note (e.g. "No pending leads").
     *     array  $log      Per-lead result lines: "OK  | Name | Phone | call_id"
     *                                         or  "ERR | Name | Phone | error".
     * }
     */
    private function _run_calling_session(): array
    {
        @set_time_limit(0); // prevent PHP timeout on shared hosting

        $stats = [
            'success' => true,
            'total'   => 0,
            'called'  => 0,
            'failed'  => 0,
            'log'     => [],
        ];

        $max_per_run    = (int)(get_option('ai_calling_max_per_run') ?: AI_MAX_CALLS_PER_RUN);
        $delay_sec      = (int)(get_option('ai_calling_delay_sec')  ?: AI_CALL_DELAY_SEC);
        $leads          = $this->ai_calling_model->get_leads_to_call($max_per_run);
        $stats['total'] = count($leads);

        if (empty($leads)) {
            $stats['message'] = 'No pending leads to call right now.';
            return $stats;
        }

        foreach ($leads as $lead) {
            $result = $this->_call_lead($lead);

            if ($result['success']) {
                $this->ai_calling_model->mark_lead_called($lead['id'], $result['call_id']);
                $stats['called']++;
                $stats['log'][] = "OK  | {$lead['name']} | {$lead['phonenumber']} | {$result['call_id']}";
            } elseif (!empty($result['concurrency_blocked'])) {
                // Vapi has hit its concurrent call cap — abort the session now.
                // Remaining leads stay pending and will be retried next run.
                $stats['log'][]     = "STOP| Concurrency limit reached — remaining leads will retry next run";
                $stats['message']   = $result['error'];
                break;
            } else {
                $stats['failed']++;
                $stats['log'][] = "ERR | {$lead['name']} | {$lead['phonenumber']} | {$result['error']}";
            }

            sleep($delay_sec);
        }

        $this->_log_session($stats);
        return $stats;
    }

    /**
     * Sends a single outbound call request to the Vapi API.
     *
     * Builds the JSON payload with the lead's E.164 phone number and variable
     * values that the Vapi assistant can reference during the conversation
     * (leadName, leadId, callTime, context).
     *
     * The call is considered successful when Vapi returns HTTP 2xx AND the
     * response body contains an `id` field (the Vapi call UUID). Any cURL
     * error, non-2xx status, or missing `id` is treated as failure.
     *
     * @param  array $lead {
     *     int    $id               Lead's primary key in tblleads.
     *     string $name             Lead's full name passed to the assistant.
     *     string $phonenumber      Raw phone number (formatted by _format_phone).
     *     string $ai_context_notes Optional context injected into the assistant prompt.
     * }
     * @return array {
     *     bool   $success  true on success, false on any error.
     *     string $call_id  Vapi call UUID (present only on success).
     *     string $error    Human-readable error message (present only on failure).
     * }
     */
    private function _call_lead(array $lead): array
    {
        $phone    = $this->_format_phone($lead['phonenumber']);
        $provider = get_option('ai_calling_provider') ?: 'amarip';
        $phone_id = ($provider === 'twilio') ? AI_VAPI_TWILIO_PHONE_ID : AI_VAPI_PHONE_ID;

        $payload = [
            'phoneNumberId' => $phone_id,
            'assistantId'   => AI_VAPI_ASSISTANT_ID,
            'customer'      => [
                'number'                 => $phone,
                'numberE164CheckEnabled' => false,
            ],
            'assistantOverrides' => [
                // Speak immediately on pickup — no silence waiting for user to go first
                'firstMessageMode'  => 'assistant-speaks-first',
                // Skip voicemail detection (removes the ~4-5 s silent listen window)
                'voicemailDetection' => ['enabled' => false],
                'variableValues' => [
                    'leadName'  => $lead['name'],
                    'leadId'    => (string) $lead['id'],
                    'callTime'  => date('Y-m-d H:i:s'),
                    'context'   => $lead['ai_context_notes'] ?? '',
                    'leadPhone' => $phone,
                ],
            ],
        ];

        // Log the outbound request for debugging SIP issues
        $lead['_provider'] = $provider;
        $lead['_phone_id'] = $phone_id;
        $this->_log_call_attempt($lead, $phone, $payload);

        $ch = curl_init(AI_VAPI_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . AI_VAPI_API_KEY,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        $curl_info = curl_getinfo($ch);
        curl_close($ch);

        // Log full response details for debugging
        $this->_log_call_response($lead, $http_code, $curl_err, $curl_info, $response);

        if ($curl_err) {
            return ['success' => false, 'error' => 'cURL: ' . $curl_err];
        }

        $body = json_decode($response, true);

        if ($http_code >= 200 && $http_code < 300 && !empty($body['id'])) {
            return ['success' => true, 'call_id' => $body['id']];
        }

        // Detect Vapi concurrency limit — stop the session immediately so we
        // don't hammer the API with calls that will all be rejected.
        if (!empty($body['subscriptionLimits']['concurrencyBlocked'])) {
            return [
                'success'             => false,
                'concurrency_blocked' => true,
                'error'               => 'Over Concurrency Limit (max ' . ($body['subscriptionLimits']['concurrencyLimit'] ?? '?') . ' simultaneous calls)',
            ];
        }

        $err = $body['message'] ?? $body['error'] ?? ("HTTP {$http_code}: {$response}");
        if (is_array($err)) {
            $err = implode('; ', $err);
        }
        return ['success' => false, 'error' => (string) $err];
    }

    /**
     * Normalises a raw phone number to E.164 format for the Vapi API.
     *
     * Handles the four common Bangladesh number formats stored in Perfex CRM:
     *
     *  01XXXXXXXXX  (11 digits, leading 0)   →  +8801XXXXXXXXX
     *  1XXXXXXXXX   (10 digits, no leading 0) →  +8801XXXXXXXXX
     *  880XXXXXXXXXX (country code, no +)     →  +880XXXXXXXXXX
     *  Anything else                          →  + prepended as-is
     *
     * Non-digit characters (spaces, dashes, parentheses) are stripped before
     * any matching. The leading `+` is preserved if already present.
     *
     * @param  string $raw  Phone number as stored in tblleads.phonenumber.
     * @return string       E.164-formatted phone number (e.g. "+8801XXXXXXXXX").
     */
    private function _format_phone(string $raw): string
    {
        $phone = preg_replace('/[^0-9]/', '', $raw);

        if (strlen($phone) === 11 && $phone[0] === '0') {
            // 01XXXXXXXXX → +8801XXXXXXXXX
            return '+880' . substr($phone, 1);
        }

        if (strlen($phone) === 10 && $phone[0] !== '0') {
            // 1XXXXXXXXX → +8801XXXXXXXXX (no leading zero stored)
            return '+880' . $phone;
        }

        if (substr($phone, 0, 3) === '880') {
            // 880XXXXXXXXXX → +880XXXXXXXXXX
            return '+' . $phone;
        }

        // Fallback: prepend + and hope for the best
        return '+' . $phone;
    }

    /**
     * Sends a Telegram notification to the owner when a client requests an expert.
     *
     * Setup: message @BotFather → /newbot → save token as AI_TELEGRAM_BOT_TOKEN.
     * Owner opens the bot and sends /start, then visit:
     * https://api.telegram.org/bot<TOKEN>/getUpdates to get the chat id.
     * Save chat id as AI_TELEGRAM_CHAT_ID in config/vapi.php.
     *
     * @param  string $client_name   Client's name from the call.
     * @param  string $client_phone  Client's phone number (E.164).
     * @return void
     */
    private function _send_whatsapp_expert_request(string $client_name, string $client_phone): void
    {
        if (empty(AI_TELEGRAM_BOT_TOKEN) || AI_TELEGRAM_BOT_TOKEN === 'YOUR_BOT_TOKEN') {
            return; // not configured yet
        }

        $time = date('d M, h:i A');
        $text = "🔔 *নতুন Expert Request!*\n\n"
              . "👤 Client: {$client_name}\n"
              . "📞 Number: {$client_phone}\n"
              . "🕐 Time: {$time}\n\n"
              . "Client এখনই কথা বলতে চাইছেন।\nPlease call করুন।";

        $url = 'https://api.telegram.org/bot' . AI_TELEGRAM_BOT_TOKEN . '/sendMessage';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'chat_id'    => AI_TELEGRAM_CHAT_ID,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function _log_session(array $stats): void
    {
        $log_dir = dirname(__FILE__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }

        $file  = $log_dir . 'session_' . date('Y-m-d') . '.log';
        $lines = [
            '',
            str_repeat('-', 60),
            '[' . date('Y-m-d H:i:s') . '] SESSION',
            "  Total={$stats['total']}  Called={$stats['called']}  Failed={$stats['failed']}",
        ];
        foreach ($stats['log'] as $entry) {
            $lines[] = '  ' . $entry;
        }

        file_put_contents($file, implode("\n", $lines) . "\n", FILE_APPEND);
    }

    /**
     * Appends a raw webhook payload to the daily webhook log file.
     *
     * Log files are written to  module/ai_calling/logs/webhook_YYYY-MM-DD.log
     * one JSON blob per line (JSON Lines format), prefixed with a timestamp.
     * Useful for debugging unexpected Vapi payload shapes or missed updates.
     *
     * @param  array $data  Decoded webhook payload from Vapi.
     * @return void
     */
    private function _log_webhook(array $data): void
    {
        $log_dir = dirname(__FILE__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }

        $file = $log_dir . 'webhook_' . date('Y-m-d') . '.log';
        file_put_contents(
            $file,
            '[' . date('Y-m-d H:i:s') . '] ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );

        // Additionally log errors to a dedicated error log for easy debugging
        $ended_reason = $data['message']['endedReason'] ?? $data['endedReason'] ?? null;
        $call         = $data['message']['call']        ?? $data['call']        ?? [];
        $call_id      = $call['id']                     ?? 'unknown';
        $call_status  = $call['status']                 ?? 'unknown';
        $phone        = $call['customer']['number']     ?? 'unknown';

        if ($ended_reason && (strncmp($ended_reason, 'error-', 6) === 0 || in_array($ended_reason, ['failed', 'busy', 'no-answer']))) {
            $error_file = $log_dir . 'errors_' . date('Y-m-d') . '.log';
            $error_data = [
                'time'          => date('Y-m-d H:i:s'),
                'call_id'       => $call_id,
                'phone'         => $phone,
                'call_status'   => $call_status,
                'ended_reason'  => $ended_reason,
                'sip_status'    => $call['telephony']['statusCode']       ?? null,
                'sip_reason'    => $call['telephony']['statusMessage']    ?? null,
                'provider'      => $call['phoneNumber']['provider']       ?? null,
                'trunk_id'      => $call['phoneNumber']['sipTrunkId']     ?? null,
                'duration_sec'  => $call['duration']                      ?? null,
                'cost'          => $call['cost']                          ?? null,
                'started_at'    => $call['startedAt']                     ?? null,
                'ended_at'      => $call['endedAt']                      ?? null,
            ];
            file_put_contents(
                $error_file,
                json_encode($error_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n---\n",
                FILE_APPEND
            );
        }
    }

    /**
     * Logs an outbound call attempt (request side) for debugging.
     *
     * @param  array  $lead     Lead data.
     * @param  string $phone    Formatted E.164 phone number.
     * @param  array  $payload  The JSON payload sent to Vapi.
     * @return void
     */
    private function _log_call_attempt(array $lead, string $phone, array $payload): void
    {
        $log_dir = dirname(__FILE__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }

        $file = $log_dir . 'call_debug_' . date('Y-m-d') . '.log';
        $entry = [
            'time'       => date('Y-m-d H:i:s'),
            'action'     => 'OUTBOUND_REQUEST',
            'provider'   => $lead['_provider'] ?? 'amarip',
            'lead_id'    => $lead['id'],
            'lead_name'  => $lead['name'],
            'phone_raw'  => $lead['phonenumber'],
            'phone_e164' => $phone,
            'api_url'    => AI_VAPI_API_URL,
            'phone_id'   => $lead['_phone_id'] ?? AI_VAPI_PHONE_ID,
            'assistant'  => AI_VAPI_ASSISTANT_ID,
        ];
        file_put_contents(
            $file,
            '[REQUEST] ' . json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );
    }

    /**
     * Logs the full Vapi API response for debugging SIP/connection issues.
     *
     * @param  array  $lead       Lead data.
     * @param  int    $http_code  HTTP status code from Vapi.
     * @param  string $curl_err   cURL error string (empty on success).
     * @param  array  $curl_info  Full cURL info array (timing, IPs, etc).
     * @param  string $response   Raw response body from Vapi.
     * @return void
     */
    private function _log_call_response(array $lead, int $http_code, string $curl_err, array $curl_info, string $response): void
    {
        $log_dir = dirname(__FILE__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }

        $file = $log_dir . 'call_debug_' . date('Y-m-d') . '.log';
        $entry = [
            'time'            => date('Y-m-d H:i:s'),
            'action'          => 'OUTBOUND_RESPONSE',
            'lead_id'         => $lead['id'],
            'http_code'       => $http_code,
            'curl_error'      => $curl_err ?: null,
            'connect_time_ms' => round(($curl_info['connect_time'] ?? 0) * 1000),
            'total_time_ms'   => round(($curl_info['total_time'] ?? 0) * 1000),
            'primary_ip'      => $curl_info['primary_ip'] ?? null,
            'response_body'   => json_decode($response, true) ?? $response,
        ];
        file_put_contents(
            $file,
            '[RESPONSE] ' . json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );
    }
}
