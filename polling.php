<?php
/**
 * Mirza Pro VPN - Long-Polling Daemon (for servers where Telegram webhook IPs are blocked).
 * Run under Supervisor; forwards updates to local Apache index.php.
 */

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/polling_log.php';
require_once __DIR__ . '/botapi.php';

$botToken = $APIKEY ?? '';
$localBotUrl = $telegram_local_bot_url ?? 'http://127.0.0.1/index.php';
$pollingAsync = !isset($telegram_polling_async) || $telegram_polling_async;
$getUpdatesTimeout = 25;

if ($botToken === '') {
    fwrite(STDERR, "Missing APIKEY in config.php\n");
    exit(1);
}

mirza_polling_log_line('🚀 Polling Daemon Started...');
mirza_polling_log_line('Monitoring updates via Proxy ' . ($telegram_proxy ?? 'none'));
mirza_polling_log_line($pollingAsync ? 'Dispatch: async (cli_update.php workers)' : "Dispatch: sync (blocking HTTP to {$localBotUrl})");
mirza_polling_log('poller_start', [
    'async' => $pollingAsync,
    'proxy' => $telegram_proxy ?? null,
    'proxy_type' => $telegram_proxy_type ?? null,
    'debug' => mirza_polling_debug_enabled(),
]);

$webhookRemoved = telegram_remove_webhook($botToken);
if ($webhookRemoved['ok'] ?? false) {
    mirza_polling_log_line('✅ Webhook removed (required for getUpdates polling).');
} else {
    mirza_polling_log_line('⚠️ deleteWebhook: ' . ($webhookRemoved['description'] ?? 'unknown'));
    mirza_polling_log('delete_webhook_failed', ['description' => $webhookRemoved['description'] ?? 'unknown']);
}

$last_offset = 0;

while (true) {
    $telegram_url = 'https://api.telegram.org/bot' . $botToken . '/getUpdates';
    $post_fields = [
        'offset' => $last_offset,
        'timeout' => $getUpdatesTimeout,
        'allowed_updates' => json_encode(['message', 'callback_query', 'channel_post', 'pre_checkout_query', 'inline_query']),
    ];

    $ch = curl_init_telegram($telegram_url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_TIMEOUT, $getUpdatesTimeout + 10);

    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        mirza_polling_log_line('⚠️ Proxy/Network Error: ' . $curl_error . ' (Retrying in 2s...)');
        mirza_polling_log('getUpdates_network_error', ['error' => $curl_error]);
        sleep(2);
        continue;
    }

    if ($http_code === 409) {
        mirza_polling_log_line('⚠️ HTTP 409: another getUpdates or webhook is active. Retrying deleteWebhook...');
        mirza_polling_log('getUpdates_conflict_409', []);
        telegram_remove_webhook($botToken);
        sleep(5);
        continue;
    }

    if ($http_code !== 200) {
        mirza_polling_log_line('⚠️ Telegram returned HTTP status ' . $http_code . ' (Retrying in 5s...)');
        mirza_polling_log('getUpdates_http_error', ['http_code' => $http_code]);
        sleep(5);
        continue;
    }

    $data = json_decode($response, true);
    if (!isset($data['ok']) || !$data['ok']) {
        mirza_polling_log_line('⚠️ API Error: ' . ($data['description'] ?? 'unknown'));
        mirza_polling_log('getUpdates_api_error', ['description' => $data['description'] ?? 'unknown']);
        sleep(2);
        continue;
    }

    if (empty($data['result'])) {
        continue;
    }

    foreach ($data['result'] as $update) {
        $update_id = $update['update_id'];
        $summary = mirza_update_summary($update);
        mirza_polling_log_line('📥 ' . $summary);

        if ($pollingAsync) {
            if (!dispatch_update_async($update, $update_id)) {
                mirza_polling_log_line("⚠️ Async dispatch failed for update {$update_id}");
                mirza_polling_log('dispatch_failed', ['update_id' => $update_id, 'summary' => $summary]);
            } else {
                mirza_polling_log_line('➡️ Dispatched async worker — ' . $summary);
                mirza_polling_log('dispatch_ok', ['update_id' => $update_id, 'summary' => $summary]);
            }
        } else {
            forward_update_sync($update, $localBotUrl, $summary);
        }

        $last_offset = $update_id + 1;
    }
}

function forward_update_sync(array $update, string $localBotUrl, string $summary = ''): void
{
    $startedAt = microtime(true);
    $local_ch = curl_init($localBotUrl);
    curl_disable_proxy($local_ch);
    curl_setopt($local_ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($local_ch, CURLOPT_POST, true);
    curl_setopt($local_ch, CURLOPT_POSTFIELDS, json_encode($update));
    curl_setopt($local_ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($local_ch, CURLOPT_TIMEOUT, 120);

    $local_response = curl_exec($local_ch);
    $local_http_code = (int) curl_getinfo($local_ch, CURLINFO_HTTP_CODE);
    $local_error = curl_error($local_ch);
    curl_close($local_ch);
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

    if ($local_http_code !== 200 || $local_response === false) {
        mirza_polling_log_line('⚠️ Local forward failed. HTTP ' . $local_http_code . ($local_error !== '' ? ' — ' . $local_error : ''));
        mirza_polling_log('sync_forward_failed', [
            'summary' => $summary,
            'http_code' => $local_http_code,
            'error' => $local_error,
            'duration_ms' => $durationMs,
            'body_snippet' => is_string($local_response) ? substr($local_response, 0, 200) : null,
        ]);
    } else {
        mirza_polling_log_line('➡️ Forwarded locally. HTTP ' . $local_http_code . " ({$durationMs}ms) — {$summary}");
        mirza_polling_log('sync_forward_ok', [
            'summary' => $summary,
            'http_code' => $local_http_code,
            'duration_ms' => $durationMs,
        ]);
    }
}

function dispatch_update_async(array $update, int $updateId): bool
{
    $tmp = sys_get_temp_dir() . '/mirza_tg_' . $updateId . '_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.json';
    if (file_put_contents($tmp, json_encode($update)) === false) {
        return false;
    }

    $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $script = __DIR__ . '/cli_update.php';
    $workerLog = mirza_polling_worker_log_path();
    mirza_polling_ensure_log_dir($workerLog);
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp)
        . ' >> ' . escapeshellarg($workerLog) . ' 2>&1 &';

    if (!function_exists('exec')) {
        @unlink($tmp);
        return false;
    }

    exec($cmd);
    return true;
}

function curl_init_telegram($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_POST, true);
    apply_telegram_proxy($ch, $url);
    return $ch;
}

function telegram_remove_webhook($token)
{
    $url = 'https://api.telegram.org/bot' . $token . '/deleteWebhook';
    $ch = curl_init_telegram($url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['drop_pending_updates' => 'false']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $raw = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'Invalid deleteWebhook response'];
}
