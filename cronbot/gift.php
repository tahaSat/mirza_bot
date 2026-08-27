<?php
require_once __DIR__ . '/cli_only.php';

ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/gift_report.php';

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
    if (!empty($job['id_message']) && isset($job['id_admin'])) {
        deletemessage($job['id_admin'], $job['id_message']);
    }
    $success = intval($job['success_count'] ?? 0);
    $failed = intval($job['failed_count'] ?? 0);
    $skipped = intval($job['skipped_count'] ?? 0);
    $summary = "📌 عملیات برای تمامی سرویس‌های درخواستی انجام شد.";
    if (!empty($job['bulk_service_charge']) || isset($job['success_count'])) {
        $summary .= "\n\n✅ موفق: {$success}\n❌ ناموفق: {$failed}\n⏭ ردشده: {$skipped}";
    }
    if (isset($job['id_admin'])) {
        sendmessage($job['id_admin'], $summary, null, 'HTML');
    }
    if (!empty($job['bulk_service_charge'])) {
        gift_send_unfinished_report($job, [], false);
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

$isTimeoutError = static function ($reason): bool {
    if (!is_string($reason) || $reason === '') {
        return false;
    }
    $msg = strtolower($reason);
    return strpos($msg, 'timed out') !== false
        || strpos($msg, 'timeout') !== false
        || strpos($msg, 'empty reply') !== false
        || strpos($msg, 'connection reset') !== false
        || strpos($msg, 'failed to connect') !== false;
};

$reportFailure = static function (array $panel, string $username, array $result) use ($setting, $errorreport): void {
    $reason = $result['msg'] ?? 'خطای نامشخص';
    if (!is_string($reason)) {
        $reason = json_encode($reason, JSON_UNESCAPED_UNICODE);
    }
    if (empty($setting['Channel_Report'])) {
        return;
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

$logResult = static function (array $invoice, array $liveUser, string $kind, int $value, array $result) use ($pdo): void {
    $isVolume = $kind === 'volume';
    $valueData = [
        $isVolume ? 'volume_value' : 'time_value' => $value,
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

$addVolume = !empty($info['add_volume']);
$addTime = !empty($info['add_time']);
$volumeValue = intval($info['volume_value'] ?? 0);
$timeValue = intval($info['time_value'] ?? 0);
if (!$addVolume && !$addTime) {
    $addVolume = ($info['typegift'] ?? '') === 'volume';
    $addTime = ($info['typegift'] ?? '') === 'day';
    if ($addVolume) {
        $volumeValue = intval($info['value'] ?? 0);
    }
    if ($addTime) {
        $timeValue = intval($info['value'] ?? 0);
    }
}
if (($info['typegift'] ?? '') === 'both') {
    $addVolume = true;
    $addTime = true;
    if ($volumeValue <= 0) {
        $volumeValue = intval($info['value'] ?? 0);
    }
    if ($timeValue <= 0) {
        $timeValue = intval($info['value'] ?? 0);
    }
}

$isBulk = !empty($info['bulk_service_charge']);
$maxPerRun = 5;
$pauseBetweenServices = $isBulk ? 1 : 0;
$opPauseSeconds = $isBulk ? 1 : 0;

$resultMessage = static function ($result): string {
    $reason = is_array($result) ? ($result['msg'] ?? '') : '';
    if (!is_string($reason) || $reason === '') {
        return 'پاسخ نامعتبر پنل';
    }
    return $reason;
};

$processed = 0;
while ($services && $processed < $maxPerRun) {
    $queuedService = array_shift($services);
    $processed++;
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

    $liveUser = $ManagePanel->DataUser($panelName, $invoice['username'], true);
    $liveFailed = !is_array($liveUser) || ($liveUser['status'] ?? '') === 'Unsuccessful';
    if ($liveFailed) {
        $failPayload = is_array($liveUser) ? $liveUser : ['msg' => 'پاسخ نامعتبر پنل'];
        $failReason = $isTimeoutError($resultMessage($failPayload)) ? 'timeout' : $resultMessage($failPayload);
        gift_append_unfinished($info, (string) $invoice['username'], (string) $panelName, $failReason);
        $info['failed_count'] = intval($info['failed_count'] ?? 0) + 1;
        $reportFailure($panel, $invoice['username'], $failPayload);
        if ($pauseBetweenServices > 0) {
            sleep($pauseBetweenServices);
        }
        continue;
    }

    $hasRemainingVolume = false;
    $dataLimit = $liveUser['data_limit'] ?? 0;
    $usedTraffic = $liveUser['used_traffic'] ?? 0;
    if (is_numeric($dataLimit) && floatval($dataLimit) > 0) {
        $usedVal = is_numeric($usedTraffic) ? floatval($usedTraffic) : 0;
        $hasRemainingVolume = (floatval($dataLimit) - $usedVal) > 0;
    }
    $hasRemainingTime = false;
    $expireAt = $liveUser['expire'] ?? 0;
    if (is_numeric($expireAt) && intval($expireAt) > time()) {
        $hasRemainingTime = true;
    }

    $volumeEligible = $addVolume && $volumeValue > 0 && $hasRemainingVolume;
    $timeEligible = $addTime && $timeValue > 0 && $hasRemainingTime;
    if (!$volumeEligible && !$timeEligible) {
        $info['skipped_count'] = intval($info['skipped_count'] ?? 0) + 1;
        if ($pauseBetweenServices > 0) {
            sleep($pauseBetweenServices);
        }
        continue;
    }

    $timedOut = false;
    $hardFailed = false;
    $applyCharge = static function (string $kind, int $value) use ($ManagePanel, $invoice, $panel, $liveUser, $logResult, $isTimeoutError, $resultMessage, $reportFailure, &$timedOut, &$hardFailed): void {
        if ($kind === 'volume') {
            $result = $ManagePanel->extra_volume($invoice['username'], $panel['code_panel'], $value, $liveUser);
        } else {
            $result = $ManagePanel->extra_time($invoice['username'], $panel['code_panel'], $value, $liveUser);
        }
        if (!is_array($result)) {
            $result = ['status' => false, 'msg' => 'پاسخ نامعتبر پنل'];
        }
        $logResult($invoice, $liveUser, $kind, $value, $result);
        if (($result['status'] ?? false) === false) {
            $reportFailure($panel, $invoice['username'], $result);
            if ($isTimeoutError($resultMessage($result))) {
                $timedOut = true;
                return;
            }
            $hardFailed = true;
        }
    };

    if ($volumeEligible) {
        if ($opPauseSeconds > 0) {
            sleep($opPauseSeconds);
        }
        $applyCharge('volume', $volumeValue);
        if ($timedOut || $hardFailed) {
            $label = $timedOut ? 'timeout (حجم)' : 'خطا (حجم)';
            gift_append_unfinished($info, (string) $invoice['username'], (string) $panelName, $label);
            $info['failed_count'] = intval($info['failed_count'] ?? 0) + 1;
            if ($pauseBetweenServices > 0) {
                sleep($pauseBetweenServices);
            }
            continue;
        }
    }
    if ($timeEligible) {
        if ($opPauseSeconds > 0) {
            sleep($opPauseSeconds);
        }
        $applyCharge('time', $timeValue);
        if ($timedOut || $hardFailed) {
            $label = $timedOut ? 'timeout (زمان)' : 'خطا (زمان)';
            gift_append_unfinished($info, (string) $invoice['username'], (string) $panelName, $label);
            $info['failed_count'] = intval($info['failed_count'] ?? 0) + 1;
            if ($pauseBetweenServices > 0) {
                sleep($pauseBetweenServices);
            }
            continue;
        }
    }

    $info['success_count'] = intval($info['success_count'] ?? 0) + 1;
    if (isset($info['text']) && trim((string) $info['text']) !== '') {
        sendmessage($invoice['id_user'], $info['text'], null, 'HTML');
    }
    if ($pauseBetweenServices > 0) {
        sleep($pauseBetweenServices);
    }
}

if (count($services) === 0) {
    $finishGift($info);
    return;
}

file_put_contents('gift', json_encode($info, JSON_UNESCAPED_UNICODE), LOCK_EX);
file_put_contents('username.json', json_encode(array_values($services), JSON_UNESCAPED_UNICODE), LOCK_EX);

if (!empty($info['id_admin']) && !empty($info['id_message'])) {
    $remaining = count($services);
    $success = intval($info['success_count'] ?? 0);
    $failed = intval($info['failed_count'] ?? 0);
    $skipped = intval($info['skipped_count'] ?? 0);
    $unfinished = count(gift_unfinished_list($info));
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
        . "باقی‌مانده: {$remaining}\n✅ موفق: {$success}\n❌ ناموفق: {$failed}\n⏭ ردشده: {$skipped}\n⏱ Timeout: {$unfinished}",
        $cancelKeyboard
    );
}
