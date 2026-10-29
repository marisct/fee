<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$memberId = (int)get('member_id');
$members  = db_all("SELECT id, name, member_code, batch FROM members WHERE status = 'active' ORDER BY name");
$errors   = [];
$data = [
    'target'    => $memberId ? 'member' : 'member',
    'member_id' => $memberId,
    'batch'     => '',
    'title'     => '',
    'amount'    => '',
    'due_date'  => date('Y-m-d', strtotime('+7 days')),
    'notes'     => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (array_keys($data) as $k) {
        $data[$k] = post($k);
    }
    $data['member_id'] = (int)$data['member_id'];
    if ($data['title'] === '') {
        $errors[] = 'Fee title is required.';
    }
    if (!is_numeric($data['amount']) || (float)$data['amount'] <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    }
    if (!$data['due_date'] || !strtotime($data['due_date'])) {
        $errors[] = 'Due date is required.';
    }
    $targets = [];
    if ($data['target'] === 'member') {
        if ($data['member_id'] <= 0) {
            $errors[] = 'Please choose a member.';
        } else {
            $targets = [$data['member_id']];
        }
    } elseif ($data['target'] === 'batch') {
        if ($data['batch'] === '') {
            $errors[] = 'Please choose a batch.';
        } else {
            $targets = array_column(db_all("SELECT id FROM members WHERE status = 'active' AND batch = ?", [$data['batch']]), 'id');
        }
    } else {
        $targets = array_column(db_all("SELECT id FROM members WHERE status = 'active'"), 'id');
    }
    if (!$errors && !$targets) {
        $errors[] = 'No active members match that selection.';
    }
    if (!$errors) {
        $n = 0;
        foreach ($targets as $mid) {
            db_insert('dues', [
                'member_id'  => $mid,
                'title'      => $data['title'],
                'period'     => null,
                'amount'     => round((float)$data['amount'], 2),
                'due_date'   => date('Y-m-d', strtotime($data['due_date'])),
                'status'     => 'pending',
                'notes'      => $data['notes'] ?: null,
                'created_by' => current_user()['id'],
            ]);
            $n++;
        }
        flash('success', $n === 1 ? 'Due added. You can now send the payment link.' : "$n dues added. Open Fee Dues to send the payment links.");
        redirect(app_url($data['target'] === 'member' ? 'member_view.php?id=' . $data['member_id'] : 'dues.php?status=pending'));
    }
}

$pageTitle = 'New Fee Due';
$pageSubtitle = 'One-off charge or custom amount';
$pageBack = $memberId ? 'member_view.php?id=' . $memberId : 'dues.php';
$active = 'dues';
$pageActions = '<a href="' . e($pageBack) . '" class="btn btn-default">Cancel</a><button class="btn btn-primary" form="dueForm"><i class="bi bi-check2 me-1"></i>Save</button>';
require __DIR__ . '/includes/layout_top.php';
?>
<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="card" id="dueForm" style="max-width: 760px;">
  <div class="card-body">
    <?= csrf_field() ?>
    <p class="small text-secondary">Use this for one-off charges (registration, gloves, tournament fee) or a custom amount. For regular monthly fees use <strong>Generate monthly dues</strong> on the Fee Dues page.</p>
    <div class="mb-3">
      <label class="form-label d-block">Charge to</label>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="target" id="tMember" value="member"<?= $data['target'] === 'member' ? ' checked' : '' ?>><label class="form-check-label" for="tMember">One member</label></div>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="target" id="tBatch" value="batch"<?= $data['target'] === 'batch' ? ' checked' : '' ?>><label class="form-check-label" for="tBatch">Whole batch</label></div>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="target" id="tAll" value="all"<?= $data['target'] === 'all' ? ' checked' : '' ?>><label class="form-check-label" for="tAll">All active members</label></div>
    </div>
    <div class="row g-3">
      <div class="col-12" id="rowMember">
        <label class="form-label">Member</label>
        <select name="member_id" class="form-select">
          <option value="">Choose...</option>
          <?php foreach ($members as $mm): ?>
            <option value="<?= (int)$mm['id'] ?>"<?= (int)$data['member_id'] === (int)$mm['id'] ? ' selected' : '' ?>><?= e($mm['name']) ?> (<?= e($mm['member_code']) ?><?= $mm['batch'] ? ', ' . e($mm['batch']) : '' ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12" id="rowBatch">
        <label class="form-label">Batch</label>
        <select name="batch" class="form-select"><option value="">Choose...</option><?php foreach (batch_list() as $b): ?><option value="<?= e($b) ?>"<?= $data['batch'] === $b ? ' selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-12 col-md-8">
        <label class="form-label">Fee title *</label>
        <input name="title" class="form-control" required list="titleList" placeholder="e.g. Registration fee" value="<?= e($data['title']) ?>">
        <datalist id="titleList"><?php foreach (db_all('SELECT DISTINCT title FROM dues ORDER BY title LIMIT 50') as $t): ?><option value="<?= e($t['title']) ?>"><?php endforeach; ?></datalist>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label">Amount (₹) *</label>
        <input name="amount" class="form-control" required inputmode="decimal" value="<?= e($data['amount']) ?>">
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label">Due date *</label>
        <input type="date" name="due_date" class="form-control" required value="<?= e($data['due_date']) ?>">
      </div>
      <div class="col-12 col-md-8">
        <label class="form-label">Note (optional, internal)</label>
        <input name="notes" class="form-control" maxlength="255" value="<?= e($data['notes']) ?>">
      </div>
    </div>
  </div>
  <div class="card-footer d-flex gap-2 justify-content-end">
    <a href="<?= e($pageBack) ?>" class="btn btn-default">Cancel</a>
    <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save</button>
  </div>
</form>
<script>
(function () {
  function sync() {
    var t = document.querySelector('input[name=target]:checked').value;
    document.getElementById('rowMember').style.display = t === 'member' ? '' : 'none';
    document.getElementById('rowBatch').style.display = t === 'batch' ? '' : 'none';
  }
  document.querySelectorAll('input[name=target]').forEach(function (r) { r.addEventListener('change', sync); });
  sync();
})();
</script>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
