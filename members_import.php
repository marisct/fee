<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        flash('danger', 'Please choose a CSV file.');
        redirect(app_url('members_import.php'));
    }
    $fh = fopen($_FILES['csv']['tmp_name'], 'r');
    $header = $fh ? fgetcsv($fh) : false;
    if (!$header) {
        flash('danger', 'Could not read the CSV file.');
        redirect(app_url('members_import.php'));
    }
    $header = array_map(fn($h) => strtolower(trim(str_replace(["\xEF\xBB\xBF", ' '], ['', '_'], (string)$h))), $header);
    $added = 0;
    $skipped = [];
    $line = 1;
    while (($row = fgetcsv($fh)) !== false) {
        $line++;
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }
        $r = [];
        foreach ($header as $i => $col) {
            $r[$col] = trim((string)($row[$i] ?? ''));
        }
        $name  = $r['name'] ?? '';
        $phone = normalize_phone($r['phone'] ?? ($r['whatsapp'] ?? ''));
        if ($name === '' || strlen($phone) < 10) {
            $skipped[] = "Line $line: name or phone missing";
            continue;
        }
        if (db_val('SELECT id FROM members WHERE phone = ? AND name = ?', [$phone, $name])) {
            $skipped[] = "Line $line: $name already exists";
            continue;
        }
        $mid = db_insert('members', [
            'name'          => $name,
            'phone'         => $phone,
            'alt_phone'     => normalize_phone($r['alt_phone'] ?? '') ?: null,
            'email'         => filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $r['email'] : null,
            'guardian_name' => ($r['guardian_name'] ?? ($r['guardian'] ?? '')) ?: null,
            'batch'         => ($r['batch'] ?? '') ?: null,
            'monthly_fee'   => is_numeric($r['monthly_fee'] ?? '') ? round((float)$r['monthly_fee'], 2) : 0,
            'join_date'     => !empty($r['join_date']) && strtotime($r['join_date']) ? date('Y-m-d', strtotime($r['join_date'])) : null,
            'status'        => 'active',
        ]);
        db_query("UPDATE members SET member_code = CONCAT('BX', LPAD(id, 4, '0')) WHERE id = ?", [$mid]);
        $added++;
    }
    fclose($fh);
    $report = ['added' => $added, 'skipped' => $skipped];
}

$pageTitle = 'Import Members';
$pageSubtitle = 'Upload a CSV exported from Excel or Google Sheets';
$pageBack = 'members.php';
$active = 'members';
require __DIR__ . '/includes/layout_top.php';
?>

<?php if ($report): ?>
  <div class="alert alert-<?= $report['added'] ? 'success' : 'warning' ?>">
    <strong><?= (int)$report['added'] ?> member(s) added.</strong>
    <?php if ($report['skipped']): ?><ul class="mb-0 mt-2 small"><?php foreach ($report['skipped'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <form method="post" enctype="multipart/form-data" class="card">
      <div class="card-body">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">CSV file</label>
          <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required>
        </div>
        <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Import</button>
      </div>
    </form>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-body small">
        <p class="mb-2">The first row must be a header. Supported columns (any order; only <strong>name</strong> and <strong>phone</strong> are required):</p>
        <div class="copy-box mb-2">name,phone,batch,monthly_fee,guardian_name,email,join_date<br>Rahul Sharma,9876543210,Morning 6-7 AM,1500,Suresh Sharma,rahul@example.com,2026-01-15</div>
        <p class="mb-0 text-secondary">Members with the same name and phone are skipped. You can export such a file from Excel or Google Sheets with "Save as CSV".</p>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
