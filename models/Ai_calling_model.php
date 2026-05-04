<?php
/**
 * @file        models/Ai_calling_model.php
 * @package     Perfex CRM — AI Calling Module
 *
 * Data-access layer for the AI Calling module. All SQL queries are isolated
 * here; the controller contains no raw database calls.
 *
 * All writes target the `tblleads` table extended with eight extra columns
 * added by install.php on module activation:
 *
 *  ai_call_status      VARCHAR(30)   pending | called | interested |
 *                                    not_interested | callback_scheduled
 *  vapi_call_id        VARCHAR(100)  Vapi's UUID for the call (set on dispatch)
 *  last_ai_call        DATETIME      Timestamp of the most recent call attempt
 *  next_followup_date  DATE          Date on or after which a callback is due
 *  followup_count      INT           Total call attempts made (capped at AI_MAX_FOLLOWUPS)
 *  ai_context_notes    TEXT          Optional per-lead context injected into the assistant
 *  ai_call_summary     TEXT          First 1000 chars of Vapi call transcript
 *  call_recording_url  VARCHAR(500)  Link to Vapi-hosted call recording
 */

defined('BASEPATH') or exit('No direct script access allowed');

class Ai_calling_model extends App_Model
{
    /**
     * CRM status IDs that qualify a lead for outbound calling.
     *
     * Corresponds to rows in tblleads_status:
     *   1 = Lead
     *   2 = FOLLOWUP CLIENT
     *
     * Any lead whose `status` column is NOT in this list is silently skipped,
     * regardless of its ai_call_status value.
     *
     * @var int[]
     */
    private array $callable_statuses = [1, 2];

    // ─────────────────────────────────────────────────────────────────────────

    public function __construct()
    {
        parent::__construct();
        $this->_ensure_meetings_table();
    }

    private function _ensure_meetings_table(): void
    {
        $exists = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblai_meeting_bookings'"
        )->row()->cnt;

