<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">

                <!-- Page header -->
                <div class="page-heading">
                    <h3 class="no-margin">
                        <i class="fa fa-calendar-check-o text-success"></i>
                        <?php echo _l('ai_calling_meetings'); ?>
                        <small class="text-muted" style="font-size:13px;margin-left:8px;">v<?php echo AI_CALLING_VERSION; ?></small>
                    </h3>
                    <small class="text-muted"><?php echo _l('ai_calling_meetings_sub'); ?></small>
                </div>
                <hr class="hr-panel-heading" />

                <!-- Sub-nav -->
                <ul class="nav nav-tabs" style="margin-bottom:20px;">
                    <li>
                        <a href="<?php echo admin_url('ai_calling'); ?>">
                            <i class="fa fa-tachometer"></i> <?php echo _l('ai_calling_dashboard'); ?>
                        </a>
                    </li>
                    <li class="active">
                        <a href="<?php echo admin_url('ai_calling/meetings'); ?>">
                            <i class="fa fa-calendar-check-o"></i> <?php echo _l('ai_calling_meetings'); ?>
                        </a>
                    </li>
                </ul>

                <!-- Stat cards -->
                <div class="row">
                    <div class="col-md-3 col-sm-6">
                        <div class="panel panel-default">
                            <div class="panel-body text-center">
                                <h2 class="text-success no-margin"><?php echo $meeting_stats['total']; ?></h2>
                                <small><?php echo _l('ai_calling_meetings_total'); ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="panel panel-default">
                            <div class="panel-body text-center">
                                <h2 class="text-primary no-margin"><?php echo $meeting_stats['today']; ?></h2>
                                <small><?php echo _l('ai_calling_meetings_today'); ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Meetings table -->
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <i class="fa fa-list"></i>
                            <?php echo _l('ai_calling_meetings_list'); ?>
                            <span class="badge"><?php echo count($meetings); ?></span>
                        </h4>
                    </div>
                    <div class="panel-body no-padding">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover no-margin" id="meetings-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th><?php echo _l('ai_calling_col_lead'); ?></th>
                                        <th><?php echo _l('ai_calling_col_phone'); ?></th>
                                        <th><?php echo _l('ai_calling_col_booked_at'); ?></th>
                                        <th><?php echo _l('ai_calling_col_notes'); ?></th>
                                        <th><?php echo _l('ai_calling_col_crm_link'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($meetings)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted" style="padding:30px;">
                                            <i class="fa fa-calendar-o" style="font-size:32px; opacity:.3; display:block; margin-bottom:8px;"></i>
                                            <?php echo _l('ai_calling_no_meetings'); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($meetings as $i => $m): ?>
                                    <tr>
                                        <td class="text-muted" style="width:50px;"><?php echo $i + 1; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($m['lead_name']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($m['lead_phone']); ?></td>
                                        <td>
                                            <?php echo date('d M Y H:i', strtotime($m['created_at'])); ?>
                                        </td>
                                        <td style="max-width:300px;">
                                            <?php if (!empty($m['booking_notes'])): ?>
                                                <span class="text-muted" style="font-size:12px; display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                                                      title="<?php echo htmlspecialchars($m['booking_notes']); ?>">
                                                    <?php echo htmlspecialchars(mb_substr($m['booking_notes'], 0, 80)); ?>
                                                </span>
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
