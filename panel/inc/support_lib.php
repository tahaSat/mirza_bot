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
 * @return array{ok: bool, msg: string}
 */
function panel_support_send_reply(array $ticket, string $reply): array
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
    $message = "📩 یک پیام از سمت مدیریت برای شما ارسال گردید.\n\nمتن پیام:\n" .
        htmlspecialchars($reply, ENT_QUOTES, 'UTF-8');

    $response = sendmessage($ticket['iduser'], $message, $keyboard, 'HTML');
    if (empty($response['ok'])) {
        return ['ok' => false, 'msg' => 'ارسال پیام به کاربر ناموفق بود: ' . ($response['description'] ?? 'خطای نامشخص')];
    }

    return ['ok' => true, 'msg' => 'پاسخ برای کاربر ارسال شد.'];
}
