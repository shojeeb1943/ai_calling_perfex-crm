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
                                           placeholder="e.g. 01792445543 or +8801792445543"
                                           value="01792445543" />
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
                                    Calls up to <strong><?php echo AI_MAX_CALLS_PER_RUN; ?></strong> pending leads now via
                                    <strong><?php echo $active_provider === 'twilio' ? 'Twilio' : 'Amarip SIP'; ?></strong>.
                                    Max <strong><?php echo AI_MAX_FOLLOWUPS; ?></strong> attempts per lead,
                                    followup in <strong><?php echo AI_FOLLOWUP_DAYS; ?></strong> days.
                                </p>
                            </div>
                            <div class="col-md-4 text-right">
                                <button id="btn-start-calling" class="btn btn-<?php echo $active_provider === 'twilio' ? 'info' : 'success'; ?> btn-lg">
                                    <i class="fa fa-phone"></i> Start Calling Now
                                    <small style="display:block; font-size:11px; font-weight:normal; opacity:0.85;">
                                        via <?php echo $active_provider === 'twilio' ? 'Twilio' : 'Amarip'; ?>
                                    </small>
                                </button>
                            </div>
                        </div>

                        <!-- Result area -->
                        <div id="calling-result" style="display:none; margin-top:15px;">
                            <div id="calling-alert" class="alert"></div>
                            <pre id="calling-log" style="max-height:200px; overflow-y:auto; background:#f5f5f5; padding:10px; border-radius:4px; font-size:12px;"></pre>
                        </div>
                    </div>
                </div>

                <!-- ── Cron / Webhook info ───────────────────────────────── -->
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">Automation Setup</h4>
                    </div>
                    <div class="panel-body">
                        <p><strong>Hostinger Cron URL</strong> (set to run daily at 9:00 AM):</p>
                        <div class="input-group">
                            <input type="text" class="form-control" id="cron-url"
                                   value="<?php echo base_url('admin/ai_calling/cron/VapiCron2024Secure'); ?>"
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
document.getElementById('btn-start-calling').addEventListener('click', function () {
    var btn    = this;
    var result = document.getElementById('calling-result');
    var alert  = document.getElementById('calling-alert');
    var log    = document.getElementById('calling-log');

    btn.disabled    = true;
    btn.innerHTML   = '<i class="fa fa-spinner fa-spin"></i> Calling...';
    result.style.display = 'none';

    var csrfData = new FormData();
    csrfData.append('<?php echo $this->security->get_csrf_token_name(); ?>', '<?php echo $this->security->get_csrf_hash(); ?>');

    fetch('<?php echo admin_url('ai_calling/start_calling'); ?>', {
        method : 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body   : csrfData
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        result.style.display = 'block';

        if (data.called > 0 || data.total === 0) {
            alert.className = 'alert alert-success';
            alert.innerHTML = '<strong>Done!</strong> Called: ' + data.called +
                              ' | Failed: ' + data.failed +
                              ' | Total: ' + data.total +
                              (data.message ? ' — ' + data.message : '');
        } else {
            alert.className = 'alert alert-warning';
            alert.innerHTML = '<strong>Warning:</strong> ' + (data.message || 'Check logs for details.');
        }

        if (data.log && data.log.length > 0) {
            log.style.display = 'block';
            log.textContent   = data.log.join('\n');
        } else {
            log.style.display = 'none';
        }

        // Refresh page after 3s to update stats
        setTimeout(function () { location.reload(); }, 3000);
    })
    .catch(function (err) {
        result.style.display = 'block';
        alert.className      = 'alert alert-danger';
        alert.innerHTML      = '<strong>Error:</strong> ' + err.message;
    })
    .finally(function () {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa fa-phone"></i> Start Calling Now';
    });
});

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
