# AI Calling — Perfex CRM Module

Automates outbound phone calls to your Perfex CRM leads using a [Vapi](https://vapi.ai) AI voice assistant. Calls are triggered manually from the dashboard or automatically via a cron job, and call outcomes are written back to the lead record in real time through a webhook.

---

## Features

- **Automated outbound calling** — dispatches AI voice calls to leads via Vapi
- **Smart lead filtering** — only calls leads with CRM status `Lead` or `FOLLOWUP CLIENT`
- **Follow-up logic** — re-calls unanswered / callback leads, up to 3 attempts, spaced 5 days apart
- **Outcome detection** — analyses the call transcript for keywords and sets the lead's AI status to `interested`, `not_interested`, or `callback_scheduled`
- **Webhook receiver** — Vapi POSTs results back after each call ends; the module updates the lead instantly
- **Dashboard** — live stat cards + a recent-calls table with transcript snippets and recording links
- **Manual trigger** — "Start Calling Now" button for on-demand sessions without waiting for cron
- **Cron endpoint** — a token-protected URL for Hostinger (or any scheduler) to run daily sessions
- **File logging** — per-day session and webhook log files written to `logs/`

---

## Requirements

| Requirement | Version |
|---|---|
| Perfex CRM | 2.3 or later |
| PHP | 7.4+ |
| PHP extensions | `curl`, `json` |
| Vapi account | [vapi.ai](https://vapi.ai) — API key, Phone Number ID, Assistant ID |

---

## Installation

1. Copy the `ai_calling` folder into your Perfex CRM `modules/` directory:
   ```
   perfex_crm/modules/ai_calling/
   ```

2. Log in to Perfex CRM admin, go to **Setup → Modules**, find **AI Calling** and click **Activate**. This creates the `logs/` directory automatically.

3. Open `ai_calling.php` and replace the placeholder constants with your own Vapi credentials:

   ```php
   define('AI_VAPI_API_KEY',      'your-vapi-api-key');
   define('AI_VAPI_PHONE_ID',     'your-vapi-phone-number-id');
   define('AI_VAPI_ASSISTANT_ID', 'your-vapi-assistant-id');
   ```

4. Add the following columns to `tblleads` in your database (run once):

   ```sql
   ALTER TABLE tblleads
     ADD COLUMN ai_call_status      VARCHAR(30)   DEFAULT 'pending',
     ADD COLUMN vapi_call_id        VARCHAR(100)  DEFAULT NULL,
     ADD COLUMN last_ai_call        DATETIME      DEFAULT NULL,
     ADD COLUMN next_followup_date  DATE          DEFAULT NULL,
     ADD COLUMN followup_count      INT           DEFAULT 0,
     ADD COLUMN ai_call_summary     TEXT          DEFAULT NULL,
     ADD COLUMN call_recording_url  VARCHAR(500)  DEFAULT NULL,
     ADD COLUMN ai_context_notes    TEXT          DEFAULT NULL;
   ```

---

## Configuration

All tuneable settings live as constants at the top of `ai_calling.php`:

| Constant | Default | Description |
|---|---|---|
| `AI_VAPI_API_KEY` | — | Your Vapi API key |
| `AI_VAPI_PHONE_ID` | — | Vapi phone number ID to call from |
| `AI_VAPI_ASSISTANT_ID` | — | Vapi assistant to use for calls |
| `AI_CRON_TOKEN` | `VapiCron2024Secure` | Secret token for the cron URL — **change this** |
| `AI_MAX_CALLS_PER_RUN` | `50` | Max leads called in a single session |
| `AI_CALL_DELAY_SEC` | `2` | Seconds to wait between each call |
| `AI_FOLLOWUP_DAYS` | `5` | Days before a follow-up call is scheduled |
| `AI_MAX_FOLLOWUPS` | `3` | Max total call attempts per lead |

---

## Lead Selection Logic

The module only calls leads that meet **all** of the following criteria:

- CRM `status` is `Lead` **or** `FOLLOWUP CLIENT`
- `phonenumber` is not empty
- `followup_count` is less than `AI_MAX_FOLLOWUPS` (3)
- `ai_call_status` is `pending` **OR** (`callback_scheduled` AND `next_followup_date` is today or earlier)

Leads are ordered by `followup_count ASC` — fresher leads are called first.

---

## Call Outcome Detection

After each call ends, Vapi sends a webhook with the call transcript. The module scans the transcript for keywords:

| Transcript contains | Status set |
|---|---|
| `interested` (but not `not interested`) | `interested` |
| `not interested` / `no interest` | `not_interested` |
| `callback` / `call back` / `call me later` | `callback_scheduled` |
| *(none of the above)* | `called` |

---

## Phone Number Formatting

The module automatically converts Bangladesh numbers to E.164 format before calling:

| Input format | Output |
|---|---|
| `01XXXXXXXXX` (11 digits) | `+8801XXXXXXXXX` |
| `1XXXXXXXXX` (10 digits, no leading 0) | `+8801XXXXXXXXX` |
| `880XXXXXXXXXX` (no `+`) | `+880XXXXXXXXXX` |
| Anything else | `+` prepended |

---

## Setting Up Automation

### Cron Job (Hostinger / any host)

Copy the cron URL from the dashboard and schedule it to run daily:

```
https://yourdomain.com/admin/ai_calling/cron/YOUR_CRON_TOKEN
```

In Hostinger: **Hosting → Cron Jobs → Add → paste URL → set to daily at 09:00**.

### Vapi Webhook

Copy the webhook URL from the dashboard and paste it into your Vapi assistant settings:

```
https://yourdomain.com/admin/ai_calling/webhook
```

Vapi → **Dashboard → Assistant → Webhook URL → Save**. Vapi will POST call results to this endpoint after every call ends.

---

## Dashboard

Navigate to **AI Calling** in the Perfex CRM sidebar.

**Stat cards:**

| Card | Description |
|---|---|
| Pending Calls | Leads with `Lead` / `FOLLOWUP CLIENT` status not yet called |
| Called Today | Leads called in the current calendar day |
| Interested | Leads where AI detected interest |
| Callback Scheduled | Leads that asked to be called back |
| Not Interested | Leads that declined |
| Total Called | All leads ever contacted |

**Recent Calls table** shows the last 20 calls with name, phone, status badge, last call time, attempt count, transcript snippet, and a recording playback link.

---

## File Structure

```
ai_calling/
├── ai_calling.php               # Module entry point — constants, hooks, menu
├── install.php                  # Activation hook — creates logs/ directory
├── controllers/
│   └── Ai_calling.php           # Dashboard, cron, webhook, calling session logic
├── models/
│   └── Ai_calling_model.php     # All database queries
├── views/
│   └── manage.php               # Dashboard UI
├── language/
│   └── english/
│       └── ai_calling_lang.php  # Language strings
└── logs/                        # Auto-created on activation (git-ignored)
    ├── session_YYYY-MM-DD.log   # Per-day call session logs
    └── webhook_YYYY-MM-DD.log   # Raw Vapi webhook payloads
```

---

## Permissions

The module registers a single `view` capability under the `AI Calling` group. Go to **Setup → Roles** to grant or restrict access per staff role.

---

## Author

**Bytesis Ltd**
