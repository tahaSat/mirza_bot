<?php

function panel_support_unanswered_statuses(): array
{
    return ['Unseen', 'Customerresponse', 'Pending'];
}

function panel_support_status_map(): array
{
    return [
        'Unseen' => ['tag-warn', 'پاسخ داده نشده'],
        'Customerresponse' => ['tag-warn', 'پاسخ جدید کاربر'],
        'Pending' => ['tag-warn', 'در انتظار'],
        'Answered' => ['tag-ok', 'پاسخ داده شده'],
        'close' => ['tag-plain', 'بسته شده'],
    ];
}

function panel_support_status_info(string $status): array
{
    return panel_support_status_map()[$status] ?? ['tag-plain', $status ?: 'نامشخص'];
}

function panel_support_unanswered_count(PDO $pdo): int
{
    try {
        $statuses = panel_support_unanswered_statuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        return db_count($pdo, "SELECT COUNT(*) FROM support_message WHERE status IN ($placeholders)", $statuses);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array{ok: bool, msg: string, media?: array}
 */
function panel_support_send_reply(array $ticket, string $reply, ?array $upload = null): array
{
    $botapi = dirname(__DIR__, 2) . '/botapi.php';
    if (!is_file($botapi)) {
        return ['ok' => false, 'msg' => 'فایل ارتباط با ربات یافت نشد.'];
    }

    require_once $botapi;
    if (!function_exists('sendmessage')) {
        return ['ok' => false, 'msg' => 'امکان ارسال پیام از طریق ربات فراهم نیست.'];
    }

    $tracking = (string) ($ticket['Tracking'] ?? '');
    $keyboard = json_encode([
        'inline_keyboard' => [[
            ['text' => '✉️ پاسخ به پیام', 'callback_data' => 'Responsesusera_' . $tracking],
        ]],
    ], JSON_UNESCAPED_UNICODE);
    $safeReply = htmlspecialchars($reply, ENT_QUOTES, 'UTF-8');
    $message = "📩 یک پیام از سمت مدیریت برای شما ارسال گردید.\n\nمتن پیام:\n" . $safeReply;
    $media = null;

    if ($upload) {
        $methodMap = [
            'photo' => ['sendPhoto', 'photo'],
            'video' => ['sendVideo', 'video'],
            'audio' => ['sendAudio', 'audio'],
            'document' => ['sendDocument', 'document'],
        ];
        [$method, $field] = $methodMap[$upload['type']];
        $caption = $reply !== '' ? $message : '📎 یک فایل از سمت مدیریت برای شما ارسال گردید.';
        $response = telegram($method, [
            'chat_id' => $ticket['iduser'],
            $field => new CURLFile($upload['path'], $upload['mime'], $upload['name']),
            'caption' => $caption,
            'reply_markup' => $keyboard,
            'parse_mode' => 'HTML',
        ]);
        if (!empty($response['ok'])) {
            $payload = $response['result'] ?? [];
            $photos = $payload['photo'] ?? [];
            $file = $upload['type'] === 'photo' ? (end($photos) ?: []) : ($payload[$upload['type']] ?? []);
            $media = [[
                $upload['type'],
                $file['file_id'] ?? '',
                $file['file_unique_id'] ?? null,
                $file['mime_type'] ?? $upload['mime'],
                $file['file_name'] ?? $upload['name'],
                $file['file_size'] ?? $upload['size'],
            ]];
            if ($media[0][1] === '') {
                return ['ok' => false, 'msg' => 'فایل ارسال شد اما شناسه آن از تلگرام دریافت نشد.'];
            }
        }
    } else {
        $response = sendmessage($ticket['iduser'], $message, $keyboard, 'HTML');
    }
    if (empty($response['ok'])) {
        return ['ok' => false, 'msg' => 'ارسال پیام به کاربر ناموفق بود: ' . ($response['description'] ?? 'خطای نامشخص')];
    }

    return ['ok' => true, 'msg' => 'پاسخ برای کاربر ارسال شد.', 'media' => $media];
}

/**
 * @return array{ok: bool, msg?: string, upload?: array}
 */
function panel_support_prepare_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'upload' => null];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['ok' => false, 'msg' => 'بارگذاری فایل ناموفق بود.'];
    }
    if (($file['size'] ?? 0) < 1 || $file['size'] > 20 * 1024 * 1024) {
        return ['ok' => false, 'msg' => 'حجم فایل باید حداکثر ۲۰ مگابایت باشد.'];
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
    $type = str_starts_with($mime, 'image/') ? 'photo'
        : (str_starts_with($mime, 'video/') ? 'video'
        : (str_starts_with($mime, 'audio/') ? 'audio' : 'document'));
    $name = basename((string) ($file['name'] ?? 'attachment'));
    $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?: 'attachment';

    return ['ok' => true, 'upload' => [
        'path' => $file['tmp_name'],
        'name' => mb_substr($name, 0, 200, 'UTF-8'),
        'mime' => $mime,
        'size' => (int) $file['size'],
        'type' => $type,
    ]];
}
