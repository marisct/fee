<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$from   = get('from', date('Y-m-01'));
$to     = get('to', date('Y-m-d'));
$q      = get('q');
$source = get('source');

$w = ['1 = 1'];
$p = [];
if ($from !== '' && strtotime($from)) {
    $w[] = 'p.paid_at >= ?';
    $p[] = date('Y-m-d 00:00:00', strtotime($from));
}
if ($to !== '' && strtotime($to)) {
    $w[] = 'p.paid_at <= ?';
    $p[] = date('Y-m-d 23:59:59', strtotime($to));
}
if ($source === 'online') {
    $w[] = "p.source <> 'manual'";
} elseif ($source === 'manual') {
    $w[] = "p.source = 'manual'";
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $w[]  = '(m.name LIKE ? OR m.member_code LIKE ? OR p.rzp_payment_id LIKE ? OR d.title LIKE ?)';
    array_push($p, $like, $like, $like, $like);
}
$where = implode(' AND ', $w);
$baseSql = 'FROM payments p JOIN members m ON m.id = p.member_id LEFT JOIN dues d ON d.id = p.due_id WHERE ' . $where;

if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Member', 'Code', 'Phone', 'Fee', 'Amount', 'Mode', 'Source', 'Payment ID', 'Note']);
    foreach (db_all("SELECT p.*, m.name AS member_name, m.member_code, m.phone, d.title $baseSql ORDER BY p.paid_at ASC", $p) as $r) {
        fputcsv($out, [$r['paid_at'], $r['member_name'], $r['member_code'], $r['phone'], $r['title'], $r['amount'], $r['method'], $r['source'], $r['rzp_payment_id'], $r['notes']]);
    }
    exit;
}

$pg = paginate("SELECT COUNT(*) $baseSql", $p, 100);
$rows = db_all("SELECT p.*, m.name AS member_name, m.member_code, d.title $baseSql ORDER BY p.paid_at DESC, p.id DESC LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $p);
$total = (float)db_val("SELECT COALESCE(SUM(p.amount),0) $baseSql AND p.status IN ('captured','authorized')", $p);

$pageTitle = 'Payments';
$pageSubtitle = (int)$pg['total'] . ' payments &middot; ' . e(money($total));
$active = 'payments';
$pageActions = '<a href="?' . e(http_build_query(array_merge($_GET, ['export' => 'csv']))) . '" class="btn btn-default"><i class="bi bi-download me-1"></i>Export CSV</a>';
require __DIR__ . '/includes/layout_top.php';
?>
<div class="card">
  <div class="list-filters">
    <form class="row g-2 filter-form" method="get">
      <div class="col-6 col-md-2"><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
      <div class="col-6 col-md-2"><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
      <div class="col-6 col-md-2">
        <select name="source" class="form-select">
          <option value="">Online + manual</option>
          <option value="online"<?= $source === 'online' ? ' selected' : '' ?>>Online (Razorpay)</option>
          <option value="manual"<?= $source === 'manual' ? ' selected' : '' ?>>Manual (cash/UPI)</option>
        </select>
      </div>
      <div class="col-6 col-md-4"><input name="q" class="form-control" placeholder="Member, payment ID, fee" value="<?= e($q) ?>"></div>
      <div class="col-12 col-md-2 d-grid"><button class="btn btn-default"><i class="bi bi-funnel me-1"></i>Filter</button></div>
    </form>
  </div>
  <div class="list-summary"><div><strong><?= (int)$pg['total'] ?></strong> payments between <?= e(fmt_date($from)) ?> and <?= e(fmt_date($to)) ?> &middot; total <strong style="color: var(--green-bright)"><?= e(money($total)) ?></strong></div></div>
  <div class="table-responsive">
    <table class="table table-sm table-hover">
      <thead><tr><th>Date</th><th>Member</th><th class="d-none d-md-table-cell">Fee</th><th>Mode</th><th class="d-none d-lg-table-cell">Reference</th><th class="text-end">Amount</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-cash-stack"></i>No payments in this period</div></td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= e(fmt_datetime($r['paid_at'])) ?></td>
          <td class="nowrap"><a href="member_view.php?id=<?= (int)$r['member_id'] ?>"><?= e($r['member_name']) ?></a><div class="sub"><?= e($r['member_code']) ?><span class="d-md-none"> &middot; <?= e($r['title'] ?? '') ?></span></div></td>
          <td class="d-none d-md-table-cell nowrap"><?= e($r['title'] ?? '-') ?></td>
          <td class="nowrap"><?= e($r['method'] ?? '-') ?><?php if ($r['source'] === 'manual'): ?> <span class="indicator-pill">manual</span><?php endif; ?></td>
          <td class="d-none d-lg-table-cell code text-secondary"><?= e($r['rzp_payment_id'] ?? ($r['notes'] ?? '')) ?></td>
          <td class="text-end nowrap fw-semibold" style="color: <?= in_array($r['status'], ['captured', 'authorized'], true) ? 'var(--green-bright)' : 'var(--text-light)' ?>"><?= e(money($r['amount'])) ?><?php if (!in_array($r['status'], ['captured', 'authorized'], true)): ?><div><?= status_badge($r['status']) ?></div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['pages'] > 1): ?><div class="card-footer d-flex justify-content-between align-items-center"><span class="small text-secondary">Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span><?= pagination_html($pg) ?></div><?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
