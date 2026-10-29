<?php
/**
 * General helpers: escaping, URLs, flash messages, CSRF, settings, formatting.
 */
declare(strict_types=1);

function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/** Absolute URL to a file inside the app. Uses app_url from config.php, auto-detects otherwise. */
function app_url(string $path = ''): string
{
    global $CONFIG;
    static $base = null;
    if ($base === null) {
        $base = rtrim((string)($CONFIG['app_url'] ?? ''), '/');
        if ($base === '' && PHP_SAPI !== 'cli') {
            $scheme  = is_https() ? 'https' : 'http';
            $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $docRoot = rtrim(str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
            $root    = str_replace('\\', '/', APP_ROOT);
            $sub     = ($docRoot !== '' && str_starts_with($root, $docRoot)) ? substr($root, strlen($docRoot)) : '';
            $base    = $scheme . '://' . $host . rtrim($sub, '/');
        }
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('Invalid or expired form token. Please go back and try again.');
    }
}

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function random_token(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

/* ---------- Settings (stored in the settings table) ---------- */

function settings_all(bool $reload = false): array
{
    static $cache = null;
    if ($cache === null || $reload) {
        $cache = [];
        foreach (db_all('SELECT setting_key, setting_value FROM settings') as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    }
    return $cache;
}

function setting(string $key, mixed $default = null): mixed
{
    $all = settings_all();
    $v = $all[$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

function set_setting(string $key, mixed $value): void
{
    db_query(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?',
        [$key, (string)$value, (string)$value]
    );
    settings_all(true);
}

/* ---------- Formatting ---------- */

/** Indian-style currency formatting: 1,50,000.00 */
function money(mixed $amount, bool $symbol = true): string
{
    $n = (float)$amount;
    $neg = $n < 0;
    $n = abs($n);
    [$int, $dec] = explode('.', number_format($n, 2, '.', ''));
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest  = substr($int, 0, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int   = $rest . ',' . $last3;
    }
    return ($neg ? '-' : '') . ($symbol ? '₹' : '') . $int . '.' . $dec;
}

function fmt_date(?string $date, string $format = 'd M Y'): string
{
    if (!$date || str_starts_with($date, '0000')) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '';
}

function fmt_datetime(?string $dt): string
{
    return fmt_date($dt, 'd M Y, h:i A');
}

/** "2026-11" -> "Nov 2026" */
function period_label(string $period): string
{
    $ts = strtotime($period . '-01');
    return $ts ? date('M Y', $ts) : $period;
}

/** Normalise a phone number to digits with country code (e.g. 919876543210). */
function normalize_phone(?string $raw): string
{
    $d = preg_replace('/\D+/', '', (string)$raw) ?? '';
    if ($d === '') {
        return '';
    }
    $cc = preg_replace('/\D+/', '', (string)setting('country_code', '91')) ?: '91';
    if (strlen($d) === 10) {
        $d = $cc . $d;
    } elseif (strlen($d) === 11 && str_starts_with($d, '0')) {
        $d = $cc . substr($d, 1);
    } elseif (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    }
    return $d;
}

function pretty_phone(string $digits): string
{
    return $digits === '' ? '' : '+' . $digits;
}

/* ---------- Status helpers ---------- */

/** Effective status of a due for display: adds "overdue" on top of the stored status. */
function due_display_status(array $d): string
{
    if (in_array($d['status'], ['pending', 'sent', 'partial'], true) && $d['due_date'] < date('Y-m-d')) {
        return 'overdue';
    }
    return $d['status'];
}

/** Frappe-style indicator pill for a status. */
function status_badge(string $status): string
{
    $map = [
        'pending'   => ['gray',   'Not sent'],
        'sent'      => ['blue',   'Sent'],
        'partial'   => ['orange', 'Partly paid'],
        'paid'      => ['green',  'Paid'],
        'cancelled' => ['dark',   'Cancelled'],
        'overdue'   => ['red',    'Overdue'],
        'active'    => ['green',  'Active'],
        'inactive'  => ['gray',   'Inactive'],
        'queued'    => ['gray',   'Queued'],
        'delivered' => ['green',  'Delivered'],
        'read'      => ['green',  'Read'],
        'failed'    => ['red',    'Failed'],
        'captured'  => ['green',  'Captured'],
        'authorized'=> ['blue',   'Authorized'],
        'refunded'  => ['orange', 'Refunded'],
        'created'   => ['blue',   'Link active'],
        'partially_paid' => ['orange', 'Partly paid'],
        'expired'   => ['gray',   'Expired'],
    ];
    [$color, $label] = $map[$status] ?? ['gray', ucfirst(str_replace('_', ' ', $status))];
    return '<span class="indicator-pill ' . $color . '">' . e($label) . '</span>';
}

/* ---------- Pagination ---------- */

function paginate(string $countSql, array $params, int $perPage = 50): array
{
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $total = (int)db_val($countSql, $params);
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min($page, $pages);
    return [
        'page'   => $page,
        'pages'  => $pages,
        'total'  => $total,
        'limit'  => $perPage,
        'offset' => ($page - 1) * $perPage,
    ];
}

function pagination_html(array $pg): string
{
    if ($pg['pages'] <= 1) {
        return '';
    }
    $q = $_GET;
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    $start = max(1, $pg['page'] - 3);
    $end   = min($pg['pages'], $pg['page'] + 3);
    $link = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($q): string {
        $q['page'] = $p;
        $cls = 'page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '');
        return '<li class="' . $cls . '"><a class="page-link" href="?' . e(http_build_query($q)) . '">' . e($label) . '</a></li>';
    };
    $html .= $link(max(1, $pg['page'] - 1), 'Prev', false, $pg['page'] <= 1);
    for ($p = $start; $p <= $end; $p++) {
        $html .= $link($p, (string)$p, $p === $pg['page']);
    }
    $html .= $link(min($pg['pages'], $pg['page'] + 1), 'Next', false, $pg['page'] >= $pg['pages']);
    $html .= '</ul></nav>';
    return $html;
}

/* ---------- Misc ---------- */

function log_line(string $file, string $line): void
{
    @file_put_contents(APP_ROOT . '/storage/' . $file, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
}

/** Distinct batch names used by members, for filter dropdowns. */
function batch_list(): array
{
    return array_column(db_all("SELECT DISTINCT batch FROM members WHERE batch IS NOT NULL AND batch <> '' ORDER BY batch"), 'batch');
}
