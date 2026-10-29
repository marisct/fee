# Boxing Fee Manager

Fee collection for a boxing class / academy, built in plain PHP + MySQL.

* Members with batches and a monthly fee
* One click to generate the month's dues for everyone
* Razorpay **Payment Link** per due, sent on **WhatsApp** (Meta Cloud API)
* Member taps the link, pays, and the due is marked **paid automatically** (Razorpay webhook)
* Optional WhatsApp receipt, automatic reminders, cash/UPI entry, payments report, CSV export
* Mobile-friendly admin (Bootstrap 5), no framework, no Composer

## Requirements

* PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`, `openssl`
* MySQL 5.7+ / MariaDB 10.3+
* A public **https** URL (needed for Razorpay webhooks and WhatsApp links). Shared cPanel hosting is fine.

## Install

1. Upload the folder to your server (e.g. `public_html/fee`).
2. Copy `config.sample.php` to `config.php` and set the database details and `app_url`
   (for example `https://yourgym.com/fee`, no trailing slash).
3. Open `https://yourgym.com/fee/install.php` in the browser. It creates the tables and your admin login.
   (Or import `database.sql` with phpMyAdmin and then open `install.php`.)
4. Log in and open **Settings**.
5. Delete `install.php` from the server.

### Hostinger / cPanel

Run `build-zip.ps1` (Windows PowerShell) to produce `dist/boxing-fee-manager.zip`, then follow
`INSTALL-HOSTINGER.txt` (create the database in hPanel, upload and extract the zip into
`public_html/fee`, edit `config.php`, open `install.php`, delete `install.php`).

### Local test with XAMPP

```bash
php -S localhost:8080
```

Then open http://localhost:8080/install.php (MySQL must be running; `config.php` already points at `root` with no password).

## Razorpay setup (Settings > Razorpay)

1. Dashboard > Account & Settings > **API Keys**: copy Key ID and Key Secret. Use `rzp_test_...` keys first.
2. Dashboard > Account & Settings > **Webhooks** > Add New Webhook:
   * URL: `https://yourgym.com/fee/webhook/razorpay.php`
   * Secret: any strong text, and save the same text in Settings
   * Events: `payment_link.paid`, `payment_link.partially_paid`, `payment_link.expired`, `payment_link.cancelled`
3. Click **Test keys** in Settings.

The app creates one Payment Link per due with `callback_url` set to `thankyou.php`. When the member pays, Razorpay calls the webhook, the due becomes **Paid**, and a receipt is sent on WhatsApp.

## WhatsApp setup (Settings > WhatsApp)

The app uses the official **WhatsApp Cloud API** from Meta.

1. Create a Meta app with the WhatsApp product, add your business number, copy the **Phone number ID**.
2. Create a **permanent token** (Business Settings > System Users > Generate token with
   `whatsapp_business_messaging` and `whatsapp_business_management`).
3. Create message templates (WhatsApp Manager > Message templates, category *Utility*):

   `fee_payment_link` (body):

   ```
   Hi {{1}}, your {{2}} of {{3}} is due on {{4}}. Please pay here: {{5}}
   - Your Boxing Academy
   ```

   Settings > body variables: `{name}, {title}, {amount}, {due_date}, {link}`

   Instead of putting the link in the body you can add a **URL button** with base URL
   `https://yourgym.com/fee/pay.php?t=` and a dynamic suffix; then set *URL button dynamic part* to `{token}`.

   `fee_payment_receipt` (body):

   ```
   Hi {{1}}, we received {{2}} for {{3}}. Payment ID {{4}}. Thank you!
   ```

   Settings > body variables: `{name}, {paid_amount}, {title}, {payment_id}`

4. Use **Send a test message** in Settings to confirm everything works.
5. Optional delivery status: Meta app > WhatsApp > Configuration > Callback URL
   `https://yourgym.com/fee/webhook/whatsapp.php` with the verify token from Settings, subscribe to `messages`.

Plain text mode exists for testing, but WhatsApp only delivers free-form text inside the 24-hour
window after the member messages you. Use templates for real sending.

Using another provider (Interakt, AiSensy, WATI, Gupshup, Twilio)? Replace the two methods in
`includes/whatsapp.php`; nothing else needs to change.

## Daily use

1. **Members**: add members (or import a CSV) with WhatsApp number, batch and monthly fee.
2. **Fee Dues > Generate monthly dues**: creates this month's due for every active member.
3. **Send to all unpaid**: each member gets a WhatsApp message with their own payment link.
4. Members pay; the dashboard shows who paid. Cash/UPI payments can be recorded with the cash button.
5. **Reminders**: set up the daily cron shown in Settings > Reminders to nudge unpaid members automatically.

## Placeholders for messages

`{name} {guardian} {member_code} {batch} {title} {amount} {amount_plain} {balance} {due_date} {period} {link} {token} {institute} {institute_phone}`
and for receipts `{payment_id} {paid_amount} {paid_date}`.

## Files

| Path | Purpose |
|------|---------|
| `index.php` | Dashboard |
| `members.php`, `member_form.php`, `member_view.php`, `members_import.php` | Members |
| `dues.php`, `due_form.php`, `due_action.php` | Fee dues, bulk WhatsApp send, manual payments |
| `payments.php`, `messages.php` | Reports / WhatsApp log |
| `settings.php` | Razorpay, WhatsApp, reminders, account |
| `pay.php` | Public tracked link that redirects to Razorpay |
| `thankyou.php` | Razorpay callback page after payment |
| `webhook/razorpay.php` | Razorpay webhook (marks dues paid) |
| `webhook/whatsapp.php` | WhatsApp delivery status webhook |
| `cron/reminders.php` | Daily reminder job |
| `includes/razorpay.php`, `includes/whatsapp.php`, `includes/fees.php` | Integrations and business logic |

Logs are written to `storage/` (php-error.log, razorpay.log, whatsapp.log, payments.log).
