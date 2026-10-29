<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$today     = date('Y-m-d');
$thisMonth = date('Y-m');
$nextMonthStart = date('Y-m-01', strtotime('first day of +1 month'));
$lastMonthStart = date('Y-m-01', strtotime('first day of -1 month'));

$activeMembers  = (int)db_val("SELECT COUNT(*) FROM members WHERE status = 'active'");
$collectedMonth = (float)db_val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status IN ('captured','authorized') AND paid_at >= ? AND paid_at < ?", [$thisMonth . '-01', $nextMonthStart]);
$collectedLast  = (float)db_val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status IN ('captured','authorized') AND paid_at >= ? AND paid_at < ?", [$lastMonthStart, $thisMonth . '-01']);
$outstanding    = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(amount - amount_paid),0) AS total FROM dues WHERE status IN ('pending','sent','partial')");
$overdue        = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(amount - amount_paid),0) AS total FROM dues WHERE status IN ('pending','sent','partial') AND due_date < ?", [$today]);
$notSent        = (int)db_val("SELECT COUNT(*) FROM dues WHERE status = 'pending'");
$failedMsgs     = (int)db_val("SELECT COUNT(*) FROM messages WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$monthDues      = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(status = 'paid'),0) AS paid FROM dues WHERE period = ?", [$thisMonth]);
$paymentsMonth  = (int)db_val("SELECT COUNT(*) FROM payments WHERE status IN ('captured','authorized') AND paid_at >= ? AND paid_at < ?", [$thisMonth . '-01', $nextMonthStart]);

$delta = null;
if ($collectedLast > 0) {
    $delta = ($collectedMonth - $collectedLast) / $collectedLast * 100;
}

$recentPayments = db_all(
    "SELECT p.*, m.name AS member_name, m.member_code, d.title
     FROM payments p JOIN members m ON m.id = p.member_id LEFT JOIN dues d ON d.id = p.due_id
     ORDER BY p.paid_at DESC, p.id DESC LIMIT 8"
);
$dueSoon = db_all(
    "SELECT d.*, m.name AS member_name, m.member_code, m.phone
     FROM dues d JOIN members m ON m.id = d.member_id
     WHERE d.status IN ('pending','sent','partial')
     ORDER BY d.due_date ASC, d.id ASC LIMIT 8"
);

// Collection for the last 6 months (chart)
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[date('Y-m', strtotime("first day of -$i month"))] = 0.0;
}
$firstMonth = array_key_first($months);
foreach (db_all("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym, SUM(amount) AS total FROM payments WHERE status IN ('captured','authorized') AND paid_at >= ? GROUP BY ym", [$firstMonth . '-01']) as $r) {
    if (isset($months[$r['ym']])) {
        $months[$r['ym']] = (float)$r['total'];
    }
}
$maxMonth  = max(1.0, max($months));
$chartData = [
    'labels' => array_map(fn($ym) => date(substr($ym, 5) === '01' ? 'M y' : 'M', strtotime($ym . '-01')), array_keys($months)),
    'values' => array_values($months),
];

$setupMissing = [];
if (!setting('rzp_key_id') || !setting('rzp_key_secret')) {
    $setupMissing[] = 'Razorpay API keys';
}
if (!setting('rzp_webhook_secret')) {
    $setupMissing[] = 'Razorpay webhook secret';
}
if (!setting('wa_token') || !setting('wa_phone_number_id')) {
    $setupMissing[] = 'WhatsApp API token / phone number ID';
}

$pageTitle = 'Dashboard';
$pageSubtitle = date('l, d M Y');
$active = 'dashboard';
$useCharts = true;
$pageActions = '<a href="dues.php?generate=1" class="btn btn-primary"><i class="bi bi-calendar-plus me-1"></i>Generate ' . e(period_label($thisMonth)) . ' dues</a>'
    . '<a href="dues.php?status=unpaid" class="btn btn-default"><i class="bi bi-whatsapp me-1"></i>Send fee links</a>'
    . '<a href="member_form.php" class="btn btn-default d-none d-md-inline-flex"><i class="bi bi-person-plus me-1"></i>Add member</a>';
