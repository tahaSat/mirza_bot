<?php
/**
 * Diagnose Telegram webhook vs polling on restricted networks (e.g. Iran + xray).
 *
 * Outbound (setWebhook, getUpdates, sendMessage): can use $telegram_proxy (xray SOCKS).
 * Inbound (Telegram POST to your URL): must reach your server from the internet; proxy does not help.
 *
 * Usage: php scripts/telegram_webhook_diag.php [https://your-public-url/index.php]
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI: php scripts/telegram_webhook_diag.php [webhook_url]\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/botapi.php';

$webhookUrl = $argv[1] ?? ('https://' . ($domainhosts ?? 'localhost') . '/index.php');

echo "=== Telegram connectivity (Iran / proxy) ===\n\n";
echo "Proxy: " . ($telegram_proxy ?? 'none') . " (" . ($telegram_proxy_type ?? 'http') . ")\n";
echo "Webhook URL to test: {$webhookUrl}\n\n";

echo "--- 1) Outbound: getMe (via proxy) ---\n";
$me = telegram('getMe');
echo json_encode($me, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "--- 2) Outbound: getWebhookInfo (via proxy) ---\n";
$info = telegram('getWebhookInfo');
echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

if (!preg_match('#^https://#i', $webhookUrl)) {
    echo "--- 3) HTTPS required ---\n";
    echo "FAIL: Webhook URL must be HTTPS. Telegram does not deliver webhooks to http:// URLs.\n\n";
} else {
    echo "--- 3) Inbound: can THIS server reach the webhook URL? (no proxy) ---\n";
    $ch = curl_init($webhookUrl);
    curl_disable_proxy($ch);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') {
        echo "FAIL curl: {$err}\n";
        echo "If port 443 is closed, install TLS (certbot) before using webhook.\n";
    } else {
        echo "HTTP {$code} (Telegram needs a similar successful HTTPS POST from their datacenters)\n";
    }
    echo "\n";
}

$httpUrl = preg_replace('#^https://#i', 'http://', $webhookUrl);
if ($httpUrl !== $webhookUrl) {
    echo "--- 3b) HTTP version (Telegram will NOT use this for webhook) ---\n";
    $ch = curl_init($httpUrl);
    curl_disable_proxy($ch);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    echo ($err !== '' ? "curl error: {$err}\n" : "HTTP {$code}\n\n");
}

echo "--- 4) Outbound: setWebhook test (via proxy) — does NOT fix inbound ---\n";
$set = telegram('setWebhook', [
    'url' => $webhookUrl,
    'allowed_updates' => json_encode(['message', 'callback_query', 'channel_post', 'pre_checkout_query', 'inline_query']),
]);
echo json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if (!($set['ok'] ?? false)) {
    $desc = (string) ($set['description'] ?? '');
    echo "\nInterpretation:\n";
    if (stripos($desc, 'Failed to resolve') !== false || stripos($desc, 'connection') !== false || stripos($desc, 'timeout') !== false) {
        echo "- Telegram's servers could not reach your webhook URL from the internet.\n";
        echo "- xray/SOCKS only helps PHP → api.telegram.org, not Telegram → your Iran IP.\n";
        echo "- Use Cloudflare Tunnel / a foreign VPS relay URL, or keep polling with async workers.\n";
    }
}

if ($set['ok'] ?? false) {
    echo "\nReverting to polling: deleteWebhook...\n";
    telegram('deleteWebhook');
}

echo "\nDone.\n";
