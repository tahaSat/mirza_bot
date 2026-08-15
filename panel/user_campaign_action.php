<?php

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/users_lib.php';
require_auth();
$pdo = panel_ensure_pdo();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}

csrf_check_post();

$admin = db_fetch(
    $pdo,
    'SELECT id_admin, username, rule FROM admin WHERE username = ?',
    [$_SESSION['admin_user'] ?? '']
);
if (!$admin) {
    flash('error', 'نشست ادمین معتبر نیست.');
    header('Location: users.php');
    exit;
}

$cronDir = dirname(__DIR__) . '/cronbot';
$infoFile = $cronDir . '/info';
$usersFile = $cronDir . '/users.json';
$giftFile = $cronDir . '/gift';
$action = $_POST['action'] ?? 'start';

$redirectQs = [
    'q' => trim((string) ($_POST['q'] ?? '')),
    'status' => trim((string) ($_POST['status'] ?? '')),
    'role' => trim((string) ($_POST['role'] ?? '')),
    'test' => trim((string) ($_POST['test'] ?? '')),
    'min_buys' => trim((string) ($_POST['min_buys'] ?? '')),
    'min_extends' => trim((string) ($_POST['min_extends'] ?? '')),
];
$redirectQs = array_filter($redirectQs, static fn($v) => $v !== '');
$redirect = 'users.php' . ($redirectQs ? ('?' . http_build_query($redirectQs)) : '');

if ($action === 'cancel') {
    $job = is_file($infoFile) ? json_decode((string) file_get_contents($infoFile), true) : null;
    if (!is_array($job) || ($job['type'] ?? '') !== 'sendmessage') {
        flash('warning', 'ارسال پیام فعالی برای لغو وجود ندارد.');
    } else {
        if (is_file($usersFile)) {
            unlink($usersFile);
        }
        if (is_file($infoFile)) {
            unlink($infoFile);
        }
        flash('success', 'ارسال پیام همگانی لغو شد.');
    }
    header('Location: ' . $redirect);
    exit;
}

$message = trim((string) ($_POST['message'] ?? ''));
$scope = ($_POST['scope'] ?? '') === 'filtered' ? 'filtered' : 'selected';
if ($message === '' || mb_strlen($message, 'UTF-8') > 3500) {
    flash('error', 'متن پیام باید بین ۱ تا ۳۵۰۰ کاراکتر باشد.');
    header('Location: ' . $redirect);
    exit;
}

support_ensure_schema($pdo);

$queueBusy = is_file($infoFile) || is_file($giftFile);
if (!$queueBusy && is_file($usersFile)) {
    $queuedItems = json_decode((string) file_get_contents($usersFile), true);
    $queueBusy = is_array($queuedItems) && count($queuedItems) > 0;
}
if ($queueBusy) {
    flash('error', 'یک عملیات گروهی دیگر در حال اجرا است. پس از پایان آن دوباره تلاش کنید.');
    header('Location: ' . $redirect);
    exit;
}

$userIds = [];
if ($scope === 'filtered') {
    $userFilters = [
        'test' => in_array($redirectQs['test'] ?? '', ['yes', 'no'], true) ? $redirectQs['test'] : '',
        'min_buys' => isset($redirectQs['min_buys']) && ctype_digit($redirectQs['min_buys']) ? (int) $redirectQs['min_buys'] : null,
        'min_extends' => isset($redirectQs['min_extends']) && ctype_digit($redirectQs['min_extends']) ? (int) $redirectQs['min_extends'] : null,
    ];
    $query = panel_users_filtered_query(
        $redirectQs['q'] ?? '',
        $redirectQs['status'] ?? '',
        $redirectQs['role'] ?? '',
        $userFilters
    );
    try {
        $rows = db_fetchAll($pdo, "SELECT u.id {$query['from']} {$query['where']}", $query['params']);
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $userIds[] = $id;
            }
        }
    } catch (Exception $e) {
        error_log('user_campaign_action filtered: ' . $e->getMessage());
        flash('error', 'خواندن فهرست کاربران فیلترشده ناموفق بود.');
        header('Location: ' . $redirect);
        exit;
    }
} else {
    $rawIds = $_POST['user_ids'] ?? [];
    if (!is_array($rawIds)) {
        $rawIds = [$rawIds];
    }
    $rawIds = array_values(array_unique(array_filter(array_map('strval', $rawIds), static fn($id) => preg_match('/^\d{4,20}$/', $id))));
    if ($rawIds) {
        $placeholders = implode(',', array_fill(0, count($rawIds), '?'));
        $found = db_fetchAll($pdo, "SELECT id FROM user WHERE id IN ($placeholders)", $rawIds);
        $userIds = array_values(array_filter(array_map(static fn($row) => (string) ($row['id'] ?? ''), $found)));
    }
}

$userIds = array_values(array_unique($userIds));
if ($userIds === []) {
    flash('error', 'هیچ کاربری برای ارسال پیام انتخاب نشده است.');
    header('Location: ' . $redirect);
    exit;
}

$userslist = json_encode(array_map(static fn($id) => ['id' => $id], $userIds), JSON_UNESCAPED_UNICODE);
$cancelKeyboard = json_encode([
    'inline_keyboard' => [[
        ['text' => 'لغو عملیات', 'callback_data' => 'cancel_sendmessage'],
    ]],
], JSON_UNESCAPED_UNICODE);

require_once dirname(__DIR__) . '/botapi.php';
$progress = sendmessage(
    $admin['id_admin'],
    '✅ عملیات آغاز گردید پس از پایان اطلاع رسانی خواهد شد.',
    $cancelKeyboard,
    'HTML'
);
$messageId = (int) ($progress['result']['message_id'] ?? 0);

$info = [
    'id_admin' => $admin['id_admin'],
    'type' => 'sendmessage',
    'id_message' => $messageId,
    'message' => $message,
    'messagemediatype' => 'text',
    'photoid' => '',
    'pingmessage' => 'no',
    'btnmessage' => 'none',
    'btntextmessage' => '',
    'create_campaign_conversation' => true,
    'campaign_admin_id' => $admin['id_admin'],
    'campaign_admin_username' => $admin['username'],
];

if (file_put_contents($usersFile, $userslist) === false || file_put_contents($infoFile, json_encode($info, JSON_UNESCAPED_UNICODE)) === false) {
    flash('error', 'ثبت صف ارسال پیام ناموفق بود.');
    header('Location: ' . $redirect);
    exit;
}

flash('success', 'ارسال پیام به ' . number_format(count($userIds)) . ' کاربر در صف کرون قرار گرفت. گفتگوی هر ارسال با وضعیت «کمپین» ثبت می‌شود.');
header('Location: ' . $redirect);
exit;
