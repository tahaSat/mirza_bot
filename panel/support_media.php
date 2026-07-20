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

require_once dirname(__DIR__) . '/botapi.php';
$file = getFileddire($media['telegram_file_id']);
$filePath = $file['result']['file_path'] ?? '';
if (empty($file['ok']) || $filePath === '') {
    http_response_code(502);
    exit('دریافت فایل از تلگرام ناموفق بود.');
}

global $APIKEY;
$url = 'https://api.telegram.org/file/bot' . $APIKEY . '/' . ltrim($filePath, '/');
$mime = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', (string) $media['mime_type'])
    ? $media['mime_type']
    : 'application/octet-stream';
$filename = trim((string) ($media['file_name'] ?: 'attachment'));
$filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'attachment';
$disposition = in_array($media['media_type'], ['photo', 'video', 'audio', 'voice'], true) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

$stream = fopen($url, 'rb');
if ($stream === false) {
    http_response_code(502);
    exit('دریافت فایل از تلگرام ناموفق بود.');
}
fpassthru($stream);
fclose($stream);
