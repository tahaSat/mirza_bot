<?php

ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';

$ManagePanel = new ManagePanel();
$setting = select("setting", "*");
$errorTopic = select("topicid", "idreport", "report", "errorreport", "select");
$errorreport = $errorTopic['idreport'] ?? null;

if (!is_file('gift') || !is_file('username.json')) {
    return;
}

$info = json_decode((string) file_get_contents('gift'), true);
$services = json_decode((string) file_get_contents('username.json'), true);
if (!is_array($info) || !is_array($services) || !isset($info['typegift'])) {
    return;
}

$finishGift = static function (array $job): void {
    if (isset($job['id_admin'])) {
        if (!empty($job['id_message'])) {
            deletemessage($job['id_admin'], $job['id_message']);
        }
        $success = intval($job['success_count'] ?? 0);
        $failed = intval($job['failed_count'] ?? 0);
        $skipped = intval($job['skipped_count'] ?? 0);
        $summary = "📌 عملیات برای تمامی سرویس‌های درخواستی انجام شد.";
        if (!empty($job['bulk_service_charge']) || isset($job['success_count'])) {
            $summary .= "\n\n✅ موفق: {$success}\n❌ ناموفق: {$failed}\n⏭ ردشده: {$skipped}";
        }
        sendmessage($job['id_admin'], $summary, null, 'HTML');
    }
    if (is_file('gift')) {
        unlink('gift');
    }
    if (is_file('username.json')) {
        unlink('username.json');
    }
};

if (count($services) === 0) {
    $finishGift($info);
    return;
}

$reportFailure = static function (array $panel, string $username, array $result) use ($setting, $errorreport): void {
    if (empty($setting['Channel_Report'])) {
        return;
    }
    $reason = $result['msg'] ?? 'خطای نامشخص';
    if (!is_string($reason)) {
        $reason = json_encode($reason, JSON_UNESCAPED_UNICODE);
    }
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $errorreport,
        'text' => "خطای اعمال شارژ همگانی سرویس\n"
            . "نام پنل : {$panel['name_panel']}\n"
            . "نام کاربری سرویس : {$username}\n"
            . "دلیل خطا : {$reason}",
        'parse_mode' => 'HTML',
    ]);
};

$logResult = static function (array $invoice, array $liveUser, array $job, array $result) use ($pdo): void {
    $isVolume = $job['typegift'] === 'volume';
    $valueData = [
        $isVolume ? 'volume_value' : 'time_value' => intval($job['value']),
        'old_volume' => $liveUser['data_limit'] ?? null,
        'expire_old' => $liveUser['expire'] ?? null,
    ];
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO service_other (id_user, username, value, type, time, price, output)
         VALUES (:id_user, :username, :value, :type, :time, :price, :output)"
    );
    $stmt->execute([
        ':id_user' => $invoice['id_user'],
        ':username' => $invoice['username'],
        ':value' => json_encode($valueData, JSON_UNESCAPED_UNICODE),
        ':type' => $isVolume ? 'gift_volume' : 'gift_time',
        ':time' => date('Y/m/d H:i:s'),
        ':price' => 0,
        ':output' => json_encode($result, JSON_UNESCAPED_UNICODE),
    ]);
};

$batch = array_splice($services, 0, 5);
foreach ($batch as $queuedService) {
    if (!is_array($queuedService) || empty($queuedService['username'])) {
        $info['skipped_count'] = intval($info['skipped_count'] ?? 0) + 1;
        continue;
    }

    if (!empty($queuedService['id_invoice'])) {
        $invoice = select("invoice", "*", "id_invoice", $queuedService['id_invoice'], "select");
    } else {
        $invoice = select("invoice", "*", "username", $queuedService['username'], "select");
    }
    if (!$invoice) {
        $info['skipped_count'] = intval($info['skipped_count'] ?? 0) + 1;
        continue;
    }

    $panelName = $queuedService['Service_location'] ?? ($info['name_panel'] ?? $invoice['Service_location']);
    $panel = select("marzban_panel", "*", "name_panel", $panelName, "select");
    if (!$panel) {
        $info['skipped_count'] = intval($info['skipped_count'] ?? 0) + 1;
        continue;
    }

    $liveUser = $ManagePanel->DataUser($panelName, $invoice['username']);
    if (!is_array($liveUser) || ($liveUser['status'] ?? '') === 'Unsuccessful') {
        $info['failed_count'] = intval($info['failed_count'] ?? 0) + 1;
        $reportFailure($panel, $invoice['username'], is_array($liveUser) ? $liveUser : ['msg' => 'پاسخ نامعتبر پنل']);
        continue;
    }

    $isVolume = $info['typegift'] === 'volume';
    $eligible = $isVolume
        ? (isset($liveUser['data_limit']) && is_numeric($liveUser['data_limit']) && floatval($liveUser['data_limit']) > 0)
        : (isset($liveUser['expire']) && is_numeric($liveUser['expire']) && intval($liveUser['expire']) > 0);
    if (!$eligible) {
        $info['skipped_count'] = intval($info['skipped_count'] ?? 0) + 1;
        continue;
    }

    if ($isVolume) {
        $result = $ManagePanel->extra_volume($invoice['username'], $panel['code_panel'], intval($info['value']));
    } else {
        $result = $ManagePanel->extra_time($invoice['username'], $panel['code_panel'], intval($info['value']));
    }
    if (!is_array($result)) {
        $result = ['status' => false, 'msg' => 'پاسخ نامعتبر پنل'];
    }

    $logResult($invoice, $liveUser, $info, $result);
    if (($result['status'] ?? false) === false) {
        $info['failed_count'] = intval($info['failed_count'] ?? 0) + 1;
        $reportFailure($panel, $invoice['username'], $result);
        continue;
    }

    $info['success_count'] = intval($info['success_count'] ?? 0) + 1;
    if (isset($info['text']) && trim((string) $info['text']) !== '') {
        sendmessage($invoice['id_user'], $info['text'], null, 'HTML');
    }
}

file_put_contents('gift', json_encode($info, JSON_UNESCAPED_UNICODE), LOCK_EX);
file_put_contents('username.json', json_encode(array_values($services), JSON_UNESCAPED_UNICODE), LOCK_EX);

if (!empty($info['id_admin']) && !empty($info['id_message'])) {
    $remaining = count($services);
    $success = intval($info['success_count'] ?? 0);
    $failed = intval($info['failed_count'] ?? 0);
    $skipped = intval($info['skipped_count'] ?? 0);
    $cancelKeyboard = json_encode([
        'inline_keyboard' => [
            [
                ['text' => "❌ لغو عملیات", 'callback_data' => 'cancel_gift'],
            ],
        ],
    ]);
    Editmessagetext(
        $info['id_admin'],
        $info['id_message'],
        "✏️ عملیات شارژ سرویس‌ها در حال انجام است...\n\n"
        . "باقی‌مانده: {$remaining}\n✅ موفق: {$success}\n❌ ناموفق: {$failed}\n⏭ ردشده: {$skipped}",
        $cancelKeyboard
    );
}