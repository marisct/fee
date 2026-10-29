<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int)get('id');
$member = $id ? db_row('SELECT * FROM members WHERE id = ?', [$id]) : null;
if ($id && !$member) {
    flash('danger', 'Member not found.');
    redirect(app_url('members.php'));
}

$data = $member ?: [
    'name' => '', 'phone' => '', 'alt_phone' => '', 'email' => '', 'guardian_name' => '', 'gender' => '',
    'dob' => '', 'batch' => '', 'monthly_fee' => '', 'join_date' => date('Y-m-d'), 'status' => 'active', 'notes' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (array_keys($data) as $k) {
        $data[$k] = post($k);
    }
    $data['phone']     = normalize_phone($data['phone']);
    $data['alt_phone'] = normalize_phone($data['alt_phone']);

    if ($data['name'] === '') {
        $errors[] = 'Name is required.';
    }
    if (strlen($data['phone']) < 10) {
        $errors[] = 'A valid WhatsApp number is required.';
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is not valid.';
    }
    if ($data['monthly_fee'] !== '' && !is_numeric($data['monthly_fee'])) {
        $errors[] = 'Monthly fee must be a number.';
    }
    if (!in_array($data['status'], ['active', 'inactive'], true)) {
        $data['status'] = 'active';
    }

    if (!$errors) {
        $save = [
            'name'          => $data['name'],
            'phone'         => $data['phone'],
            'alt_phone'     => $data['alt_phone'] ?: null,
            'email'         => $data['email'] ?: null,
            'guardian_name' => $data['guardian_name'] ?: null,
            'gender'        => $data['gender'] ?: null,
            'dob'           => $data['dob'] ?: null,
            'batch'         => $data['batch'] ?: null,
            'monthly_fee'   => $data['monthly_fee'] !== '' ? round((float)$data['monthly_fee'], 2) : 0,
            'join_date'     => $data['join_date'] ?: null,
            'status'        => $data['status'],
            'notes'         => $data['notes'] ?: null,
        ];
        if ($member) {
            db_update('members', $save, 'id = ?', [$id]);
            flash('success', 'Member updated.');
        } else {
            $id = db_insert('members', $save);
            db_query("UPDATE members SET member_code = CONCAT('BX', LPAD(id, 4, '0')) WHERE id = ? AND (member_code IS NULL OR member_code = '')", [$id]);
            flash('success', 'Member added.');
        }
        redirect(app_url('member_view.php?id=' . $id));
    }
}

$pageTitle = $member ? $member['name'] : 'New Member';
$pageSubtitle = $member ? 'Edit member ' . e($member['member_code']) : 'Add a member to the club';
$pageBack = $member ? 'member_view.php?id=' . (int)$id : 'members.php';
$active = 'members';
$pageActions = '<a href="' . e($pageBack) . '" class="btn btn-default">Cancel</a><button class="btn btn-primary" form="memberForm"><i class="bi bi-check2 me-1"></i>Save</button>';
require __DIR__ . '/includes/layout_top.php';
?>
<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="card" id="memberForm" style="max-width: 900px;">
  <div class="card-body">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-12 col-md-6">
        <label class="form-label">Full name *</label>
        <input name="name" class="form-control" required value="<?= e($data['name']) ?>">
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">WhatsApp number *</label>
        <input name="phone" class="form-control" required inputmode="tel" placeholder="98765 43210" value="<?= e($data['phone']) ?>">
        <div class="form-text">10-digit number; country code +<?= e(setting('country_code', '91')) ?> is added automatically.</div>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Parent / guardian name</label>
        <input name="guardian_name" class="form-control" value="<?= e($data['guardian_name']) ?>">
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Alternate phone</label>
        <input name="alt_phone" class="form-control" inputmode="tel" value="<?= e($data['alt_phone']) ?>">
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= e($data['email']) ?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Gender</label>
        <select name="gender" class="form-select">
          <option value="">-</option>
          <?php foreach (['Male', 'Female', 'Other'] as $g): ?><option<?= $data['gender'] === $g ? ' selected' : '' ?>><?= $g ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Date of birth</label>
        <input type="date" name="dob" class="form-control" value="<?= e($data['dob']) ?>">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Batch</label>
        <input name="batch" class="form-control" list="batchList" placeholder="e.g. Morning 6-7 AM" value="<?= e($data['batch']) ?>">
        <datalist id="batchList"><?php foreach (batch_list() as $b): ?><option value="<?= e($b) ?>"><?php endforeach; ?></datalist>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label">Monthly fee (₹)</label>
        <input name="monthly_fee" class="form-control" inputmode="decimal" value="<?= e($data['monthly_fee']) ?>">
        <div class="form-text">Used by "Generate monthly dues".</div>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label">Joining date</label>
        <input type="date" name="join_date" class="form-control" value="<?= e($data['join_date']) ?>">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="active"<?= $data['status'] === 'active' ? ' selected' : '' ?>>Active</option>
          <option value="inactive"<?= $data['status'] === 'inactive' ? ' selected' : '' ?>>Inactive (no monthly dues)</option>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="2"><?= e($data['notes']) ?></textarea>
      </div>
    </div>
  </div>
  <div class="card-footer d-flex gap-2 justify-content-end">
    <a href="<?= e($pageBack) ?>" class="btn btn-default">Cancel</a>
    <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save</button>
  </div>
</form>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
