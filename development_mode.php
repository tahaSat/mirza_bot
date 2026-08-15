<?php

/**
 * When $development_mode is true in config.php the bot answers users with a
 * maintenance message and does not run purchases, crons, or APIs.
 * The /panel admin UI stays available. Telegram users with the administrator
 * role (and $adminnumber) can still use the bot.
 */

function mirza_development_mode_support_id(): string
{
    global $development_mode_support_id, $adminnumber;

    $id = trim((string) ($development_mode_support_id ?? ''));
    if ($id === '') {
        $id = trim((string) ($adminnumber ?? ''));
    }
    return $id;
}

function mirza_development_mode_message(bool $html = false): string
{
    $message = 'ربات در حالت توسعه و بروز رسانی میباشد. لطفا پس از مدتی مجددا تلاش نمایید.';
    $id = mirza_development_mode_support_id();
    if ($id === '') {
        return $message;
    }

    $display = $id;
    if ($html) {
        $safe = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (preg_match('/^\d+$/', $id)) {
            $display = '<a href="tg://user?id=' . $id . '">' . $safe . '</a>';
        } else {
            $display = $safe;
        }
    }

    return $message . "\nبرای خرید محصول و پشتیبانی به اکانت " . $display . " پیام دهید";
}

function mirza_is_development_mode(): bool
{
    global $development_mode;
    return !empty($development_mode);
}

function mirza_development_mode_script_path(): string
{
    return (string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? '');
}

function mirza_development_mode_user_id(): int
{
    global $from_id, $update;

    if (isset($from_id) && intval($from_id) !== 0) {
        return intval($from_id);
    }

    if (!is_array($update ?? null)) {
        return 0;
    }

    return intval(
        $update['message']['from']['id']
        ?? $update['callback_query']['from']['id']
        ?? $update['inline_query']['from']['id']
        ?? $update['pre_checkout_query']['from']['id']
        ?? 0
    );
}

function mirza_development_mode_is_administrator(): bool
{
    global $adminnumber;

    $userId = mirza_development_mode_user_id();
    if ($userId === 0) {
        return false;
    }

    if (isset($adminnumber) && $adminnumber !== '' && (string) $userId === (string) $adminnumber) {
        return true;
    }

    if (!function_exists('select')) {
        return false;
    }

    $admin = select('admin', 'rule', 'id_admin', (string) $userId, 'select');
    return is_array($admin) && ($admin['rule'] ?? '') === 'administrator';
}

function mirza_development_mode_should_skip_boot(): bool
{
    $script = mirza_development_mode_script_path();
    $base = basename($script);

    if (in_array($base, ['table.php', 'polling.php'], true)) {
        return true;
    }
    if (strpos($script, DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR) !== false) {
        return true;
    }
    if (strpos($script, DIRECTORY_SEPARATOR . 'panel' . DIRECTORY_SEPARATOR) !== false) {
        return true;
    }
    // Agent bots parse the update after function.php is loaded.
    if (strpos($script, DIRECTORY_SEPARATOR . 'vpnbot' . DIRECTORY_SEPARATOR) !== false) {
        return true;
    }

    return false;
}

function mirza_development_mode_reply_telegram(): void
{
    global $from_id, $callback_query_id, $inline_query_id, $update;

    $message = mirza_development_mode_message();

    if (function_exists('checktelegramip') && !checktelegramip()) {
        die('Unauthorized access');
    }

    if (isset($update['chat_member']) && !isset($update['message']) && !isset($update['callback_query']) && !isset($update['pre_checkout_query'])) {
        exit;
    }

    if (!empty($callback_query_id) && function_exists('telegram')) {
        telegram('answerCallbackQuery', [
            'callback_query_id' => $callback_query_id,
            'text' => $message,
            'show_alert' => true,
        ]);
    }

    if (isset($update['pre_checkout_query']['id']) && function_exists('telegram')) {
        telegram('answerPreCheckoutQuery', [
            'pre_checkout_query_id' => $update['pre_checkout_query']['id'],
            'ok' => false,
            'error_message' => $message,
        ]);
    }

    if (!empty($inline_query_id) && function_exists('telegram')) {
        telegram('answerInlineQuery', [
            'inline_query_id' => $inline_query_id,
            'results' => json_encode([]),
            'cache_time' => 1,
        ]);
    }

    $chatId = intval($from_id ?? 0);
    $isCallbackOnly = !empty($callback_query_id) && !isset($update['message']);
    if ($chatId !== 0 && !$isCallbackOnly && function_exists('sendmessage')) {
        sendmessage($chatId, mirza_development_mode_message(true), null, 'HTML');
    }

    exit;
}

function mirza_development_mode_reply_http(): void
{
    $message = mirza_development_mode_message();
    $script = mirza_development_mode_script_path();

    http_response_code(503);

    if (strpos($script, DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR) !== false) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'msg' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Maintenance</title></head><body style="font-family:Tahoma,sans-serif;text-align:center;padding:3rem;line-height:1.8;">'
        . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</body></html>';
    exit;
}

function mirza_halt_if_development_mode(): void
{
    if (!mirza_is_development_mode() || defined('MIRZA_DEV_MODE_HALTED')) {
        return;
    }
    if (mirza_development_mode_is_administrator()) {
        return;
    }
    define('MIRZA_DEV_MODE_HALTED', true);
    mirza_development_mode_reply_telegram();
}

function mirza_development_mode_boot(): void
{
    if (!mirza_is_development_mode() || defined('MIRZA_DEV_MODE_HALTED')) {
        return;
    }
    if (mirza_development_mode_should_skip_boot()) {
        return;
    }
    if (mirza_development_mode_is_administrator()) {
        return;
    }

    define('MIRZA_DEV_MODE_HALTED', true);

    $script = mirza_development_mode_script_path();
    $base = basename($script);
    $root = realpath(__DIR__) ?: __DIR__;
    $scriptDir = realpath(dirname($script)) ?: dirname($script);

    if (strpos($script, DIRECTORY_SEPARATOR . 'cronbot' . DIRECTORY_SEPARATOR) !== false) {
        http_response_code(503);
        echo 'development_mode';
        exit;
    }

    $isRootIndex = ($base === 'index.php' && $scriptDir === $root);
    if ($isRootIndex || $base === 'cli_update.php') {
        mirza_development_mode_reply_telegram();
    }

    mirza_development_mode_reply_http();
}
