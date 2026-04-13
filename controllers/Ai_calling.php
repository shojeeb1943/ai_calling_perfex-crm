<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Ai_calling extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('ai_calling/ai_calling_model');
    }

    // ─── Dashboard ────────────────────────────────────────────────────────────
    // URL: admin/ai_calling
    public function index()
    {
        if (!staff_can('view', AI_CALLING_MODULE_NAME)) {
            access_denied(AI_CALLING_MODULE_NAME);
        }

        $data['stats']        = $this->ai_calling_model->get_stats();
        $data['recent_calls'] = $this->ai_calling_model->get_recent_calls(20);
        $data['title']        = _l('ai_calling_dashboard');

        // Views in modules must be loaded as 'module_name/view_name'
        $this->load->view('ai_calling/manage', $data);
    }

    // ─── Manual trigger from dashboard button (AJAX) ──────────────────────────
    // URL: admin/ai_calling/start_calling  (POST)
    public function start_calling()
    {
        header('Content-Type: application/json');

        if (!staff_can('view', AI_CALLING_MODULE_NAME)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            return;
        }

        $result = $this->_run_calling_session();
        echo json_encode($result);
    }

    // ─── Cron endpoint (called by Hostinger cron job) ─────────────────────────
    // URL: admin/ai_calling/cron/VapiCron2024Secure
    public function cron($token = '')
    {
        if ($token !== AI_CRON_TOKEN) {
            http_response_code(403);
            die('Forbidden');
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

    // ─── Vapi webhook receiver ────────────────────────────────────────────────
    // URL: admin/ai_calling/webhook  (POST from Vapi dashboard settings)
    public function webhook()
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (empty($data)) {
            http_response_code(400);
            die('Invalid payload');
        }

        // Log every webhook for debugging
        $this->_log_webhook($data);

        // Vapi sends different event shapes; handle both
        $type        = $data['message']['type']           ?? $data['type']           ?? null;
        $call        = $data['message']['call']           ?? $data['call']           ?? [];
        $call_id     = $call['id']                        ?? null;
        $transcript  = $data['message']['transcript']     ?? $data['transcript']     ?? '';
        $recording   = $data['message']['recordingUrl']   ?? $call['recordingUrl']   ?? null;

        if ($call_id) {
            // Detect outcome from transcript keywords
            $status = 'called'; // default after call ends
            $lower  = strtolower($transcript);
            if (strpos($lower, 'interested') !== false && strpos($lower, 'not interested') === false) {
                $status = 'interested';
            } elseif (strpos($lower, 'not interested') !== false || strpos($lower, 'no interest') !== false) {
                $status = 'not_interested';
            } elseif (strpos($lower, 'callback') !== false || strpos($lower, 'call back') !== false || strpos($lower, 'call me later') !== false) {
                $status = 'callback_scheduled';
            }

            $this->ai_calling_model->update_lead_from_webhook($call_id, [
                'ai_call_status'    => $status,
                'ai_call_summary'   => mb_substr($transcript, 0, 1000),
                'call_recording_url'=> $recording,
            ]);
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'received']);
    }

    // ─── Core calling session ─────────────────────────────────────────────────
    private function _run_calling_session()
    {
        $stats = [
            'success' => true,
            'total'   => 0,
            'called'  => 0,
            'failed'  => 0,
            'log'     => [],
        ];

        $leads         = $this->ai_calling_model->get_leads_to_call(AI_MAX_CALLS_PER_RUN);
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
            } else {
                $stats['failed']++;
                $stats['log'][] = "ERR | {$lead['name']} | {$lead['phonenumber']} | {$result['error']}";
            }

            sleep(AI_CALL_DELAY_SEC);
        }

        $this->_log_session($stats);
        return $stats;
    }

    // ─── Single lead call via Vapi ────────────────────────────────────────────
    private function _call_lead($lead)
    {
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
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            return ['success' => false, 'error' => 'cURL: ' . $curl_err];
        }

        $body = json_decode($response, true);

        if ($http_code >= 200 && $http_code < 300 && !empty($body['id'])) {
            return ['success' => true, 'call_id' => $body['id']];
        }

        $err = $body['message'] ?? $body['error'] ?? ("HTTP " . $http_code . ": " . $response);
        return ['success' => false, 'error' => $err];
    }

    // ─── Phone number formatter (Bangladesh) ──────────────────────────────────
    private function _format_phone($raw)
    {
        // Strip everything except digits and leading +
        $phone = preg_replace('/[^0-9]/', '', $raw);

        if (strlen($phone) === 11 && substr($phone, 0, 1) === '0') {
            // 01XXXXXXXXX → +8801XXXXXXXXX
            $phone = '+880' . substr($phone, 1);
        } elseif (strlen($phone) === 10 && substr($phone, 0, 1) !== '0') {
            // 1XXXXXXXXX → +8801XXXXXXXXX (already without leading 0)
            $phone = '+880' . $phone;
        } elseif (substr($phone, 0, 3) === '880') {
            // 880XXXXXXXXXX → +880XXXXXXXXXX
            $phone = '+' . $phone;
        } else {
            // Fallback: prepend + if not present
            $phone = '+' . $phone;
        }

        return $phone;
    }

    // ─── File logger ──────────────────────────────────────────────────────────
    private function _log_session($stats)
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

    private function _log_webhook($data)
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
    }
}
