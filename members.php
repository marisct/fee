<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$q      = get('q');
$batch  = get('batch');
$status = get('status', 'active');

$w = ['1 = 1'];
$p = [];
if ($status !== '' && $status !== 'all') {
    $w[] = 'm.status = ?';
    $p[] = $status;
}
if ($batch !== '') {
    $w[] = 'm.batch = ?';
    $p[] = $batch;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $w[]  = '(m.name LIKE ? OR m.member_code LIKE ? OR m.phone LIKE ? OR m.guardian_name LIKE ?)';
    array_push($p, $like, $like, $like, $like);
}
$where = implode(' AND ', $w);

$pg = paginate('SELECT COUNT(*) FROM members m WHERE ' . $where, $p, 50);
$rows = db_all(
    "SELECT m.*,
            (SELECT COALESCE(SUM(amount - amount_paid), 0) FROM dues d WHERE d.member_id = m.id AND d.status IN ('pending','sent','partial')) AS outstanding,
            (SELECT COUNT(*) FROM dues d WHERE d.member_id = m.id AND d.status IN ('pending','sent','partial') AND d.due_date < CURDATE()) AS overdue_count
     FROM members m WHERE $where
     ORDER BY m.name ASC LIMIT {$pg['limit']} OFFSET {$pg['offset']}",
    $p
);

$pageTitle = 'Members';
$pageSubtitle = $pg['total'] . ' member' . ($pg['total'] === 1 ? '' : 's');
$active = 'members';
$pageActions = '<a href="members_import.php" class="btn btn-default"><i class="bi bi-upload me-1"></i>Import</a>'
    . '<a href="member_form.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Member</a>';
require __DIR__ . '/includes/layout_top.php';
?>
<div class="card">
  <div class="list-filters">
    <form class="row g-2 filter-form" method="get">
      <div class="col-12 col-md-4"><input name="q" class="form-control" placeholder="Search name, code, phone" value="<?= e($q) ?>"></div>
      <div class="col-6 col-md-3">
        <select name="batch" class="form-select">
          <option value="">All batches</option>
          <?php foreach (batch_list() as $b): ?><option value="<?= e($b) ?>"<?= $b === $batch ? ' selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <select name="status" class="form-select">
          <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
          <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option>
          <option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All</option>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid"><button class="btn btn-default"><i class="bi bi-funnel me-1"></i>Filter</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr>
        <th>Member</th>
        <th class="d-none d-md-table-cell">Phone</th>
        <th class="d-none d-md-table-cell">Batch</th>
        <th class="text-end">Monthly fee</th>
        <th class="text-end">Outstanding</th>
        <th class="d-none d-sm-table-cell">Status</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-people"></i>No members found</div></td></tr><?php endif; ?>
      <?php foreach ($rows as $m): ?>
        <tr>
          <td class="nowrap">
            <a href="member_view.php?id=<?= (int)$m['id'] ?>"><?= e($m['name']) ?></a>
            <div class="sub"><?= e($m['member_code']) ?><span class="d-md-none"> &middot; <?= e(pretty_phone($m['phone'])) ?></span></div>
          </td>
          <td class="d-none d-md-table-cell nowrap"><?= e(pretty_phone($m['phone'])) ?></td>
          <td class="d-none d-md-table-cell"><?= e($m['batch']) ?></td>
          <td class="text-end nowrap"><?= e(money($m['monthly_fee'])) ?></td>
          <td class="text-end nowrap">
            <?php if ((float)$m['outstanding'] > 0): ?>
              <span class="fw-semibold" style="color: <?= (int)$m['overdue_count'] > 0 ? 'var(--red)' : 'var(--orange)' ?>"><?= e(money($m['outstanding'])) ?></span>
            <?php else: ?><span class="text-secondary">-</span><?php endif; ?>
          </td>
          <td class="d-none d-sm-table-cell"><?= status_badge($m['status']) ?></td>
          <td class="row-actions text-end">
            <a href="member_view.php?id=<?= (int)$m['id'] ?>" class="btn btn-default" title="View"><i class="bi bi-eye"></i></a>
            <a href="member_form.php?id=<?= (int)$m['id'] ?>" class="btn btn-default" title="Edit"><i class="bi bi-pencil"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['pages'] > 1): ?><div class="card-footer d-flex justify-content-between align-items-center"><span class="small text-secondary">Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span><?= pagination_html($pg) ?></div><?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
