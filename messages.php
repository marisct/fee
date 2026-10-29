<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$status = get('status');
$type   = get('type');
$q      = get('q');

$w = ['1 = 1'];
$p = [];
if ($status !== '') {
    $w[] = 'x.status = ?';
    $p[] = $status;
}
if ($type !== '') {
    $w[] = 'x.type = ?';
    $p[] = $type;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $w[]  = '(m.name LIKE ? OR x.phone LIKE ? OR x.error LIKE ?)';
    array_push($p, $like, $like, $like);
}
$where = implode(' AND ', $w);
$baseSql = 'FROM messages x LEFT JOIN members m ON m.id = x.member_id LEFT JOIN dues d ON d.id = x.due_id WHERE ' . $where;

$pg = paginate("SELECT COUNT(*) $baseSql", $p, 100);
$rows = db_all("SELECT x.*, m.name AS member_name, d.title $baseSql ORDER BY x.id DESC LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $p);

$pageTitle = 'WhatsApp Log';
$pageSubtitle = (int)$pg['total'] . ' messages';
$active = 'messages';
require __DIR__ . '/includes/layout_top.php';
?>
<div class="card">
  <div class="list-filters">
    <form class="row g-2 filter-form" method="get">
      <div class="col-6 col-md-2">
        <select name="status" class="form-select">
          <option value="">Any status</option>
          <?php foreach (['queued', 'sent', 'delivered', 'read', 'failed'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select name="type" class="form-select">
          <option value="">Any type</option>
          <?php foreach (['fee_link' => 'Fee link', 'reminder' => 'Reminder', 'receipt' => 'Receipt', 'test' => 'Test'] as $k => $label): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-6"><input name="q" class="form-control" placeholder="Member, phone or error text" value="<?= e($q) ?>"></div>
      <div class="col-12 col-md-2 d-grid"><button class="btn btn-default"><i class="bi bi-funnel me-1"></i>Filter</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover">
      <thead><tr><th>Time</th><th>To</th><th>Type</th><th>Status</th><th class="d-none d-lg-table-cell">Message</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-whatsapp"></i>No messages yet</div></td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= e(fmt_datetime($r['created_at'])) ?></td>
          <td class="nowrap">
            <?php if ($r['member_id']): ?><a href="member_view.php?id=<?= (int)$r['member_id'] ?>"><?= e($r['member_name']) ?></a><?php else: ?><span class="fw-medium">Test</span><?php endif; ?>
            <div class="sub"><?= e(pretty_phone($r['phone'])) ?><?= $r['title'] ? ' &middot; ' . e($r['title']) : '' ?></div>
          </td>
          <td class="nowrap"><?= e(ucfirst(str_replace('_', ' ', $r['type']))) ?></td>
          <td><?= status_badge($r['status']) ?><?php if ($r['error']): ?><div class="sub" style="color: var(--red)"><?= e($r['error']) ?></div><?php endif; ?></td>
          <td class="d-none d-lg-table-cell sub" style="max-width: 420px;"><?= e(mb_strimwidth((string)$r['body'], 0, 160, '...')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['pages'] > 1): ?><div class="card-footer d-flex justify-content-between align-items-center"><span class="small text-secondary">Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span><?= pagination_html($pg) ?></div><?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
