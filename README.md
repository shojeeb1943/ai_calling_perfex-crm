<div align="center">

# 📞 AI Calling

**Perfex CRM Module · Powered by [Vapi](https://vapi.ai)**

*Automate outbound AI voice calls to your leads — fully hands-free.*

<br/>

[![Perfex CRM](https://img.shields.io/badge/Perfex%20CRM-2.3+-4A90D9?style=for-the-badge)](https://perfexcrm.com)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![Vapi](https://img.shields.io/badge/Vapi-AI%20Voice-FF6B6B?style=for-the-badge)](https://vapi.ai)
[![License](https://img.shields.io/badge/License-Proprietary-1a1a2e?style=for-the-badge)](.)

</div>

---

## How It Works

```
┌─────────────┐     trigger      ┌──────────────────┐     API call     ┌────────────┐
│  Perfex CRM │ ──────────────►  │  AI Calling Mod  │ ───────────────► │  Vapi API  │
│   (Leads)   │                  │                  │                  │            │
└─────────────┘                  └──────────────────┘                  └─────┬──────┘
       ▲                                  ▲                                  │
       │         lead updated             │           webhook POST            │
       └──────────────────────────────────┴───────────────────────────────────┘
```

Calls are triggered **manually** from the dashboard or **automatically** via a cron job. When a call ends, Vapi sends a webhook — the module parses the transcript, detects the outcome, and writes the result back to the lead record instantly.

---

## Features

<table>
<tr><td>🤖</td><td><strong>Outbound AI Calls</strong></td><td>Dispatches voice calls to leads via Vapi with zero manual effort</td></tr>
<tr><td>🎯</td><td><strong>Smart Lead Filtering</strong></td><td>Only calls leads with CRM status <code>Lead</code> or <code>FOLLOWUP CLIENT</code></td></tr>
<tr><td>🔁</td><td><strong>Follow-up Logic</strong></td><td>Re-calls unanswered leads up to 3 times, spaced 5 days apart</td></tr>
<tr><td>🧠</td><td><strong>Outcome Detection</strong></td><td>Analyses transcripts → sets <code>interested</code>, <code>not_interested</code>, or <code>callback_scheduled</code></td></tr>
<tr><td>🪝</td><td><strong>Webhook Receiver</strong></td><td>Vapi POSTs results after each call; lead record updates instantly</td></tr>
<tr><td>📊</td><td><strong>Dashboard</strong></td><td>Live stat cards + recent-calls table with transcript snippets and recordings</td></tr>
<tr><td>▶️</td><td><strong>Manual Trigger</strong></td><td>"Start Calling Now" button for on-demand sessions</td></tr>
<tr><td>⏰</td><td><strong>Cron Endpoint</strong></td><td>Token-protected URL for any scheduler — Hostinger, cPanel, GitHub Actions</td></tr>
<tr><td>📝</td><td><strong>File Logging</strong></td><td>Per-day session and webhook logs written to <code>logs/</code></td></tr>
</table>

---

## Requirements

| | Requirement | Notes |
|---|---|---|
| 🖥️ | **Perfex CRM** | Version 2.3 or later |
| 🐘 | **PHP** | 7.4+ with `curl` and `json` extensions |
| 🔊 | **Vapi Account** | API key · Phone Number ID · Assistant ID — [vapi.ai](https://vapi.ai) |

---

## Quick Start

### `1` &nbsp; Copy the module

```bash
cp -r ai_calling/ perfex_crm/modules/ai_calling/
```

### `2` &nbsp; Activate in Perfex CRM

**Setup → Modules → AI Calling → Activate**

> The `logs/` directory is created automatically on activation.

### `3` &nbsp; Add Vapi credentials

Open `ai_calling.php` and fill in your keys:

```php
define('AI_VAPI_API_KEY',      'your-vapi-api-key');
define('AI_VAPI_PHONE_ID',     'your-vapi-phone-number-id');
define('AI_VAPI_ASSISTANT_ID', 'your-vapi-assistant-id');
```

### `4` &nbsp; Run the database migration

Execute this **once** against your Perfex CRM database:

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

All settings are constants at the top of `ai_calling.php`:

| Constant | Default | Description |
|---|---|---|
| `AI_VAPI_API_KEY` | — | Your Vapi API key |
| `AI_VAPI_PHONE_ID` | — | Phone number ID to call from |
| `AI_VAPI_ASSISTANT_ID` | — | Assistant to use for calls |
| `AI_CRON_TOKEN` | `VapiCron2024Secure` | ⚠️ Secret token for the cron URL — **change this** |
| `AI_MAX_CALLS_PER_RUN` | `50` | Max leads called per session |
| `AI_CALL_DELAY_SEC` | `2` | Seconds between each call |
| `AI_FOLLOWUP_DAYS` | `5` | Days before a follow-up is scheduled |
| `AI_MAX_FOLLOWUPS` | `3` | Max call attempts per lead |

---

## Automation

<details>
<summary><strong>⏰ Cron Job (Hostinger / cPanel / any host)</strong></summary>

<br/>

Copy the cron URL from the dashboard:

```
https://yourdomain.com/admin/ai_calling/cron/YOUR_CRON_TOKEN
```

**Hostinger:** Hosting → Cron Jobs → Add → paste URL → daily at 09:00

</details>

<details>
<summary><strong>🪝 Vapi Webhook</strong></summary>

<br/>

Copy the webhook URL from the dashboard:

```
https://yourdomain.com/admin/ai_calling/webhook
```

**Vapi:** Dashboard → Assistant → Webhook URL → Save

Vapi will POST call results to this endpoint after every call ends.

</details>

---

## How Leads Are Selected

A lead is called when it meets **all** of these criteria:

```
✔  CRM status is "Lead"  OR  "FOLLOWUP CLIENT"
✔  phonenumber is not empty
✔  followup_count < AI_MAX_FOLLOWUPS (default 3)
✔  ai_call_status is "pending"
   OR  "callback_scheduled" with next_followup_date ≤ today
```

> Leads are ordered by `followup_count ASC` — fresher leads are called first.

---

## Outcome Detection

After each call Vapi sends the full transcript. The module matches keywords and sets the lead's AI status:

| Transcript signal | Status |
|---|---|
| `interested` *(without "not")* | `interested` |
| `not interested` / `no interest` | `not_interested` |
| `callback` / `call back` / `call me later` | `callback_scheduled` |
| *(no match)* | `called` |

---

## Phone Number Formatting

Numbers are auto-normalised to E.164 before calling:

| Input format | Output |
|---|---|
| `01XXXXXXXXX` — 11 digits | `+8801XXXXXXXXX` |
| `1XXXXXXXXX` — 10 digits, no leading 0 | `+8801XXXXXXXXX` |
| `880XXXXXXXXXX` — no `+` prefix | `+880XXXXXXXXXX` |
| Anything else | `+` prepended as-is |

---

## Dashboard

Navigate to **AI Calling** in the Perfex CRM sidebar.

**Stat Cards**

| Card | Description |
|---|---|
| 🕐 Pending Calls | `Lead` / `FOLLOWUP CLIENT` leads not yet called |
| 📅 Called Today | Leads called in the current calendar day |
| ✅ Interested | AI detected interest |
| 🔁 Callback Scheduled | Lead asked to be called back |
| ❌ Not Interested | Lead declined |
| 📞 Total Called | All leads ever contacted |

**Recent Calls table** — last 20 calls with name, phone, status badge, timestamp, attempt count, transcript snippet, and a recording playback link.

---

## File Structure

```
ai_calling/
├── ai_calling.php                   # Entry point — constants, hooks, menu
├── install.php                      # Activation hook — creates logs/
│
├── controllers/
│   └── Ai_calling.php               # Dashboard · cron · webhook · session logic
│
├── models/
│   └── Ai_calling_model.php         # All DB queries
│
├── views/
│   └── manage.php                   # Dashboard UI
│
├── language/
│   └── english/
│       └── ai_calling_lang.php      # Language strings
│
└── logs/                            # Auto-created on activation · git-ignored
    ├── session_YYYY-MM-DD.log       # Per-day call session logs
    └── webhook_YYYY-MM-DD.log       # Raw Vapi webhook payloads
```

---

## Permissions

The module registers a single `view` capability under the **AI Calling** group.

**Setup → Roles** — grant or restrict access per staff role.

---

<div align="center">

Built by **Bytesis Ltd**

</div>
