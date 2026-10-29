<?php
/**
 * Fee service: payment links, WhatsApp sending, recording payments, monthly generation.
 *
 * A "due" row joined with its member (see fee_load) is passed around as $row.
 */
declare(strict_types=1);

/** Load one due together with the member fields the service needs. */
function fee_load(int $dueId): ?array
{
    return db_row(
        'SELECT d.*, m.name AS member_name, m.member_code, m.phone, m.email AS member_email,
                m.guardian_name, m.batch, m.status AS member_status
         FROM dues d JOIN members m ON m.id = d.member_id
         WHERE d.id = ?',
        [$dueId]
    );
}

/** Placeholder => value map used in WhatsApp templates and text messages. */
function fee_placeholders(array $row, array $extra = []): array
{
    $balance = (float)$row['amount'] - (float)$row['amount_paid'];
    $ph = [
        '{name}'            => $row['member_name'] ?? '',
        '{guardian}'        => ($row['guardian_name'] ?? '') !== '' ? $row['guardian_name'] : ($row['member_name'] ?? ''),
        '{member_code}'     => $row['member_code'] ?? '',
        '{batch}'           => $row['batch'] ?? '',
        '{title}'           => $row['title'] ?? '',
        '{amount}'          => money($row['amount'] ?? 0),
        '{amount_plain}'    => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
        '{balance}'         => money($balance),
        '{due_date}'        => fmt_date($row['due_date'] ?? null),
        '{period}'          => !empty($row['period']) ? period_label($row['period']) : '',
        '{link}'            => !empty($row['link_token']) ? fee_payment_url($row) : '',
        '{token}'           => $row['link_token'] ?? '',
        '{institute}'       => setting('institute_name', 'Boxing Academy'),
        '{institute_phone}' => setting('institute_phone', ''),
        '{payment_id}'      => '',
        '{paid_amount}'     => '',
        '{paid_date}'       => '',
    ];
    return array_merge($ph, $extra);
}

function fee_render(string $template, array $placeholders): string
{
    return strtr($template, $placeholders);
}

/** The URL we put in the message: our tracked pay.php link, or the raw Razorpay short URL. */
function fee_payment_url(array $row): string
{
    if (setting('link_mode', 'tracked') === 'direct' && !empty($row['rzp_short_url'])) {
        return $row['rzp_short_url'];
    }
    return app_url('pay.php?t=' . $row['link_token']);
}

function fee_link_is_usable(array $row): bool
{
    if (empty($row['rzp_link_id']) || empty($row['rzp_short_url'])) {
        return false;
    }
    if (!in_array($row['rzp_status'], ['created', 'partially_paid'], true)) {
        return false;
    }
    if (!empty($row['link_expires_at']) && strtotime($row['link_expires_at']) < time() + 3600) {
        return false;
    }
    return true;
}

/**
 * Make sure the due has a live Razorpay payment link. Creates (or re-creates) one when needed.
 * Returns the refreshed row.
 */
