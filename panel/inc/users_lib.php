<?php

function panel_invoice_active_statuses(): array
{
    return ['active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold'];
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
