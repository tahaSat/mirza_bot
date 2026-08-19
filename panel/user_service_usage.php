<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/users_lib.php';
require_auth();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$token = (string) ($_GET['_csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'درخواست نامعتبر.'], JSON_UNESCAPED_UNICODE);
    exit;
}

session_write_close();

$userId = (int) ($_GET['user_id'] ?? 0);
$idInvoice = trim((string) ($_GET['id_invoice'] ?? ''));
$empty = ['ok' => false, 'id_invoice' => $idInvoice, 'usage_volume' => '—', 'usage_time' => '—'];

if ($userId <= 0 || $idInvoice === '') {
    echo json_encode($empty, JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = panel_ensure_pdo();
$invoice = db_fetch(
    $pdo,
    'SELECT username, Service_location FROM invoice WHERE id_invoice = ? AND id_user = ?',
    [$idInvoice, (string) $userId]
);
if (!$invoice) {
    echo json_encode($empty, JSON_UNESCAPED_UNICODE);
    exit;
}

@set_time_limit(8);
$usage = panel_fetch_service_usage_live(
    (string) ($invoice['Service_location'] ?? ''),
    (string) ($invoice['username'] ?? '')
);

echo json_encode([
    'ok' => true,
    'id_invoice' => $idInvoice,
    'usage_volume' => $usage['usage_volume'],
    'usage_time' => $usage['usage_time'],
], JSON_UNESCAPED_UNICODE);
