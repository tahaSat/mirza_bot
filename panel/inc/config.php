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
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    if (empty($_SESSION['admin_user'])) {
        header('Location: login.php');
        exit;
    }
    try {
        global $pdo;
        $pdo = panel_ensure_pdo();
        $admin = db_fetch($pdo, "SELECT id_admin FROM admin WHERE username = ?", [$_SESSION['admin_user']]);
        if (!$admin) {
            session_destroy();
            header('Location: login.php');
            exit;
        }
    } catch (Throwable $e) {
        error_log('panel require_auth: ' . $e->getMessage());
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE)
        session_start();
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
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    $_SESSION["flash_{$key}"] = $msg;
}

function get_flash(string $key): ?string
{
    if (session_status() === PHP_SESSION_NONE)
        session_start();
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
