<?php
/**
 * Structured logging for polling daemon and async workers.
 */

function mirza_polling_debug_enabled(): bool
{
    global $telegram_polling_debug;
    return !empty($telegram_polling_debug);
}

function mirza_polling_log_path(): string
{
    global $telegram_polling_log_file;
    if (!empty($telegram_polling_log_file) && is_string($telegram_polling_log_file)) {
        return $telegram_polling_log_file;
    }
    return __DIR__ . '/logs/polling.log';
}

function mirza_polling_worker_log_path(): string
{
    global $telegram_polling_worker_log_file;
    if (!empty($telegram_polling_worker_log_file) && is_string($telegram_polling_worker_log_file)) {
        return $telegram_polling_worker_log_file;
    }
    return __DIR__ . '/logs/polling.worker.log';
}

function mirza_polling_ensure_log_dir(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

/**
 * @param array<string, mixed> $context
 */
function mirza_polling_log(string $event, array $context = [], ?string $logFile = null): void
{
    if (!mirza_polling_debug_enabled()) {
        return;
    }

    $logFile = $logFile ?? mirza_polling_log_path();
    mirza_polling_ensure_log_dir($logFile);

    $line = json_encode([
        'ts' => date('c'),
        'event' => $event,
        'pid' => getmypid(),
        'ctx' => $context,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($line === false) {
        $line = '{"ts":"' . date('c') . '","event":"log_encode_failed"}';
    }

    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Human-readable one-line summary for console + logs.
 */
function mirza_update_summary(array $update): string
{
    $updateId = $update['update_id'] ?? '?';
    $fromId = $update['message']['from']['id']
        ?? $update['callback_query']['from']['id']
        ?? $update['inline_query']['from']['id']
        ?? 0;

    if (isset($update['callback_query'])) {
        $data = (string) ($update['callback_query']['data'] ?? '');
        $data = strlen($data) > 80 ? substr($data, 0, 77) . '...' : $data;
        return "update={$updateId} user={$fromId} type=callback data={$data}";
    }

    if (isset($update['message']['text'])) {
        $text = (string) $update['message']['text'];
        $text = strlen($text) > 80 ? substr($text, 0, 77) . '...' : $text;
        return "update={$updateId} user={$fromId} type=message text={$text}";
    }

    if (isset($update['inline_query'])) {
        $q = (string) ($update['inline_query']['query'] ?? '');
        return "update={$updateId} user={$fromId} type=inline query={$q}";
    }

    if (isset($update['pre_checkout_query'])) {
        return "update={$updateId} user={$fromId} type=pre_checkout";
    }

    $keys = implode(',', array_keys($update));
    return "update={$updateId} user={$fromId} type=other keys={$keys}";
}

function mirza_polling_log_line(string $message): void
{
    echo $message . (str_ends_with($message, "\n") ? '' : "\n");
    if (!mirza_polling_debug_enabled()) {
        return;
    }
    mirza_polling_log('poller_stdout', ['message' => rtrim($message)]);
}

function mirza_panel_url_for_log(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    $host = $parts['host'] ?? '';
    $path = $parts['path'] ?? '';
    return $host . $path;
}
