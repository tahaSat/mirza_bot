<?php

function panel_invoice_active_statuses(): array
{
    return ['active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold'];
}

function panel_invoice_unpaid_statuses(): array
{
    return invoice_unpaid_statuses();
}

function panel_invoice_paid_sql(string $statusCol = 'Status'): string
{
    return invoice_paid_status_sql($statusCol);
}

function panel_extend_types(): array
{
    return ['extend_user', 'extends_not_user', 'extend_user_by_admin'];
}

function panel_extend_paid_sql(string $typeCol = 'type', string $statusCol = 'status'): string
{
    $quoted = [];
    foreach (panel_extend_types() as $type) {
        $quoted[] = "'" . str_replace("'", "''", $type) . "'";
    }
    return "$typeCol IN (" . implode(',', $quoted) . ") AND $statusCol = 'paid'";
}

function panel_datetime_epoch_sql(string $column): string
{
    return "CASE
        WHEN $column REGEXP '^[0-9]{9,}$' THEN CAST($column AS UNSIGNED)
        ELSE COALESCE(
            UNIX_TIMESTAMP(STR_TO_DATE($column, '%Y-%m-%d %H:%i:%s')),
            UNIX_TIMESTAMP(STR_TO_DATE($column, '%Y/%m/%d %H:%i:%s'))
        )
    END";
}

function panel_user_test_filter_values(): array
{
    return ['yes', 'no', 'only'];
}

function panel_user_test_filter_label(string $test): string
{
    return [
        'yes' => 'دارای اکانت تست',
        'no' => 'بدون اکانت تست',
        'only' => 'فقط اکانت تست',
    ][$test] ?? '';
}

function panel_user_segment_from_request(): array
{
    $test = (string) ($_GET['test'] ?? '');
    if (!in_array($test, panel_user_test_filter_values(), true)) {
        $test = '';
    }
    $minBuysRaw = trim((string) ($_GET['min_buys'] ?? ''));
    $minExtendsRaw = trim((string) ($_GET['min_extends'] ?? ''));
    return [
        'test' => $test,
        'min_buys' => $minBuysRaw !== '' && ctype_digit($minBuysRaw) ? (int) $minBuysRaw : null,
        'min_extends' => $minExtendsRaw !== '' && ctype_digit($minExtendsRaw) ? (int) $minExtendsRaw : null,
    ];
}

function panel_user_segment_active(array $filters): bool
{
    return ($filters['test'] ?? '') !== ''
        || ($filters['min_buys'] ?? null) !== null
        || ($filters['min_extends'] ?? null) !== null;
}

/**
 * Shared user-list query fragments for filters on users.php and campaign send.
 *
 * @return array{from:string,where:string,params:array,select:array}
 */
function panel_users_filtered_query(string $search, string $status, string $role, array $userFilters, bool $withCounts = false): array
{
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = "(u.id LIKE ? OR COALESCE(u.username,'') LIKE ? OR COALESCE(u.namecustom,'') LIKE ? OR COALESCE(u.number,'') LIKE ?)";
        $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
    }
    if ($status === 'block') {
        $where[] = "LOWER(u.User_Status) = 'block'";
    } elseif ($status === 'active') {
        $where[] = "(u.User_Status IS NULL OR u.User_Status = '' OR LOWER(u.User_Status) != 'block')";
    }
    if ($role !== '') {
        $where[] = 'u.agent = ?';
        $params[] = $role;
    }
    $seg = panel_user_segment_query_parts($userFilters, $withCounts || panel_user_segment_active($userFilters));
    foreach ($seg['where'] as $clause) {
        $where[] = $clause;
    }
    $params = array_merge($params, $seg['params']);
    return [
        'from' => 'FROM user u' . ($seg['joins'] !== '' ? "\n{$seg['joins']}" : ''),
        'where' => $where ? 'WHERE ' . implode(' AND ', $where) : '',
        'params' => $params,
        'select' => $seg['select'],
    ];
}

