<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">

                <!-- Page header -->
                <div class="page-heading">
                    <h3 class="no-margin">
                        <i class="fa fa-phone text-success"></i>
                        AI Calling Dashboard
                        <small class="text-muted" style="font-size:13px;margin-left:8px;">v<?php echo AI_CALLING_VERSION; ?></small>
                    </h3>
                    <small class="text-muted">Automated Vapi AI lead calling system</small>
                </div>
                <hr class="hr-panel-heading" />

                <!-- ── Stat cards ────────────────────────────────────────── -->
                <div class="row">

                    <div class="col-md-2 col-sm-4 col-xs-6">
                        <div class="panel panel-default">
                            <div class="panel-body text-center">
                                <h2 class="text-warning no-margin"><?php echo $stats['pending']; ?></h2>
                                <small>Pending Calls</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2 col-sm-4 col-xs-6">
                        <div class="panel panel-default">
                            <div class="panel-body text-center">
                                <h2 class="text-primary no-margin"><?php echo $stats['called_today']; ?></h2>
                                <small>Called Today</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2 col-sm-4 col-xs-6">
                        <div class="panel panel-default">
                            <div class="panel-body text-center">
                                <h2 class="text-success no-margin"><?php echo $stats['interested']; ?></h2>
                                <small>Interested</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2 col-sm-4 col-xs-6">
                        <div class="panel panel-default">
                            <div class="panel-body text-center">
                                <h2 class="text-info no-margin"><?php echo $stats['callback']; ?></h2>
                                <small>Callback Scheduled</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2 col-sm-4 col-xs-6">
                        <div class="panel panel-default">
                            <div class="panel-body text-center">
                                <h2 class="text-danger no-margin"><?php echo $stats['not_interested']; ?></h2>
                                <small>Not Interested</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2 col-sm-4 col-xs-6">
                        <div class="panel panel-default">
                            <div class="panel-body text-center">
                                <h2 class="text-danger no-margin"><?php echo $stats['failed']; ?></h2>
                                <small>Failed (will retry)</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2 col-sm-4 col-xs-6">
                        <div class="panel panel-default">
                            <div class="panel-body text-center">
                                <h2 class="text-muted no-margin"><?php echo $stats['total_called']; ?></h2>
                                <small>Total Called</small>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- /stat cards -->

                <!-- ── Provider Selector ────────────────────────────────── -->
                <?php
                $active_provider   = $active_provider ?? 'amarip';
                $twilio_configured = defined('AI_VAPI_TWILIO_PHONE_ID') && AI_VAPI_TWILIO_PHONE_ID !== '' && AI_VAPI_TWILIO_PHONE_ID !== 'your-twilio-phone-number-id-in-vapi';
                ?>
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <i class="fa fa-exchange"></i> Calling Provider
                            <small class="text-muted" style="font-weight:normal; margin-left:8px;">
                                Active provider is used for all outbound calls
                            </small>
                        </h4>
                    </div>
                    <div class="panel-body">
                        <div class="row">

                            <!-- Amarip card -->
                            <div class="col-md-5">
                                <div class="panel <?php echo $active_provider === 'amarip' ? 'panel-success' : 'panel-default'; ?>"
                                     style="margin-bottom:0; border-width:2px;">
                                    <div class="panel-body">
                                        <div class="row">
                                            <div class="col-xs-9">
                                                <h4 class="no-margin">
                                                    <i class="fa fa-server"></i> Amarip SIP Trunk
                                                    <?php if ($active_provider === 'amarip'): ?>
                                                        <span class="label label-success" style="font-size:10px; vertical-align:middle;">ACTIVE</span>
                                                    <?php endif; ?>
                                                </h4>
                                                <small class="text-muted">BYO SIP trunk via Vapi · Local BD provider</small>
                                                <div style="margin-top:6px; font-size:12px;">
                                                    <span class="text-warning"><i class="fa fa-exclamation-triangle"></i> BD IPs only — may fail from Vapi (US)</span>
                                                </div>
                                            </div>
                                            <div class="col-xs-3 text-right">
                                                <?php if ($active_provider !== 'amarip'): ?>
                                                    <button class="btn btn-default btn-sm btn-switch-provider"
                                                            data-provider="amarip"
                                                            title="Switch to Amarip">
                                                        <i class="fa fa-toggle-off"></i> Use
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-success btn-sm" disabled>
                                                        <i class="fa fa-check"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Arrow -->
                            <div class="col-md-2 text-center" style="padding-top:20px; font-size:20px; color:#ccc;">
                                <i class="fa fa-exchange"></i>
                            </div>

                            <!-- Twilio card -->
                            <div class="col-md-5">
                                <div class="panel <?php echo $active_provider === 'twilio' ? 'panel-info' : 'panel-default'; ?>"
                                     style="margin-bottom:0; border-width:2px;">
                                    <div class="panel-body">
                                        <div class="row">
                                            <div class="col-xs-9">
                                                <h4 class="no-margin">
                                                    <i class="fa fa-cloud"></i> Twilio
                                                    <?php if ($active_provider === 'twilio'): ?>
                                                        <span class="label label-info" style="font-size:10px; vertical-align:middle;">ACTIVE</span>
                                                    <?php endif; ?>
                                                </h4>
                                                <small class="text-muted">Global cloud telephony · Works internationally</small>
                                                <div style="margin-top:6px; font-size:12px;">
                                                    <?php if ($twilio_configured): ?>
                                                        <span class="text-success"><i class="fa fa-check-circle"></i> Phone ID configured</span>
                                                    <?php else: ?>
                                                        <span class="text-danger"><i class="fa fa-times-circle"></i> Set AI_VAPI_TWILIO_PHONE_ID in vapi.php</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-xs-3 text-right">
                                                <?php if ($active_provider !== 'twilio'): ?>
                                                    <button class="btn btn-<?php echo $twilio_configured ? 'default' : 'default disabled'; ?> btn-sm btn-switch-provider"
                                                            data-provider="twilio"
                                                            <?php echo !$twilio_configured ? 'disabled title="Configure AI_VAPI_TWILIO_PHONE_ID first"' : 'title="Switch to Twilio"'; ?>>
                                                        <i class="fa fa-toggle-off"></i> Use
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-info btn-sm" disabled>
                                                        <i class="fa fa-check"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div><!-- /row -->

                        <!-- Provider switch result -->
                        <div id="provider-alert" style="display:none; margin-top:12px;" class="alert"></div>

                    </div>
                </div>
                <!-- /provider selector -->

                <!-- ── Test Call ────────────────────────────────────────── -->
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <i class="fa fa-flask"></i> Test Call
                            <small class="text-muted" style="font-weight:normal; margin-left:8px;">
                                Call a specific number to verify the active provider works
                            </small>
                        </h4>
                    </div>
                    <div class="panel-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="input-group">
                                    <span class="input-group-addon"><i class="fa fa-phone"></i></span>
                                    <input type="text" id="test-call-phone" class="form-control"
                                           placeholder="e.g. 01816122188 or +8801816122188"
                                           value="01816122188" />
                                    <span class="input-group-btn">
                                        <button id="btn-test-call" class="btn btn-warning">
                                            <i class="fa fa-phone"></i> Call Now
                                            <small style="display:block; font-size:10px; font-weight:normal;">
                                                via <strong><?php echo $active_provider === 'twilio' ? 'Twilio' : 'Amarip'; ?></strong>
                                            </small>
                                        </button>
                                    </span>
                                </div>
                                <small class="text-muted">Uses the currently active provider. Does NOT create a lead record.</small>
                            </div>
                            <div class="col-md-6">
                                <div id="test-call-result" style="display:none;" class="alert"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- /test call -->

                <!-- ── Manual trigger ───────────────────────────────────── -->
                <div class="panel panel-default">
                    <div class="panel-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h4 class="no-margin">Start Calling Session</h4>
                                <p class="text-muted" style="margin-bottom:0;">
                                    One click calls <strong>all pending leads</strong> one by one via
                                    <strong><?php echo $active_provider === 'twilio' ? 'Twilio' : 'Amarip SIP'; ?></strong>,
                                    with <strong><?php echo $setting_delay_sec; ?>s</strong> delay between each call.
                                    Max <strong><?php echo $setting_max_followups; ?></strong> attempts per lead,
                                    followup in <strong><?php echo $setting_followup_days; ?></strong> days.
                                </p>
                            </div>
                            <div class="col-md-4 text-right">
                                <button id="btn-start-calling" class="btn btn-<?php echo $active_provider === 'twilio' ? 'info' : 'success'; ?> btn-lg">
                                    <i class="fa fa-phone"></i> Start Calling Now
                                    <small style="display:block; font-size:11px; font-weight:normal; opacity:0.85;">
                                        via <?php echo $active_provider === 'twilio' ? 'Twilio' : 'Amarip'; ?>
                                    </small>
                                </button>
                                <button id="btn-stop-calling" class="btn btn-danger btn-lg" style="display:none; margin-top:5px;">
                                    <i class="fa fa-stop"></i> Stop Queue
                                </button>
                            </div>
                        </div>

                        <!-- Progress bar -->
                        <div id="calling-progress" style="display:none; margin-top:15px;">
                            <div class="progress" style="margin-bottom:6px;">
                                <div id="calling-progress-bar" class="progress-bar progress-bar-striped active"
                                     role="progressbar" style="width:0%; min-width:30px;">
                                    <span id="calling-progress-text">0</span>
                                </div>
                            </div>
                            <small class="text-muted" id="calling-progress-label">Starting…</small>
                        </div>

                        <!-- Result area -->
                        <div id="calling-result" style="display:none; margin-top:15px;">
                            <div id="calling-alert" class="alert"></div>
                            <pre id="calling-log" style="max-height:250px; overflow-y:auto; background:#f5f5f5; padding:10px; border-radius:4px; font-size:12px;"></pre>
                        </div>
                    </div>
                </div>

                <!-- ── Calling Settings ─────────────────────────────────── -->
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <i class="fa fa-cog"></i> Calling Settings
                        </h4>
                    </div>
                    <div class="panel-body">
                        <form id="form-settings">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Calling Hours (24-hour)</label>
                                        <div class="input-group">
                                            <span class="input-group-addon">Start</span>
                                            <input type="number" name="hour_start" class="form-control" min="0" max="23"
                                                   value="<?php echo $setting_hour_start; ?>" />
                                            <span class="input-group-addon">End</span>
                                            <input type="number" name="hour_end" class="form-control" min="1" max="24"
                                                   value="<?php echo $setting_hour_end; ?>" />
                                        </div>
                                        <small class="text-muted">Cron calls are skipped outside this window. End is exclusive (22 = until 21:59).</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Max calls per session</label>
                                        <input type="number" name="max_per_run" class="form-control" min="1" max="200"
                                               value="<?php echo $setting_max_per_run; ?>" />
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Delay between calls (sec)</label>
                                        <input type="number" name="delay_sec" class="form-control" min="0" max="120"
                                               value="<?php echo $setting_delay_sec; ?>" />
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Follow-up after (days)</label>
                                        <input type="number" name="followup_days" class="form-control" min="1" max="365"
                                               value="<?php echo $setting_followup_days; ?>" />
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Max attempts per lead</label>
                                        <input type="number" name="max_followups" class="form-control" min="1" max="20"
                                               value="<?php echo $setting_max_followups; ?>" />
                                    </div>
                                </div>
                                <div class="col-md-6 text-right" style="padding-top:25px;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fa fa-save"></i> Save Settings
                                    </button>
                                </div>
                            </div>
                            <div id="settings-alert" style="display:none;" class="alert"></div>
                        </form>
                    </div>
                </div>
                <!-- /calling settings -->

                <!-- ── Cron / Webhook info ───────────────────────────────── -->
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">Automation Setup</h4>
                    </div>
                    <div class="panel-body">
                        <p><strong>Hostinger Cron URL</strong> (set to run daily at 9:00 AM):</p>
                        <div class="input-group">
                            <input type="text" class="form-control" id="cron-url"
                                   value="<?php echo base_url('admin/ai_calling/cron/' . AI_CRON_TOKEN); ?>"
                                   readonly />
                            <span class="input-group-btn">
                                <button class="btn btn-default" onclick="copyCronUrl()">
                                    <i class="fa fa-copy"></i> Copy
                                </button>
                            </span>
                        </div>
                        <small class="text-muted">Hostinger → Hosting → Cron Jobs → Add → paste URL → set schedule</small>

                        <hr/>

                        <p><strong>Vapi Webhook URL</strong> (paste in Vapi Dashboard → Assistant → Webhook URL):</p>
                        <div class="input-group">
                            <input type="text" class="form-control" id="webhook-url"
                                   value="<?php echo base_url('admin/ai_calling/webhook'); ?>"
                                   readonly />
                            <span class="input-group-btn">
                                <button class="btn btn-default" onclick="copyWebhookUrl()">
                                    <i class="fa fa-copy"></i> Copy
                                </button>
                            </span>
                        </div>
                        <small class="text-muted">Vapi will POST call results to this URL after each call ends</small>
                    </div>
                </div>

                <!-- ── Recent calls table ────────────────────────────────── -->
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">Recent Calls (Last 20)</h4>
                    </div>
                    <div class="panel-body no-padding">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover no-margin">
                                <thead>
                                    <tr>
                                        <th>Lead</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                        <th>Last Called</th>
                                        <th>Attempts</th>
                                        <th>Summary</th>
                                        <th>Recording</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($recent_calls)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">No calls made yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_calls as $call): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo admin_url('leads/index/' . $call['id']); ?>">
                                                <?php echo htmlspecialchars($call['name']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars($call['phonenumber']); ?></td>
                                        <td>
                                            <?php
                                            $status_map = [
                                                'pending'            => ['label' => 'Pending',          'class' => 'warning'],
                                                'called'             => ['label' => 'Called',           'class' => 'primary'],
                                                'interested'         => ['label' => 'Interested',       'class' => 'success'],
                                                'not_interested'     => ['label' => 'Not Interested',   'class' => 'danger'],
                                                'callback_scheduled' => ['label' => 'Callback',         'class' => 'info'],
                                                'failed'             => ['label' => 'Failed',           'class' => 'danger'],
                                                'expert_requested'   => ['label' => 'Expert Req.',      'class' => 'warning'],
                                                'meeting_booked'     => ['label' => 'Meeting Booked',   'class' => 'success'],
                                            ];
                                            $s = $status_map[$call['ai_call_status']] ?? ['label' => ucfirst($call['ai_call_status']), 'class' => 'default'];
                                            ?>
                                            <span class="label label-<?php echo $s['class']; ?>">
                                                <?php echo $s['label']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $call['last_ai_call']
                                                ? date('d M Y H:i', strtotime($call['last_ai_call']))
                                                : '-'; ?>
                                        </td>
                                        <td class="text-center"><?php echo (int) $call['followup_count']; ?></td>
                                        <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                                            title="<?php echo htmlspecialchars($call['ai_call_summary'] ?? ''); ?>">
                                            <?php echo htmlspecialchars(mb_substr($call['ai_call_summary'] ?? '', 0, 60)) ?: '-'; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($call['call_recording_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($call['call_recording_url']); ?>"
                                                   target="_blank" class="btn btn-xs btn-default">
                                                    <i class="fa fa-play"></i> Play
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <!-- /recent calls -->

            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>

<script>
// ── Queue-loop calling ────────────────────────────────────────────────────────
(function () {
    var DELAY_MS   = <?php echo (int)$setting_delay_sec * 1000; ?>;
    var CSRF_NAME  = '<?php echo $this->security->get_csrf_token_name(); ?>';
    var CSRF_HASH  = '<?php echo $this->security->get_csrf_hash(); ?>';
    var CALL_URL   = '<?php echo admin_url('ai_calling/call_one_lead'); ?>';
    var PROVIDER   = '<?php echo $active_provider === 'twilio' ? 'Twilio' : 'Amarip'; ?>';

    var stopRequested = false;
    var totalCalled   = 0;
    var totalFailed   = 0;
    var totalStarted  = 0; // total leads at session start (first response tells us)

    var btn      = document.getElementById('btn-start-calling');
    var btnStop  = document.getElementById('btn-stop-calling');
    var progress = document.getElementById('calling-progress');
    var progBar  = document.getElementById('calling-progress-bar');
    var progText = document.getElementById('calling-progress-text');
    var progLabel= document.getElementById('calling-progress-label');
    var result   = document.getElementById('calling-result');
    var alertBox = document.getElementById('calling-alert');
    var log      = document.getElementById('calling-log');

    function appendLog(line) {
        log.textContent += line + '\n';
        log.scrollTop = log.scrollHeight;
    }

    function updateProgress(called, remaining) {
        if (totalStarted === 0) totalStarted = called + remaining;
        var done = totalStarted - remaining;
        var pct  = totalStarted > 0 ? Math.round((done / totalStarted) * 100) : 0;
        progBar.style.width   = pct + '%';
        progText.textContent  = pct + '%';
        progLabel.textContent = 'Called ' + done + ' of ' + totalStarted + ' leads — ' + remaining + ' remaining';
    }

    function finish(stopped) {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa fa-phone"></i> Start Calling Now'
                      + '<small style="display:block;font-size:11px;font-weight:normal;opacity:0.85;">via ' + PROVIDER + '</small>';
        btnStop.style.display = 'none';
        progBar.classList.remove('active');

        result.style.display = 'block';
        if (totalCalled > 0 || totalFailed > 0) {
            alertBox.className = totalFailed === 0 ? 'alert alert-success' : 'alert alert-warning';
            alertBox.innerHTML = (stopped ? '<strong>Stopped.</strong> ' : '<strong>Done!</strong> ')
                               + 'Called: <strong>' + totalCalled + '</strong>'
                               + ' &nbsp;|&nbsp; Failed: <strong>' + totalFailed + '</strong>';
        } else {
            alertBox.className = 'alert alert-info';
            alertBox.innerHTML = '<strong>No leads</strong> pending to call right now.';
        }

        setTimeout(function () { location.reload(); }, 3000);
    }

    function callNext() {
        if (stopRequested) { finish(true); return; }

        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);

        fetch(CALL_URL, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            // Update CSRF token for next request if server returned a fresh one
            if (data.csrf) { CSRF_HASH = data.csrf; }

            if (data.done && data.remaining === 0 && totalCalled === 0 && totalFailed === 0) {
                // Nothing to call at all
                finish(false);
                return;
            }

            if (data.success) {
                totalCalled++;
                appendLog('OK  | ' + data.lead_name + ' | ' + data.phone + ' | ' + data.call_id);
            } else if (!data.done) {
                totalFailed++;
                appendLog('ERR | ' + data.lead_name + ' | ' + data.phone + ' | ' + data.error);
            }

            if (data.done) {
                if (data.message) appendLog('--- ' + data.message);
                finish(false);
                return;
            }

            updateProgress(totalCalled + totalFailed, data.remaining);

            // Wait delay then fire next call
            setTimeout(callNext, DELAY_MS);
        })
        .catch(function (err) {
            appendLog('NET | Request error: ' + err.message);
            finish(false);
        });
    }

    btn.addEventListener('click', function () {
        stopRequested = false;
        totalCalled   = 0;
        totalFailed   = 0;
        totalStarted  = 0;

        btn.disabled  = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Calling…'
                      + '<small style="display:block;font-size:11px;font-weight:normal;opacity:0.85;">Queue running…</small>';
        btnStop.style.display = 'inline-block';

        progress.style.display = 'block';
        progBar.style.width    = '0%';
        progBar.classList.add('active');
        progText.textContent   = '0%';
        progLabel.textContent  = 'Starting…';

        result.style.display = 'block';
        alertBox.className   = 'alert alert-info';
        alertBox.innerHTML   = '<i class="fa fa-spinner fa-spin"></i> Queue running — do not close this tab.';
        log.textContent      = '';

        callNext();
    });

    btnStop.addEventListener('click', function () {
        stopRequested = true;
        btnStop.disabled  = true;
        btnStop.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Stopping…';
    });
})();

function copyCronUrl() {
    var el = document.getElementById('cron-url');
    el.select();
    document.execCommand('copy');
    alert('Cron URL copied!');
}

function copyWebhookUrl() {
    var el = document.getElementById('webhook-url');
    el.select();
    document.execCommand('copy');
    alert('Webhook URL copied!');
}

// ── Test call ─────────────────────────────────────────────────────────────────
document.getElementById('btn-test-call').addEventListener('click', function () {
    var btn    = this;
    var phone  = document.getElementById('test-call-phone').value.trim();
    var result = document.getElementById('test-call-result');

    if (!phone) { alert('Enter a phone number first.'); return; }

    btn.disabled   = true;
    btn.innerHTML  = '<i class="fa fa-spinner fa-spin"></i> Calling...';
    result.style.display = 'none';

    var csrfData = new FormData();
    csrfData.append('<?php echo $this->security->get_csrf_token_name(); ?>', '<?php echo $this->security->get_csrf_hash(); ?>');
    csrfData.append('phone', phone);

    fetch('<?php echo admin_url('ai_calling/test_call_number'); ?>', {
        method : 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body   : csrfData
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        result.style.display = 'block';
        if (data.success) {
            result.className = 'alert alert-success';
            result.innerHTML = '<i class="fa fa-check"></i> <strong>Call dispatched!</strong> '
                             + data.message + '<br>'
                             + '<small>Vapi Call ID: <code>' + data.call_id + '</code></small>';
        } else {
            result.className = 'alert alert-danger';
            result.innerHTML = '<i class="fa fa-times"></i> <strong>Failed:</strong> ' + data.message;
        }
    })
    .catch(function (err) {
        result.style.display = 'block';
        result.className     = 'alert alert-danger';
        result.innerHTML     = 'Error: ' + err.message;
    })
    .finally(function () {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa fa-phone"></i> Call Now<small style="display:block;font-size:10px;font-weight:normal;">via <strong><?php echo $active_provider === 'twilio' ? 'Twilio' : 'Amarip'; ?></strong></small>';
    });
});

// ── Settings form ─────────────────────────────────────────────────────────────
document.getElementById('form-settings').addEventListener('submit', function (e) {
    e.preventDefault();
    var form  = this;
    var alert = document.getElementById('settings-alert');
    var btn   = form.querySelector('button[type=submit]');

    btn.disabled   = true;
    btn.innerHTML  = '<i class="fa fa-spinner fa-spin"></i> Saving...';
    alert.style.display = 'none';

    var fd = new FormData(form);
    fd.append('<?php echo $this->security->get_csrf_token_name(); ?>', '<?php echo $this->security->get_csrf_hash(); ?>');

    fetch('<?php echo admin_url('ai_calling/save_settings'); ?>', {
        method : 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body   : fd
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        alert.style.display = 'block';
        alert.className     = data.success ? 'alert alert-success' : 'alert alert-danger';
        alert.innerHTML     = data.message;
    })
    .catch(function (err) {
        alert.style.display = 'block';
        alert.className     = 'alert alert-danger';
        alert.innerHTML     = 'Error: ' + err.message;
    })
    .finally(function () {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa fa-save"></i> Save Settings';
    });
});

// ── Provider switcher ─────────────────────────────────────────────────────────
document.querySelectorAll('.btn-switch-provider').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var provider     = this.dataset.provider;
        var providerAlert = document.getElementById('provider-alert');
        var allBtns      = document.querySelectorAll('.btn-switch-provider');

        allBtns.forEach(function (b) { b.disabled = true; });
        providerAlert.style.display = 'none';

        var csrfData = new FormData();
        csrfData.append('<?php echo $this->security->get_csrf_token_name(); ?>', '<?php echo $this->security->get_csrf_hash(); ?>');
        csrfData.append('provider', provider);

        fetch('<?php echo admin_url('ai_calling/switch_provider'); ?>', {
            method : 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body   : csrfData
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            providerAlert.style.display = 'block';
            if (data.success) {
                providerAlert.className = 'alert alert-success';
                providerAlert.innerHTML = '<i class="fa fa-check"></i> Switched to <strong>' + data.label + '</strong>. Reloading...';
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                providerAlert.className = 'alert alert-danger';
                providerAlert.innerHTML = '<i class="fa fa-times"></i> ' + data.message;
                allBtns.forEach(function (b) { b.disabled = false; });
            }
        })
        .catch(function (err) {
            providerAlert.style.display = 'block';
            providerAlert.className     = 'alert alert-danger';
            providerAlert.innerHTML     = 'Error: ' + err.message;
            allBtns.forEach(function (b) { b.disabled = false; });
        });
    });
});
</script>
