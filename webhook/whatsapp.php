<?php
/**
 * WhatsApp Cloud API webhook: verification handshake + delivery status updates (sent/delivered/read/failed).
 * In Meta for Developers > WhatsApp > Configuration, set the callback URL shown in Settings > WhatsApp
 * with the same verify token, and subscribe to the "messages" field.
 */
define('NO_SESSION', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = (string)($_GET['hub_mode'] ?? '');
    $token     = (string)($_GET['hub_verify_token'] ?? '');
    $challenge = (string)($_GET['hub_challenge'] ?? '');
    $expected  = (string)setting('wa_verify_token', '');
    if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit('Verification failed');
}

$raw  = (string)file_get_contents('php://input');
$data = json_decode($raw, true);
$logId = db_insert('webhook_logs', ['source' => 'whatsapp', 'event' => 'message', 'payload' => mb_substr($raw, 0, 1000000)]);

$updated = 0;
$incoming = 0;
if (is_array($data)) {
    foreach ($data['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            $value = $change['value'] ?? [];
            foreach ($value['statuses'] ?? [] as $st) {
                $id     = (string)($st['id'] ?? '');
                $status = (string)($st['status'] ?? '');
                if ($id === '' || !in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
                    continue;
                }
                $upd = ['status' => $status];
                if ($status === 'failed') {
                    $errs = [];
                    foreach ($st['errors'] ?? [] as $er) {
                        $errs[] = ($er['code'] ?? '') . ' ' . ($er['title'] ?? '') . (isset($er['error_data']['details']) ? ': ' . $er['error_data']['details'] : '');
                    }
                    $upd['error'] = mb_substr(implode('; ', $errs) ?: 'Delivery failed', 0, 500);
                }
                // Never downgrade read -> delivered -> sent when statuses arrive out of order
                $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
                $cur  = db_row('SELECT id, status FROM messages WHERE provider_msg_id = ?', [$id]);
                if ($cur && ($rank[$status] >= ($rank[$cur['status']] ?? 0))) {
                    db_update('messages', $upd, 'id = ?', [$cur['id']]);
                    $updated++;
                }
            }
            $incoming += count($value['messages'] ?? []);
        }
    }
}
db_update('webhook_logs', ['processed' => 1, 'note' => "$updated status update(s), $incoming incoming message(s)"], 'id = ?', [$logId]);
json_response(['status' => 'ok']);
