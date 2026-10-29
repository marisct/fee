-- Boxing Fee Manager - database schema
-- Import this file into an empty MySQL/MariaDB database, or simply open install.php in the browser.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'admin',
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_code VARCHAR(20) NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL COMMENT 'WhatsApp number, digits with country code e.g. 919876543210',
  alt_phone VARCHAR(20) NULL,
  email VARCHAR(150) NULL,
  guardian_name VARCHAR(120) NULL,
  gender VARCHAR(10) NULL,
  dob DATE NULL,
  batch VARCHAR(60) NULL,
  monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  join_date DATE NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_members_phone (phone),
  KEY idx_members_batch (batch),
  KEY idx_members_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dues (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  period CHAR(7) NULL COMMENT 'YYYY-MM for monthly fees',
  amount DECIMAL(10,2) NOT NULL,
  amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
  due_date DATE NOT NULL,
  status ENUM('pending','sent','partial','paid','cancelled') NOT NULL DEFAULT 'pending',
  payment_mode VARCHAR(20) NULL,
  notes VARCHAR(255) NULL,
  rzp_link_id VARCHAR(40) NULL,
  rzp_short_url VARCHAR(255) NULL,
  rzp_reference_id VARCHAR(40) NULL,
  rzp_status VARCHAR(20) NULL,
  link_token CHAR(32) NULL UNIQUE,
  link_created_at DATETIME NULL,
  link_expires_at DATETIME NULL,
  link_clicked_at DATETIME NULL,
  link_clicks INT UNSIGNED NOT NULL DEFAULT 0,
  last_sent_at DATETIME NULL,
  send_count INT UNSIGNED NOT NULL DEFAULT 0,
  reminder_count INT UNSIGNED NOT NULL DEFAULT 0,
  paid_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dues_member_period (member_id, period),
  KEY idx_dues_status (status),
  KEY idx_dues_due_date (due_date),
  KEY idx_dues_rzp_link (rzp_link_id),
  CONSTRAINT fk_dues_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  due_id INT UNSIGNED NULL,
  member_id INT UNSIGNED NOT NULL,
  rzp_payment_id VARCHAR(40) NULL UNIQUE,
  rzp_order_id VARCHAR(40) NULL,
  rzp_link_id VARCHAR(40) NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  method VARCHAR(30) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'captured',
  payer_email VARCHAR(150) NULL,
  payer_contact VARCHAR(20) NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'razorpay_webhook',
  notes VARCHAR(255) NULL,
  raw_json MEDIUMTEXT NULL,
  paid_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payments_paid_at (paid_at),
  CONSTRAINT fk_payments_due FOREIGN KEY (due_id) REFERENCES dues(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NULL,
  due_id INT UNSIGNED NULL,
  phone VARCHAR(20) NOT NULL,
  type ENUM('fee_link','reminder','receipt','test','custom') NOT NULL DEFAULT 'fee_link',
  provider VARCHAR(20) NOT NULL DEFAULT 'meta',
  provider_msg_id VARCHAR(120) NULL,
  status ENUM('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'queued',
  error VARCHAR(500) NULL,
  body TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_messages_provider_msg (provider_msg_id),
  KEY idx_messages_created (created_at),
  CONSTRAINT fk_messages_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  CONSTRAINT fk_messages_due FOREIGN KEY (due_id) REFERENCES dues(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(20) NOT NULL,
  event VARCHAR(80) NULL,
  remote_id VARCHAR(120) NULL,
  payload MEDIUMTEXT NULL,
  processed TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_webhook_remote (remote_id),
  KEY idx_webhook_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings (existing values are kept)
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('institute_name', 'Boxing Academy'),
('institute_phone', ''),
('institute_address', ''),
('country_code', '91'),
('currency', 'INR'),
('due_day', '5'),
('monthly_title', 'Monthly Fee - {period}'),
('link_mode', 'tracked'),
('rzp_key_id', ''),
('rzp_key_secret', ''),
('rzp_webhook_secret', ''),
('rzp_link_expiry_days', '30'),
('rzp_accept_partial', '0'),
('wa_token', ''),
('wa_phone_number_id', ''),
('wa_api_version', 'v22.0'),
('wa_verify_token', ''),
('wa_mode', 'template'),
('wa_tpl_link_name', 'fee_payment_link'),
('wa_tpl_link_lang', 'en'),
('wa_tpl_link_params', '{name}, {title}, {amount}, {due_date}, {link}'),
('wa_tpl_link_button_param', ''),
('wa_tpl_link_button_index', '0'),
('wa_tpl_reminder_name', ''),
('wa_tpl_reminder_lang', 'en'),
('wa_tpl_reminder_params', ''),
('wa_tpl_reminder_button_param', ''),
('wa_receipt_enabled', '1'),
('wa_tpl_receipt_name', 'fee_payment_receipt'),
('wa_tpl_receipt_lang', 'en'),
('wa_tpl_receipt_params', '{name}, {paid_amount}, {title}, {payment_id}'),
('wa_text_link', 'Hi {name}, your {title} of {amount} for {institute} is due on {due_date}.\n\nPay securely using this link: {link}\n\nThank you!'),
('wa_text_receipt', 'Hi {name}, we have received your payment of {paid_amount} for {title}.\nPayment ID: {payment_id}\n\nThank you! - {institute}'),
('reminder_enabled', '1'),
('reminder_days_before', '3'),
('reminder_repeat_days', '3'),
('reminder_max', '5'),
('cron_secret', '');
