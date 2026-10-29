<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$f = [
    'status' => get('status', 'unpaid'),
    'period' => get('period'),
    'batch'  => get('batch'),
    'q'      => get('q'),
];
[$where, $params] = dues_filter($f);

$pg = paginate("SELECT COUNT(*) FROM dues d JOIN members m ON m.id = d.member_id WHERE $where", $params, 100);
$rows = db_all(
    "SELECT d.*, m.name AS member_name, m.member_code, m.phone, m.batch
     FROM dues d JOIN members m ON m.id = d.member_id
     WHERE $where
     ORDER BY d.due_date DESC, m.name ASC LIMIT {$pg['limit']} OFFSET {$pg['offset']}",
    $params
);
$totals = db_row(
    "SELECT COUNT(*) AS n, COALESCE(SUM(d.amount),0) AS amount, COALESCE(SUM(d.amount_paid),0) AS paid,
            COALESCE(SUM(d.status IN ('pending','sent','partial')),0) AS open_count
     FROM dues d JOIN members m ON m.id = d.member_id WHERE $where",
    $params
);
$periods = array_column(db_all("SELECT DISTINCT period FROM dues WHERE period IS NOT NULL ORDER BY period DESC LIMIT 36"), 'period');

$thisMonth = date('Y-m');
$dueDay    = max(1, min(28, (int)setting('due_day', 5)));
$defaultDueDate = $thisMonth . '-' . str_pad((string)$dueDay, 2, '0', STR_PAD_LEFT);
$openGenerate = get('generate') === '1';
$filterQuery = http_build_query(array_filter($f, fn($v) => $v !== ''));
$back = 'dues.php' . ($filterQuery ? '?' . $filterQuery : '');

$pageTitle = 'Fee Dues';
$pageSubtitle = (int)$totals['n'] . ' dues &middot; balance ' . e(money($totals['amount'] - $totals['paid']));
$active = 'dues';
$pageActions = '<a href="due_form.php" class="btn btn-default"><i class="bi bi-plus-lg me-1"></i>Add due</a>'
    . '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#genModal"><i class="bi bi-calendar-plus me-1"></i>Generate monthly dues</button>';
require __DIR__ . '/includes/layout_top.php';
?>
<form method="post" action="due_action.php" id="bulkForm" data-once>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_send">
  <input type="hidden" name="back" value="<?= e($back) ?>">
  <input type="hidden" name="id" id="singleId" value="">
  <?php foreach ($f as $k => $v): ?><input type="hidden" name="filter[<?= e($k) ?>]" value="<?= e($v) ?>"><?php endforeach; ?>

  <div class="card">
    <div class="list-filters">
      <div class="row g-2 filter-form">
        <div class="col-6 col-md-2">
          <select name="status" class="form-select" form="filterForm">
            <?php foreach (['unpaid' => 'Unpaid (all)', 'pending' => 'Not sent yet', 'sent' => 'Sent, awaiting', 'overdue' => 'Overdue', 'partial' => 'Partially paid', 'paid' => 'Paid', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $label): ?>
              <option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <select name="period" class="form-select" form="filterForm">
            <option value="">All months</option>
            <?php foreach ($periods as $pd): ?><option value="<?= e($pd) ?>"<?= $f['period'] === $pd ? ' selected' : '' ?>><?= e(period_label($pd)) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <select name="batch" class="form-select" form="filterForm">
            <option value="">All batches</option>
            <?php foreach (batch_list() as $b): ?><option value="<?= e($b) ?>"<?= $b === $f['batch'] ? ' selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-4"><input name="q" class="form-control" placeholder="Search member / fee" value="<?= e($f['q']) ?>" form="filterForm"></div>
        <div class="col-12 col-md-2 d-grid"><button class="btn btn-default" form="filterForm"><i class="bi bi-funnel me-1"></i>Filter</button></div>
      </div>
    </div>
    <div class="list-summary">
      <div>
        <strong><?= (int)$totals['n'] ?></strong> dues &middot; total <strong><?= e(money($totals['amount'])) ?></strong> &middot; received <strong style="color: var(--green-bright)"><?= e(money($totals['paid'])) ?></strong> &middot; balance <strong style="color: var(--red)"><?= e(money($totals['amount'] - $totals['paid'])) ?></strong>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-success btn-sm" data-bulk-btn disabled><i class="bi bi-whatsapp me-1"></i>Send to selected (<span data-bulk-count>0</span>)</button>
        <?php if ((int)$totals['open_count'] > 0): ?>
          <button class="btn btn-outline-success btn-sm" name="action" value="send_filtered" data-confirm="Send a WhatsApp payment link to ALL <?= (int)$totals['open_count'] ?> unpaid dues in this list?"><i class="bi bi-send me-1"></i>Send to all <?= (int)$totals['open_count'] ?> unpaid</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover">
        <thead><tr>
          <th style="width:1%"><input type="checkbox" class="form-check-input" data-check-all=".row-check" title="Select all"></th>
          <th>Member</th>
          <th>Fee</th>
          <th class="text-end">Amount</th>
          <th class="text-end d-none d-md-table-cell">Balance</th>
          <th>Due date</th>
          <th>Status</th>
          <th class="d-none d-lg-table-cell">WhatsApp</th>
          <th></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-receipt"></i>No dues match this filter</div></td></tr><?php endif; ?>
        <?php foreach ($rows as $d): $open = in_array($d['status'], ['pending', 'sent', 'partial'], true); ?>
          <tr>
            <td><?php if ($open): ?><input type="checkbox" class="form-check-input row-check" name="ids[]" value="<?= (int)$d['id'] ?>"><?php endif; ?></td>
            <td class="nowrap">
              <a href="member_view.php?id=<?= (int)$d['member_id'] ?>"><?= e($d['member_name']) ?></a>
              <div class="sub"><?= e($d['member_code']) ?><?= $d['batch'] ? ' &middot; ' . e($d['batch']) : '' ?></div>
            </td>
            <td class="nowrap"><?= e($d['title']) ?></td>
            <td class="text-end nowrap"><?= e(money($d['amount'])) ?></td>
            <td class="text-end nowrap d-none d-md-table-cell"><?= e(money($d['amount'] - $d['amount_paid'])) ?></td>
            <td class="nowrap"><?= e(fmt_date($d['due_date'])) ?></td>
            <td><?= status_badge(due_display_status($d)) ?></td>
            <td class="d-none d-lg-table-cell sub">
              <?php if ($d['send_count']): ?>
                Sent <?= (int)$d['send_count'] ?>x &middot; <?= e(fmt_datetime($d['last_sent_at'])) ?>
                <?php if ($d['link_clicks']): ?><br><i class="bi bi-cursor"></i> link opened<?php endif; ?>
              <?php else: ?>-<?php endif; ?>
            </td>
            <td class="row-actions text-end">
              <?php if ($open): ?>
                <button class="btn btn-success" name="action" value="send" title="<?= $d['send_count'] ? 'Resend' : 'Send' ?> WhatsApp" onclick="document.getElementById('singleId').value='<?= (int)$d['id'] ?>'"><i class="bi bi-whatsapp"></i></button>
                <button type="button" class="btn btn-default" title="Record cash / UPI payment" data-bs-toggle="modal" data-bs-target="#payModal" data-due="<?= (int)$d['id'] ?>" data-title="<?= e($d['member_name'] . ' - ' . $d['title']) ?>" data-balance="<?= e(number_format((float)$d['amount'] - (float)$d['amount_paid'], 2, '.', '')) ?>"><i class="bi bi-cash"></i></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pg['pages'] > 1): ?><div class="card-footer d-flex justify-content-between align-items-center"><span class="small text-secondary">Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span><?= pagination_html($pg) ?></div><?php endif; ?>
  </div>
