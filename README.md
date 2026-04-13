# AI Calling
### Perfex CRM Module — Powered by [Vapi](https://vapi.ai)

> Automate outbound AI voice calls to your leads. Calls trigger on demand or via cron, and outcomes sync back to Perfex CRM in real time through a webhook.

![Perfex CRM](https://img.shields.io/badge/Perfex%20CRM-2.3+-4A90D9?style=flat-square)
![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=flat-square&logo=php&logoColor=white)
![Vapi](https://img.shields.io/badge/Vapi-AI%20Voice-FF6B6B?style=flat-square)
![License](https://img.shields.io/badge/License-Proprietary-lightgrey?style=flat-square)

---

## Overview

```
Lead added to CRM
       │
       ▼
 AI Calling Module ──► Vapi API ──► Phone Call
       │                               │
       │            Webhook ◄──────────┘
       ▼
 Lead record updated (status, summary, recording)
```

---

## Features

| Feature | Description |
|---|---|
| Outbound AI Calls | Dispatches voice calls to leads via Vapi with zero manual effort |
| Smart Lead Filtering | Only calls leads with CRM status `Lead` or `FOLLOWUP CLIENT` |
| Follow-up Logic | Re-calls unanswered leads up to 3 times, spaced 5 days apart |
| Outcome Detection | Analyses transcripts for keywords → sets `interested`, `not_interested`, or `callback_scheduled` |
| Webhook Receiver | Vapi POSTs results after each call; module updates the lead instantly |
| Dashboard | Live stat cards + recent-calls table with transcript snippets and recording links |
| Manual Trigger | "Start Calling Now" button for on-demand sessions |
| Cron Endpoint | Token-protected URL for any scheduler (Hostinger, cPanel, GitHub Actions) |
| File Logging | Per-day session and webhook logs written to `logs/` |

---

## Requirements

| Requirement | Version / Notes |
|---|---|
| Perfex CRM | 2.3 or later |
| PHP | 7.4+ |
| PHP extensions | `curl`, `json` |
| Vapi account | API key · Phone Number ID · Assistant ID — [vapi.ai](https://vapi.ai) |

---

## Installation

### 1 — Copy the module

```bash
cp -r ai_calling/ perfex_crm/modules/ai_calling/
```

### 2 — Activate in Perfex CRM

Go to **Setup → Modules**, find **AI Calling**, and click **Activate**.
The `logs/` directory is created automatically on activation.

### 3 — Add your Vapi credentials

Open `ai_calling.php` and replace the placeholder constants:

```php
define('AI_VAPI_API_KEY',      'your-vapi-api-key');
define('AI_VAPI_PHONE_ID',     'your-vapi-phone-number-id');
define('AI_VAPI_ASSISTANT_ID', 'your-vapi-assistant-id');
```

### 4 — Run the database migration

Execute once against your Perfex CRM database:

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

## Automation Setup

### Cron Job

Copy the cron URL from the dashboard and schedule it daily:

```
https://yourdomain.com/admin/ai_calling/cron/YOUR_CRON_TOKEN
```

**Hostinger:** Hosting → Cron Jobs → Add → paste URL → set to daily at 09:00

### Vapi Webhook

Paste the webhook URL into your Vapi assistant settings:

```
https://yourdomain.com/admin/ai_calling/webhook
```

**Vapi:** Dashboard → Assistant → Webhook URL → Save

---

## How It Works

### Lead Selection

A lead is called when it meets **all** of the following:

- CRM `status` is `Lead` **or** `FOLLOWUP CLIENT`
- `phonenumber` is not empty
- `followup_count` < `AI_MAX_FOLLOWUPS`
- `ai_call_status` is `pending` — **or** `callback_scheduled` with `next_followup_date` today or earlier

Leads are ordered by `followup_count ASC` so fresher leads are called first.

### Outcome Detection

After each call, Vapi sends the transcript. The module maps keywords to statuses:

| Transcript contains | Status set |
|---|---|
| `interested` (without `not`) | `interested` |
| `not interested` / `no interest` | `not_interested` |
| `callback` / `call back` / `call me later` | `callback_scheduled` |
| *(no match)* | `called` |

### Phone Number Formatting

Numbers are normalised to E.164 format automatically:

| Input | Output |
|---|---|
| `01XXXXXXXXX` (11 digits) | `+8801XXXXXXXXX` |
| `1XXXXXXXXX` (10 digits) | `+8801XXXXXXXXX` |
| `880XXXXXXXXXX` (no `+`) | `+880XXXXXXXXXX` |
| Anything else | `+` prepended |

---

## Dashboard

Navigate to **AI Calling** in the Perfex CRM sidebar.

**Stat cards**

| Card | What it shows |
|---|---|
| Pending Calls | Leads with `Lead` / `FOLLOWUP CLIENT` status not yet called |
| Called Today | Leads called in the current calendar day |
| Interested | Leads where the AI detected interest |
| Callback Scheduled | Leads that requested a call back |
| Not Interested | Leads that declined |
| Total Called | All leads ever contacted |

**Recent Calls table** — last 20 calls with name, phone, status badge, timestamp, attempt count, transcript snippet, and recording playback link.

---

## File Structure

```
ai_calling/
├── ai_calling.php                   # Entry point — constants, hooks, menu registration
├── install.php                      # Activation hook — creates logs/ directory
│
├── controllers/
│   └── Ai_calling.php               # Dashboard · cron · webhook · calling session
│
├── models/
│   └── Ai_calling_model.php         # All database queries
│
├── views/
│   └── manage.php                   # Dashboard UI
│
├── language/
│   └── english/
│       └── ai_calling_lang.php      # Language strings
│
└── logs/                            # Auto-created on activation (git-ignored)
    ├── session_YYYY-MM-DD.log       # Per-day call session logs
    └── webhook_YYYY-MM-DD.log       # Raw Vapi webhook payloads
```

---

## Permissions

The module registers a single `view` capability under the **AI Calling** group.

Go to **Setup → Roles** to grant or restrict access per staff role.

---

## Author

**Bytesis Ltd**
