<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int)get('id');
$m  = $id ? db_row('SELECT * FROM members WHERE id = ?', [$id]) : null;
if (!$m) {
    flash('danger', 'Member not found.');
    redirect(app_url('members.php'));
}

$dues     = db_all('SELECT * FROM dues WHERE member_id = ? ORDER BY due_date DESC, id DESC', [$id]);
$payments = db_all('SELECT p.*, d.title FROM payments p LEFT JOIN dues d ON d.id = p.due_id WHERE p.member_id = ? ORDER BY p.paid_at DESC, p.id DESC LIMIT 50', [$id]);
$messages = db_all('SELECT * FROM messages WHERE member_id = ? ORDER BY id DESC LIMIT 30', [$id]);

$outstanding = 0.0;
$totalPaid = 0.0;
foreach ($dues as $d) {
    if (in_array($d['status'], ['pending', 'sent', 'partial'], true)) {
        $outstanding += (float)$d['amount'] - (float)$d['amount_paid'];
    }
}
foreach ($payments as $p) {
    if (in_array($p['status'], ['captured', 'authorized'], true)) {
        $totalPaid += (float)$p['amount'];
    }
}
$back = 'member_view.php?id=' . $id;

$pageTitle = $m['name'];
$pageSubtitle = e($m['member_code']) . ($m['batch'] ? ' &middot; ' . e($m['batch']) : '') . ' &nbsp;' . status_badge($m['status']);
$pageBack = 'members.php';
$active = 'members';
$pageActions = '<a href="member_form.php?id=' . $id . '" class="btn btn-default"><i class="bi bi-pencil me-1"></i>Edit</a>'
    . '<a href="due_form.php?member_id=' . $id . '" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add due</a>';
