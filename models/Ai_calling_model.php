<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Ai_calling_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Only leads with these two CRM status IDs will be called.
     * From tblleads_status:  1 = Lead,  2 = FOLLWUP CLIENT
     */
    private $callable_statuses = [1, 2];

    public function get_leads_to_call($limit = 50)
    {
        $today = date('Y-m-d');

        $this->db->select('id, name, phonenumber, ai_context_notes, followup_count');
        $this->db->from('tblleads');
        $this->db->where_in('status', $this->callable_statuses);
        $this->db->where('phonenumber !=', '');
        $this->db->where('phonenumber IS NOT NULL', null, false);
        $this->db->where('followup_count <', AI_MAX_FOLLOWUPS);

        // pending OR (callback_scheduled AND due today)
        $this->db->group_start();
            $this->db->where('ai_call_status', 'pending');
            $this->db->or_group_start();
                $this->db->where('ai_call_status', 'callback_scheduled');
                $this->db->where('next_followup_date <=', $today);
            $this->db->group_end();
        $this->db->group_end();

        $this->db->order_by('followup_count', 'ASC');
        $this->db->limit((int) $limit);

        return $this->db->get()->result_array();
    }

    /**
     * Mark a lead as called right after the Vapi call is initiated.
     */
    public function mark_lead_called($lead_id, $call_id)
    {
        $lead_id = (int) $lead_id;

        // Fetch current followup_count to increment it
        $row = $this->db
            ->select('followup_count')
            ->where('id', $lead_id)
            ->get('tblleads')
            ->row();

        $new_count = $row ? ((int) $row->followup_count + 1) : 1;

        $this->db->where('id', $lead_id);
        $this->db->update('tblleads', [
            'ai_call_status'    => 'called',
            'vapi_call_id'      => $call_id,
            'last_ai_call'      => date('Y-m-d H:i:s'),
            'next_followup_date'=> date('Y-m-d', strtotime('+' . AI_FOLLOWUP_DAYS . ' days')),
            'followup_count'    => $new_count,
        ]);
    }

    /**
     * Called by the Vapi webhook to update call outcome after the call ends.
     */
    public function update_lead_from_webhook($vapi_call_id, $fields)
    {
        $this->db->where('vapi_call_id', $vapi_call_id);
        $this->db->update('tblleads', $fields);
    }

    /**
     * Dashboard stats counts.
     */
    public function get_stats()
    {
        $today = date('Y-m-d');

        // Pending = only "Lead" or "FOLLOWUP CLIENT" CRM status leads not yet called
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
            'total_called'   => $this->db->where('last_ai_call IS NOT NULL', null, false)->get('tblleads')->num_rows(),
        ];
    }

    /**
     * Recent calls for the dashboard table.
     */
    public function get_recent_calls($limit = 20)
    {
        $this->db->select('id, name, phonenumber, ai_call_status, vapi_call_id, last_ai_call, followup_count, ai_call_summary, call_recording_url');
        $this->db->from('tblleads');
        $this->db->where('last_ai_call IS NOT NULL', null, false);
        $this->db->order_by('last_ai_call', 'DESC');
        $this->db->limit((int) $limit);

        return $this->db->get()->result_array();
    }
}
