<?php

/** VPN panel (marzban_panel) helpers — mirrors Telegram admin defaults. */

const PANEL_TYPES = [
    'marzban' => 'مرزبان',
    'marzneshin' => 'مرزنشین',
    'x-ui_single' => 'ثنایی تک‌پورت',
    'alireza_single' => 'علیرضا تک‌پورت',
    'Manualsale' => 'فروش دستی',
    'hiddify' => 'هیدیفای',
    'WGDashboard' => 'WGDashboard',
    's_ui' => 's_ui',
    'ibsng' => 'ibsng',
    'mikrotik' => 'میکروتیک',
];

const METHOD_USERNAME_OPTIONS = [
    'نام کاربری + عدد به ترتیب',
    'آیدی عددی + حروف و عدد رندوم',
    'نام کاربری دلخواه',
    'نام کاربری دلخواه + عدد رندوم',
    'متن دلخواه + عدد رندوم',
    'متن دلخواه + عدد ترتیبی',
    'آیدی عددی+عدد ترتیبی',
    'متن دلخواه نماینده + عدد ترتیبی',
];

const METHOD_EXTEND_OPTIONS = [
    'ریست حجم و زمان',
    'اضافه شدن زمان و حجم به ماه بعد',
    'ریست زمان و اضافه کردن حجم قبلی',
    'ریست شدن حجم و اضافه شدن زمان',
    'اضافه شدن زمان و تبدیل حجم کل به حجم باقی مانده',
];

function require_administrator(): void
{
    require_auth();
    global $pdo;
    $admin = db_fetch($pdo, "SELECT rule FROM admin WHERE username = ?", [$_SESSION['admin_user'] ?? '']);
    if (!$admin || ($admin['rule'] ?? '') !== 'administrator') {
        flash('error', 'فقط مدیر اصلی به این بخش دسترسی دارد.');
        header('Location: index.php');
        exit;
    }
}

function panel_default_price_json(): string
{
    return json_encode(['f' => '4000', 'n' => '4000', 'n2' => '4000'], JSON_UNESCAPED_UNICODE);
}

function panel_default_volume_json(): string
{
    return json_encode(['f' => '1', 'n' => '1', 'n2' => '1'], JSON_UNESCAPED_UNICODE);
}

function panel_default_max_json(): string
{
    return json_encode(['f' => '1000', 'n' => '1000', 'n2' => '1000'], JSON_UNESCAPED_UNICODE);
}

function panel_default_customvolume(): string
{
    return json_encode(['f' => '0', 'n' => '0', 'n2' => '0'], JSON_UNESCAPED_UNICODE);
}

function panel_decode_agent_json(?string $json, string $default = '0'): array
{
    if (!$json) {
        return ['f' => $default, 'n' => $default, 'n2' => $default];
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return ['f' => $default, 'n' => $default, 'n2' => $default];
    }
    return [
        'f' => (string) ($d['f'] ?? $default),
        'n' => (string) ($d['n'] ?? $default),
        'n2' => (string) ($d['n2'] ?? $default),
    ];
}

function panel_encode_agent_json(array $values): string
{
    return json_encode([
        'f' => (string) ($values['f'] ?? '0'),
        'n' => (string) ($values['n'] ?? '0'),
        'n2' => (string) ($values['n2'] ?? '0'),
    ], JSON_UNESCAPED_UNICODE);
}

function panel_all_names(PDO $pdo, ?int $exceptId = null): array
{
    $rows = db_fetchAll($pdo, "SELECT id, name_panel FROM marzban_panel ORDER BY name_panel");
    $names = [];
    foreach ($rows as $r) {
        if ($exceptId !== null && (int) $r['id'] === $exceptId) {
            continue;
        }
        $names[] = $r['name_panel'];
    }
    return $names;
}

function panel_name_exists(PDO $pdo, string $name, ?int $exceptId = null): bool
{
    if ($exceptId) {
        return db_count($pdo, "SELECT COUNT(*) FROM marzban_panel WHERE name_panel = ? AND id != ?", [$name, $exceptId]) > 0;
    }
    return db_count($pdo, "SELECT COUNT(*) FROM marzban_panel WHERE name_panel = ?", [$name]) > 0;
}

function panel_rename_cascade(PDO $pdo, string $oldName, string $newName): void
{
    db_query($pdo, "UPDATE invoice SET Service_location = ? WHERE Service_location = ?", [$newName, $oldName]);
    db_query($pdo, "UPDATE product SET Location = ? WHERE Location = ?", [$newName, $oldName]);
}

