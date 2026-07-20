<?php

require __DIR__ . '/../../config.php';
require __DIR__ . '/../../function.php';

function panel_ensure_pdo(): PDO
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Database (PDO) is not available. Check config.php credentials and that php-mysql is installed.\n";
        echo "See logs/php_errors.log on the server for details.\n";
        exit;
    }
    return $pdo;
}

/** Session / remember-me helpers */
function panel_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
}

function panel_remember_lifetime(): int
{
    return 60 * 60 * 24 * 30; // 30 days
}

function panel_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => panel_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function panel_wants_remember(): bool
{
    return !empty($_COOKIE['panel_remember']);
}

function panel_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $lifetime = panel_wants_remember() ? panel_remember_lifetime() : 0;

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => panel_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Keep server-side session data at least as long as the cookie
    ini_set('session.gc_maxlifetime', (string) max(1440, $lifetime ?: 1440));
    session_start();
}

function panel_enable_remember(): void
{
    $lifetime = panel_remember_lifetime();
    setcookie('panel_remember', '1', panel_cookie_options(time() + $lifetime));

    if (session_status() === PHP_SESSION_ACTIVE) {
        setcookie(session_name(), session_id(), panel_cookie_options(time() + $lifetime));
        ini_set('session.gc_maxlifetime', (string) $lifetime);
    }
}

function panel_clear_remember(): void
{
    setcookie('panel_remember', '', panel_cookie_options(time() - 3600));
}

function panel_logout(): void
{
    panel_session_start();
    panel_clear_remember();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', panel_cookie_options(time() - 3600));
        // Ensure path matches what session used
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'] ?: '/',
            'secure' => panel_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }
}

function db_query(PDO $pdo, string $sql, array $params = []): PDOStatement
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_fetch(PDO $pdo, string $sql, array $params = []): ?array
{
    return db_query($pdo, $sql, $params)->fetch() ?: null;
}

function db_fetchAll(PDO $pdo, string $sql, array $params = []): array
{
    return db_query($pdo, $sql, $params)->fetchAll();
}

function db_count(PDO $pdo, string $sql, array $params = []): int
{
    return (int) db_query($pdo, $sql, $params)->fetchColumn();
}
function require_auth(): void
{
    panel_session_start();
    if (empty($_SESSION['admin_user'])) {
        header('Location: login.php');
        exit;
    }
    try {
        global $pdo;
        $pdo = panel_ensure_pdo();
        $admin = db_fetch($pdo, "SELECT id_admin FROM admin WHERE username = ?", [$_SESSION['admin_user']]);
        if (!$admin) {
            panel_logout();
            header('Location: login.php');
            exit;
        }
    } catch (Throwable $e) {
        error_log('panel require_auth: ' . $e->getMessage());
        panel_logout();
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string
{
    panel_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check_post(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        die('درخواست نامعتبر.');
    }
}

function csrf_check_get(): void
{
    $token = $_GET['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        die('درخواست نامعتبر.');
    }
}

function flash(string $key, string $msg): void
{
    panel_session_start();
    $_SESSION["flash_{$key}"] = $msg;
}

function get_flash(string $key): ?string
{
    panel_session_start();
    $msg = $_SESSION["flash_{$key}"] ?? null;
    unset($_SESSION["flash_{$key}"]);
    return $msg;
}

function trunc(string $str, int $max = 30): string
{
    return mb_strlen($str, 'UTF-8') > $max
        ? mb_substr($str, 0, $max, 'UTF-8') . '…'
        : $str;
}

function safe_date($ts, string $fmt = 'Y/m/d'): string
{
    if (!$ts)
        return '—';
    if (!is_numeric($ts))
        return htmlspecialchars((string) $ts);
    return date($fmt, (int) $ts);
}
function check_login_rate(string $ip): bool
{
    $file = sys_get_temp_dir() . '/panel_login_' . md5($ip);
    $data = @json_decode(@file_get_contents($file) ?: '{}', true) ?: [];
    $now = time();
    $data = array_filter($data, fn($t) => ($now - $t) < 900);
    if (count($data) >= 10)
        return false;
    $data[] = $now;
    @file_put_contents($file, json_encode(array_values($data)), LOCK_EX);
    return true;
}

function clear_login_rate(string $ip): void
{
    @unlink(sys_get_temp_dir() . '/panel_login_' . md5($ip));
}

function user_role_label(string $agent): string
{
    return match ($agent) {
        'n' => 'نماینده',
        'n2' => 'نماینده پیشرفته',
        'all' => 'دسترسی کامل',
        default => 'کاربر عادی',
    };
}

function user_role_tag(string $agent): string
{
    return match ($agent) {
        'f' => 'tag-info',
        'n' => 'tag-info',
        'n2' => 'tag-warn',
        'all' => 'tag-ok',
        default => 'tag-plain',
    };
}

function panel_agent_label(string $agent): string
{
    return match ($agent) {
        'f' => 'کاربر عادی',
        'n' => 'نماینده',
        'n2' => 'نماینده پیشرفته',
        'all' => 'همه گروه‌ها',
        default => $agent,
    };
}

/** Web path prefix for panel assets, e.g. /panel */
function panel_web_base(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/panel'));
    $dir = rtrim($dir, '/');
    return $dir !== '' ? $dir : '/panel';
}

function panel_asset(string $path): string
{
    return panel_web_base() . '/' . ltrim($path, '/');
}
