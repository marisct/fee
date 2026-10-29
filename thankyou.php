<?php
/**
 * Razorpay redirects the payer here after paying a payment link (callback_url).
 * We verify the signature and mark the due paid right away (the webhook does the same, idempotently).
 */
define('NO_SESSION', true);
require __DIR__ . '/includes/bootstrap.php';

$q = $_GET;
$linkId  = (string)($q['razorpay_payment_link_id'] ?? '');
$payId   = (string)($q['razorpay_payment_id'] ?? '');
$status  = (string)($q['razorpay_payment_link_status'] ?? '');
$verified = false;
$row = null;
$note = '';

if ($linkId !== '') {
    $verified = RazorpayClient::verifyCallbackSignature($q, (string)setting('rzp_key_secret', ''));
    $due = db_row('SELECT id FROM dues WHERE rzp_link_id = ?', [$linkId]);
    if ($due) {
        if ($verified && $payId !== '' && in_array($status, ['paid', 'partially_paid'], true)) {
            try {
                $rzp     = RazorpayClient::fromSettings();
                $payment = $rzp->fetchPayment($payId);
                $link    = ['id' => $linkId, 'status' => $status, 'notes' => ['due_id' => (string)$due['id']]];
                $note    = fee_apply_link_event($link, $payment, 'razorpay_callback');
            } catch (Throwable $e) {
                log_line('razorpay.log', 'thankyou.php could not fetch payment ' . $payId . ': ' . $e->getMessage());
            }
        }
        $row = fee_load((int)$due['id']);
    }
}

$pageTitle = 'Payment status';
require __DIR__ . '/includes/layout_public.php';
?>
<div class="public-card">
  <div>
    <?php if ($row && $row['status'] === 'paid'): ?>
      <i class="bi bi-big bi-check-circle-fill text-success"></i>
      <h1 class="h4 mt-3">Payment received. Thank you!</h1>
      <p class="text-secondary mb-3"><?= e($row['member_name']) ?> &middot; <?= e($row['title']) ?></p>
      <table class="table table-sm text-start mb-0">
        <tr><th>Amount</th><td><?= e(money($row['amount_paid'])) ?></td></tr>
        <?php if ($payId): ?><tr><th>Payment ID</th><td class="code"><?= e($payId) ?></td></tr><?php endif; ?>
        <tr><th>Date</th><td><?= e(fmt_datetime($row['paid_at'])) ?></td></tr>
      </table>
      <p class="small text-secondary mt-3 mb-0">A confirmation will be sent on WhatsApp. You can close this page.</p>
    <?php elseif ($row && $row['status'] === 'partial'): ?>
      <i class="bi bi-big bi-check-circle text-success"></i>
      <h1 class="h4 mt-3">Partial payment received</h1>
      <p class="text-secondary mb-0">Paid <?= e(money($row['amount_paid'])) ?> of <?= e(money($row['amount'])) ?> for <?= e($row['title']) ?>. The remaining amount can be paid with the same link.</p>
    <?php elseif ($row && $verified): ?>
      <i class="bi bi-big bi-hourglass-split text-warning"></i>
      <h1 class="h4 mt-3">Payment is being confirmed</h1>
      <p class="text-secondary mb-0">If the amount was deducted, your fee will be marked paid automatically within a few minutes. Please do not pay again.</p>
    <?php else: ?>
      <i class="bi bi-big bi-info-circle text-secondary"></i>
      <h1 class="h4 mt-3">Payment status</h1>
      <p class="text-secondary mb-0">We could not verify this payment. If money was deducted from your account, please contact us with your payment reference.</p>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/layout_public_end.php'; ?>
