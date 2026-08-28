<?php
require_once __DIR__ . '/inc/config.php';
require_once dirname(__DIR__) . '/withdraw_lib.php';
require_auth();

$pdo = panel_ensure_pdo();
withdraw_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
$row = withdraw_get($id, $pdo);
if (!$row) {
    http_response_code(404);
    exit('رسید یافت نشد.');
}

$path = trim((string) ($row['receipt_path'] ?? ''));
$abs = $path !== '' ? withdraw_absolute_receipt_path($path) : '';
$root = realpath(__DIR__ . '/../storage/withdraw_receipts');
$fileReal = $abs !== '' ? realpath($abs) : false;

if ($fileReal && $root && str_starts_with($fileReal, $root) && is_file($fileReal)) {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($fileReal) ?: 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($fileReal));
    header('Content-Disposition: inline; filename="' . basename($fileReal) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($fileReal);
    exit;
}

$fileId = trim((string) ($row['receipt_file_id'] ?? ''));
if ($fileId === '') {
    http_response_code(404);
    exit('فایل رسید در دسترس نیست.');
}

withdraw_telegram_ready();
$downloaded = withdraw_download_telegram_file($fileId);
if (!$downloaded) {
    http_response_code(404);
    exit('دانلود رسید از تلگرام ناموفق بود.');
}

$saved = withdraw_save_bytes($id, $downloaded['bytes'], $downloaded['ext']);
if ($saved) {
    db_query($pdo, 'UPDATE wallet_withdraw SET receipt_path = ? WHERE id = ?', [$saved, $id]);
    $abs = withdraw_absolute_receipt_path($saved);
    if (is_file($abs)) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: 'image/jpeg';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($abs));
        header('Content-Disposition: inline; filename="' . basename($abs) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($abs);
        exit;
    }
}

$ext = $downloaded['ext'] ?: 'jpg';
$mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
header('Content-Type: ' . ($mimeMap[$ext] ?? 'image/jpeg'));
header('Content-Disposition: inline; filename="receipt-' . $id . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
echo $downloaded['bytes'];