function fee_ensure_link(array $row, bool $force = false): array
{
    if (in_array($row['status'], ['paid', 'cancelled'], true)) {
        throw new RuntimeException('This due is already ' . $row['status'] . '.');
    }
    $balance = round((float)$row['amount'] - (float)$row['amount_paid'], 2);
    if ($balance <= 0) {
        throw new RuntimeException('Nothing left to pay on this due.');
    }
    if (!$force && fee_link_is_usable($row)) {
        return $row;
    }

    $rzp = RazorpayClient::fromSettings();

    // Cancel the previous link so the member cannot pay twice through an old message.
    if (!empty($row['rzp_link_id']) && in_array($row['rzp_status'], ['created', 'partially_paid'], true)) {
        try {
            $rzp->cancelPaymentLink($row['rzp_link_id']);
        } catch (Throwable $e) {
            log_line('razorpay.log', 'Cancel old link failed for due #' . $row['id'] . ': ' . $e->getMessage());
        }
    }

    $token     = !empty($row['link_token']) ? $row['link_token'] : random_token(16);
    $days      = max(1, min(180, (int)setting('rzp_link_expiry_days', 30)));
    $expire    = time() + $days * 86400;
    $institute = (string)setting('institute_name', 'Boxing Academy');

    $customer = [
        'name'    => mb_substr((string)$row['member_name'], 0, 100),
        'contact' => '+' . $row['phone'],
    ];
    if (!empty($row['member_email']) && filter_var($row['member_email'], FILTER_VALIDATE_EMAIL)) {
        $customer['email'] = $row['member_email'];
    }

    $reference = substr('DUE' . $row['id'] . '-' . base_convert((string)time(), 10, 36), 0, 40);
    $payload = [
        'amount'          => (int)round($balance * 100),
        'currency'        => (string)setting('currency', 'INR'),
        'accept_partial'  => (int)setting('rzp_accept_partial', 0) === 1,
        'reference_id'    => $reference,
        'description'     => mb_substr($institute . ' - ' . $row['title'] . ' - ' . $row['member_name'], 0, 2048),
        'customer'        => $customer,
        'notify'          => ['sms' => false, 'email' => false],
        'reminder_enable' => false,
        'notes'           => [
            'due_id'      => (string)$row['id'],
            'member_id'   => (string)$row['member_id'],
            'member_code' => (string)($row['member_code'] ?? ''),
        ],
        'callback_url'    => app_url('thankyou.php'),
        'callback_method' => 'get',
        'expire_by'       => $expire,
    ];

    $link = $rzp->createPaymentLink($payload);

    db_update('dues', [
        'rzp_link_id'      => $link['id'],
        'rzp_short_url'    => $link['short_url'],
        'rzp_reference_id' => $reference,
        'rzp_status'       => $link['status'] ?? 'created',
        'link_token'       => $token,
        'link_created_at'  => date('Y-m-d H:i:s'),
        'link_expires_at'  => date('Y-m-d H:i:s', $expire),
    ], 'id = ?', [$row['id']]);

    log_line('razorpay.log', 'Created link ' . $link['id'] . ' for due #' . $row['id'] . ' amount ' . $balance);

    return fee_load((int)$row['id']);
}

/* ---------------- WhatsApp sending ---------------- */

function fee_log_message(array $row, string $type, string $status, ?string $providerId, ?string $error, string $body): int
{
    return db_insert('messages', [
        'member_id'       => $row['member_id'] ?? null,
        'due_id'          => $row['id'] ?? null,
        'phone'           => $row['phone'] ?? '',
        'type'            => $type,
        'provider'        => 'meta',
        'provider_msg_id' => $providerId,
        'status'          => $status,
        'error'           => $error !== null ? mb_substr($error, 0, 500) : null,
        'body'            => $body,
    ]);
}

/** Turn "{name}, {amount}, {link}" into a list of rendered template parameters. */
function fee_template_params(string $spec, array $placeholders): array
{
    $out = [];
    foreach (preg_split('/[\n,]/', $spec) ?: [] as $piece) {
        $piece = trim($piece);
        if ($piece === '') {
            continue;
        }
        $out[] = WhatsAppClient::cleanParam(fee_render($piece, $placeholders));
    }
    return $out;
}

/**
 * Build the WhatsApp payload for a message kind ('link', 'reminder', 'receipt') and send it.
 * Returns ['ok' => bool, 'error' => string|null, 'id' => provider message id|null, 'body' => preview].
 */
