<?php
require_once __DIR__ . '/inc/config.php';
require_auth();
panel_require_n2();

$agentId = panel_n2_agent_id();
$redirect = 'n2_products.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $result = agent_own_add_product($agentId, [
            'name_product' => (string) ($_POST['name_product'] ?? ''),
            'price_product' => (int) ($_POST['price_product'] ?? 0),
            'Volume_constraint' => (int) ($_POST['volume_product'] ?? 0),
            'Service_time' => (int) ($_POST['time_product'] ?? 0),
            'category' => (string) ($_POST['category'] ?? ''),
            'Location' => (string) ($_POST['namepanel'] ?? ''),
            'note' => (string) ($_POST['note'] ?? ''),
        ]);
        flash($result['ok'] ? 'success' : 'error', $result['msg']);
    } elseif ($action === 'set_panel') {
        $result = agent_own_update_product($agentId, (int) ($_POST['product_id'] ?? 0), [
            'Location' => (string) ($_POST['namepanel'] ?? ''),
        ]);
        flash($result['ok'] ? 'success' : 'error', $result['msg']);
    }
    header('Location: ' . $redirect);
    exit;
}

if (isset($_GET['delete'])) {
    csrf_check_get();
    $result = agent_own_delete_product($agentId, (int) $_GET['delete']);
    flash($result['ok'] ? 'success' : 'error', $result['msg']);
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect);
exit;