function panel_insert_defaults(PDO $pdo, array $in): int
{
    $type = $in['type'] ?? 'marzban';
    $name = trim($in['name_panel'] ?? '');
    $url = trim($in['url_panel'] ?? '');
    $username = trim($in['username_panel'] ?? '');
    $password = trim($in['password_panel'] ?? '');
    $limit = trim($in['limit_panel'] ?? 'unlimted');

    if ($type === 'Manualsale' || $type === 'hiddify') {
        if ($url === '') {
            $url = 'null';
        }
        $username = $username !== '' ? $username : 'null';
        $password = $password !== '' ? $password : 'null';
    } elseif ($type === 's_ui' || $type === 'WGDashboard') {
        $username = 'null';
    }

    $code = bin2hex(random_bytes(2));
    $price = panel_default_price_json();
    $volMain = panel_default_volume_json();
    $volMax = panel_default_max_json();

    db_query(
        $pdo,
        "INSERT INTO marzban_panel (
            code_panel, name_panel, sublink, config, MethodUsername, TestAccount, status, limit_panel,
            namecustom, Methodextend, type, conecton, inboundid, agent, inbound_deactive, inboundstatus,
            url_panel, username_panel, password_panel, time_usertest, val_usertest, linksubx,
            priceextravolume, priceextratime, pricecustomvolume, pricecustomtime,
            mainvolume, maxvolume, maintime, maxtime, status_extend, subvip, changeloc, customvolume,
            on_hold_test, version_panel, priceChangeloc
        ) VALUES (
            ?, ?, 'onsublink', 'offconfig', 'آیدی عددی + حروف و عدد رندوم', 'ONTestAccount', 'active', ?,
            'none', 'ریست حجم و زمان', ?, 'offconecton', '1', 'all', '0', 'offinbounddisable',
            ?, ?, ?, '1', '100', ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            'on_extend', 'offsubvip', 'offchangeloc', ?, '1', '0', '0'
        )",
        [
            $code,
            $name,
            $limit,
            $type,
            $url,
            $username,
            $password,
            $url !== 'null' && $url !== '' ? $url : '',
            $price,
            $price,
            $price,
            $price,
            $volMain,
            $volMax,
            $volMain,
            $volMax,
            panel_default_customvolume(),
        ]
    );

    return (int) $pdo->lastInsertId();
}

function panel_type_label(?string $type): string
{
    return PANEL_TYPES[$type ?? ''] ?? ($type ?: '—');
}

function panel_status_label(?string $status): string
{
    return ($status ?? '') === 'active' ? 'فعال' : 'غیرفعال';
}

function panel_bool_on(string $value, string $onValue): bool
{
    return $value === $onValue;
}

function panel_parse_hide_users(?string $raw): array
{
    if (!$raw) {
        return [];
    }
    $d = json_decode($raw, true);
    return is_array($d) ? array_values(array_filter(array_map('strval', $d))) : [];
}

function panel_format_hide_users(array $ids): string
{
    $ids = array_values(array_unique(array_filter(array_map('trim', $ids), fn($x) => $x !== '' && ctype_digit($x))));
    return json_encode($ids, JSON_UNESCAPED_UNICODE);
}

/** Display stored PasarGuard group_ids (marzban_panel.inbounds JSON) as "1, 2". */
function panel_format_pasarguard_group_ids(?string $inboundsJson): string
{
    if ($inboundsJson === null || $inboundsJson === '' || $inboundsJson === 'null') {
        return '';
    }
    $decoded = json_decode($inboundsJson, true);
    if (!is_array($decoded)) {
        return '';
    }
    $ids = [];
    foreach ($decoded as $item) {
        if (is_numeric($item)) {
            $id = (int) $item;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }

    return implode(', ', $ids);
}

/**
 * Parse "1,2" into JSON array for marzban_panel.inbounds.
 * @return string|null|false JSON string, null if empty, false if invalid
 */
function panel_parse_pasarguard_group_ids(string $input): string|false|null
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }
    if (!preg_match('/^\d+(\s*,\s*\d+)*$/', $input)) {
        return false;
    }
    $ids = array_map('intval', preg_split('/\s*,\s*/', $input));
    $ids = array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));

    return $ids === [] ? null : json_encode($ids);
}