function fee_send_message(array $row, string $kind, array $placeholders, string $logType): array
{
    try {
        $wa = WhatsAppClient::fromSettings();
    } catch (Throwable $e) {
        fee_log_message($row, $logType, 'failed', null, $e->getMessage(), '');
        return ['ok' => false, 'error' => $e->getMessage(), 'id' => null];
    }

    $mode = (string)setting('wa_mode', 'template');

    if ($mode === 'text') {
        $tplKey = $kind === 'receipt' ? 'wa_text_receipt' : 'wa_text_link';
        $body   = fee_render((string)setting($tplKey, ''), $placeholders);
        if (trim($body) === '') {
            $err = 'Text message template is empty (Settings > WhatsApp).';
            fee_log_message($row, $logType, 'failed', null, $err, '');
            return ['ok' => false, 'error' => $err, 'id' => null];
        }
        $msgId = fee_log_message($row, $logType, 'queued', null, null, $body);
        $res   = $wa->sendText((string)$row['phone'], $body);
    } else {
        // Template mode. A reminder uses its own template only when one is configured.
        $prefix = 'wa_tpl_link_';
        if ($kind === 'receipt') {
            $prefix = 'wa_tpl_receipt_';
        } elseif ($kind === 'reminder' && setting('wa_tpl_reminder_name')) {
            $prefix = 'wa_tpl_reminder_';
        }
        $name = (string)setting($prefix . 'name', '');
        $lang = (string)setting($prefix . 'lang', 'en');
        $spec = (string)setting($prefix . 'params', '');
        if ($name === '') {
            $err = 'WhatsApp template name is not configured (Settings > WhatsApp).';
            fee_log_message($row, $logType, 'failed', null, $err, '');
            return ['ok' => false, 'error' => $err, 'id' => null];
        }
        $params  = fee_template_params($spec, $placeholders);
        $buttons = [];
        $btnSpec = (string)setting($prefix . 'button_param', '');
        if ($btnSpec !== '' && $kind !== 'receipt') {
            $buttons[] = [
                'index' => (string)setting('wa_tpl_link_button_index', '0'),
                'text'  => fee_render($btnSpec, $placeholders),
            ];
        }
        $body  = '[template ' . $name . '/' . $lang . '] ' . implode(' | ', $params)
               . ($buttons ? ' | button: ' . $buttons[0]['text'] : '');
        $msgId = fee_log_message($row, $logType, 'queued', null, null, $body);
        $res   = $wa->sendTemplate((string)$row['phone'], $name, $lang, $params, $buttons);
    }

    if ($res['ok']) {
        db_update('messages', ['status' => 'sent', 'provider_msg_id' => $res['id']], 'id = ?', [$msgId]);
        return ['ok' => true, 'error' => null, 'id' => $res['id']];
    }
    db_update('messages', ['status' => 'failed', 'error' => mb_substr((string)$res['error'], 0, 500)], 'id = ?', [$msgId]);
    return ['ok' => false, 'error' => $res['error'], 'id' => null];
}

/**
 * Create/refresh the payment link and send it on WhatsApp.
 * $type is 'fee_link' for the first message or 'reminder' for follow-ups.
 */
function fee_send_whatsapp(int $dueId, string $type = 'fee_link'): array
{
    $row = fee_load($dueId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Due #' . $dueId . ' not found'];
    }
    $who = $row['member_name'] . ' (' . ($row['member_code'] ?: '#' . $row['member_id']) . ')';

    if ((string)$row['phone'] === '') {
        return ['ok' => false, 'error' => $who . ': no WhatsApp number'];
    }
    try {
        $row = fee_ensure_link($row);
    } catch (Throwable $e) {
        fee_log_message($row, $type, 'failed', null, $e->getMessage(), '');
        return ['ok' => false, 'error' => $who . ': ' . $e->getMessage()];
    }

    $kind = $type === 'reminder' ? 'reminder' : 'link';
    $res  = fee_send_message($row, $kind, fee_placeholders($row), $type);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $who . ': ' . $res['error']];
    }

    $upd = [
        'last_sent_at' => date('Y-m-d H:i:s'),
        'send_count'   => (int)$row['send_count'] + 1,
    ];
    if ($type === 'reminder') {
        $upd['reminder_count'] = (int)$row['reminder_count'] + 1;
    }
    if ($row['status'] === 'pending') {
        $upd['status'] = 'sent';
    }
    db_update('dues', $upd, 'id = ?', [$row['id']]);

    return ['ok' => true, 'message' => 'Sent to ' . $who];
}

