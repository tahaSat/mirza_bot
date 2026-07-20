<?php
require_once __DIR__ . '/inc/config.php';
require_auth();
$pdo = panel_ensure_pdo();

if (!support_ensure_media_table($pdo)) {
    http_response_code(503);
    exit('سامانه فایل پشتیبانی در دسترس نیست.');
}

$mediaId = (int) ($_GET['id'] ?? 0);
if ($mediaId < 1) {
    http_response_code(404);
    exit('فایل یافت نشد.');
}

$media = db_fetch(
    $pdo,
    'SELECT sm.* FROM support_media sm INNER JOIN support_message s ON s.id = sm.message_id WHERE sm.id = ?',
    [$mediaId]
);
if (!$media) {
    http_response_code(404);
    exit('فایل یافت نشد.');
}

global $APIKEY;

/**
 * Direct Telegram HTTP call without proxy.
 * @return array{ok:bool,http?:int,error?:string,json?:array,body?:string}
 */
function panel_support_telegram_direct(string $url, array $postFields = [], bool $asJson = true): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl init failed'];
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_PROXY, '');
    curl_setopt($ch, CURLOPT_NOPROXY, '*');
    if ($postFields) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    }

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'http' => $httpCode, 'error' => $curlError];
    }

    if (!$asJson) {
        return ['ok' => $httpCode > 0 && $httpCode < 400 && $body !== '', 'http' => $httpCode, 'body' => $body, 'error' => $curlError];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'http' => $httpCode, 'error' => 'invalid json'];
    }

    return ['ok' => !empty($json['ok']), 'http' => $httpCode, 'json' => $json, 'error' => $json['description'] ?? $curlError];
}

$fileLookup = panel_support_telegram_direct(
    'https://api.telegram.org/bot' . $APIKEY . '/getFile',
    ['file_id' => $media['telegram_file_id']]
);
$filePath = $fileLookup['json']['result']['file_path'] ?? '';
if (empty($fileLookup['ok']) || $filePath === '') {
    error_log('support_media getFile failed id=' . $mediaId . ' err=' . ($fileLookup['error'] ?? ''));
    http_response_code(502);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('دریافت فایل از تلگرام ناموفق بود.');
}

$url = 'https://api.telegram.org/file/bot' . $APIKEY . '/' . ltrim($filePath, '/');
$mime = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', (string) $media['mime_type'])
    ? $media['mime_type']
    : 'application/octet-stream';
$filename = trim((string) ($media['file_name'] ?: 'attachment'));
$filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'attachment';
$disposition = in_array($media['media_type'], ['photo', 'video', 'audio', 'voice'], true) ? 'inline' : 'attachment';

$download = panel_support_telegram_direct($url, [], false);
if (empty($download['ok'])) {
    error_log('support_media download failed id=' . $mediaId . ' http=' . ($download['http'] ?? 0) . ' err=' . ($download['error'] ?? ''));
    http_response_code(502);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('دریافت فایل از تلگرام ناموفق بود.');
}

$body = $download['body'];
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($body));
echo $body;
