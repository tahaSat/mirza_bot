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
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwarded !== '') {
        return trim(explode(',', $forwarded)[0]) === 'https';
    }
    return (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https');
}

function panel_remember_lifetime(): int
{
    return 60 * 60 * 24 * 30; // 30 days
}

function panel_cookie_path(): string
{
    // Prefer panel path so auth cookies are scoped; fall back to /
    $base = panel_web_base();
    return $base !== '' ? $base : '/';
}

function panel_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => panel_cookie_path(),
        'secure' => panel_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function panel_wants_remember(): bool
{
    return !empty($_COOKIE['panel_remember']);
}

function panel_session_save_path(): string
{
    $dir = dirname(__DIR__, 2) . '/storage/sessions/panel';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function panel_ensure_remember_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS panel_remember_token (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        selector VARCHAR(64) NOT NULL,
        token_hash VARCHAR(255) NOT NULL,
        username VARCHAR(200) NOT NULL,
        expires_at INT UNSIGNED NOT NULL,
        created_at INT UNSIGNED NOT NULL,
        UNIQUE KEY uq_panel_remember_selector (selector),
        KEY idx_panel_remember_user (username),
        KEY idx_panel_remember_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function panel_set_session_cookie(int $lifetime): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $expires = $lifetime > 0 ? time() + $lifetime : 0;
    setcookie(session_name(), session_id(), panel_cookie_options($expires));
}

function panel_parse_remember_cookie(): ?array
{
    $raw = (string) ($_COOKIE['panel_remember'] ?? '');
    // Legacy flag-only cookie ("1") cannot restore a dead session
    if ($raw === '' || $raw === '1' || !str_contains($raw, ':')) {
        return null;
    }
    [$selector, $validator] = explode(':', $raw, 2);
    if ($selector === '' || $validator === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $selector)) {
        return null;
    }
    return ['selector' => $selector, 'validator' => $validator];
}

function panel_revoke_remember_selector(?string $selector): void
{
    if ($selector === null || $selector === '') {
        return;
    }
    try {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }
        panel_ensure_remember_table($pdo);
        db_query($pdo, 'DELETE FROM panel_remember_token WHERE selector = ?', [$selector]);
    } catch (Throwable $e) {
        error_log('panel_revoke_remember_selector: ' . $e->getMessage());
    }
}

function panel_try_restore_remember(): void
{
    if (!empty($_SESSION['admin_user'])) {
        return;
    }
    $parts = panel_parse_remember_cookie();
    if ($parts === null) {
        // Drop useless legacy "1" cookie so lifetime logic stays honest
        if (isset($_COOKIE['panel_remember']) && $_COOKIE['panel_remember'] === '1') {
            setcookie('panel_remember', '', panel_cookie_options(time() - 3600));
            // Also clear old path=/ cookie from previous versions
            setcookie('panel_remember', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => panel_is_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE['panel_remember']);
        }
        return;
    }

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }
        panel_ensure_remember_table($pdo);
        $row = db_fetch(
            $pdo,
            'SELECT selector, token_hash, username, expires_at FROM panel_remember_token WHERE selector = ? LIMIT 1',
            [$parts['selector']]
        );
        if (!$row || (int) $row['expires_at'] < time()) {
            panel_revoke_remember_selector($parts['selector']);
            panel_clear_remember_cookie();
            return;
        }
        if (!hash_equals($row['token_hash'], hash('sha256', $parts['validator']))) {
            panel_revoke_remember_selector($parts['selector']);
            panel_clear_remember_cookie();
            return;
        }
        $admin = db_fetch($pdo, 'SELECT username FROM admin WHERE username = ? LIMIT 1', [$row['username']]);
        if (!$admin) {
            panel_revoke_remember_selector($parts['selector']);
            panel_clear_remember_cookie();
            return;
        }

        // Rotate remember token (steal protection)
        panel_revoke_remember_selector($parts['selector']);
        panel_issue_remember_token($admin['username']);

        session_regenerate_id(true);
        $_SESSION['admin_user'] = $admin['username'];
        $_SESSION['login_time'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['remember'] = true;
        panel_set_session_cookie(panel_remember_lifetime());
    } catch (Throwable $e) {
        error_log('panel_try_restore_remember: ' . $e->getMessage());
    }
}

function panel_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        if (empty($_SESSION['admin_user'])) {
            panel_try_restore_remember();
        }
        return;
    }

    $lifetime = panel_wants_remember() ? panel_remember_lifetime() : 0;
    // Keep server-side data long enough for remember-me even when cookie is session-only
    $gcLifetime = panel_remember_lifetime();

    $savePath = panel_session_save_path();
    if (is_dir($savePath) && is_writable($savePath)) {
        session_save_path($savePath);
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) $gcLifetime);

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => panel_cookie_path(),
        'secure' => panel_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('PICHASESSID');
    session_start();

    if (!empty($_SESSION['remember']) || panel_wants_remember()) {
        panel_set_session_cookie(panel_remember_lifetime());
    }

    panel_try_restore_remember();
}

function panel_issue_remember_token(string $username): void
{
    $lifetime = panel_remember_lifetime();
    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $now = time();

    try {
        global $pdo;
        if ($pdo instanceof PDO) {
            panel_ensure_remember_table($pdo);
            // Limit active tokens per admin
            db_query(
                $pdo,
                'DELETE FROM panel_remember_token WHERE username = ? OR expires_at < ?',
                [$username, $now]
            );
            db_query(
                $pdo,
                'INSERT INTO panel_remember_token (selector, token_hash, username, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
                [$selector, hash('sha256', $validator), $username, $now + $lifetime, $now]
            );
        }
    } catch (Throwable $e) {
        error_log('panel_issue_remember_token: ' . $e->getMessage());
    }

    setcookie('panel_remember', $selector . ':' . $validator, panel_cookie_options($now + $lifetime));
    $_COOKIE['panel_remember'] = $selector . ':' . $validator;
}