/** WhatsApp receipt after a successful payment (if enabled in settings). */
function fee_send_receipt(array $row, array $payment): array
{
    $extra = [
        '{payment_id}'  => (string)($payment['rzp_payment_id'] ?? ($payment['source'] === 'manual' ? 'CASH' : '')),
        '{paid_amount}' => money($payment['amount'] ?? 0),
        '{paid_date}'   => fmt_datetime($payment['paid_at'] ?? date('Y-m-d H:i:s')),
    ];
    return fee_send_message($row, 'receipt', fee_placeholders($row, $extra), 'receipt');
}

/* ---------------- Payments ---------------- */

/**
 * Record a payment against a due (idempotent on rzp_payment_id) and update the due status.
 * $p keys: rzp_payment_id, rzp_order_id, rzp_link_id, amount (rupees), currency, method, status,
 *          payer_email, payer_contact, source, notes, raw, paid_at, rzp_status
 */
function fee_record_payment(int $dueId, array $p): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $due = db_row('SELECT * FROM dues WHERE id = ? FOR UPDATE', [$dueId]);
        if (!$due) {
            throw new RuntimeException('Due #' . $dueId . ' not found');
        }
        if (!empty($p['rzp_payment_id'])) {
            $exists = db_val('SELECT id FROM payments WHERE rzp_payment_id = ?', [$p['rzp_payment_id']]);
            if ($exists) {
                $pdo->commit();
                return ['recorded' => false, 'reason' => 'duplicate', 'due' => fee_load($dueId)];
            }
        }
        $paidAt = $p['paid_at'] ?? date('Y-m-d H:i:s');
        db_insert('payments', [
            'due_id'         => $dueId,
            'member_id'      => $due['member_id'],
            'rzp_payment_id' => $p['rzp_payment_id'] ?? null,
            'rzp_order_id'   => $p['rzp_order_id'] ?? null,
            'rzp_link_id'    => $p['rzp_link_id'] ?? $due['rzp_link_id'],
            'amount'         => round((float)$p['amount'], 2),
            'currency'       => $p['currency'] ?? 'INR',
            'method'         => $p['method'] ?? null,
            'status'         => $p['status'] ?? 'captured',
            'payer_email'    => $p['payer_email'] ?? null,
            'payer_contact'  => $p['payer_contact'] ?? null,
            'source'         => $p['source'] ?? 'manual',
            'notes'          => $p['notes'] ?? null,
            'raw_json'       => isset($p['raw']) ? json_encode($p['raw']) : null,
            'paid_at'        => $paidAt,
        ]);

        $paid   = (float)db_val("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE due_id = ? AND status IN ('captured', 'authorized')", [$dueId]);
        $status = ($paid + 0.005 >= (float)$due['amount']) ? 'paid' : ($paid > 0 ? 'partial' : $due['status']);
        $upd = [
            'amount_paid'  => $paid,
            'status'       => $status,
            'payment_mode' => $p['method'] ?? (($p['source'] ?? '') === 'manual' ? 'cash' : 'online'),
        ];
        if ($status === 'paid') {
            $upd['paid_at'] = $paidAt;
        }
        if (!empty($p['rzp_status'])) {
            $upd['rzp_status'] = $p['rzp_status'];
        }
        db_update('dues', $upd, 'id = ?', [$dueId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $row = fee_load($dueId);
    log_line('payments.log', 'Due #' . $dueId . ' payment ' . ($p['rzp_payment_id'] ?? 'manual') . ' amount ' . $p['amount'] . ' => ' . $status);

    if ($status === 'paid' && (int)setting('wa_receipt_enabled', 1) === 1 && (string)$row['phone'] !== '') {
        try {
            fee_send_receipt($row, $p);
        } catch (Throwable $e) {
            log_line('whatsapp.log', 'Receipt failed for due #' . $dueId . ': ' . $e->getMessage());
        }
    }
    return ['recorded' => true, 'due' => $row];
}

/**
 * Apply a Razorpay payment-link entity (from a webhook, the callback page, or a manual sync).
 * $payment is the payment entity when the event carries one. Returns a human-readable note.
 */
function fee_apply_link_event(array $link, ?array $payment, string $source): string
{
    $dueId = (int)($link['notes']['due_id'] ?? 0);
    $due   = $dueId > 0 ? db_row('SELECT * FROM dues WHERE id = ?', [$dueId]) : null;
    if (!$due && !empty($link['id'])) {
        $due = db_row('SELECT * FROM dues WHERE rzp_link_id = ?', [$link['id']]);
    }
    if (!$due) {
        return 'No matching due for payment link ' . ($link['id'] ?? '?');
    }
    if (!empty($link['status'])) {
        db_update('dues', ['rzp_status' => $link['status']], 'id = ?', [$due['id']]);
    }

    $notes = [];
    $apply = function (array $pm) use ($due, $link, $source, &$notes): void {
        $pid = $pm['id'] ?? $pm['payment_id'] ?? null;
        if (!$pid) {
            return;
        }
        $st = $pm['status'] ?? 'captured';
        if (!in_array($st, ['captured', 'authorized'], true)) {
            $notes[] = 'Payment ' . $pid . ' status ' . $st . ' ignored';
            return;
        }
        $r = fee_record_payment((int)$due['id'], [
            'rzp_payment_id' => $pid,
            'rzp_order_id'   => $pm['order_id'] ?? null,
            'rzp_link_id'    => $link['id'] ?? null,
            'amount'         => ((int)($pm['amount'] ?? 0)) / 100,
            'currency'       => $pm['currency'] ?? 'INR',
            'method'         => $pm['method'] ?? null,
            'status'         => $st,
            'payer_email'    => $pm['email'] ?? null,
            'payer_contact'  => isset($pm['contact']) ? normalize_phone((string)$pm['contact']) : null,
            'source'         => $source,
            'raw'            => $pm,
            'paid_at'        => date('Y-m-d H:i:s', (int)($pm['created_at'] ?? time())),
            'rzp_status'     => $link['status'] ?? null,
        ]);
        $notes[] = $r['recorded']
            ? 'Recorded payment ' . $pid . ' for due #' . $due['id']
            : 'Payment ' . $pid . ' was already recorded';
    };

    if ($payment && !empty($payment['id'])) {
        $apply($payment);
    } elseif (!empty($link['payments']) && is_array($link['payments'])) {
        foreach ($link['payments'] as $lp) {
            $apply($lp);
        }
    } else {
        $notes[] = 'Link ' . ($link['id'] ?? '?') . ' status ' . ($link['status'] ?? '?') . ' (no payment data)';
    }
    return implode('; ', $notes);
}

/** Ask Razorpay for the current state of the due's link and record any payments found. */
function fee_sync_link(int $dueId): string
{
    $row = fee_load($dueId);
    if (!$row || empty($row['rzp_link_id'])) {
        return 'No payment link to check.';
    }
    $link = RazorpayClient::fromSettings()->fetchPaymentLink($row['rzp_link_id']);
    return fee_apply_link_event($link, null, 'razorpay_sync');
}

/** Record a cash / UPI / bank payment entered by the admin. */
function fee_mark_paid_manual(int $dueId, float $amount, string $mode, string $notes, ?string $date): array
{
    $paidAt = $date ? date('Y-m-d H:i:s', strtotime($date) ?: time()) : date('Y-m-d H:i:s');
    return fee_record_payment($dueId, [
        'amount'  => $amount,
        'method'  => $mode,
        'status'  => 'captured',
        'source'  => 'manual',
        'notes'   => $notes,
        'paid_at' => $paidAt,
    ]);
}

function fee_cancel_due(int $dueId): void
{
    $row = fee_load($dueId);
    if (!$row) {
        throw new RuntimeException('Due not found');
    }
    if ($row['status'] === 'paid') {
        throw new RuntimeException('A paid due cannot be cancelled.');
    }
    $upd = ['status' => 'cancelled'];
    if (!empty($row['rzp_link_id']) && in_array($row['rzp_status'], ['created', 'partially_paid'], true)) {
        try {
            RazorpayClient::fromSettings()->cancelPaymentLink($row['rzp_link_id']);
            $upd['rzp_status'] = 'cancelled';
        } catch (Throwable $e) {
            log_line('razorpay.log', 'Cancel link failed for due #' . $dueId . ': ' . $e->getMessage());
        }
    }
    db_update('dues', $upd, 'id = ?', [$dueId]);
}

/* ---------------- Monthly generation ---------------- */

/** Create this month's due for every active member with a monthly fee (skips members who already have one). */
function fee_generate_monthly(string $period, string $dueDate, ?string $batch = null, ?int $userId = null): array
{
    $title  = fee_render((string)setting('monthly_title', 'Monthly Fee - {period}'), ['{period}' => period_label($period)]);
    $sql    = "SELECT id, monthly_fee FROM members WHERE status = 'active' AND monthly_fee > 0";
    $params = [];
    if ($batch !== null && $batch !== '') {
        $sql .= ' AND batch = ?';
        $params[] = $batch;
    }
    $created = 0;
    $skipped = 0;
    foreach (db_all($sql, $params) as $m) {
        $exists = db_val('SELECT id FROM dues WHERE member_id = ? AND period = ?', [$m['id'], $period]);
        if ($exists) {
            $skipped++;
            continue;
        }
        db_insert('dues', [
            'member_id'  => $m['id'],
            'title'      => $title,
            'period'     => $period,
            'amount'     => $m['monthly_fee'],
            'due_date'   => $dueDate,
            'status'     => 'pending',
            'created_by' => $userId,
        ]);
        $created++;
    }
    return ['created' => $created, 'skipped' => $skipped, 'title' => $title];
}

/* ---------------- Filters shared by dues.php and bulk actions ---------------- */

function dues_filter(array $f): array
{
    $w = ['1 = 1'];
    $p = [];
    switch ($f['status'] ?? '') {
        case 'pending':
            $w[] = "d.status = 'pending'";
            break;
        case 'sent':
            $w[] = "d.status = 'sent'";
            break;
        case 'partial':
            $w[] = "d.status = 'partial'";
            break;
        case 'paid':
            $w[] = "d.status = 'paid'";
            break;
        case 'cancelled':
            $w[] = "d.status = 'cancelled'";
            break;
        case 'overdue':
            $w[] = "d.status IN ('pending', 'sent', 'partial') AND d.due_date < CURDATE()";
            break;
        case 'unpaid':
            $w[] = "d.status IN ('pending', 'sent', 'partial')";
            break;
    }
    if (!empty($f['period'])) {
        $w[] = 'd.period = ?';
        $p[] = $f['period'];
    }
    if (!empty($f['batch'])) {
        $w[] = 'm.batch = ?';
        $p[] = $f['batch'];
    }
    if (!empty($f['member_id'])) {
        $w[] = 'd.member_id = ?';
        $p[] = (int)$f['member_id'];
    }
    if (!empty($f['q'])) {
        $like = '%' . $f['q'] . '%';
        $w[]  = '(m.name LIKE ? OR m.member_code LIKE ? OR m.phone LIKE ? OR d.title LIKE ?)';
        array_push($p, $like, $like, $like, $like);
    }
    return [implode(' AND ', $w), $p];
}
