<?php
/**
 * Public short link sent on WhatsApp: /pay.php?t=TOKEN
 * Records the click, refreshes an expired Razorpay link if needed, then redirects to Razorpay.
 */
define('NO_SESSION', true);
require __DIR__ . '/includes/bootstrap.php';

$token = get('t');
$row = preg_match('/^[a-f0-9]{32}$/', $token) ? db_row(
    'SELECT d.*, m.name AS member_name, m.member_code, m.phone, m.email AS member_email, m.guardian_name, m.batch, m.status AS member_status
     FROM dues d JOIN members m ON m.id = d.member_id WHERE d.link_token = ?',
    [$token]
) : null;

$pageTitle = 'Fee payment';
$message = '';
$icon = 'bi-exclamation-circle text-warning';

if (!$row) {
    http_response_code(404);
    $message = 'This payment link is not valid.';
} elseif ($row['status'] === 'paid') {
    $icon = 'bi-check-circle-fill text-success';
    $message = 'This fee has already been paid on ' . fmt_date($row['paid_at']) . '. Thank you!';
} elseif ($row['status'] === 'cancelled') {
    $message = 'This fee request was cancelled. Please contact us if you think this is a mistake.';
} else {
    try {
        $row = fee_ensure_link($row);
        db_query('UPDATE dues SET link_clicks = link_clicks + 1, link_clicked_at = COALESCE(link_clicked_at, NOW()) WHERE id = ?', [$row['id']]);
        header('Cache-Control: no-store');
        redirect($row['rzp_short_url']);
    } catch (Throwable $e) {
        log_line('razorpay.log', 'pay.php could not open link for due #' . $row['id'] . ': ' . $e->getMessage());
        $message = 'We could not open the payment page right now. Please try again in a few minutes or contact us.';
    }
}

require __DIR__ . '/includes/layout_public.php';
?>
<div class="public-card">
  <i class="bi bi-big <?= $icon ?>"></i>
  <p class="mt-3 mb-0 fs-5"><?= e($message) ?></p>
  <?php if ($row): ?>
    <p class="text-secondary small mt-2 mb-0"><?= e($row['title']) ?> &middot; <?= e(money($row['amount'])) ?> &middot; <?= e($row['member_name']) ?></p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_public_end.php'; ?>
