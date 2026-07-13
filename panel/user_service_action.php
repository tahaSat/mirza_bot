<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/users_lib.php';
require_auth();
$pdo = panel_ensure_pdo();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

csrf_check_post();

$action = $_POST['action'] ?? '';
$userId = (int) ($_POST['user_id'] ?? 0);
$back = 'users.php';

if ($userId) {
    $back = 'user_services.php?id=' . $userId;
}

if (!$userId) {
    flash('error', 'شناسه کاربر نامعتبر است.');
    header('Location: users.php');
    exit;
}

$user = db_fetch($pdo, 'SELECT id FROM user WHERE id = ?', [$userId]);
if (!$user) {
    flash('error', 'کاربر یافت نشد.');
    header('Location: users.php');
    exit;
}

switch ($action) {
    case 'add_service':
        $result = panel_add_user_service(
            $pdo,
            $userId,
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['panel'] ?? ''),
            (string) ($_POST['product'] ?? '')
        );
        flash($result['ok'] ? 'success' : 'error', $result['msg']);
        break;

    case 'remove_service':
        $idInvoice = trim((string) ($_POST['id_invoice'] ?? ''));
        $refund = !empty($_POST['refund']);
        $result = panel_remove_user_service($pdo, $idInvoice, $userId, $refund);
        flash($result['ok'] ? 'success' : 'error', $result['msg']);
        error_log("Admin {$_SESSION['admin_user']} removed service $idInvoice for user $userId refund=" . ($refund ? '1' : '0'));
        break;

    default:
        flash('error', 'عملیات نامعتبر است.');
}

header('Location: ' . $back);
exit;