/**
 * JOIN/WHERE fragments for combinable user filters (test account, paid buys, paid extends).
 *
 * @return array{joins:string,where:array,params:array,select:array}
 */
function panel_user_segment_query_parts(array $filters, bool $alwaysJoin = false): array
{
    $needBuys = $alwaysJoin || (($filters['min_buys'] ?? null) !== null);
    $needExtends = $alwaysJoin || (($filters['min_extends'] ?? null) !== null);
    $needTest = $alwaysJoin || (($filters['test'] ?? '') !== '');

    $joins = [];
    $where = [];
    $params = [];
    $select = [];
    $paidSql = panel_invoice_paid_sql('Status');
    $extendSql = panel_extend_paid_sql();

    if ($needBuys) {
        $joins[] = "LEFT JOIN (
            SELECT id_user, COUNT(*) AS buy_count
            FROM invoice
            WHERE name_product != 'سرویس تست' AND $paidSql
            GROUP BY id_user
        ) seg_buys ON CONVERT(seg_buys.id_user USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(u.id USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        $select[] = 'COALESCE(seg_buys.buy_count, 0) AS buy_count';
    }
    if ($needExtends) {
        $joins[] = "LEFT JOIN (
            SELECT id_user, COUNT(*) AS extend_count
            FROM service_other
            WHERE $extendSql
            GROUP BY id_user
        ) seg_extends ON CONVERT(seg_extends.id_user USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(u.id USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        $select[] = 'COALESCE(seg_extends.extend_count, 0) AS extend_count';
    }
    if ($needTest) {
        $joins[] = "LEFT JOIN (
            SELECT id_user,
                   SUM(name_product = 'سرویس تست') AS test_count,
                   SUM(name_product != 'سرویس تست') AS non_test_count
            FROM invoice
            GROUP BY id_user
        ) seg_tests ON CONVERT(seg_tests.id_user USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(u.id USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        $select[] = 'COALESCE(seg_tests.test_count, 0) AS test_count';
    }

    if (($filters['test'] ?? '') === 'yes') {
        $where[] = 'COALESCE(seg_tests.test_count, 0) > 0';
    } elseif (($filters['test'] ?? '') === 'no') {
        $where[] = 'COALESCE(seg_tests.test_count, 0) = 0';
    } elseif (($filters['test'] ?? '') === 'only') {
        $where[] = 'COALESCE(seg_tests.test_count, 0) > 0';
        $where[] = 'COALESCE(seg_tests.non_test_count, 0) = 0';
    }
    if (($filters['min_buys'] ?? null) !== null) {
        $where[] = 'COALESCE(seg_buys.buy_count, 0) >= ?';
        $params[] = (int) $filters['min_buys'];
    }
    if (($filters['min_extends'] ?? null) !== null) {
        $where[] = 'COALESCE(seg_extends.extend_count, 0) >= ?';
        $params[] = (int) $filters['min_extends'];
    }

    return [
        'joins' => implode("\n", $joins),
        'where' => $where,
        'params' => $params,
        'select' => $select,
    ];
}

function panel_invoice_status_map(): array
{
    return [
        'active' => ['tag-ok', 'فعال'],
        'end_of_time' => ['tag-warn', 'نزدیک به پایان زمان'],
        'end_of_volume' => ['tag-no', 'نزدیک به پایان حجم'],
        'sendedwarn' => ['tag-warn', 'اعلان همگی ارسال شده'],
        'send_on_hold' => ['tag-plain', 'در انتظار'],
        'unpaid' => ['tag-plain', 'پرداخت نشده'],
        'unpiad' => ['tag-plain', 'پرداخت نشده'],
        'removebyadmin' => ['tag-no', 'حذف توسط ادمین'],
        'disablebyadmin' => ['tag-no', 'غیرفعال توسط ادمین'],
        'disabledn' => ['tag-no', 'غیرفعال در پنل'],
        'Unsuccessful' => ['tag-plain', 'خطا دریافت اطلاعات'],
    ];
}

function panel_invoice_get_status(array $invoice): string
{
    return (string) ($invoice['Status'] ?? $invoice['status'] ?? '');
}

function panel_invoice_status_label(string $status): array
{
    return panel_invoice_status_map()[$status] ?? ['tag-plain', $status ?: '—'];
}

function panel_user_is_blocked(array $user): bool
{
    return strtolower((string) ($user['User_Status'] ?? '')) === 'block';
}

function panel_user_display_name(array $user): string
{
    $name = $user['namecustom'] ?? '';
    if ($name === 'none') {
        $name = '';
    }
    $uname = $user['username'] ?? '';
    if ($uname === 'none') {
        $uname = '';
    }
    if ($name !== '') {
        return $name;
    }
    if ($uname !== '') {
        return '@' . $uname;
    }
    return 'کاربر #' . ($user['id'] ?? '');
}

function panel_service_button_label(array $invoice): string
{
    $suffix = '';
    if (!empty($invoice['note']) && $invoice['note'] !== 'none') {
        $suffix = ' | ' . $invoice['note'];
    }
    return mirza_inline_service_button_text((string) ($invoice['username'] ?? '—'), $suffix);
}

function panel_invoice_active_where(): string
{
    $parts = [];
    foreach (panel_invoice_active_statuses() as $st) {
        $parts[] = "status = '$st'";
        $parts[] = "Status = '$st'";
    }
    return '(' . implode(' OR ', $parts) . ')';
}

function panel_count_user_services(PDO $pdo, $userId): int
{
    $where = panel_invoice_active_where();
    return db_count(
        $pdo,
        "SELECT COUNT(*) FROM invoice WHERE id_user = ? AND $where",
        [(string) $userId]
    );
}

function panel_fetch_user_services(PDO $pdo, $userId, int $limit = 100, int $offset = 0): array
{
    $where = panel_invoice_active_where();
    return db_fetchAll(
        $pdo,
        "SELECT * FROM invoice WHERE id_user = ? AND $where ORDER BY time_sell DESC LIMIT $limit OFFSET $offset",
        [(string) $userId]
    );
}

function panel_format_traffic_gb($bytes, int $precision = 2): string
{
    if (!is_numeric($bytes) || (float) $bytes <= 0) {
        return '0';
    }
    $gb = (float) $bytes / pow(1024, 3);
    $formatted = number_format($gb, $precision, '.', '');
    return rtrim(rtrim($formatted, '0'), '.') ?: '0';
}

function panel_format_remaining_time($expireTs): string
{
    if ($expireTs === null || $expireTs === '' || !is_numeric($expireTs) || (int) $expireTs <= 0) {
        return 'نامحدود';
    }
    $diff = (int) $expireTs - time();
    if ($diff <= 0) {
        return 'منقضی';
    }
    $days = intdiv($diff, 86400);
    $hours = intdiv($diff % 86400, 3600);
    $mins = intdiv($diff % 3600, 60);
    if ($days > 0) {
        return $days . ' روز' . ($hours > 0 ? ' و ' . $hours . ' ساعت' : '');
    }
    if ($hours > 0) {
        return $hours . ' ساعت' . ($mins > 0 ? ' و ' . $mins . ' دقیقه' : '');
    }
    return max(1, $mins) . ' دقیقه';
}

/**
 * Format live panel data into table display strings.
 *
 * @param array<string,mixed>|null $live
 * @return array{usage_volume:string,usage_time:string}
 */
function panel_format_live_usage(?array $live): array
{
    $out = ['usage_volume' => '—', 'usage_time' => '—'];
    if (!is_array($live) || ($live['status'] ?? '') === 'Unsuccessful') {
        return $out;
    }

    $usedBytes = isset($live['used_traffic']) && is_numeric($live['used_traffic'])
        ? (float) $live['used_traffic']
        : 0.0;
    $limitBytes = isset($live['data_limit']) && is_numeric($live['data_limit'])
        ? (float) $live['data_limit']
        : 0.0;
    $usedGb = panel_format_traffic_gb($usedBytes);
    if ($limitBytes > 0) {
        $out['usage_volume'] = $usedGb . ' / ' . panel_format_traffic_gb($limitBytes) . ' گیگ';
    } else {
        $out['usage_volume'] = $usedGb . ' گیگ / نامحدود';
    }
    $out['usage_time'] = panel_format_remaining_time($live['expire'] ?? null);
    return $out;
}

function panel_usage_bootstrap(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $root = dirname(__DIR__, 2);
    if (!class_exists('ManagePanel', false)) {
        require_once $root . '/panels.php';
    }
    global $ManagePanel;
    if (!isset($ManagePanel) || !is_object($ManagePanel)) {
        $ManagePanel = new ManagePanel();
    }
    $done = true;
}

/**
 * Fetch used traffic + remaining time from the VPN panel (no sub/config download).
 *
 * @return array{usage_volume:string,usage_time:string}
 */
function panel_fetch_service_usage_live(string $panel, string $username): array
{
    $empty = ['usage_volume' => '—', 'usage_time' => '—'];
    if ($panel === '' || $username === '') {
        return $empty;
    }

    try {
        panel_usage_bootstrap();
    } catch (Throwable $e) {
        error_log('panel_fetch_service_usage_live bootstrap: ' . $e->getMessage());
        return $empty;
    }

    global $ManagePanel, $request_exec_timeout;
    $prevTimeout = $request_exec_timeout ?? null;
    $request_exec_timeout = 2500;

    try {
        $live = $ManagePanel->DataUser($panel, $username, true);
        return panel_format_live_usage(is_array($live) ? $live : null);
    } catch (Throwable $e) {
        error_log('panel_fetch_service_usage_live DataUser: ' . $e->getMessage());
        return $empty;
    } finally {
        $request_exec_timeout = $prevTimeout;
    }
}

/**
 * Attach live panel usage (used/total GB + remaining time) to invoice rows.
 *
 * @param list<array<string,mixed>> $services
 * @return list<array<string,mixed>>
 */
function panel_enrich_services_usage(array $services): array
{
    foreach ($services as &$svc) {
        $formatted = panel_fetch_service_usage_live(
            (string) ($svc['Service_location'] ?? ''),
            (string) ($svc['username'] ?? '')
        );
        $svc['usage_volume'] = $formatted['usage_volume'];
        $svc['usage_time'] = $formatted['usage_time'];
    }
    unset($svc);
    return $services;
}

function panel_record_admin_balance_change(PDO $pdo, $userId, int $amount, string $method): void
{
    if ($amount <= 0) {
        return;
    }
    $dateacc = date('Y/m/d H:i:s');
    $orderId = bin2hex(random_bytes(5));
    db_query(
        $pdo,
        "INSERT INTO Payment_report (id_user, id_order, time, price, payment_Status, Payment_Method, id_invoice) VALUES (?,?,?,?,?,?,?)",
        [$userId, $orderId, $dateacc, $amount, 'paid', $method, null]
    );
}

function panel_notify_user($userId, string $text): void
{
    $botapi = dirname(__DIR__, 2) . '/botapi.php';
    if (!is_file($botapi)) {
        return;
    }
    require_once $botapi;
    if (function_exists('sendmessage')) {
        sendmessage($userId, $text, null, 'HTML');
    }
}

function panel_service_bootstrap(): void
{
    if (!function_exists('panel_payment_bootstrap')) {
        require_once __DIR__ . '/payments_lib.php';
    }
    panel_payment_bootstrap();
    global $datatextbot;
    if (!isset($datatextbot)) {
        global $pdo;
        $datatextbot = $pdo->query('SELECT id_text, text FROM textbot')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}

function panel_mark_invoice_removed(PDO $pdo, string $idInvoice): void
{
    db_query(
        $pdo,
        "UPDATE invoice SET Status = 'removebyadmin', status = 'removebyadmin' WHERE id_invoice = ?",
        [$idInvoice]
    );
}

function panel_mark_invoice_disabled_by_admin(PDO $pdo, string $idInvoice): void
{
    db_query(
        $pdo,
        "UPDATE invoice SET Status = 'disablebyadmin' WHERE id_invoice = ?",
        [$idInvoice]
    );
}

/**
 * Disable the VPN user on the sub-link panel and mark the robot invoice as disabled by admin.
 * The invoice row is kept. Local status is updated even if the panel call fails.
 *
 * @return array{ok:bool,msg:string}
 */
function panel_disable_invoice_service(PDO $pdo, array $invoice): array
{
    panel_service_bootstrap();
    global $ManagePanel;

    $idInvoice = (string) ($invoice['id_invoice'] ?? '');
    $username = trim((string) ($invoice['username'] ?? ''));
    $location = trim((string) ($invoice['Service_location'] ?? ''));
    $notes = [];
    $panelOk = true;

    if ($username === '' || $location === '') {
        $panelOk = false;
        $notes[] = 'نام کاربری یا پنل سرویس مشخص نیست.';
    } else {
        try {
            $live = $ManagePanel->DataUser($location, $username);
            $liveStatus = is_array($live) ? (string) ($live['status'] ?? '') : '';
            if ($liveStatus === 'Unsuccessful') {
                $panelOk = false;
                $detail = trim((string) ($live['msg'] ?? $live['detail'] ?? ''));
                $notes[] = 'غیرفعال‌سازی پنل ساب‌لینک ناموفق بود' . ($detail !== '' ? ': ' . $detail : '.');
            } elseif ($liveStatus === 'disabled') {
                $notes[] = 'سرویس از قبل در پنل ساب‌لینک غیرفعال بود.';
            } elseif ($liveStatus === 'active') {
                $result = $ManagePanel->Change_status($username, $location);
                $ok = is_array($result) && ($result['status'] ?? '') === 'successful';
                $panelOk = $ok;
                $notes[] = $ok
                    ? 'سرویس در پنل ساب‌لینک غیرفعال شد.'
                    : ('غیرفعال‌سازی پنل ساب‌لینک: ' . trim((string) ($result['msg'] ?? 'ناموفق')));
            } else {
                $result = $ManagePanel->Modifyuser($username, $location, ['status' => 'disabled']);
                if (is_array($result) && array_key_exists('status', $result) && $result['status'] === false) {
                    $panelOk = false;
                    $notes[] = 'غیرفعال‌سازی پنل ساب‌لینک: ' . trim((string) ($result['msg'] ?? 'ناموفق'));
                } else {
                    $notes[] = 'سرویس در پنل ساب‌لینک غیرفعال شد.';
                }
            }
        } catch (Throwable $e) {
            error_log('panel_disable_invoice_service: ' . $e->getMessage());
            $panelOk = false;
            $notes[] = 'غیرفعال‌سازی در پنل ساب‌لینک ناموفق بود.';
        }
    }

    if ($idInvoice !== '') {
        panel_mark_invoice_disabled_by_admin($pdo, $idInvoice);
        $notes[] = 'وضعیت سرویس در ربات به غیرفعال توسط ادمین تغییر کرد.';
    }

    return ['ok' => $panelOk, 'msg' => implode(' ', $notes)];
}

/**
 * Refund a purchased service: optionally credit the wallet and/or disable the product.
 *
 * @return array{ok:bool,msg:string}
 */
function panel_invoice_apply_refund(PDO $pdo, string $idInvoice, bool $disableProduct = false, bool $creditWallet = false): array
{
    $invoice = db_fetch($pdo, 'SELECT * FROM invoice WHERE id_invoice = ?', [$idInvoice]);
    if (!$invoice) {
        return ['ok' => false, 'msg' => 'فاکتور یافت نشد.'];
    }

    if (!$disableProduct && !$creditWallet) {
        return ['ok' => false, 'msg' => 'یکی از گزینه‌های بازگشت مبلغ به کیف پول یا غیرفعال‌سازی سرویس را انتخاب کنید.'];
    }

    $notes = [];
    $userId = $invoice['id_user'] ?? null;

    if ($creditWallet) {
        $price = (int) ($invoice['price_product'] ?? 0);
        if ($price <= 0) {
            $notes[] = 'مبلغ سرویس صفر است و به کیف پول اضافه نشد.';
        } else {
            $already = db_count(
                $pdo,
                "SELECT COUNT(*) FROM Payment_report
                 WHERE id_user = ? AND id_invoice = ? AND Payment_Method = 'refund to wallet'",
                [(string) $userId, $idInvoice]
            );
            if ($already) {
                $notes[] = 'مبلغ این سرویس قبلاً به کیف پول کاربر بازگردانده شده است.';
            } else {
                db_query($pdo, 'UPDATE user SET Balance = Balance + ? WHERE id = ?', [$price, $userId]);
                $dateacc = date('Y/m/d H:i:s');
                $orderId = bin2hex(random_bytes(5));
                db_query(
                    $pdo,
                    'INSERT INTO Payment_report (id_user, id_order, time, price, payment_Status, Payment_Method, id_invoice) VALUES (?,?,?,?,?,?,?)',
                    [$userId, $orderId, $dateacc, $price, 'paid', 'refund to wallet', $idInvoice]
                );
                panel_notify_user($userId, '💎 کاربر عزیز مبلغ ' . number_format($price) . ' تومان بابت مرجوعی سرویس به موجودی کیف پول تان اضافه گردید.');
                $notes[] = 'مبلغ ' . number_format($price) . ' تومان به کیف پول کاربر بازگردانده شد.';
            }
        }
    }

    if ($disableProduct) {
        $status = panel_invoice_get_status($invoice);
        if (in_array($status, ['disablebyadmin', 'removebyadmin'], true)) {
            $notes[] = 'این سرویس از قبل در ربات غیرفعال است.';
        } else {
            $disabled = panel_disable_invoice_service($pdo, $invoice);
            $notes[] = $disabled['msg'];
        }
    }

    return ['ok' => true, 'msg' => implode(' ', $notes)];
}

/**
 * @return array{ok:bool,msg:string}
 */
function panel_add_user_service(PDO $pdo, $userId, string $username, string $panelName, string $productName): array
{
    panel_service_bootstrap();
    global $ManagePanel, $textbotlang, $datatextbot;

    $username = strtolower(trim($username));
    if (!preg_match('/^\w{3,32}$/', $username)) {
        return ['ok' => false, 'msg' => 'نام کاربری باید ۳ تا ۳۲ کاراکتر و فقط حروف، عدد و _ باشد.'];
    }

    if (db_count($pdo, 'SELECT COUNT(*) FROM invoice WHERE username = ?', [$username])) {
        return ['ok' => false, 'msg' => 'این نام کاربری از قبل در ربات ثبت شده است.'];
    }

    $info_product = db_fetch(
        $pdo,
        "SELECT * FROM product WHERE name_product = ? AND (Location = ? OR Location = '/all') LIMIT 1",
        [$productName, $panelName]
    );
    if (!$info_product) {
        return ['ok' => false, 'msg' => 'محصول انتخاب‌شده برای این پنل یافت نشد.'];
    }

    $marzban_list_get = db_fetch($pdo, 'SELECT * FROM marzban_panel WHERE name_panel = ?', [$panelName]);
    if (!$marzban_list_get) {
        return ['ok' => false, 'msg' => 'پنل یافت نشد.'];
    }

    $DataUserOut = $ManagePanel->DataUser($panelName, $username);
    if (($DataUserOut['status'] ?? '') === 'Unsuccessful') {
        $serviceTime = (int) ($info_product['Service_time'] ?? 0);
        $datetimestep = $serviceTime === 0 ? 0 : strtotime('+' . $serviceTime . ' days');
        $datac = [
            'expire' => $datetimestep,
            'data_limit' => (int) $info_product['Volume_constraint'] * pow(1024, 3),
            'from_id' => $userId,
            'username' => '',
            'type' => 'buy',
        ];
        $DataUserOut = $ManagePanel->createUser($panelName, $info_product['code_product'], $username, $datac);
        if (empty($DataUserOut['username'])) {
            $err = is_string($DataUserOut['msg'] ?? null) ? $DataUserOut['msg'] : json_encode($DataUserOut['msg'] ?? 'unknown');
            return ['ok' => false, 'msg' => 'خطا در ساخت سرویس روی پنل: ' . $err];
        }
    } else {
        $DataUserOut['configs'] = $DataUserOut['links'] ?? [];
    }

    $idInvoice = bin2hex(random_bytes(4));
    $notifctions = json_encode(['volume' => false, 'time' => false]);
    db_query(
        $pdo,
        'INSERT INTO invoice (id_user, id_invoice, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status, notifctions) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [
            (string) $userId,
            $idInvoice,
            $username,
            time(),
            $panelName,
            $info_product['name_product'],
            $info_product['price_product'],
            $info_product['Volume_constraint'],
            $info_product['Service_time'],
            'active',
            $notifctions,
        ]
    );

    $output_config_link = ($marzban_list_get['sublink'] ?? '') === 'onsublink' ? ($DataUserOut['subscription_url'] ?? '') : '';
    $config = '';
    if (($marzban_list_get['config'] ?? '') === 'onconfig' && is_array($DataUserOut['configs'] ?? null)) {
        foreach ($DataUserOut['configs'] as $link) {
            $config .= "\n" . $link;
        }
    }

    $textTemplate = $datatextbot['textafterpay'] ?? '✅ سرویس {name_service} برای {username} ایجاد شد.';
    if (($marzban_list_get['type'] ?? '') === 'Manualsale') {
        $textTemplate = $datatextbot['textmanual'] ?? $textTemplate;
    } elseif (in_array($marzban_list_get['type'] ?? '', ['ibsng', 'mikrotik'], true)) {
        $textTemplate = $datatextbot['textafterpayibsng'] ?? $textTemplate;
    }

    $dayLabel = (int) ($info_product['Service_time'] ?? 0) === 0
        ? ($textbotlang['users']['stateus']['Unlimited'] ?? 'نامحدود')
        : $info_product['Service_time'];
    $volumeLabel = (int) ($info_product['Volume_constraint'] ?? 0) === 0
        ? ($textbotlang['users']['stateus']['Unlimited'] ?? 'نامحدود')
        : $info_product['Volume_constraint'];

    $textcreatuser = str_replace(
        ['{username}', '{name_service}', '{location}', '{day}', '{volume}', '{config}', '{links}', '{links2}'],
        [
            '<code>' . ($DataUserOut['username'] ?? $username) . '</code>',
            $info_product['name_product'],
            $panelName,
            $dayLabel,
            $volumeLabel,
            '<code>' . $output_config_link . '</code>',
            $config,
            $output_config_link,
        ],
        $textTemplate
    );

    if (function_exists('sendMessageService')) {
        $Shoppinginfo = json_encode([
            'inline_keyboard' => [[['text' => $textbotlang['users']['help']['btninlinebuy'] ?? 'راهنما', 'callback_data' => 'helpbtn']]],
        ]);
        sendMessageService(
            $marzban_list_get,
            $DataUserOut['configs'] ?? [],
            $output_config_link,
            $DataUserOut['username'] ?? $username,
            $Shoppinginfo,
            $textcreatuser,
            $idInvoice,
            $userId
        );
    } else {
        panel_notify_user($userId, strip_tags(str_replace(['<code>', '</code>'], '', $textcreatuser)));
    }

    return ['ok' => true, 'msg' => 'سرویس «' . $username . '» با موفقیت برای کاربر ایجاد شد.'];
}

/**
 * @return array{ok:bool,msg:string}
 */
function panel_remove_user_service(PDO $pdo, string $idInvoice, $userId, bool $refund = false): array
{
    panel_service_bootstrap();
    global $ManagePanel;

    $invoice = db_fetch($pdo, 'SELECT * FROM invoice WHERE id_invoice = ? AND id_user = ?', [$idInvoice, (string) $userId]);
    if (!$invoice) {
        return ['ok' => false, 'msg' => 'سرویس یافت نشد یا متعلق به این کاربر نیست.'];
    }

    if (panel_invoice_get_status($invoice) === 'removebyadmin') {
        return ['ok' => false, 'msg' => 'این سرویس از قبل حذف شده است.'];
    }

    try {
        $ManagePanel->RemoveUser($invoice['Service_location'], $invoice['username']);
    } catch (Throwable $e) {
        error_log('panel_remove_user_service: ' . $e->getMessage());
    }

    panel_mark_invoice_removed($pdo, $idInvoice);

    if ($refund) {
        $price = (int) ($invoice['price_product'] ?? 0);
        if ($price > 0) {
            db_query($pdo, 'UPDATE user SET Balance = Balance + ? WHERE id = ?', [$price, $userId]);
            panel_notify_user($userId, '💎 کاربر عزیز مبلغ ' . number_format($price) . ' تومان به موجودی کیف پول تان اضافه گردید.');
        }
    }

    $msg = $refund ? 'سرویس حذف و مبلغ به کیف پول کاربر بازگردانده شد.' : 'سرویس از پنل حذف و در ربات غیرفعال شد.';
    return ['ok' => true, 'msg' => $msg];
}

/**
 * @return array{ok:bool,msg:string}
 */
function panel_update_invoice_record(PDO $pdo, string $idInvoice, array $fields): array
{
    $invoice = db_fetch($pdo, 'SELECT * FROM invoice WHERE id_invoice = ?', [$idInvoice]);
    if (!$invoice) {
        return ['ok' => false, 'msg' => 'فاکتور یافت نشد.'];
    }

    $allowed = [
        'Status' => 'Status',
        'name_product' => 'name_product',
        'price_product' => 'price_product',
        'Volume' => 'Volume',
        'Service_time' => 'Service_time',
        'username' => 'username',
        'note' => 'note',
        'Service_location' => 'Service_location',
    ];
    $sets = [];
    $params = [];
    foreach ($allowed as $key => $column) {
        if (!array_key_exists($key, $fields)) {
            continue;
        }
        $sets[] = "`$column` = ?";
        $params[] = trim((string) $fields[$key]);
    }
    if (!$sets) {
        return ['ok' => false, 'msg' => 'فیلدی برای به‌روزرسانی ارسال نشد.'];
    }
    $params[] = $idInvoice;
    db_query($pdo, 'UPDATE invoice SET ' . implode(', ', $sets) . ' WHERE id_invoice = ?', $params);
    return ['ok' => true, 'msg' => 'فاکتور به‌روز شد.'];
}

/**
 * @return array{ok:bool,msg:string}
 */
function panel_update_service_other_record(PDO $pdo, int $id, array $fields): array
{
    $row = db_fetch($pdo, 'SELECT * FROM service_other WHERE id = ?', [$id]);
    if (!$row) {
        return ['ok' => false, 'msg' => 'سفارش یافت نشد.'];
    }

    $allowed = [
        'status' => 'status',
        'value' => 'value',
        'price' => 'price',
        'username' => 'username',
    ];
    $sets = [];
    $params = [];
    foreach ($allowed as $key => $column) {
        if (!array_key_exists($key, $fields)) {
            continue;
        }
        $sets[] = "`$column` = ?";
        $params[] = trim((string) $fields[$key]);
    }
    if (!$sets) {
        return ['ok' => false, 'msg' => 'فیلدی برای به‌روزرسانی ارسال نشد.'];
    }
    $params[] = $id;
    db_query($pdo, 'UPDATE service_other SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    return ['ok' => true, 'msg' => 'سفارش به‌روز شد.'];
}
