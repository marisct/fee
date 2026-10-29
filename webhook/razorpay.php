<?php
/**
 * Razorpay webhook endpoint.
 * Configure in Razorpay Dashboard > Settings > Webhooks with the URL shown in Settings > Razorpay,
 * the same secret, and the events: payment_link.paid, payment_link.partially_paid,
 * payment_link.expired, payment_link.cancelled (payment.captured is optional).
 */
define('NO_SESSION', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'razorpay webhook endpoint'], 200);
}

$raw       = (string)file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
$eventId   = (string)($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '');
$data      = json_decode($raw, true);
$event     = is_array($data) ? (string)($data['event'] ?? '') : '';

$logId = db_insert('webhook_logs', [
    'source'    => 'razorpay',
    'event'     => $event ?: null,
    'remote_id' => $eventId ?: null,
    'payload'   => mb_substr($raw, 0, 1000000),
]);

$finish = function (string $note, bool $processed, int $code = 200) use ($logId): never {
    db_update('webhook_logs', ['note' => mb_substr($note, 0, 500), 'processed' => $processed ? 1 : 0], 'id = ?', [$logId]);
    json_response(['status' => $processed ? 'ok' : 'ignored', 'note' => $note], $code);
};

$secret = (string)setting('rzp_webhook_secret', '');
if ($secret === '') {
    $finish('Webhook secret not configured in Settings > Razorpay', false, 500);
}
if (!RazorpayClient::verifyWebhookSignature($raw, $signature, $secret)) {
    $finish('Invalid signature', false, 400);
}
if (!is_array($data) || $event === '') {
    $finish('Malformed payload', false, 400);
}

// Razorpay retries deliveries; skip an event we already processed.
if ($eventId !== '') {
    $dup = db_val('SELECT id FROM webhook_logs WHERE remote_id = ? AND processed = 1 AND id <> ?', [$eventId, $logId]);
    if ($dup) {
        $finish('Duplicate event already processed', true);
    }
}

try {
    $linkEntity    = $data['payload']['payment_link']['entity'] ?? null;
    $paymentEntity = $data['payload']['payment']['entity'] ?? null;

    switch ($event) {
        case 'payment_link.paid':
        case 'payment_link.partially_paid':
            if (!$linkEntity) {
                $finish('No payment_link entity', false);
            }
            $finish(fee_apply_link_event($linkEntity, $paymentEntity, 'razorpay_webhook'), true);
            // no break: $finish exits

        case 'payment_link.expired':
        case 'payment_link.cancelled':
            if ($linkEntity && !empty($linkEntity['id'])) {
                db_query('UPDATE dues SET rzp_status = ? WHERE rzp_link_id = ?', [$linkEntity['status'] ?? substr($event, 13), $linkEntity['id']]);
            }
            $finish('Link status updated', true);
            // no break

        case 'payment.captured':
            // Fallback in case payment_link.* events are not enabled: payment notes carry our due_id.
            $dueId = (int)($paymentEntity['notes']['due_id'] ?? 0);
            if ($paymentEntity && $dueId > 0) {
                $note = fee_apply_link_event(['id' => null, 'notes' => ['due_id' => (string)$dueId]], $paymentEntity, 'razorpay_webhook');
                $finish($note, true);
            }
            $finish('payment.captured without due_id note ignored', true);
            // no break

        default:
            $finish('Event ' . $event . ' ignored', true);
    }
} catch (Throwable $e) {
    log_line('razorpay.log', 'Webhook error: ' . $e->getMessage());
    $finish('Error: ' . $e->getMessage(), false, 500);
}
