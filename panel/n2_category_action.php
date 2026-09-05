<?php
require_once __DIR__ . '/inc/config.php';
require_auth();
panel_require_n2();

$agentId = panel_n2_agent_id();
$redirect = 'n2_categories.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $result = agent_own_add_category($agentId, (string) ($_POST['remark'] ?? ''), (string) ($_POST['description'] ?? ''));
        flash($result['ok'] ? 'success' : 'error', $result['msg']);
    }
    header('Location: ' . $redirect);
    exit;
}

if (isset($_GET['delete'])) {
    csrf_check_get();
    $result = agent_own_delete_category($agentId, (int) $_GET['delete']);
    flash($result['ok'] ? 'success' : 'error', $result['msg']);
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect);
exit;
