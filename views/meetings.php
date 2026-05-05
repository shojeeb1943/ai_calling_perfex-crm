<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
// Defensive defaults — never let a missing var fatal the page
$meetings      = isset($meetings)      && is_array($meetings)      ? $meetings      : [];
$meeting_stats = isset($meeting_stats) && is_array($meeting_stats) ? $meeting_stats : ['total' => 0, 'today' => 0];
$meeting_stats['total'] = $meeting_stats['total'] ?? 0;
$meeting_stats['today'] = $meeting_stats['today'] ?? 0;
$load_error    = isset($load_error) ? $load_error : null;
$module_ver    = defined('AI_CALLING_VERSION') ? AI_CALLING_VERSION : '';
?>

<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">

                <!-- Page header -->
                <div class="page-heading">
                    <h3 class="no-margin">
                        <i class="fa fa-calendar-check-o text-success"></i>
                        Meeting Bookings
                        <?php if ($module_ver): ?>
                        <small class="text-muted" style="font-size:13px;margin-left:8px;">v<?php echo htmlspecialchars($module_ver); ?></small>
                        <?php endif; ?>
                    </h3>
                    <small class="text-muted">Clients who booked a meeting during an AI call</small>
                </div>
                <hr class="hr-panel-heading" />

                <?php if (!empty($load_error)): ?>
                <div class="alert alert-warning">
                    <strong>Could not load meetings:</strong>
                    <?php echo htmlspecialchars($load_error); ?>
                    &nbsp;<a href="<?php echo admin_url('ai_calling/meetings?debug=1'); ?>">Run diagnostics</a>
                </div>
                <?php endif; ?>

                <!-- Sub-nav -->
                <ul class="nav nav-tabs" style="margin-bottom:20px;">
                    <li>
                        <a href="<?php echo admin_url('ai_calling'); ?>">
                            <i class="fa fa-tachometer"></i> Dashboard
                        </a>
                    </li>
                    <li class="active">
                        <a href="<?php echo admin_url('ai_calling/meetings'); ?>">
                            <i class="fa fa-calendar-check-o"></i> Meetings
                        </a>
                    </li>
                </ul>

                <!-- Stat cards -->
                <div class="row">
                    <div class="col-md-3 col-sm-6">
                        <div class="panel panel-default">
                            <div class="panel-body text-center">
                                <h2 class="text-success no-margin"><?php echo (int) $meeting_stats['total']; ?></h2>
                                <small>Total Meetings Booked</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="panel panel-default">
                            <div class="panel-body text-center">
                                <h2 class="text-primary no-margin"><?php echo (int) $meeting_stats['today']; ?></h2>
                                <small>Booked Today</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Meetings table -->
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <i class="fa fa-list"></i>
                            Meeting Bookings List
                            <span class="badge"><?php echo count($meetings); ?></span>
                        </h4>
                    </div>
                    <div class="panel-body no-padding">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover no-margin" id="meetings-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Client Name</th>
                                        <th>Phone</th>
                                        <th>Booked At</th>
                                        <th>Notes</th>
                                        <th>Call Transcript</th>
                                        <th>CRM Lead</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($meetings)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted" style="padding:30px;">
                                            <i class="fa fa-calendar-o" style="font-size:32px; opacity:.3; display:block; margin-bottom:8px;"></i>
                                            No meeting bookings yet. They will appear here when a client books a meeting during a call.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($meetings as $i => $m): ?>
                                    <?php
                                        if (!is_array($m)) { continue; }
                                        $lead_name  = $m['lead_name']  ?? 'Unknown';
                                        $lead_phone = $m['lead_phone'] ?? '';
                                        $created_at = $m['created_at'] ?? null;
                                        $booking_notes = $m['booking_notes'] ?? '';
                                        $row_id     = $m['id'] ?? $i;

                                        // Full transcript: prefer lead's ai_call_summary, fall back to booking_notes
                                        $transcript = !empty($m['lead_transcript'])
                                            ? $m['lead_transcript']
                                            : $booking_notes;
                                        $recording  = $m['lead_recording_url'] ?? null;
                                        $modal_id   = 'transcript-modal-' . (int) $row_id;
                                        $when       = $created_at ? date('d M Y H:i', strtotime($created_at)) : '-';
                                    ?>
                                    <tr>
                                        <td class="text-muted" style="width:50px;"><?php echo $i + 1; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars((string) $lead_name); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars((string) $lead_phone); ?></td>
                                        <td><?php echo htmlspecialchars($when); ?></td>
                                        <td style="max-width:200px;">
                                            <?php if (!empty($booking_notes)): ?>
                                                <span class="text-muted" style="font-size:12px; display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                                                      title="<?php echo htmlspecialchars((string) $booking_notes); ?>">
                                                    <?php echo htmlspecialchars(mb_substr((string) $booking_notes, 0, 60)); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($transcript)): ?>
                                                <button type="button" class="btn btn-xs btn-info"
                                                        data-toggle="modal" data-target="#<?php echo $modal_id; ?>">
                                                    <i class="fa fa-file-text-o"></i> View
                                                </button>

                                                <!-- Transcript Modal -->
                                                <div class="modal fade" id="<?php echo $modal_id; ?>" tabindex="-1" role="dialog">
                                                    <div class="modal-dialog modal-lg" role="document">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                                <h4 class="modal-title">
                                                                    <i class="fa fa-file-text-o"></i>
                                                                    Call Transcript &mdash; <?php echo htmlspecialchars((string) $lead_name); ?>
                                                                    <small class="text-muted"><?php echo htmlspecialchars($when); ?></small>
                                                                </h4>
                                                            </div>
                                                            <div class="modal-body">
                                                                <?php if (!empty($recording)): ?>
                                                                <div style="margin-bottom:14px;">
                                                                    <audio controls style="width:100%;">
                                                                        <source src="<?php echo htmlspecialchars((string) $recording); ?>">
                                                                        Your browser does not support audio playback.
                                                                    </audio>
                                                                </div>
                                                                <?php endif; ?>
                                                                <pre style="white-space:pre-wrap; word-break:break-word; background:#f8f8f8; padding:14px; border-radius:4px; font-size:13px; max-height:420px; overflow-y:auto;"><?php echo htmlspecialchars((string) $transcript); ?></pre>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <?php if (!empty($recording)): ?>
                                                                <a href="<?php echo htmlspecialchars((string) $recording); ?>" target="_blank"
                                                                   class="btn btn-default pull-left">
                                                                    <i class="fa fa-download"></i> Download Recording
                                                                </a>
                                                                <?php endif; ?>
                                                                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($m['crm_lead_id'])): ?>
                                                <a href="<?php echo admin_url('leads/index/' . $m['crm_lead_id']); ?>"
                                                   class="btn btn-xs btn-default" target="_blank">
                                                    <i class="fa fa-external-link"></i> CRM
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

            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
