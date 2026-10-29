<?php
/**
 * Minimal public layout (payment redirect / thank-you pages). Expects $pageTitle.
 * Usage: include this file, then echo content, then include layout_public_end.php.
 */
$appName = (string)setting('institute_name', $CONFIG['app_name'] ?? 'Fee Manager');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Payment') ?> - <?= e($appName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(app_url('assets/app.css')) ?>" rel="stylesheet">
</head>
<body>
<main class="container py-4" style="max-width: 520px;">
  <div class="text-center mb-4">
    <div class="d-inline-flex align-items-center gap-2 fs-5 fw-semibold"><span class="avatar" style="background: var(--gray-900); color: #f6c343;"><i class="bi bi-trophy-fill"></i></span><?= e($appName) ?></div>
    <?php if (setting('institute_phone')): ?>
      <div class="text-secondary small mt-1"><i class="bi bi-telephone me-1"></i><?= e(setting('institute_phone')) ?></div>
    <?php endif; ?>
  </div>
