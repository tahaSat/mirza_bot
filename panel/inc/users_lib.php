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
