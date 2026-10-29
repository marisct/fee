<?php
/**
 * Admin login / session helpers.
 */
declare(strict_types=1);

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $id = (int)($_SESSION['uid'] ?? 0);
        $user = $id > 0 ? db_row('SELECT id, name, email, role FROM users WHERE id = ? AND active = 1', [$id]) : null;
    }
    return $user;
}

function require_login(): void
{
    if (!current_user()) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect(app_url('login.php'));
    }
}

function attempt_login(string $email, string $password): bool
{
    $u = db_row('SELECT * FROM users WHERE email = ? AND active = 1', [strtolower(trim($email))]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    db_update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$u['id']]);
    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
