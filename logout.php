<?php
require __DIR__ . '/includes/bootstrap.php';
logout_user();
redirect(app_url('login.php'));
