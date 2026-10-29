<?php
/**
 * Automatic WhatsApp reminders for unpaid dues.
 *
 * Run once a day, either from the command line:
 *     php /path/to/fee/cron/reminders.php
 * or by opening the URL shown in Settings > Reminders (cron/reminders.php?key=...).
 *
 * A due gets a reminder when: it is unpaid, the due date is within "days before" (or already past),
 * the last message is older than "repeat every N days", and fewer than "max reminders" were sent.
 */
define('NO_SESSION', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $secret = (string)setting('cron_secret', '');
    if ($secret === '' || !hash_equals($secret, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain');
}
set_time_limit(0);

if ((int)setting('reminder_enabled', 1) !== 1) {
    exit("Reminders are disabled in Settings.\n");
}

$daysBefore = max(0, (int)setting('reminder_days_before', 3));
$repeatDays = max(1, (int)setting('reminder_repeat_days', 3));
$maxCount   = max(1, (int)setting('reminder_max', 5));

$rows = db_all(
    "SELECT d.id, d.send_count
     FROM dues d JOIN members m ON m.id = d.member_id
     WHERE d.status IN ('pending','sent','partial')
       AND m.status = 'active' AND m.phone <> ''
       AND d.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
       AND (d.last_sent_at IS NULL OR d.last_sent_at <= DATE_SUB(NOW(), INTERVAL ? DAY))
       AND d.reminder_count < ?
     ORDER BY d.due_date ASC, d.id ASC",
    [$daysBefore, $repeatDays, $maxCount]
);

echo date('Y-m-d H:i:s') . ' - ' . count($rows) . " due(s) need a message\n";
$ok = 0;
$fail = 0;
foreach ($rows as $r) {
    $type = (int)$r['send_count'] > 0 ? 'reminder' : 'fee_link';
    $res  = fee_send_whatsapp((int)$r['id'], $type);
    if ($res['ok']) {
        $ok++;
        echo "  OK   due #{$r['id']}: {$res['message']}\n";
    } else {
        $fail++;
        echo "  FAIL due #{$r['id']}: {$res['error']}\n";
    }
    usleep(200000);
}
echo "Done. Sent: $ok, failed: $fail\n";
log_line('reminders.log', "Sent $ok, failed $fail");