function panel_clear_remember_cookie(): void
{
    $opts = panel_cookie_options(time() - 3600);
    setcookie('panel_remember', '', $opts);
    // Clear legacy path=/ cookie too
    setcookie('panel_remember', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => panel_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['panel_remember']);
}

function panel_enable_remember(string $username = ''): void
{
    $lifetime = panel_remember_lifetime();
    if ($username === '') {
        $username = (string) ($_SESSION['admin_user'] ?? '');
    }
    if ($username !== '') {
        panel_issue_remember_token($username);
    } else {
        // Fallback: persistent flag (weaker); still extends session cookie
        setcookie('panel_remember', '1', panel_cookie_options(time() + $lifetime));
        $_COOKIE['panel_remember'] = '1';
    }

    $_SESSION['remember'] = true;
    if (session_status() === PHP_SESSION_ACTIVE) {
        panel_set_session_cookie($lifetime);
        ini_set('session.gc_maxlifetime', (string) $lifetime);
    }
}

function panel_clear_remember(): void
{
    $parts = panel_parse_remember_cookie();
    if ($parts !== null) {
        panel_revoke_remember_selector($parts['selector']);
    }
    panel_clear_remember_cookie();
    unset($_SESSION['remember']);
}

function panel_logout(): void
{
    // Revoke remember token first so session bootstrap cannot restore auth
    $parts = panel_parse_remember_cookie();
    if ($parts !== null) {
        panel_revoke_remember_selector($parts['selector']);
    }
    panel_clear_remember_cookie();

    if (session_status() === PHP_SESSION_NONE) {
        $savePath = panel_session_save_path();
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }
        session_name('PICHASESSID');
        session_start();
    }

    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', panel_cookie_options(time() - 3600));
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'] ?: '/',
            'secure' => panel_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        setcookie('PHPSESSID', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => panel_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        setcookie('PHPSESSID', '', [
            'expires' => time() - 3600,
            'path' => panel_cookie_path(),
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
function panel_current_admin(): ?array
{
    static $cached = false;
    static $admin = null;
    if ($cached) {
        return $admin;
    }
    $cached = true;
    $username = (string) ($_SESSION['admin_user'] ?? '');
    if ($username === '') {
        return null;
    }
    try {
        global $pdo;
        $pdo = panel_ensure_pdo();
        $admin = db_fetch($pdo, 'SELECT * FROM admin WHERE username = ?', [$username]);
    } catch (Throwable $e) {
        $admin = null;
    }
    return $admin;
}

function panel_is_n2_user(?array $admin = null): bool
{
    $admin = $admin ?? panel_current_admin();
    return is_array($admin) && ($admin['rule'] ?? '') === 'n2_panel';
}

function panel_n2_agent_id(?array $admin = null): string
{
    $admin = $admin ?? panel_current_admin();
    return (string) ($admin['id_admin'] ?? '');
}

function panel_n2_bot(?array $admin = null): ?array
{
    $aid = panel_n2_agent_id($admin);
    if ($aid === '') {
        return null;
    }
    try {
        global $pdo;
        $pdo = panel_ensure_pdo();
        return db_fetch($pdo, 'SELECT * FROM botsaz WHERE id_user = ?', [$aid]);
    } catch (Throwable $e) {
        return null;
    }
}

function panel_n2_allowed_scripts(): array
{
    return [
        'index.php',
        'logout.php',
        'n2_products.php',
        'n2_product_action.php',
        'n2_categories.php',
        'n2_category_action.php',
        'n2_messages.php',
        'support_media.php',
        'n2_payments.php',
        'n2_purchases.php',
    ];
}

function panel_admin_rule_label(string $rule): string
{
    return match ($rule) {
        'administrator' => 'مدیر اصلی',
        'support' => 'پشتیبان',
        'Seller' => 'فروشنده',
        'n2_panel' => 'کاربر پنل',
        default => $rule !== '' ? $rule : 'مدیر پنل',
    };
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
        $admin = panel_current_admin();
        if (!$admin) {
            panel_logout();
            header('Location: login.php');
            exit;
        }
        if (panel_is_n2_user($admin)) {
            $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            if (!in_array($script, panel_n2_allowed_scripts(), true)) {
                flash('error', 'دسترسی این بخش برای کاربر پنل مجاز نیست.');
                header('Location: index.php');
                exit;
            }
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
    $rel = ltrim($path, '/');
    $url = panel_web_base() . '/' . $rel;
    $file = dirname(__DIR__) . '/' . $rel;
    if (is_file($file)) {
        $url .= '?v=' . filemtime($file);
    }
    return $url;
}

function panel_sw_register_script(): void
{
    echo <<<'HTML'
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/panel/sw.js', { scope: '/panel/', updateViaCache: 'none' })
    .then(function (reg) { return reg.update(); })
    .catch(function () {});
  if (!window.__panelSwReloadBound) {
    window.__panelSwReloadBound = true;
    var reloaded = false;
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      if (reloaded) return;
      reloaded = true;
      location.reload();
    });
  }
}
</script>
HTML;
}
