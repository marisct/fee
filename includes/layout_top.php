<?php
/**
 * App shell (Frappe Desk style): left sidebar, top bar, page head.
 * Pages set: $pageTitle (string), $active (nav key) and optionally
 *   $pageActions  - HTML for the buttons on the right of the page head
 *   $pageBack     - URL for a back arrow next to the title
 *   $pageSubtitle - small grey text under the title
 *   $useCharts    - true to load Frappe Charts
 */
$user      = current_user();
$appName   = (string)setting('institute_name', $CONFIG['app_name'] ?? 'Fee Manager');
$pageTitle = $pageTitle ?? 'Fee Manager';
$active    = $active ?? '';
$pageActions  = $pageActions ?? '';
$pageBack     = $pageBack ?? '';
$pageSubtitle = $pageSubtitle ?? '';
$overdueCount = (int)db_val("SELECT COUNT(*) FROM dues WHERE status IN ('pending','sent','partial') AND due_date < CURDATE()");
$initials = strtoupper(mb_substr((string)($user['name'] ?? 'A'), 0, 1));
$navSections = [
    'Main' => [
        'dashboard' => ['index.php',    'Dashboard',    'bi-grid-1x2'],
        'members'   => ['members.php',  'Members',      'bi-people'],
        'dues'      => ['dues.php',     'Fee Dues',     'bi-receipt', $overdueCount],
        'payments'  => ['payments.php', 'Payments',     'bi-cash-stack'],
    ],
    'Messaging' => [
        'messages'  => ['messages.php', 'WhatsApp Log', 'bi-whatsapp'],
    ],
    'Setup' => [
        'settings'  => ['settings.php', 'Settings',     'bi-gear'],
    ],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> - <?= e($appName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(app_url('assets/app.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="app">
  <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Navigation">
    <div class="offcanvas-header d-lg-none">
      <span class="fw-semibold"><?= e($appName) ?></span>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
      <div class="sidebar-inner">
        <a class="brand" href="index.php"><span class="logo"><i class="bi bi-trophy-fill"></i></span><span class="text-truncate"><?= e($appName) ?></span></a>
        <?php foreach ($navSections as $section => $items): ?>
          <div class="section-label"><?= e($section) ?></div>
          <?php foreach ($items as $key => $item): [$href, $label, $icon] = $item; $count = $item[3] ?? 0; ?>
            <a class="nav-link<?= $active === $key ? ' active' : '' ?>" href="<?= e($href) ?>"><i class="bi <?= e($icon) ?>"></i><span><?= e($label) ?></span><?php if ($count > 0): ?><span class="count" title="Overdue dues"><?= $count ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="sidebar-footer">Fee Manager</div>
      </div>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="btn btn-default btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Menu"><i class="bi bi-list fs-5"></i></button>
      <form class="search" action="members.php" method="get" role="search">
        <i class="bi bi-search"></i>
        <input type="search" name="q" class="form-control" placeholder="Search members..." aria-label="Search members">
        <input type="hidden" name="status" value="all">
      </form>
      <div class="spacer"></div>
      <div class="dropdown user-menu">
        <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <span class="avatar"><?= e($initials) ?></span><span class="d-none d-sm-inline"><?= e($user['name'] ?? '') ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="settings.php?tab=account"><i class="bi bi-person me-2"></i>My account</a></li>
          <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
        </ul>
      </div>
    </header>

    <div class="page-head">
      <div class="title-wrap">
        <?php if ($pageBack !== ''): ?><a class="back" href="<?= e($pageBack) ?>" title="Back"><i class="bi bi-arrow-left"></i></a><?php endif; ?>
        <div class="min-w-0">
          <h1><?= e($pageTitle) ?></h1>
          <?php if ($pageSubtitle !== ''): ?><div class="subtitle"><?= $pageSubtitle ?></div><?php endif; ?>
        </div>
      </div>
      <?php if ($pageActions !== ''): ?><div class="actions"><?= $pageActions ?></div><?php endif; ?>
    </div>

    <main class="page-body">
<?php foreach (take_flashes() as [$type, $msg]): ?>
      <div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
        <?= nl2br(e($msg)) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
<?php endforeach; ?>
