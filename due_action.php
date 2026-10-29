<?php
/**
 * Handles all POST actions on dues: send / resend WhatsApp, bulk send, mark paid, sync, cancel, delete, generate.
 */
require __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(app_url('dues.php'));
}
csrf_verify();

$action = post('action');
$id     = (int)post('id');
$back   = post('back', 'dues.php');
// Only allow redirects to pages inside this app
if ($back === '' || preg_match('#^(https?:)?//#i', $back) || str_contains($back, "\n")) {
    $back = 'dues.php';
}
$backUrl = app_url($back);

/** Summarise a list of send results into one flash message. */
function flash_send_results(array $results): void
{
    $ok = 0;
    $errors = [];
    foreach ($results as $r) {
        if ($r['ok']) {
            $ok++;
        } else {
            $errors[] = $r['error'];
        }
    }
    if ($ok > 0) {
        flash('success', "$ok WhatsApp message(s) sent.");
    }
    if ($errors) {
        $shown = array_slice($errors, 0, 10);
        $more  = count($errors) - count($shown);
        flash('danger', count($errors) . " failed:\n- " . implode("\n- ", $shown) . ($more > 0 ? "\n... and $more more (see WhatsApp Log)" : ''));
    }
    if ($ok === 0 && !$errors) {
        flash('warning', 'Nothing to send.');
    }
}

try {
    switch ($action) {
        case 'send':
            if ($id <= 0) {
                throw new RuntimeException('No due selected.');
            }
            $due = db_row('SELECT send_count FROM dues WHERE id = ?', [$id]);
            $type = ($due && (int)$due['send_count'] > 0) ? 'reminder' : 'fee_link';
            $r = fee_send_whatsapp($id, $type);
            flash($r['ok'] ? 'success' : 'danger', $r['ok'] ? $r['message'] : $r['error']);
            break;

        case 'bulk_send':
        case 'send_filtered':
            set_time_limit(0);
            if ($action === 'bulk_send') {
                $ids = array_map('intval', (array)($_POST['ids'] ?? []));
            } else {
                $filter = (array)($_POST['filter'] ?? []);
                $filter = array_map(fn($v) => is_string($v) ? trim($v) : '', $filter);
                if (!in_array($filter['status'] ?? '', ['unpaid', 'pending', 'sent', 'overdue', 'partial'], true)) {
                    $filter['status'] = 'unpaid';
                }
                [$where, $params] = dues_filter($filter);
                $ids = array_column(db_all("SELECT d.id FROM dues d JOIN members m ON m.id = d.member_id WHERE $where AND d.status IN ('pending','sent','partial') ORDER BY d.id", $params), 'id');
            }
            $results = [];
            foreach ($ids as $dueId) {
                if ($dueId <= 0) {
                    continue;
                }
                $due = db_row('SELECT send_count FROM dues WHERE id = ?', [$dueId]);
                $type = ($due && (int)$due['send_count'] > 0) ? 'reminder' : 'fee_link';
                $results[] = fee_send_whatsapp((int)$dueId, $type);
                usleep(200000); // be gentle with the WhatsApp API rate limits
            }
            flash_send_results($results);
            break;

        case 'mark_paid':
            $amount = (float)post('amount');
            if ($id <= 0 || $amount <= 0) {
                throw new RuntimeException('Enter a valid amount.');
            }
            $mode = post('mode', 'cash');
            if (!in_array($mode, ['cash', 'upi', 'bank', 'card', 'other'], true)) {
                $mode = 'other';
            }
            $cur = db_row('SELECT status, amount, amount_paid FROM dues WHERE id = ?', [$id]);
            if (!$cur) {
                throw new RuntimeException('Due not found.');
            }
            if (in_array($cur['status'], ['paid', 'cancelled'], true)) {
                throw new RuntimeException('This due is already ' . $cur['status'] . '; no payment was recorded.');
            }
            if ($amount > (float)$cur['amount'] - (float)$cur['amount_paid'] + 0.005) {
                throw new RuntimeException('Amount is more than the balance of ' . money((float)$cur['amount'] - (float)$cur['amount_paid']) . '.');
            }
            $r = fee_mark_paid_manual($id, $amount, $mode, post('notes'), post('date') ?: null);
            $st = $r['due']['status'];
            flash('success', 'Payment of ' . money($amount) . ' recorded. Due is now ' . $st . '.');
            if ($st === 'paid' && !empty($r['due']['rzp_link_id']) && in_array($r['due']['rzp_status'], ['created', 'partially_paid'], true)) {
                // Close the online link so the member does not pay twice
                try {
                    RazorpayClient::fromSettings()->cancelPaymentLink($r['due']['rzp_link_id']);
                    db_update('dues', ['rzp_status' => 'cancelled'], 'id = ?', [$id]);
                } catch (Throwable $e) {
                    log_line('razorpay.log', 'Could not cancel link after manual payment for due #' . $id . ': ' . $e->getMessage());
                }
            }
            break;

        case 'sync':
            if ($id <= 0) {
                throw new RuntimeException('No due selected.');
            }
            flash('info', fee_sync_link($id));
            break;

        case 'cancel':
            if ($id <= 0) {
                throw new RuntimeException('No due selected.');
            }
            fee_cancel_due($id);
            flash('success', 'Due cancelled.');
            break;

        case 'delete':
            $due = db_row('SELECT status FROM dues WHERE id = ?', [$id]);
            if (!$due || $due['status'] !== 'cancelled') {
                throw new RuntimeException('Only cancelled dues can be deleted.');
            }
            db_query('DELETE FROM dues WHERE id = ?', [$id]);
            flash('success', 'Due deleted.');
            break;

        case 'generate':
            $period  = post('period');
            $dueDate = post('due_date');
            $batch   = post('batch');
            if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
                throw new RuntimeException('Please choose a month.');
            }
            if (!$dueDate || !strtotime($dueDate)) {
                throw new RuntimeException('Please choose a due date.');
            }
            $r = fee_generate_monthly($period, date('Y-m-d', strtotime($dueDate)), $batch ?: null, current_user()['id']);
            flash($r['created'] > 0 ? 'success' : 'warning', $r['created'] . ' due(s) created for ' . period_label($period) . ' ("' . $r['title'] . '"), ' . $r['skipped'] . ' member(s) already had one.');
            $backUrl = app_url('dues.php?status=pending&period=' . $period);
            break;

        default:
            throw new RuntimeException('Unknown action.');
    }
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
}

redirect($backUrl);
