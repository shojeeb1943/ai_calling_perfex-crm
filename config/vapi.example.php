<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Vapi credentials — copy this file to vapi.php and fill in your values.
 * vapi.php is git-ignored and will never be committed.
 *
 * Get these from: https://dashboard.vapi.ai
 */

define('AI_VAPI_API_KEY',      'your-vapi-api-key-here');
define('AI_VAPI_ASSISTANT_ID', 'your-vapi-assistant-id-here');

/**
 * Cron security token — change this to something secret.
 * Used in the cron URL: /admin/ai_calling/cron/YOUR_TOKEN
 */
define('AI_CRON_TOKEN', 'change-me-to-something-secret');

// ── Provider: Amarip (BYO SIP trunk) ─────────────────────────────────────────
// Get from: Vapi Dashboard → Phone Numbers → your Amarip SIP trunk number → copy ID
define('AI_VAPI_PHONE_ID',        'your-amarip-vapi-phone-number-id');

// ── Provider: Twilio ─────────────────────────────────────────────────────────
// 1. Buy a number in Twilio → import to Vapi (Dashboard → Phone Numbers → Add → Twilio)
// 2. Copy the Vapi Phone Number ID (NOT the Twilio SID) and paste here
define('AI_VAPI_TWILIO_PHONE_ID', 'your-twilio-vapi-phone-number-id');