require __DIR__ . '/includes/layout_top.php';
?>
<?php if ($setupMissing): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i><strong>Setup incomplete:</strong> <?= e(implode(', ', $setupMissing)) ?>. <a href="settings.php" class="alert-link">Open Settings</a></div>
<?php endif; ?>
<?php if ($failedMsgs > 0): ?>
  <div class="alert alert-danger"><i class="bi bi-whatsapp me-1"></i><?= $failedMsgs ?> WhatsApp message(s) failed in the last 7 days. <a href="messages.php?status=failed" class="alert-link">View log</a></div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3">
    <div class="number-card">
      <div class="nc-label">Active members <i class="bi bi-people"></i></div>
      <div class="nc-value"><?= $activeMembers ?></div>
      <div class="nc-sub"><?= (int)$monthDues['paid'] ?> of <?= (int)$monthDues['n'] ?> paid for <?= e(period_label($thisMonth)) ?></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="number-card">
      <div class="nc-label">Collected this month <i class="bi bi-cash-coin"></i></div>
      <div class="nc-value"><?= e(money($collectedMonth)) ?></div>
      <div class="nc-sub">
        <?php if ($delta !== null): ?>
          <span class="nc-delta <?= $delta >= 0 ? 'up' : 'down' ?>"><i class="bi bi-arrow-<?= $delta >= 0 ? 'up' : 'down' ?>-short"></i><?= number_format(abs($delta), 1) ?>%</span> vs last month
        <?php else: ?><?= $paymentsMonth ?> payment(s) this month<?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="number-card">
      <div class="nc-label">Outstanding <i class="bi bi-hourglass-split"></i></div>
      <div class="nc-value"><?= e(money($outstanding['total'])) ?></div>
      <div class="nc-sub"><?= (int)$outstanding['n'] ?> dues &middot; <?= $notSent ?> not sent yet</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="number-card">
      <div class="nc-label">Overdue <i class="bi bi-exclamation-circle"></i></div>
      <div class="nc-value <?= (int)$overdue['n'] > 0 ? 'text-danger' : '' ?>"><?= e(money($overdue['total'])) ?></div>
      <div class="nc-sub"><?= (int)$overdue['n'] ?> dues past due date</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center"><span>Collection - last 6 months</span><a href="payments.php" class="small fw-normal">All payments</a></div>
      <div class="card-body">
        <div class="chart-wrap" id="collectionChart" data-chart='<?= e(json_encode($chartData)) ?>' data-fallback="chartFallback"></div>
        <div id="chartFallback" class="chart-fallback">
          <?php foreach ($months as $ym => $total): ?>
            <div class="d-flex align-items-center mb-2 small">
              <div style="width: 70px;"><?= e(period_label($ym)) ?></div>
              <div class="flex-grow-1 bar-track me-2"><div class="bar" style="width: <?= (int)round($total / $maxMonth * 100) ?>%"></div></div>
              <div style="width: 90px;" class="text-end"><?= e(money($total)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Shortcuts</div>
      <div class="card-body p-2">
        <a class="shortcut" href="dues.php?status=pending"><i class="bi bi-send"></i>Dues not sent yet<?php if ($notSent): ?><span class="count indicator-pill blue"><?= $notSent ?></span><?php endif; ?></a>
        <a class="shortcut" href="dues.php?status=overdue"><i class="bi bi-exclamation-circle"></i>Overdue dues<?php if ($overdue['n']): ?><span class="count indicator-pill red"><?= (int)$overdue['n'] ?></span><?php endif; ?></a>
        <a class="shortcut" href="dues.php?status=paid&period=<?= e($thisMonth) ?>"><i class="bi bi-check2-circle"></i>Paid this month<span class="count indicator-pill green"><?= (int)$monthDues['paid'] ?></span></a>
        <a class="shortcut" href="members.php"><i class="bi bi-people"></i>Members<span class="count indicator-pill"><?= $activeMembers ?></span></a>
        <a class="shortcut" href="messages.php?status=failed"><i class="bi bi-whatsapp"></i>Failed messages (7 days)<?php if ($failedMsgs): ?><span class="count indicator-pill red"><?= $failedMsgs ?></span><?php endif; ?></a>
        <a class="shortcut" href="due_form.php"><i class="bi bi-plus-circle"></i>Add a one-off charge</a>
        <a class="shortcut" href="settings.php"><i class="bi bi-gear"></i>Settings</a>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center"><span>Upcoming &amp; overdue dues</span><a href="dues.php?status=unpaid" class="small fw-normal">View all</a></div>
      <div class="table-responsive">
        <table class="table table-sm table-hover">
          <thead><tr><th>Member</th><th>Fee</th><th class="text-end">Balance</th><th>Due</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php if (!$dueSoon): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-check2-circle"></i>No unpaid dues</div></td></tr><?php endif; ?>
          <?php foreach ($dueSoon as $d): ?>
            <tr>
              <td class="nowrap"><a href="member_view.php?id=<?= (int)$d['member_id'] ?>"><?= e($d['member_name']) ?></a><div class="sub d-none d-md-block"><?= e($d['member_code']) ?></div></td>
              <td class="nowrap"><?= e($d['title']) ?></td>
              <td class="text-end nowrap"><?= e(money($d['amount'] - $d['amount_paid'])) ?></td>
              <td class="nowrap"><?= e(fmt_date($d['due_date'])) ?></td>
              <td><?= status_badge(due_display_status($d)) ?></td>
              <td class="row-actions text-end">
                <form method="post" action="due_action.php"><?= csrf_field() ?><input type="hidden" name="action" value="send"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="back" value="index.php">
                  <button class="btn btn-success" title="Send WhatsApp"><i class="bi bi-whatsapp"></i></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center"><span>Recent payments</span><a href="payments.php" class="small fw-normal">View all</a></div>
      <ul class="list-group list-group-flush">
        <?php if (!$recentPayments): ?><li class="list-group-item"><div class="empty-state"><i class="bi bi-cash-stack"></i>No payments yet</div></li><?php endif; ?>
        <?php foreach ($recentPayments as $p): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div class="min-w-0">
              <div class="fw-medium text-truncate"><?= e($p['member_name']) ?></div>
              <div class="sub text-secondary"><?= e($p['title'] ?? 'Payment') ?> &middot; <?= e(fmt_datetime($p['paid_at'])) ?> &middot; <?= e($p['method'] ?? $p['source']) ?></div>
            </div>
            <span class="fw-semibold nowrap ms-2" style="color: var(--green-bright)"><?= e(money($p['amount'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