function panel_is_pasarguard(array $panel): bool
{
    return ($panel['type'] ?? '') === 'marzban' && ($panel['version_panel'] ?? '0') === '1';
}

function panel_invoice_stats(PDO $pdo, string $namePanel): array
{
    try {
        $count = db_count(
            $pdo,
            "SELECT COUNT(*) FROM invoice WHERE Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold') AND Service_location = ? AND name_product != 'سرویس تست'",
            [$namePanel]
        );
        $sum = (int) db_query(
            $pdo,
            "SELECT COALESCE(SUM(price_product),0) FROM invoice WHERE Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold') AND Service_location = ? AND name_product != 'سرویس تست'",
            [$namePanel]
        )->fetchColumn();
        return ['count' => $count, 'sum' => $sum];
    } catch (Exception $e) {
        return ['count' => 0, 'sum' => 0];
    }
}

function panel_toggle_keys_for_tab(string $tab): array
{
    if ($tab === 'connection') {
        return ['status_active', 'test_on'];
    }
    if ($tab === 'features') {
        return [
            'extend_on', 'custom_f', 'custom_n', 'custom_n2', 'config_on', 'sublink_on',
            'conecton_on', 'on_hold_test', 'changeloc_on', 'subvip_on', 'inbound_disable_on', 'version_panel_on',
        ];
    }
    return [];
}

function panel_toggle_field(array $panel, string $postKey, string $dbField, string $onVal, string $offVal, bool $inForm = false): string
{
    if (!array_key_exists($postKey, $_POST)) {
        return $inForm ? $offVal : (string) ($panel[$dbField] ?? $offVal);
    }
    return !empty($_POST[$postKey]) ? $onVal : $offVal;
}

function panel_merge_agent_json_field(array $panel, string $field, string $prefix): string
{
    if (!isset($_POST[$prefix . '_f'])) {
        return (string) ($panel[$field] ?? panel_default_price_json());
    }
    return panel_encode_agent_json([
        'f' => $_POST[$prefix . '_f'] ?? '0',
        'n' => $_POST[$prefix . '_n'] ?? '0',
        'n2' => $_POST[$prefix . '_n2'] ?? '0',
    ]);
}

function panel_merge_customvolume(array $panel, bool $inForm = false): string
{
    if (!$inForm && !array_key_exists('custom_f', $_POST)) {
        return (string) ($panel['customvolume'] ?? panel_default_customvolume());
    }
    return panel_encode_agent_json([
        'f' => !empty($_POST['custom_f']) ? '1' : '0',
        'n' => !empty($_POST['custom_n']) ? '1' : '0',
        'n2' => !empty($_POST['custom_n2']) ? '1' : '0',
    ]);
}

/**
 * Test VPN panel API connection (same logic as Telegram admin).
 * @return array{ok: bool, title: string, lines: string[]}
 */
