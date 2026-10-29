<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$secretKeys = ['rzp_key_secret', 'rzp_webhook_secret', 'wa_token'];
$checkKeys  = ['rzp_accept_partial', 'wa_receipt_enabled', 'reminder_enabled'];
$textKeys   = [
    'institute_name', 'institute_phone', 'institute_address', 'country_code', 'currency', 'due_day', 'monthly_title', 'link_mode',
    'rzp_key_id', 'rzp_link_expiry_days',
    'wa_phone_number_id', 'wa_api_version', 'wa_verify_token', 'wa_mode',
    'wa_tpl_link_name', 'wa_tpl_link_lang', 'wa_tpl_link_params', 'wa_tpl_link_button_param', 'wa_tpl_link_button_index',
    'wa_tpl_reminder_name', 'wa_tpl_reminder_lang', 'wa_tpl_reminder_params', 'wa_tpl_reminder_button_param',
    'wa_tpl_receipt_name', 'wa_tpl_receipt_lang', 'wa_tpl_receipt_params',
    'wa_text_link', 'wa_text_receipt',
    'reminder_days_before', 'reminder_repeat_days', 'reminder_max', 'cron_secret',
];
$tab = get('tab', 'academy');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do  = post('do');
    $tab = post('tab', $tab);
    try {
        if ($do === 'save') {
            foreach ($textKeys as $k) {
                if (array_key_exists($k, $_POST)) {
                    set_setting($k, trim((string)$_POST[$k]));
                }
            }
            foreach ($secretKeys as $k) {
                $v = trim((string)($_POST[$k] ?? ''));
                if ($v !== '') {
                    set_setting($k, $v);
                }
            }
            if (post('tab') !== '') {
                foreach ($checkKeys as $k) {
                    if (array_key_exists('has_' . $k, $_POST)) {
                        set_setting($k, isset($_POST[$k]) ? '1' : '0');
                    }
                }
            }
            flash('success', 'Settings saved.');
        } elseif ($do === 'clear_secret') {
            $k = post('key');
            if (in_array($k, $secretKeys, true)) {
                set_setting($k, '');
                flash('success', 'Cleared.');
            }
        } elseif ($do === 'test_rzp') {
            RazorpayClient::fromSettings()->ping();
            flash('success', 'Razorpay keys work. Mode: ' . (str_starts_with((string)setting('rzp_key_id'), 'rzp_test') ? 'TEST' : 'LIVE'));
        } elseif ($do === 'test_wa') {
            $to = normalize_phone(post('test_phone'));
            if (strlen($to) < 10) {
                throw new RuntimeException('Enter a valid phone number.');
            }
            $row = [
                'id' => null, 'member_id' => null, 'phone' => $to, 'member_name' => 'Test Member', 'member_code' => 'BX0000',
                'guardian_name' => '', 'batch' => 'Demo batch', 'title' => 'Monthly Fee - ' . period_label(date('Y-m')),
                'amount' => 1500, 'amount_paid' => 0, 'due_date' => date('Y-m-d'), 'period' => date('Y-m'),
                'link_token' => str_repeat('0', 32), 'rzp_short_url' => 'https://rzp.io/i/test',
            ];
            $res = fee_send_message($row, 'link', fee_placeholders($row), 'test');
            flash($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Test message sent to +' . $to . ' (id ' . $res['id'] . ').' : 'WhatsApp error: ' . $res['error']);
        } elseif ($do === 'account') {
            $u = current_user();
            $name = post('name');
            $email = strtolower(post('email'));
            $pass = (string)($_POST['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Name and a valid email are required.');
            }
            $upd = ['name' => $name, 'email' => $email];
            if ($pass !== '') {
                if (strlen($pass) < 6) {
                    throw new RuntimeException('Password must be at least 6 characters.');
                }
                $upd['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
            }
            db_update('users', $upd, 'id = ?', [$u['id']]);
            flash('success', 'Account updated.');
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect(app_url('settings.php?tab=' . urlencode($tab)));
}

$s = fn(string $k, string $d = '') => (string)setting($k, $d);
$secretField = function (string $key, string $label, string $help = '') use ($s): string {
    $has = $s($key) !== '';
    $h = '<label class="form-label">' . e($label) . '</label>';
    $h .= '<div class="input-group"><input type="password" name="' . e($key) . '" class="form-control" autocomplete="new-password" placeholder="' . ($has ? 'Saved - leave blank to keep' : 'Not set') . '">';
    if ($has) {
        $h .= '<button class="btn btn-outline-danger" name="do" value="clear_secret" formnovalidate onclick="this.form.querySelector(\'[name=key]\').value=\'' . e($key) . '\'">Clear</button>';
    }
    $h .= '</div>';
    if ($help) {
        $h .= '<div class="form-text">' . $help . '</div>';
    }
    return $h;
};
$placeholders = '{name} {guardian} {member_code} {batch} {title} {amount} {amount_plain} {balance} {due_date} {period} {link} {token} {institute} {institute_phone} - receipts also: {payment_id} {paid_amount} {paid_date}';
$tabs = ['academy' => 'Academy', 'razorpay' => 'Razorpay', 'whatsapp' => 'WhatsApp', 'reminders' => 'Reminders', 'account' => 'Account'];
if (!isset($tabs[$tab])) {
    $tab = 'academy';
}
$user = current_user();

$pageTitle = 'Settings';
$pageSubtitle = $tabs[$tab];
$active = 'settings';
require __DIR__ . '/includes/layout_top.php';
?>
<div class="frappe-tabs">
  <?php foreach ($tabs as $k => $label): ?>
    <a class="<?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'academy'): ?>
<form method="post" class="card" style="max-width: 760px;">
  <div class="card-body"><?= csrf_field() ?><input type="hidden" name="tab" value="academy"><input type="hidden" name="key" value="">
    <div class="row g-3">
      <div class="col-12 col-md-6"><label class="form-label">Academy / club name</label><input name="institute_name" class="form-control" value="<?= e($s('institute_name')) ?>"></div>
      <div class="col-12 col-md-6"><label class="form-label">Contact phone (shown to members)</label><input name="institute_phone" class="form-control" value="<?= e($s('institute_phone')) ?>"></div>
      <div class="col-12"><label class="form-label">Address</label><input name="institute_address" class="form-control" value="<?= e($s('institute_address')) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label">Country code</label><input name="country_code" class="form-control" value="<?= e($s('country_code', '91')) ?>"><div class="form-text">Added to 10-digit numbers.</div></div>
      <div class="col-6 col-md-3"><label class="form-label">Currency</label><input name="currency" class="form-control" value="<?= e($s('currency', 'INR')) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label">Monthly due day</label><input name="due_day" type="number" min="1" max="28" class="form-control" value="<?= e($s('due_day', '5')) ?>"><div class="form-text">Default due date when generating monthly dues.</div></div>
      <div class="col-12 col-md-6"><label class="form-label">Monthly due title</label><input name="monthly_title" class="form-control" value="<?= e($s('monthly_title', 'Monthly Fee - {period}')) ?>"><div class="form-text">{period} becomes e.g. "Nov 2026".</div></div>
    </div>
  </div>
  <div class="card-footer"><button class="btn btn-primary" name="do" value="save">Save</button></div>
</form>

<?php elseif ($tab === 'razorpay'): ?>
<div class="row g-3">
  <div class="col-12 col-lg-7">
    <form method="post" class="card">
      <div class="card-body"><?= csrf_field() ?><input type="hidden" name="tab" value="razorpay"><input type="hidden" name="key" value="">
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Key ID</label><input name="rzp_key_id" class="form-control code" value="<?= e($s('rzp_key_id')) ?>" placeholder="rzp_test_xxxxxxxx or rzp_live_xxxxxxxx"><div class="form-text">Razorpay Dashboard &rarr; Account &amp; Settings &rarr; API Keys.</div></div>
          <div class="col-12"><?= $secretField('rzp_key_secret', 'Key Secret') ?></div>
          <div class="col-12"><?= $secretField('rzp_webhook_secret', 'Webhook Secret', 'The secret you type when creating the webhook in Razorpay (any strong text). Must match exactly.') ?></div>
          <div class="col-6 col-md-4"><label class="form-label">Link validity (days)</label><input name="rzp_link_expiry_days" type="number" min="1" max="180" class="form-control" value="<?= e($s('rzp_link_expiry_days', '30')) ?>"></div>
          <div class="col-6 col-md-4"><label class="form-label">Link in message</label>
            <select name="link_mode" class="form-select"><option value="tracked"<?= $s('link_mode', 'tracked') === 'tracked' ? ' selected' : '' ?>>Tracked (your domain)</option><option value="direct"<?= $s('link_mode') === 'direct' ? ' selected' : '' ?>>Direct rzp.io link</option></select>
            <div class="form-text">Tracked links record clicks and self-heal expired links.</div></div>
          <div class="col-12 col-md-4"><input type="hidden" name="has_rzp_accept_partial" value="1"><div class="form-check mt-md-4"><input class="form-check-input" type="checkbox" name="rzp_accept_partial" id="partial" value="1"<?= $s('rzp_accept_partial', '0') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="partial">Allow partial payments</label></div></div>
        </div>
      </div>
      <div class="card-footer d-flex gap-2"><button class="btn btn-primary" name="do" value="save">Save</button><button class="btn btn-outline-secondary" name="do" value="test_rzp" formnovalidate><i class="bi bi-plug me-1"></i>Test keys</button></div>
    </form>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card"><div class="card-body small">
      <h2 class="h6">Webhook setup (needed to mark fees paid automatically)</h2>
      <ol class="ps-3 mb-2">
        <li>Razorpay Dashboard &rarr; Account &amp; Settings &rarr; Webhooks &rarr; Add New Webhook.</li>
        <li>Webhook URL:<div class="copy-box my-1" id="whUrl"><?= e(app_url('webhook/razorpay.php')) ?></div><button type="button" class="btn btn-outline-secondary btn-sm" data-copy="#whUrl"><i class="bi bi-clipboard"></i> Copy</button></li>
        <li class="mt-2">Secret: the same text you saved as <strong>Webhook Secret</strong> here.</li>
        <li>Active events: <code>payment_link.paid</code>, <code>payment_link.partially_paid</code>, <code>payment_link.expired</code>, <code>payment_link.cancelled</code>.</li>
      </ol>
      <p class="mb-1">After paying, members are redirected to:</p>
      <div class="copy-box mb-2"><?= e(app_url('thankyou.php')) ?></div>
      <p class="mb-0 text-secondary">Your <code>app_url</code> in config.php must be the public https address of this site for links and webhooks to work. Current: <strong><?= e(app_url('')) ?></strong></p>
    </div></div>
  </div>
</div>

<?php elseif ($tab === 'whatsapp'): ?>
<div class="row g-3">
  <div class="col-12 col-lg-7">
    <form method="post" class="card">
      <div class="card-body"><?= csrf_field() ?><input type="hidden" name="tab" value="whatsapp"><input type="hidden" name="key" value="">
        <h2 class="h6">Meta WhatsApp Cloud API</h2>
        <div class="row g-3 mb-4">
          <div class="col-12"><?= $secretField('wa_token', 'Access token', 'Use a permanent System User token from Meta Business Settings (temporary tokens expire in 24 hours).') ?></div>
          <div class="col-12 col-md-6"><label class="form-label">Phone number ID</label><input name="wa_phone_number_id" class="form-control code" value="<?= e($s('wa_phone_number_id')) ?>"><div class="form-text">Meta for Developers &rarr; WhatsApp &rarr; API Setup.</div></div>
          <div class="col-6 col-md-3"><label class="form-label">API version</label><input name="wa_api_version" class="form-control code" value="<?= e($s('wa_api_version', 'v22.0')) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label">Message mode</label>
            <select name="wa_mode" class="form-select"><option value="template"<?= $s('wa_mode', 'template') === 'template' ? ' selected' : '' ?>>Template</option><option value="text"<?= $s('wa_mode') === 'text' ? ' selected' : '' ?>>Plain text</option></select></div>
          <div class="col-12"><label class="form-label">Webhook verify token</label><input name="wa_verify_token" class="form-control code" value="<?= e($s('wa_verify_token')) ?>"><div class="form-text">Any text; enter the same value in Meta when setting the webhook URL (for delivery status).</div></div>
        </div>

        <h2 class="h6">Fee link template <span class="text-secondary fw-normal">(business-initiated messages must use an approved template)</span></h2>
        <div class="row g-3 mb-4">
          <div class="col-8 col-md-6"><label class="form-label">Template name</label><input name="wa_tpl_link_name" class="form-control code" value="<?= e($s('wa_tpl_link_name')) ?>"></div>
          <div class="col-4 col-md-2"><label class="form-label">Language</label><input name="wa_tpl_link_lang" class="form-control code" value="<?= e($s('wa_tpl_link_lang', 'en')) ?>"></div>
          <div class="col-12"><label class="form-label">Body variables ({{1}}, {{2}} ... in order, comma separated)</label><input name="wa_tpl_link_params" class="form-control code" value="<?= e($s('wa_tpl_link_params')) ?>"></div>
          <div class="col-8 col-md-6"><label class="form-label">URL button dynamic part (optional)</label><input name="wa_tpl_link_button_param" class="form-control code" value="<?= e($s('wa_tpl_link_button_param')) ?>" placeholder="{token}"><div class="form-text">If your template has a URL button like <code><?= e(app_url('pay.php?t=')) ?>{{1}}</code>, put <code>{token}</code> here.</div></div>
          <div class="col-4 col-md-2"><label class="form-label">Button index</label><input name="wa_tpl_link_button_index" class="form-control" value="<?= e($s('wa_tpl_link_button_index', '0')) ?>"></div>
        </div>

        <h2 class="h6">Reminder template <span class="text-secondary fw-normal">(optional; leave empty to reuse the fee link template)</span></h2>
        <div class="row g-3 mb-4">
          <div class="col-8 col-md-6"><label class="form-label">Template name</label><input name="wa_tpl_reminder_name" class="form-control code" value="<?= e($s('wa_tpl_reminder_name')) ?>"></div>
          <div class="col-4 col-md-2"><label class="form-label">Language</label><input name="wa_tpl_reminder_lang" class="form-control code" value="<?= e($s('wa_tpl_reminder_lang', 'en')) ?>"></div>
          <div class="col-12 col-md-8"><label class="form-label">Body variables</label><input name="wa_tpl_reminder_params" class="form-control code" value="<?= e($s('wa_tpl_reminder_params')) ?>"></div>
          <div class="col-12 col-md-4"><label class="form-label">URL button dynamic part</label><input name="wa_tpl_reminder_button_param" class="form-control code" value="<?= e($s('wa_tpl_reminder_button_param')) ?>"></div>
        </div>

        <h2 class="h6">Payment receipt template</h2>
        <div class="row g-3 mb-4">
          <div class="col-12"><input type="hidden" name="has_wa_receipt_enabled" value="1"><div class="form-check"><input class="form-check-input" type="checkbox" name="wa_receipt_enabled" id="rcpt" value="1"<?= $s('wa_receipt_enabled', '1') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="rcpt">Send a WhatsApp confirmation when a fee is paid</label></div></div>
          <div class="col-8 col-md-6"><label class="form-label">Template name</label><input name="wa_tpl_receipt_name" class="form-control code" value="<?= e($s('wa_tpl_receipt_name')) ?>"></div>
          <div class="col-4 col-md-2"><label class="form-label">Language</label><input name="wa_tpl_receipt_lang" class="form-control code" value="<?= e($s('wa_tpl_receipt_lang', 'en')) ?>"></div>
          <div class="col-12"><label class="form-label">Body variables</label><input name="wa_tpl_receipt_params" class="form-control code" value="<?= e($s('wa_tpl_receipt_params')) ?>"></div>
        </div>

        <h2 class="h6">Plain text mode <span class="text-secondary fw-normal">(only delivered inside the 24-hour window after the member messages you)</span></h2>
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Fee link message</label><textarea name="wa_text_link" class="form-control" rows="4"><?= e($s('wa_text_link')) ?></textarea></div>
          <div class="col-12"><label class="form-label">Receipt message</label><textarea name="wa_text_receipt" class="form-control" rows="3"><?= e($s('wa_text_receipt')) ?></textarea></div>
          <div class="col-12 form-text">Placeholders: <?= e($placeholders) ?></div>
        </div>
      </div>
      <div class="card-footer"><button class="btn btn-primary" name="do" value="save">Save</button></div>
    </form>
  </div>
  <div class="col-12 col-lg-5">
    <form method="post" class="card mb-3">
      <div class="card-body"><?= csrf_field() ?><input type="hidden" name="tab" value="whatsapp">
        <h2 class="h6">Send a test message</h2>
        <p class="small text-secondary">Sends the fee link template (or text) with sample values using the saved settings.</p>
        <div class="input-group"><input name="test_phone" class="form-control" placeholder="98765 43210" inputmode="tel" required><button class="btn btn-success" name="do" value="test_wa"><i class="bi bi-whatsapp me-1"></i>Send</button></div>
      </div>
    </form>
    <div class="card"><div class="card-body small">
      <h2 class="h6">Setup steps</h2>
      <ol class="ps-3 mb-3">
        <li>Create a Meta Business app with the WhatsApp product, add your business phone number and copy the <strong>Phone number ID</strong>.</li>
        <li>Create a permanent token: Business Settings &rarr; System Users &rarr; Generate token with <code>whatsapp_business_messaging</code> and <code>whatsapp_business_management</code>.</li>
        <li>Create templates under WhatsApp Manager &rarr; Message templates (category: Utility). Example body for <code>fee_payment_link</code>:
          <div class="copy-box my-1">Hi {{1}}, your {{2}} of {{3}} is due on {{4}}. Please pay here: {{5}}<br>- <?= e($s('institute_name', 'Boxing Academy')) ?></div>
          With body variables <code>{name}, {title}, {amount}, {due_date}, {link}</code>. Alternatively add a URL button with base URL <code><?= e(app_url('pay.php?t=')) ?></code> and dynamic suffix, then set the button part to <code>{token}</code>.</li>
        <li>Example receipt template <code>fee_payment_receipt</code>: <em>Hi {{1}}, we received {{2}} for {{3}}. Payment ID {{4}}. Thank you!</em></li>
        <li>Delivery status webhook (optional): Meta app &rarr; WhatsApp &rarr; Configuration &rarr; Callback URL:
          <div class="copy-box my-1" id="waWh"><?= e(app_url('webhook/whatsapp.php')) ?></div><button type="button" class="btn btn-outline-secondary btn-sm" data-copy="#waWh"><i class="bi bi-clipboard"></i> Copy</button> with the verify token above; subscribe to <code>messages</code>.</li>
      </ol>
      <p class="mb-0 text-secondary">Using a provider like Interakt, AiSensy, WATI or Gupshup instead? Only <code>includes/whatsapp.php</code> needs to change.</p>
    </div></div>
  </div>
</div>

<?php elseif ($tab === 'reminders'): ?>
<div class="row g-3">
  <div class="col-12 col-lg-6">
    <form method="post" class="card">
      <div class="card-body"><?= csrf_field() ?><input type="hidden" name="tab" value="reminders"><input type="hidden" name="key" value="">
        <input type="hidden" name="has_reminder_enabled" value="1">
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="reminder_enabled" id="rem" value="1"<?= $s('reminder_enabled', '1') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="rem">Enable automatic reminders (needs the daily cron below)</label></div>
        <div class="row g-3">
          <div class="col-4"><label class="form-label">Days before due date</label><input type="number" min="0" name="reminder_days_before" class="form-control" value="<?= e($s('reminder_days_before', '3')) ?>"></div>
          <div class="col-4"><label class="form-label">Repeat every (days)</label><input type="number" min="1" name="reminder_repeat_days" class="form-control" value="<?= e($s('reminder_repeat_days', '3')) ?>"></div>
          <div class="col-4"><label class="form-label">Max reminders</label><input type="number" min="1" name="reminder_max" class="form-control" value="<?= e($s('reminder_max', '5')) ?>"></div>
          <div class="col-12"><label class="form-label">Cron secret key</label><input name="cron_secret" class="form-control code" value="<?= e($s('cron_secret')) ?>"></div>
        </div>
      </div>
      <div class="card-footer"><button class="btn btn-primary" name="do" value="save">Save</button></div>
    </form>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card"><div class="card-body small">
      <h2 class="h6">Daily cron</h2>
      <p>Run once a day (e.g. 10:00). In cPanel &rarr; Cron Jobs, or any uptime/cron service, use one of:</p>
      <div class="copy-box mb-2" id="cronUrl"><?= e(app_url('cron/reminders.php?key=' . $s('cron_secret'))) ?></div>
      <button type="button" class="btn btn-outline-secondary btn-sm mb-3" data-copy="#cronUrl"><i class="bi bi-clipboard"></i> Copy URL</button>
      <div class="copy-box mb-2">0 10 * * * php <?= e(str_replace('\\', '/', APP_ROOT)) ?>/cron/reminders.php</div>
      <p class="mb-0 text-secondary">Reminders use the fee link template (or the reminder template if set). Members who already paid are never messaged.</p>
    </div></div>
  </div>
</div>

<?php else: ?>
<form method="post" class="card" style="max-width: 560px;">
  <div class="card-body"><?= csrf_field() ?><input type="hidden" name="tab" value="account">
    <div class="mb-3"><label class="form-label">Name</label><input name="name" class="form-control" required value="<?= e($user['name']) ?>"></div>
    <div class="mb-3"><label class="form-label">Login email</label><input type="email" name="email" class="form-control" required value="<?= e($user['email']) ?>"></div>
    <div class="mb-0"><label class="form-label">New password</label><input type="password" name="password" class="form-control" autocomplete="new-password" placeholder="Leave blank to keep current"></div>
  </div>
  <div class="card-footer"><button class="btn btn-primary" name="do" value="account">Update account</button></div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
