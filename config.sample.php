<?php
/**
 * Boxing Fee Manager - configuration
 * Copy this file to config.php and fill in your details.
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'boxing_fee',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Public URL of this app WITHOUT trailing slash, e.g. https://fees.yourgym.com
    // Used for payment links, the Razorpay callback and the webhook URLs shown in Settings.
    // It must be the same address you open in the browser (www vs non-www matters),
    // otherwise you will be logged out after every form submit.
    'app_url'  => 'http://localhost:8080',

    'app_name' => 'Boxing Fee Manager',
    'timezone' => 'Asia/Kolkata',
    'session_name' => 'bfm_session',

    // Show PHP errors on screen (turn OFF on a live server)
    'debug' => false,
];