function panel_probe_connection(array $panel): array
{
    $type = $panel['type'] ?? '';
    $root = dirname(__DIR__, 2);
    $lines = [];

    if ($type === 'Manualsale') {
        return [
            'ok' => true,
            'title' => 'فروش دستی — بدون اتصال API',
            'lines' => ['این پنل به سرور خارجی متصل نمی‌شود.'],
        ];
    }

    $url = trim($panel['url_panel'] ?? '');
    if ($url === '' || $url === 'null') {
        return [
            'ok' => false,
            'title' => 'آدرس پنل تنظیم نشده',
            'lines' => ['آدرس URL را در فرم زیر وارد کنید.'],
        ];
    }

    try {
        switch ($type) {
            case 'marzban':
                require_once $root . '/Marzban.php';
                $tok = token_panel($panel['code_panel'], false);
                if (!empty($tok['access_token'])) {
                    $sys = Get_System_Stats($panel['name_panel']);
                    $lines[] = 'اتصال به API مرزبان برقرار است.';
                    if (is_array($sys) && isset($sys['version'])) {
                        $lines[] = 'نسخه پنل: ' . $sys['version'];
                    }
                    if (is_array($sys) && isset($sys['total_user'])) {
                        $lines[] = 'کل کاربران: ' . number_format((int) $sys['total_user']);
                    }
                    $vu = $sys['users_active'] ?? $sys['active_users'] ?? $sys['online_users'] ?? null;
                    if ($vu !== null) {
                        $lines[] = 'کاربران فعال: ' . number_format((int) $vu);
                    }
                    return ['ok' => true, 'title' => 'پنل متصل است', 'lines' => $lines];
                }
                if (!empty($tok['detail']) && $tok['detail'] === 'Incorrect username or password') {
                    return ['ok' => false, 'title' => 'نام کاربری یا رمز اشتباه', 'lines' => []];
                }
                $err = $tok['error'] ?? $tok['detail'] ?? json_encode($tok, JSON_UNESCAPED_UNICODE);
                return ['ok' => false, 'title' => 'خطا در اتصال', 'lines' => [(string) $err]];

            case 'marzneshin':
                require_once $root . '/marzneshin.php';
                $tok = token_panelm($panel['code_panel']);
                if (!empty($tok['access_token'])) {
                    $sys = Get_System_Statsm($panel['name_panel']);
                    $body = json_decode(is_array($sys) ? ($sys['body'] ?? '{}') : '{}', true) ?: [];
                    $lines[] = 'اتصال به مرزنشین برقرار است.';
                    if (isset($body['total'])) {
                        $lines[] = 'کل کاربران: ' . number_format((int) $body['total']);
                    }
                    if (isset($body['active'])) {
                        $lines[] = 'کاربران فعال: ' . number_format((int) $body['active']);
                    }
                    return ['ok' => true, 'title' => 'پنل متصل است', 'lines' => $lines];
                }
                return ['ok' => false, 'title' => 'خطا در اتصال', 'lines' => [(string) ($tok['detail'] ?? $tok['errror'] ?? 'احراز ناموفق')]];

            case 'x-ui_single':
            case 'alireza_single':
                $file = $type === 'alireza_single' ? '/alireza_single.php' : '/x-ui_single.php';
                require_once $root . $file;
                $res = login($panel['code_panel'], false);
                if (is_array($res) && !empty($res['success'])) {
                    return ['ok' => true, 'title' => 'پنل متصل است', 'lines' => ['ورود به پنل ثنایی موفق بود.']];
                }
                $msg = is_array($res) ? ($res['msg'] ?? json_encode($res, JSON_UNESCAPED_UNICODE)) : 'پاسخ نامعتبر';
                return ['ok' => false, 'title' => 'خطا در اتصال', 'lines' => [(string) $msg]];

            case 'hiddify':
                require_once $root . '/hiddify.php';
                $res = serverstatus($panel['name_panel']);
                if (!empty($res['status']) && (int) $res['status'] !== 200) {
                    return ['ok' => false, 'title' => 'خطا', 'lines' => ['کد HTTP: ' . $res['status']]];
                }
                if (!empty($res['error'])) {
                    return ['ok' => false, 'title' => 'خطا', 'lines' => [(string) $res['error']]];
                }
                $body = json_decode($res['body'] ?? '', true);
                if (isset($body['stats'])) {
                    return ['ok' => true, 'title' => 'پنل متصل است', 'lines' => ['اتصال به هیدیفای برقرار است.']];
                }
                if (($body['message'] ?? '') === 'Unathorized') {
                    return ['ok' => false, 'title' => 'لینک یا UUID اشتباه', 'lines' => []];
                }
                return ['ok' => false, 'title' => 'پنل متصل نیست', 'lines' => []];

            case 'ibsng':
                require_once $root . '/ibsng.php';
                $res = loginIBsng($url, $panel['username_panel'], $panel['password_panel']);
                if (!empty($res['status'])) {
                    return ['ok' => true, 'title' => 'پنل متصل است', 'lines' => [$res['msg'] ?? 'ورود موفق']];
                }
                return ['ok' => false, 'title' => 'خطا در اتصال', 'lines' => [(string) ($res['msg'] ?? 'ناموفق')]];

            case 'mikrotik':
                require_once $root . '/mikrotik.php';
                $res = login_mikrotik($url, $panel['username_panel'], $panel['password_panel']);
                if (!isset($res['error'])) {
                    $lines[] = 'اتصال به میکروتیک برقرار است.';
                    if (isset($res['version'])) {
                        $lines[] = 'نسخه: ' . $res['version'];
                    }
                    return ['ok' => true, 'title' => 'پنل متصل است', 'lines' => $lines];
                }
                return ['ok' => false, 'title' => 'خطا', 'lines' => [json_encode($res, JSON_UNESCAPED_UNICODE)]];

            case 's_ui':
            case 'WGDashboard':
                if (trim($panel['password_panel'] ?? '') === '' || ($panel['password_panel'] ?? '') === 'null') {
                    return ['ok' => false, 'title' => 'توکن تنظیم نشده', 'lines' => ['توکن API را وارد کنید.']];
                }
                return [
                    'ok' => true,
                    'title' => 'آماده (تست کامل از ربات)',
                    'lines' => ['توکن ثبت شده است. برای تست اینباند از ربات «تنظیم پروتکل و اینباند» را انجام دهید.'],
                ];

            default:
                return ['ok' => false, 'title' => 'نوع پنل ناشناخته', 'lines' => [$type]];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'title' => 'خطای سیستم', 'lines' => [$e->getMessage()]];
    }
}

