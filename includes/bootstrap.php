<?php
/**
 * Boxing Fee Manager - application bootstrap.
 * Every page includes this file first.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('config.php not found. Copy config.sample.php to config.php and fill in your database details.');
}
$CONFIG = require $configFile;

date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Kolkata');
error_reporting(E_ALL);
ini_set('display_errors', !empty($CONFIG['debug']) ? '1' : '0');
ini_set('log_errors', '1');
if (!is_dir(APP_ROOT . '/storage')) {
    @mkdir(APP_ROOT . '/storage', 0775, true);
}
ini_set('error_log', APP_ROOT . '/storage/php-error.log');
mb_internal_encoding('UTF-8');

require APP_ROOT . '/includes/db.php';
require APP_ROOT . '/includes/helpers.php';
require APP_ROOT . '/includes/auth.php';
require APP_ROOT . '/includes/razorpay.php';
require APP_ROOT . '/includes/whatsapp.php';
require APP_ROOT . '/includes/fees.php';

// Sessions are not needed for webhooks / cron (they define NO_SESSION before including this file)
if (PHP_SAPI !== 'cli' && !defined('NO_SESSION')) {
    session_name($CONFIG['session_name'] ?? 'bfm_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => is_https(),
    ]);
    session_start();
}
