<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/users_lib.php';
require_once __DIR__ . '/inc/payments_lib.php';
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
    $rawBack = (string) ($_POST['back'] ?? '');
    $base = explode('?', $rawBack)[0];
    if (in_array($base, ['user_services.php', 'user.php'], true)) {
        $back = $base . '?id=' . $userId;
    }
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
            (string) ($_POST['product'] ?? ''),
            [
                'gb' => (int) ($_POST['custom_gb'] ?? 0),
                'months' => (int) ($_POST['custom_months'] ?? 0),
            ]
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

    case 'refund_service':
        $idInvoice = trim((string) ($_POST['id_invoice'] ?? ''));
        $invoice = db_fetch($pdo, 'SELECT id_invoice FROM invoice WHERE id_invoice = ? AND id_user = ?', [$idInvoice, (string) $userId]);
        if (!$invoice) {
            flash('error', 'سرویس یافت نشد یا متعلق به این کاربر نیست.');
            break;
        }
        $result = panel_invoice_apply_refund(
            $pdo,
            $idInvoice,
            !empty($_POST['disable_product']),
            !empty($_POST['credit_wallet'])
        );
        flash($result['ok'] ? 'success' : 'error', $result['msg']);
        error_log(
            "Admin {$_SESSION['admin_user']} refunded service $idInvoice for user $userId"
            . ' disable=' . (!empty($_POST['disable_product']) ? '1' : '0')
            . ' wallet=' . (!empty($_POST['credit_wallet']) ? '1' : '0')
        );
        break;

    default:
        flash('error', 'عملیات نامعتبر است.');
}

header('Location: ' . $back);
exit;
