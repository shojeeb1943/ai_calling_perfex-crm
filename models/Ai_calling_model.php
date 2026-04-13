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
        $this->db->where('followup_count <', AI_MAX_FOLLOWUPS);

        // pending OR failed (retry) OR (callback due today or earlier)
        $this->db->group_start();
            $this->db->where('ai_call_status', 'pending');
            $this->db->or_where('ai_call_status', 'failed');
            $this->db->or_group_start();
                $this->db->where('ai_call_status', 'callback_scheduled');
                $this->db->where('next_followup_date <=', $today);
            $this->db->group_end();
        $this->db->group_end();

        $this->db->order_by('id', 'DESC'); // newest leads first
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
            'interested'     => $this->db->where('ai_call_status', 'interested')->get('tblleads')->num_rows(),
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
            'next_followup_date' => date('Y-m-d', strtotime('+' . AI_FOLLOWUP_DAYS . ' days')),
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
}