</form>
<form id="filterForm" method="get" action="dues.php"></form>

<!-- Generate monthly dues -->
<div class="modal fade" id="genModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" action="due_action.php" class="modal-content" data-once>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="generate">
      <div class="modal-header"><h5 class="modal-title">Generate monthly dues</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="small text-secondary">Creates one due per <strong>active</strong> member using their monthly fee. Members who already have a due for that month are skipped, so it is safe to run again.</p>
        <div class="mb-3"><label class="form-label">Month</label><input type="month" name="period" class="form-control" required value="<?= e($thisMonth) ?>"></div>
        <div class="mb-3"><label class="form-label">Due date</label><input type="date" name="due_date" class="form-control" required value="<?= e($defaultDueDate) ?>"></div>
        <div class="mb-0"><label class="form-label">Batch (optional)</label>
          <select name="batch" class="form-select"><option value="">All batches</option><?php foreach (batch_list() as $b): ?><option value="<?= e($b) ?>"><?= e($b) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Close</button><button class="btn btn-primary">Generate</button></div>
    </form>
  </div>
</div>

<!-- Manual payment modal -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" action="due_action.php" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mark_paid">
      <input type="hidden" name="id" id="payDueId" value="">
      <input type="hidden" name="back" value="<?= e($back) ?>">
      <div class="modal-header"><h5 class="modal-title">Record payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="mb-3 small"><strong id="payDueTitle"></strong></p>
        <div class="mb-3"><label class="form-label">Amount received (₹)</label><input name="amount" id="payAmount" class="form-control" inputmode="decimal" required></div>
        <div class="mb-3"><label class="form-label">Payment mode</label>
          <select name="mode" class="form-select"><option value="cash">Cash</option><option value="upi">UPI</option><option value="bank">Bank transfer</option><option value="card">Card</option><option value="other">Other</option></select></div>
        <div class="mb-3"><label class="form-label">Date</label><input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="mb-0"><label class="form-label">Note (optional)</label><input name="notes" class="form-control" maxlength="255"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Close</button><button class="btn btn-primary">Save payment</button></div>
    </form>
  </div>
</div>
<script>
document.getElementById('payModal').addEventListener('show.bs.modal', function (ev) {
  var b = ev.relatedTarget;
  document.getElementById('payDueId').value = b.getAttribute('data-due');
  document.getElementById('payDueTitle').textContent = b.getAttribute('data-title');
  document.getElementById('payAmount').value = b.getAttribute('data-balance');
});
<?php if ($openGenerate): ?>
document.addEventListener('DOMContentLoaded', function () { new bootstrap.Modal(document.getElementById('genModal')).show(); });
<?php endif; ?>
</script>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
