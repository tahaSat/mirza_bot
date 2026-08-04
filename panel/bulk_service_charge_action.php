<?php

require_once __DIR__ . '/inc/config.php';
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
if (!$admin || ($admin['rule'] ?? '') !== 'administrator') {
    flash('error', 'فقط مدیر اصلی می‌تواند شارژ همگانی سرویس‌ها را اجرا کند.');
    header('Location: users.php');
    exit;
}

$cronDir = dirname(__DIR__) . '/cronbot';
$giftFile = $cronDir . '/gift';
$serviceQueueFile = $cronDir . '/username.json';
$messageQueueFile = $cronDir . '/users.json';
$messageInfoFile = $cronDir . '/info';
$action = $_POST['action'] ?? 'start';

if ($action === 'cancel') {
    $job = is_file($giftFile)
        ? json_decode((string) file_get_contents($giftFile), true)
        : null;
    if (!is_array($job) || empty($job['bulk_service_charge'])) {
        flash('warning', 'عملیات شارژ همگانی فعالی برای لغو وجود ندارد.');
    } else {
        if (is_file($serviceQueueFile)) {
            unlink($serviceQueueFile);
        }
        if (is_file($giftFile)) {
            unlink($giftFile);
        }
        flash('success', 'عملیات شارژ همگانی سرویس‌ها لغو شد.');
    }
    header('Location: users.php');
    exit;
}

$agent = $_POST['agent'] ?? 'all';
$serviceType = $_POST['service_type'] ?? '';
$valueRaw = trim((string) ($_POST['value'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

if (!in_array($agent, ['all', 'f', 'n', 'n2'], true)) {
    flash('error', 'گروه کاربری نامعتبر است.');
    header('Location: users.php');
    exit;
}
if (!in_array($serviceType, ['volume', 'day'], true)) {
    flash('error', 'نوع سرویس نامعتبر است.');
    header('Location: users.php');
    exit;
}
if ($valueRaw === '' || !ctype_digit($valueRaw) || intval($valueRaw) <= 0) {
    flash('error', 'مقدار شارژ باید یک عدد صحیح بزرگ‌تر از صفر باشد.');
    header('Location: users.php');
    exit;
}
if ($message === '' || mb_strlen($message, 'UTF-8') > 4000) {
    flash('error', 'پیام ارسالی باید بین ۱ تا ۴۰۰۰ کاراکتر باشد.');
    header('Location: users.php');
    exit;
}

$queueBusy = is_file($giftFile) || is_file($messageInfoFile);
foreach ([$serviceQueueFile, $messageQueueFile] as $queueFile) {
    if ($queueBusy || !is_file($queueFile)) {
        continue;
    }
    $queuedItems = json_decode((string) file_get_contents($queueFile), true);
    $queueBusy = is_array($queuedItems) && count($queuedItems) > 0;
}
if ($queueBusy) {
    flash('error', 'یک عملیات گروهی دیگر در حال اجرا است. پس از پایان آن دوباره تلاش کنید.');
    header('Location: users.php');
    exit;
}

$sql = "SELECT i.id_invoice, i.id_user, i.username, i.Service_location
        FROM invoice i
        INNER JOIN user u ON u.id = i.id_user
        WHERE i.Status = 'active'
          AND i.name_product != 'سرویس تست'
          AND u.User_Status = 'Active'";
$params = [];
if ($agent !== 'all') {
    $sql .= ' AND u.agent = :agent';
    $params[':agent'] = $agent;
}
if ($serviceType === 'volume') {
    $sql .= " AND CAST(COALESCE(NULLIF(i.Volume, ''), '0') AS UNSIGNED) > 0";
} else {
    $sql .= " AND CAST(COALESCE(NULLIF(i.Volume, ''), '0') AS UNSIGNED) = 0
              AND CAST(COALESCE(NULLIF(i.Service_time, ''), '0') AS UNSIGNED) > 0";
}
$sql .= ' ORDER BY i.id_invoice';
$services = db_fetchAll($pdo, $sql, $params);

if (!$services) {
    flash('warning', 'هیچ سرویس فعال مطابق فیلتر انتخاب‌شده یافت نشد.');
    header('Location: users.php');
    exit;
}

$job = [
    'bulkcharge_mode' => 'service',
    'bulk_service_charge' => true,
    'agent' => $agent,
    'typecustomer' => 'customer',
    'typegift' => $serviceType,
    'value' => intval($valueRaw),
    'text' => $message,
    'id_admin' => $admin['id_admin'],
    'total' => count($services),
    'success_count' => 0,
    'failed_count' => 0,
    'skipped_count' => 0,
];

try {
    $suffix = bin2hex(random_bytes(4));
    $queueTemp = $serviceQueueFile . '.' . $suffix . '.tmp';
    $giftTemp = $giftFile . '.' . $suffix . '.tmp';
    $queueActivated = false;
    if (file_put_contents($queueTemp, json_encode($services, JSON_UNESCAPED_UNICODE), LOCK_EX) === false
        || file_put_contents($giftTemp, json_encode($job, JSON_UNESCAPED_UNICODE), LOCK_EX) === false
    ) {
        throw new RuntimeException('Unable to write bulk service charge queue.');
    }
    if (!rename($queueTemp, $serviceQueueFile)) {
        throw new RuntimeException('Unable to activate bulk service charge queue.');
    }
    $queueActivated = true;
    if (!rename($giftTemp, $giftFile)) {
        throw new RuntimeException('Unable to activate bulk service charge queue.');
    }
    flash('success', 'شارژ همگانی برای ' . number_format(count($services)) . ' سرویس در صف اجرا قرار گرفت.');
} catch (Throwable $e) {
    if (isset($queueTemp) && is_file($queueTemp)) {
        unlink($queueTemp);
    }
    if (isset($giftTemp) && is_file($giftTemp)) {
        unlink($giftTemp);
    }
    if (!empty($queueActivated) && !is_file($giftFile) && is_file($serviceQueueFile)) {
        unlink($serviceQueueFile);
    }
    error_log('bulk_service_charge_action.php: ' . $e->getMessage());
    flash('error', 'ثبت عملیات شارژ همگانی ناموفق بود.');
}

header('Location: users.php');
exit;