        if (!$exists) {
            $this->db->query("
                CREATE TABLE `tblai_meeting_bookings` (
                    `id`            INT(11)      NOT NULL AUTO_INCREMENT,
                    `lead_id`       INT(11)      DEFAULT NULL,
                    `lead_name`     VARCHAR(255) NOT NULL DEFAULT '',
                    `lead_phone`    VARCHAR(50)  NOT NULL DEFAULT '',
                    `vapi_call_id`  VARCHAR(100) DEFAULT NULL,
                    `booking_notes` TEXT         DEFAULT NULL,
                    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_lead_id` (`lead_id`),
                    KEY `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    // ─── Read ─────────────────────────────────────────────────────────────────

    /**
     * Returns leads that are eligible to be called in the current session.
     *
     * A lead is eligible when ALL of the following hold:
     *  - `status` is in $callable_statuses (Lead or FOLLOWUP CLIENT)
     *  - `phonenumber` is present and non-empty
     *  - `followup_count` is below AI_MAX_FOLLOWUPS (default 3)
     *  - `ai_call_status` is 'pending'
     *     OR ('callback_scheduled' AND next_followup_date ≤ today)
     *
     * Results are ordered by id DESC so the most recently added leads are
     * called first.
     *
     * @param  int   $limit  Max records to return. Defaults to 50 (AI_MAX_CALLS_PER_RUN).
     * @return array         Array of associative arrays, each with keys:
     *                       id, name, phonenumber, ai_context_notes, followup_count.
     */
    public function get_leads_to_call(int $limit = 50): array
    {
        $today = date('Y-m-d');

        $this->db->select('id, name, phonenumber, ai_context_notes, followup_count');
        $this->db->from('tblleads');
        $this->db->where_in('status', $this->callable_statuses);
        $this->db->where('phonenumber !=', '');
        $this->db->where('phonenumber IS NOT NULL', null, false);
        $max_followups = (int)(get_option('ai_calling_max_followups') ?: AI_MAX_FOLLOWUPS);
        $this->db->where('followup_count <', $max_followups);

        // pending OR failed (retry) OR (callback due today or earlier)
        $this->db->group_start();
            $this->db->where('ai_call_status', 'pending');
            $this->db->or_where('ai_call_status', 'failed');
            $this->db->or_group_start();
                $this->db->where('ai_call_status', 'callback_scheduled');
                $this->db->where('next_followup_date <=', $today);
            $this->db->group_end();
        $this->db->group_end();

        // Failed/retry leads always come first so unconnected leads are
        // re-attempted before new pending ones. Within each group, newest first.
        $this->db->order_by('CASE WHEN ai_call_status = \'failed\' THEN 0 ELSE 1 END', 'ASC', false);
        $this->db->order_by('id', 'DESC');
        $this->db->limit((int) $limit);

        return $this->db->get()->result_array();
    }

    /**
     * Fetches the N most recently called leads for the dashboard table.
     *
     * Returns leads where `last_ai_call` is not null, ordered newest first.
     * Includes all columns needed to render the Recent Calls table:
     * status badge, attempt count, transcript snippet, and recording link.
     *
     * @param  int   $limit  Max records to return. Defaults to 20.
     * @return array         Array of associative arrays with keys:
     *                       id, name, phonenumber, ai_call_status, vapi_call_id,
     *                       last_ai_call, followup_count, ai_call_summary, call_recording_url.
     */
    public function get_recent_calls(int $limit = 20): array
    {
        $this->db->select('id, name, phonenumber, ai_call_status, vapi_call_id, last_ai_call, followup_count, ai_call_summary, call_recording_url');
        $this->db->from('tblleads');
        $this->db->where('last_ai_call IS NOT NULL', null, false);
        $this->db->order_by('last_ai_call', 'DESC');
        $this->db->limit((int) $limit);

        return $this->db->get()->result_array();
    }

    /**
     * Returns aggregate counts for the six dashboard stat cards.
     *
     * Each count is derived from a separate query against tblleads:
     *
     *  pending        — ai_call_status = 'pending' AND status in callable_statuses
     *  called_today   — DATE(last_ai_call) = today
     *  interested     — ai_call_status = 'interested'
     *  callback       — ai_call_status = 'callback_scheduled'
     *  not_interested — ai_call_status = 'not_interested'
     *  total_called   — last_ai_call IS NOT NULL (ever contacted)
     *
     * Note: `pending` is intentionally filtered to callable_statuses only so
     * the count matches what _get_leads_to_call() would fetch.
     *
     * @return array<string, int>  Keys: pending, called_today, interested,
     *                             callback, not_interested, total_called.
     */
    public function get_stats(): array
    {
        $today = date('Y-m-d');

        $pending = $this->db
            ->where('ai_call_status', 'pending')
            ->where_in('status', $this->callable_statuses)
            ->get('tblleads')
            ->num_rows();

        return [
            'pending'        => $pending,
            'called_today'   => $this->db->where('DATE(last_ai_call)', $today)->get('tblleads')->num_rows(),
            // "interested" = callback_scheduled with CRM status=2 (FOLLOWUP CLIENT)
            'interested'     => $this->db->where('ai_call_status', 'callback_scheduled')->where('status', 2)->get('tblleads')->num_rows(),
            'callback'       => $this->db->where('ai_call_status', 'callback_scheduled')->get('tblleads')->num_rows(),
            'not_interested' => $this->db->where('ai_call_status', 'not_interested')->get('tblleads')->num_rows(),
            'failed'         => $this->db->where('ai_call_status', 'failed')->get('tblleads')->num_rows(),
            'total_called'   => $this->db->where('last_ai_call IS NOT NULL', null, false)->get('tblleads')->num_rows(),
        ];
    }

    // ─── Write ────────────────────────────────────────────────────────────────

    /**
     * Updates a lead immediately after a Vapi call has been successfully dispatched.
     *
     * Called by the controller as soon as _call_lead() returns success. Sets the
     * lead's status to 'called', stores the Vapi call ID for later webhook
     * matching, records the call timestamp, schedules the next follow-up date,
     * and increments the attempt counter.
     *
     * The followup_count is fetched fresh from the DB before incrementing to
     * avoid race conditions if two sessions run concurrently.
     *
     * @param  int    $lead_id  Primary key of the lead in tblleads.
     * @param  string $call_id  Vapi call UUID returned in the API response body.
     * @return void
     */
    public function mark_lead_called(int $lead_id, string $call_id): void
    {
        $row = $this->db
            ->select('followup_count')
            ->where('id', $lead_id)
            ->get('tblleads')
            ->row();

        $new_count = $row ? ((int) $row->followup_count + 1) : 1;

        $this->db->where('id', $lead_id);
        $this->db->update('tblleads', [
            'ai_call_status'     => 'called',
            'vapi_call_id'       => $call_id,
            'last_ai_call'       => date('Y-m-d H:i:s'),
            'next_followup_date' => date('Y-m-d', strtotime('+' . (int)(get_option('ai_calling_followup_days') ?: AI_FOLLOWUP_DAYS) . ' days')),
            'followup_count'     => $new_count,
        ]);
    }

    /**
     * Applies call-outcome fields from a Vapi webhook to the matching lead.
     *
     * Looks up the lead by `vapi_call_id` (set during mark_lead_called) and
     * merges the provided $fields array. Typically called with:
     *
     *  'ai_call_status'     → outcome detected from transcript keywords
     *  'ai_call_summary'    → first 1000 chars of the call transcript
     *  'call_recording_url' → Vapi-hosted recording URL (may be null)
     *
     * If no lead matches the call ID (e.g. the webhook fires before
     * mark_lead_called completes) the update silently affects zero rows.
     *
     * @param  string $vapi_call_id  Vapi call UUID from the webhook payload.
     * @param  array  $fields        Associative array of column → value pairs to update.
     * @return void
     */
    public function update_lead_from_webhook(string $vapi_call_id, array $fields): void
    {
        $this->db->where('vapi_call_id', $vapi_call_id);
        $this->db->update('tblleads', $fields);
    }

    /**
     * Marks a lead as failed after a Vapi webhook reports a call error.
     *
     * For SIP/infrastructure errors (the call never connected at network level)
     * the followup_count is decremented so the slot is not wasted — the lead
     * will be retried as if the call had never been attempted.
     *
     * For soft failures (no-answer, busy, voicemail) the count is kept so
     * repeated unreachable attempts eventually age the lead out.
     *
     * In both cases next_followup_date is cleared so get_leads_to_call()
     * picks the lead up immediately on the next session.
     *
     * @param  string $vapi_call_id  Vapi call UUID from the webhook payload.
     * @param  string $reason        Raw endedReason string from Vapi.
     * @param  bool   $refund_count  True for SIP errors — refunds followup_count.
     * @return void
     */
    // ─── Meeting bookings ─────────────────────────────────────────────────────

    /**
     * Inserts a meeting booking record when a lead books during a call.
     *
     * @param  int         $lead_id      Primary key of the lead (0 if unresolved).
     * @param  string      $lead_name    Display name from the CRM / Vapi payload.
     * @param  string      $lead_phone   Phone number.
     * @param  string      $vapi_call_id Vapi call UUID.
     * @param  string|null $notes        Transcript excerpt or AI summary.
     * @return int                       Inserted row ID.
     */
    public function insert_meeting_booking(int $lead_id, string $lead_name, string $lead_phone, string $vapi_call_id, ?string $notes = null): int
    {
        $this->db->insert('tblai_meeting_bookings', [
            'lead_id'       => $lead_id ?: null,
            'lead_name'     => $lead_name,
            'lead_phone'    => $lead_phone,
            'vapi_call_id'  => $vapi_call_id,
            'booking_notes' => $notes ? mb_substr($notes, 0, 1000) : null,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->insert_id();
    }

    /**
     * Returns all meeting bookings, newest first.
     *
     * @param  int $limit Max rows to return.
     * @return array
     */
    public function get_all_meetings(int $limit = 200): array
    {
        // l1 = match by stored lead_id; l2 = phone-number fallback when lead_id is NULL
        $sql = "
            SELECT
                m.*,
                COALESCE(l1.id,                  l2.id)                  AS crm_lead_id,
                COALESCE(l1.ai_call_summary,      l2.ai_call_summary)     AS lead_transcript,
                COALESCE(l1.call_recording_url,   l2.call_recording_url)  AS lead_recording_url
            FROM  tblai_meeting_bookings m
            LEFT JOIN tblleads l1 ON l1.id          = m.lead_id
            LEFT JOIN tblleads l2 ON l2.phonenumber = m.lead_phone
                                 AND m.lead_id IS NULL
            ORDER BY m.created_at DESC
            LIMIT ?
        ";
        return $this->db->query($sql, [(int) $limit])->result_array();
    }

    /**
     * Returns total and today's meeting booking counts.
     *
     * @return array<string, int>  Keys: total, today.
     */
    public function get_meeting_stats(): array
    {
        $today = date('Y-m-d');
        return [
            'total' => $this->db->count_all('tblai_meeting_bookings'),
            'today' => $this->db
                ->where('DATE(created_at)', $today)
                ->get('tblai_meeting_bookings')
                ->num_rows(),
        ];
    }

    public function mark_lead_failed(string $vapi_call_id, string $reason, bool $refund_count = false): void
    {
        $fields = [
            'ai_call_status'     => 'failed',
            'ai_call_summary'    => 'Call failed: ' . $reason,
            'next_followup_date' => null, // retry immediately next session
        ];

        if ($refund_count) {
            // Pull current count and decrement (floor 0) — SIP error means
            // no real call attempt was made.
            $row = $this->db
                ->select('followup_count')
                ->where('vapi_call_id', $vapi_call_id)
                ->get('tblleads')
                ->row();

            if ($row) {
                $fields['followup_count'] = max(0, (int) $row->followup_count - 1);
            }
        }

        $this->db->where('vapi_call_id', $vapi_call_id);
        $this->db->update('tblleads', $fields);
    }
}