function panel_features_for_type(string $type): array
{
    $all = ['status', 'test', 'extend', 'custom_f', 'custom_n', 'custom_n2', 'config', 'sublink', 'conecton', 'on_hold_test', 'changeloc', 'subvip', 'inbound_disable', 'version_panel'];
    if (in_array($type, ['ibsng', 'mikrotik'], true)) {
        return ['status', 'test'];
    }
    if ($type === 'Manualsale' || $type === 'WGDashboard' || $type === 'hiddify') {
        return array_diff($all, ['config', 'sublink', 'conecton', 'on_hold_test', 'changeloc', 'subvip', 'inbound_disable', 'version_panel']);
    }
    if ($type !== 'marzban') {
        $all = array_diff($all, ['version_panel']);
    }
    if (!in_array($type, ['marzban', 'x-ui_single', 'marzneshin'], true)) {
        $all = array_diff($all, ['conecton', 'on_hold_test']);
    }
    return array_values($all);
}

/**
 * Discover readable report log files for web panel.
 * @return array<string, array{label:string,path:string}>
 */
function panel_report_log_files(): array
{
    $root = dirname(__DIR__, 2);
    $candidates = [
        'subscription_failures' => ['label' => 'خطای ساخت اشتراک (logs/subscription_failures.log)', 'path' => $root . '/logs/subscription_failures.log'],
        'php_errors' => ['label' => 'خطاهای PHP (logs/php_errors.log)', 'path' => $root . '/logs/php_errors.log'],
        'error_log' => ['label' => 'گزارش خطا (error_log)', 'path' => $root . '/error_log'],
        'error_dot_log' => ['label' => 'گزارش خطا (error.log)', 'path' => $root . '/error.log'],
        'polling_log' => ['label' => 'لاگ پولینگ (polling.log)', 'path' => $root . '/polling.log'],
        'storage_polling' => ['label' => 'لاگ پولینگ (storage/logs)', 'path' => $root . '/storage/logs/polling.log'],
        'storage_panel' => ['label' => 'لاگ پنل (storage/logs/panel.log)', 'path' => $root . '/storage/logs/panel.log'],
    ];

    $out = [];
    foreach ($candidates as $key => $info) {
        if (is_file($info['path']) && is_readable($info['path'])) {
            $out[$key] = $info;
        }
    }
    return $out;
}

/**
 * Read last lines from a log file safely.
 * @return array{lines:string[],size:int,mtime:int|null}
 */
function panel_read_log_tail(string $path, int $maxLines = 200, int $maxBytes = 262144): array
{
    if (!is_file($path) || !is_readable($path)) {
        return ['lines' => [], 'size' => 0, 'mtime' => null];
    }

    $size = (int) (filesize($path) ?: 0);
    $mtime = @filemtime($path) ?: null;
    if ($size === 0) {
        return ['lines' => [], 'size' => 0, 'mtime' => $mtime];
    }

    $readBytes = min($size, max(4096, $maxBytes));
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        return ['lines' => [], 'size' => $size, 'mtime' => $mtime];
    }

    if ($size > $readBytes) {
        fseek($fp, -$readBytes, SEEK_END);
    }
    $chunk = stream_get_contents($fp);
    fclose($fp);

    if (!is_string($chunk) || $chunk === '') {
        return ['lines' => [], 'size' => $size, 'mtime' => $mtime];
    }

    $chunk = str_replace("\r\n", "\n", $chunk);
    if ($size > $readBytes) {
        $firstBreak = strpos($chunk, "\n");
        if ($firstBreak !== false) {
            $chunk = substr($chunk, $firstBreak + 1);
        }
    }

    $lines = explode("\n", trim($chunk));
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }

    return ['lines' => $lines, 'size' => $size, 'mtime' => $mtime];
}