require __DIR__ . '/includes/layout_top.php';
?>
<div class="row g-3 mb-3">
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-header">Details</div>
      <div class="card-body">
        <div class="row g-2 mb-3">
          <div class="col-6"><div class="number-card p-3"><div class="nc-label">Outstanding</div><div class="nc-value nc-value-sm" style="color: <?= $outstanding > 0 ? 'var(--red)' : 'var(--green-bright)' ?>"><?= e(money($outstanding)) ?></div></div></div>
          <div class="col-6"><div class="number-card p-3"><div class="nc-label">Total paid</div><div class="nc-value nc-value-sm"><?= e(money($totalPaid)) ?></div></div></div>
        </div>
        <dl class="details mb-0">
          <dt>WhatsApp</dt><dd><a href="https://wa.me/<?= e($m['phone']) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp me-1" style="color:#22a65b"></i><?= e(pretty_phone($m['phone'])) ?></a></dd>
          <?php if ($m['alt_phone']): ?><dt>Alternate phone</dt><dd><?= e(pretty_phone($m['alt_phone'])) ?></dd><?php endif; ?>
          <?php if ($m['guardian_name']): ?><dt>Parent / guardian</dt><dd><?= e($m['guardian_name']) ?></dd><?php endif; ?>
          <?php if ($m['email']): ?><dt>Email</dt><dd><?= e($m['email']) ?></dd><?php endif; ?>
          <dt>Batch</dt><dd><?= e($m['batch'] ?: '-') ?></dd>
          <dt>Monthly fee</dt><dd><?= e(money($m['monthly_fee'])) ?></dd>
          <dt>Joined</dt><dd><?= e(fmt_date($m['join_date']) ?: '-') ?></dd>
          <?php if ($m['dob']): ?><dt>Date of birth</dt><dd><?= e(fmt_date($m['dob'])) ?></dd><?php endif; ?>
          <?php if ($m['gender']): ?><dt>Gender</dt><dd><?= e($m['gender']) ?></dd><?php endif; ?>
          <?php if ($m['notes']): ?><dt>Notes</dt><dd class="text-secondary"><?= nl2br(e($m['notes'])) ?></dd><?php endif; ?>
        </dl>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card h-100">
      <div class="card-header">Fee dues</div>
      <div class="table-responsive">
        <table class="table table-sm table-hover">
          <thead><tr><th>Fee</th><th class="text-end">Amount</th><th class="d-none d-md-table-cell text-end">Paid</th><th>Due date</th><th>Status</th><th class="d-none d-lg-table-cell">Link</th><th></th></tr></thead>
          <tbody>
          <?php if (!$dues): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-receipt"></i>No dues yet</div></td></tr><?php endif; ?>
          <?php foreach ($dues as $d): $open = in_array($d['status'], ['pending', 'sent', 'partial'], true); ?>
            <tr>
              <td class="nowrap"><?= e($d['title']) ?><?php if ($d['send_count']): ?><div class="sub">Sent <?= (int)$d['send_count'] ?>x &middot; last <?= e(fmt_datetime($d['last_sent_at'])) ?></div><?php endif; ?></td>
              <td class="text-end nowrap"><?= e(money($d['amount'])) ?></td>
              <td class="d-none d-md-table-cell text-end nowrap"><?= e(money($d['amount_paid'])) ?></td>
              <td class="nowrap"><?= e(fmt_date($d['due_date'])) ?></td>
              <td><?= status_badge(due_display_status($d)) ?></td>
              <td class="d-none d-lg-table-cell sub nowrap">
                <?php if ($d['rzp_link_id']): ?>
                  <?= status_badge((string)$d['rzp_status']) ?>
                  <?php if ($d['link_clicks']): ?><div><i class="bi bi-cursor"></i> opened <?= (int)$d['link_clicks'] ?>x</div><?php endif; ?>
                <?php else: ?>-<?php endif; ?>
              </td>
              <td class="row-actions text-end">
                <?php if ($open): ?>
                  <form method="post" action="due_action.php"><?= csrf_field() ?><input type="hidden" name="action" value="send"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                    <button class="btn btn-success" title="<?= $d['send_count'] ? 'Resend WhatsApp' : 'Send WhatsApp' ?>"><i class="bi bi-whatsapp"></i></button></form>
                  <button class="btn btn-default" title="Record cash / UPI payment" data-bs-toggle="modal" data-bs-target="#payModal" data-due="<?= (int)$d['id'] ?>" data-title="<?= e($d['title']) ?>" data-balance="<?= e(number_format((float)$d['amount'] - (float)$d['amount_paid'], 2, '.', '')) ?>"><i class="bi bi-cash"></i></button>
                  <?php if ($d['rzp_link_id']): ?>
                    <form method="post" action="due_action.php"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                      <button class="btn btn-default" title="Check payment status at Razorpay"><i class="bi bi-arrow-repeat"></i></button></form>
                  <?php endif; ?>
                  <form method="post" action="due_action.php" data-confirm="Cancel this due?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                    <button class="btn btn-outline-danger" title="Cancel due"><i class="bi bi-x-lg"></i></button></form>
                <?php elseif ($d['status'] === 'paid'): ?>
                  <span class="sub nowrap"><i class="bi bi-check2-circle" style="color: var(--green-bright)"></i> <?= e(fmt_date($d['paid_at'])) ?></span>
                <?php else: ?>
                  <form method="post" action="due_action.php" data-confirm="Delete this cancelled due permanently?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                    <button class="btn btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header">Payments</div>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Date</th><th>For</th><th>Mode</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php if (!$payments): ?><tr><td colspan="4"><div class="empty-state"><i class="bi bi-cash-stack"></i>No payments yet</div></td></tr><?php endif; ?>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td class="nowrap"><?= e(fmt_datetime($p['paid_at'])) ?></td>
              <td class="nowrap"><?= e($p['title'] ?? '-') ?><?php if ($p['rzp_payment_id']): ?><div class="sub code"><?= e($p['rzp_payment_id']) ?></div><?php endif; ?></td>
              <td><?= e($p['method'] ?? $p['source']) ?></td>
              <td class="text-end nowrap fw-semibold" style="color: var(--green-bright)"><?= e(money($p['amount'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header">WhatsApp messages</div>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Sent</th><th>Type</th><th>Status</th></tr></thead>
          <tbody>
          <?php if (!$messages): ?><tr><td colspan="3"><div class="empty-state"><i class="bi bi-whatsapp"></i>No messages yet</div></td></tr><?php endif; ?>
          <?php foreach ($messages as $msg): ?>
            <tr>
              <td class="nowrap"><?= e(fmt_datetime($msg['created_at'])) ?></td>
              <td class="nowrap"><?= e(ucfirst(str_replace('_', ' ', $msg['type']))) ?></td>
              <td><?= status_badge($msg['status']) ?><?php if ($msg['error']): ?><div class="sub" style="color: var(--red)"><?= e($msg['error']) ?></div><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
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
        <p class="mb-3 small">Fee: <strong id="payDueTitle"></strong></p>
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
</script>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
