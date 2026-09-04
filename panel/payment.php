<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_once __DIR__ . '/inc/payment_import_lib.php';
require_auth();

$pdo = panel_ensure_pdo();
panel_payment_ensure_schema($pdo);

$tab = $_GET['tab'] ?? 'list';
if (!in_array($tab, ['list', 'income', 'pending', 'costs'], true)) {
    $tab = 'list';
}

function payment_redirect_url(string $tab, array $extra = []): string
{
    if ($tab === 'pending') {
        return 'payment.php?tab=pending';
    }
    $qs = array_filter([
        'tab' => in_array($tab, ['costs', 'income'], true) ? $tab : '',
        'q' => $extra['q'] ?? '',
        'status' => $extra['status'] ?? '',
        'price_min' => $extra['price_min'] ?? '',
        'price_max' => $extra['price_max'] ?? '',
        'from' => $extra['from'] ?? '',
        'to' => $extra['to'] ?? '',
        'method' => $extra['method'] ?? '',
        'category' => $extra['category'] ?? '',
        'kind' => $extra['kind'] ?? '',
        'expense_status' => $extra['expense_status'] ?? '',
        'page' => $extra['page'] ?? '',
    ], static fn($v) => $v !== null && $v !== '');
    return $qs ? ('payment.php?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986)) : 'payment.php';
}

function payment_is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function payment_json_exit(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function payment_known_users_for(PDO $pdo, string $uid): array
{
    $uid = trim($uid);
    if ($uid === '' || $uid === '0') {
        return [];
    }
    try {
        $row = db_fetch($pdo, 'SELECT id FROM user WHERE id = ?', [$uid]);
        return $row ? [(string) $row['id'] => true] : [];
    } catch (Exception $e) {
        return [];
    }
}

function payment_sheet_result(PDO $pdo, array $r): array
{
    if (empty($r['ok'])) {
        return $r;
    }
    $oid = (string) ($r['id_order'] ?? '');
    if ($oid === '') {
        return $r;
    }
    $row = db_fetch($pdo, 'SELECT * FROM Payment_report WHERE id_order = ?', [$oid]);
    if ($row) {
        $r['row'] = panel_payment_serialize_sheet_row(
            $row,
            payment_known_users_for($pdo, (string) ($row['id_user'] ?? ''))
        );
    }
    return $r;
}

function payment_shared_filter_clauses(
    string $search,
    $priceMin,
    $priceMax,
    ?array $fromFilter,
    ?array $toFilter,
    string $method = '',
    string $category = ''
): array {
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = "(`id_user` LIKE ? OR `id_order` LIKE ? OR COALESCE(`note`,'') LIKE ?)";
        $params = ["%$search%", "%$search%", "%$search%"];
    }
    if ($method !== '') {
        $where[] = 'Payment_Method = ?';
        $params[] = $method;
    }
    if ($category !== '') {
        $where[] = 'expense_category = ?';
        $params[] = $category;
    }
    if ($priceMin !== null) {
        $where[] = 'CAST(price AS DECIMAL(20,0)) >= ?';
        $params[] = $priceMin;
    }
    if ($priceMax !== null) {
        $where[] = 'CAST(price AS DECIMAL(20,0)) <= ?';
        $params[] = $priceMax;
    }
    panel_payment_append_time_range($where, $params, $fromFilter, $toFilter);
    return [$where, $params];
}

function payment_income_type_sql(): string
{
    return "payment_Status != 'cost'";
}

function payment_expense_type_sql(): string
{
    return "payment_Status = 'cost'";
}

function payment_append_income_filters(array &$where, array &$params, string $status, string $method): void
{
    if ($status === 'manual') {
        $where[] = "Payment_Method = 'manual invoice'";
    } elseif ($status !== '') {
        $where[] = 'payment_Status = ?';
        $params[] = $status;
    }
    if ($method !== '') {
        $where[] = 'Payment_Method = ?';
        $params[] = $method;
    }
}

function payment_append_expense_filters(array &$where, array &$params, string $category, string $expenseStatus): void
{
    if ($expenseStatus !== '') {
        $where[] = 'payment_Status = ?';
        $params[] = $expenseStatus;
    }
    if ($category !== '') {
        $where[] = 'expense_category = ?';
        $params[] = $category;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = $_POST['action'] ?? '';
    $orderId = trim($_POST['order_id'] ?? '');
    $postTab = (string) ($_POST['tab'] ?? $tab);
    if (!in_array($postTab, ['list', 'income', 'pending', 'costs'], true)) {
        $postTab = $tab;
    }
    $isAjax = payment_is_ajax();
    $redirect = payment_redirect_url($tab === 'pending' ? 'pending' : $postTab, [
        'q' => trim((string) ($_POST['q'] ?? '')),
        'status' => trim((string) ($_POST['status_filter'] ?? '')),
        'price_min' => isset($_POST['price_min']) && $_POST['price_min'] !== '' ? (int) $_POST['price_min'] : '',
        'price_max' => isset($_POST['price_max']) && $_POST['price_max'] !== '' ? (int) $_POST['price_max'] : '',
        'from' => trim((string) ($_POST['from'] ?? '')),
        'to' => trim((string) ($_POST['to'] ?? '')),
        'method' => trim((string) ($_POST['filter_method'] ?? '')),
        'category' => trim((string) ($_POST['filter_category'] ?? '')),
        'kind' => trim((string) ($_POST['filter_kind'] ?? '')),
        'expense_status' => trim((string) ($_POST['filter_expense_status'] ?? '')),
        'page' => !empty($_POST['page']) ? (int) $_POST['page'] : '',
    ]);

    if ($action === 'search_users') {
        $q = ltrim(trim((string) ($_POST['q'] ?? '')), '@');
        if ($q === '') {
            payment_json_exit(['ok' => true, 'users' => []]);
        }
        $like = '%' . $q . '%';
        $prefix = $q . '%';
        $rows = [];
        try {
            $rows = db_fetchAll(
                $pdo,
                "SELECT id, username, namecustom FROM user
                 WHERE CAST(id AS CHAR) LIKE ?
                    OR (username IS NOT NULL AND username != '' AND username != 'none' AND username LIKE ?)
                    OR (namecustom IS NOT NULL AND namecustom != '' AND namecustom != 'none' AND namecustom LIKE ?)
                 ORDER BY
                   CASE
                     WHEN username = ? THEN 0
                     WHEN CAST(id AS CHAR) = ? THEN 1
                     WHEN username LIKE ? THEN 2
                     ELSE 3
                   END,
                   id DESC
                 LIMIT 12",
                [$like, $like, $like, $q, $q, $prefix]
            );
        } catch (Exception $e) {
            payment_json_exit(['ok' => false, 'msg' => 'جستجوی کاربر ناموفق بود.'], 400);
        }
        $users = [];
        foreach ($rows as $u) {
            $uname = trim((string) ($u['username'] ?? ''));
            if ($uname === 'none') {
                $uname = '';
            }
            $name = trim((string) ($u['namecustom'] ?? ''));
            if ($name === 'none') {
                $name = '';
            }
            $users[] = [
                'id' => (string) ($u['id'] ?? ''),
                'username' => $uname,
                'name' => $name,
            ];
        }
        payment_json_exit(['ok' => true, 'users' => $users]);
    }

    if ($action === 'import_parse') {
        $r = panel_payment_import_parse_file($pdo, $_FILES['file'] ?? null, $_POST['usd_rate'] ?? '');
        payment_json_exit($r, !empty($r['ok']) ? 200 : 400);
    }

    if ($action === 'import_commit') {
        $rows = json_decode((string) ($_POST['rows'] ?? ''), true);
        if (!is_array($rows)) {
            payment_json_exit(['ok' => false, 'msg' => 'داده پیش‌نمایش نامعتبر است.'], 400);
        }
        $r = panel_payment_import_commit($pdo, $rows);
        payment_json_exit($r, !empty($r['ok']) ? 200 : 400);
    }

    if ($action === 'save_row') {
        $sheetInput = [
            'amount' => $_POST['amount'] ?? $_POST['price'] ?? 0,
            'time' => $_POST['time'] ?? '',
            'id_order' => $orderId,
            'id_user' => $_POST['id_user'] ?? '',
            'note' => $_POST['note'] ?? '',
            'method' => $_POST['payment_method'] ?? '',
            'expense_category' => $_POST['expense_category'] ?? '',
            'status' => $_POST['new_status'] ?? $_POST['status'] ?? '',
            'remove_product' => !empty($_POST['remove_product']),
            'reject_invoice' => !empty($_POST['reject_invoice']),
        ];
        $existing = $orderId !== ''
            ? db_fetch($pdo, 'SELECT id, payment_Status, Payment_Method, id_invoice FROM Payment_report WHERE id_order = ?', [$orderId])
            : null;
        $asCost = $postTab === 'costs'
            || ($existing && panel_payment_is_cost($existing))
            || ($sheetInput['status'] ?? '') === 'cost';
        if ($existing) {
            $r = panel_payment_update_row($pdo, $orderId, $sheetInput);
        } elseif ($asCost) {
            $r = panel_payment_add_cost($pdo, $sheetInput);
        } else {
            $r = panel_payment_add_manual($pdo, $sheetInput);
        }
        $r = payment_sheet_result($pdo, $r);
        if ($isAjax) {
            payment_json_exit($r, !empty($r['ok']) ? 200 : 400);
        }
        flash(!empty($r['ok']) ? 'success' : 'error', $r['msg'] ?? '');
        $redirect = payment_redirect_url(in_array($postTab, ['list', 'income', 'costs'], true) ? $postTab : 'list');
    } elseif ($action === 'delete_row' && $orderId !== '') {
        $r = panel_payment_delete_row($pdo, $orderId);
        if ($isAjax) {
            payment_json_exit($r, !empty($r['ok']) ? 200 : 400);
        }
        flash($r['ok'] ? 'success' : 'error', $r['msg']);
    } elseif ($action === 'confirm' && $orderId !== '') {
        $r = panel_payment_confirm($pdo, $orderId);
        flash($r['ok'] ? 'success' : 'error', $r['msg']);
    } elseif ($action === 'reject' && $orderId !== '') {
        $r = panel_payment_reject($pdo, $orderId, $_POST['reason'] ?? '');
        flash($r['ok'] ? 'success' : 'error', $r['msg']);
    } elseif ($action === 'dismiss' && $orderId !== '') {
        $r = panel_payment_dismiss($pdo, $orderId);
        flash($r['ok'] ? 'success' : 'error', $r['msg']);
    } elseif ($action === 'set_status' && $orderId !== '') {
        $r = panel_payment_set_status(
            $pdo,
            $orderId,
            (string) ($_POST['new_status'] ?? $_POST['status'] ?? ''),
            !empty($_POST['remove_product']),
            !empty($_POST['reject_invoice'])
        );
        $r['id_order'] = $orderId;
        $r = payment_sheet_result($pdo, $r);
        if ($isAjax) {
            payment_json_exit($r, !empty($r['ok']) ? 200 : 400);
        }
        flash($r['ok'] ? 'success' : 'error', $r['msg']);
    } elseif ($action === 'reject_all') {
        db_query(
            $pdo,
            "UPDATE Payment_report SET payment_Status = 'reject', dec_not_confirmed = 'remove_all'
             WHERE Payment_Method = 'cart to cart' AND payment_Status = 'waiting'"
        );
        flash('success', 'همه رسیدهای در انتظار رد شدند.');
        $redirect = 'payment.php?tab=pending';
    } elseif ($action === 'add_manual') {
        $r = panel_payment_add_manual($pdo, [
            'amount' => $_POST['amount'] ?? 0,
            'time' => $_POST['time'] ?? '',
            'id_order' => $_POST['id_order'] ?? '',
            'id_user' => $_POST['id_user'] ?? '',
            'note' => $_POST['note'] ?? '',
            'method' => $_POST['payment_method'] ?? '',
            'status' => $_POST['new_status'] ?? $_POST['status'] ?? '',
            'credit_wallet' => !empty($_POST['credit_wallet']),
        ]);
        flash($r['ok'] ? 'success' : 'error', $r['msg']);
        $redirect = $r['ok'] ? 'payment.php?status=manual' : 'payment.php';
    } elseif ($action === 'add_cost') {
        $r = panel_payment_add_cost($pdo, [
            'amount' => $_POST['amount'] ?? 0,
            'time' => $_POST['time'] ?? '',
            'id_order' => $_POST['id_order'] ?? '',
            'id_user' => $_POST['id_user'] ?? '',
            'note' => $_POST['note'] ?? '',
            'expense_category' => $_POST['expense_category'] ?? '',
        ]);
        flash($r['ok'] ? 'success' : 'error', $r['msg']);
        $redirect = 'payment.php?tab=costs';
    } elseif ($action === 'delete_cost' && $orderId !== '') {
        $r = panel_payment_delete_cost($pdo, $orderId);
        flash($r['ok'] ? 'success' : 'error', $r['msg']);
        $redirect = 'payment.php?tab=costs';
    }

    header('Location: ' . $redirect);
    exit;
}

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$priceMinRaw = trim((string) ($_GET['price_min'] ?? ''));
$priceMaxRaw = trim((string) ($_GET['price_max'] ?? ''));
$priceMin = $priceMinRaw !== '' && is_numeric($priceMinRaw) ? (int) $priceMinRaw : null;
$priceMax = $priceMaxRaw !== '' && is_numeric($priceMaxRaw) ? (int) $priceMaxRaw : null;
if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
    [$priceMin, $priceMax] = [$priceMax, $priceMin];
}
$fromRaw = trim((string) ($_GET['from'] ?? ''));
$toRaw = trim((string) ($_GET['to'] ?? ''));
$fromFilter = $fromRaw !== '' ? panel_payment_parse_filter_datetime($fromRaw, false) : null;
$toFilter = $toRaw !== '' ? panel_payment_parse_filter_datetime($toRaw, true) : null;
if ($fromRaw !== '' && $fromFilter === null) {
    flash('error', 'تاریخ و ساعت شروع معتبر نیست.');
}
if ($toRaw !== '' && $toFilter === null) {
    flash('error', 'تاریخ و ساعت پایان معتبر نیست.');
}
if ($fromFilter && $toFilter && $fromFilter['ts'] > $toFilter['ts']) {
    flash('error', 'زمان شروع باید قبل از زمان پایان باشد.');
    $fromFilter = $toFilter = null;
}
$fromInput = $fromFilter['input'] ?? '';
$toInput = $toFilter['input'] ?? '';
$method = trim((string) ($_GET['method'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$kind = trim((string) ($_GET['kind'] ?? ''));
if (!in_array($kind, ['income', 'expense'], true)) {
    $kind = '';
}
$expenseStatus = trim((string) ($_GET['expense_status'] ?? ''));
if ($expenseStatus !== 'cost') {
    $expenseStatus = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($tab === 'pending') {
    $where[] = "Payment_Method = 'cart to cart'";
    $where[] = "payment_Status = 'waiting'";
} elseif ($tab === 'costs') {
    $where[] = "payment_Status = 'cost'";
    if ($search !== '') {
        $where[] = "(`id_user` LIKE ? OR `id_order` LIKE ? OR COALESCE(`note`,'') LIKE ?)";
        $params = ["%$search%", "%$search%", "%$search%"];
    }
    if ($priceMin !== null) {
        $where[] = 'CAST(price AS DECIMAL(20,0)) >= ?';
        $params[] = $priceMin;
    }
    if ($priceMax !== null) {
        $where[] = 'CAST(price AS DECIMAL(20,0)) <= ?';
        $params[] = $priceMax;
    }
    if ($category !== '') {
        $where[] = 'expense_category = ?';
        $params[] = $category;
    }
    panel_payment_append_time_range($where, $params, $fromFilter, $toFilter);
} else {
    if ($search !== '') {
        $where[] = "(`id_user` LIKE ? OR `id_order` LIKE ? OR COALESCE(`note`,'') LIKE ?)";
        $params = ["%$search%", "%$search%", "%$search%"];
    }
    if ($tab === 'income' || ($tab === 'list' && $kind === 'income')) {
        $where[] = payment_income_type_sql();
        payment_append_income_filters($where, $params, $status, $method);
    } elseif ($tab === 'list' && $kind === 'expense') {
        $where[] = payment_expense_type_sql();
        payment_append_expense_filters($where, $params, $category, $expenseStatus);
    } elseif ($tab === 'list') {
        $hasIncomeFilter = $status !== '' || $method !== '';
        $hasExpenseFilter = $category !== '' || $expenseStatus !== '';
        $incomeParts = [payment_income_type_sql()];
        $incomeParams = [];
        payment_append_income_filters($incomeParts, $incomeParams, $status, $method);
        $expenseParts = [payment_expense_type_sql()];
        $expenseParams = [];
        payment_append_expense_filters($expenseParts, $expenseParams, $category, $expenseStatus);
        if ($hasIncomeFilter && $hasExpenseFilter) {
            $where[] = '(' . implode(' AND ', $incomeParts) . ' OR ' . implode(' AND ', $expenseParts) . ')';
            $params = array_merge($params, $incomeParams, $expenseParams);
        } elseif ($hasIncomeFilter) {
            array_push($where, ...$incomeParts);
            $params = array_merge($params, $incomeParams);
        } elseif ($hasExpenseFilter) {
            array_push($where, ...$expenseParts);
            $params = array_merge($params, $expenseParams);
        }
    } else {
        $where[] = payment_income_type_sql();
        payment_append_income_filters($where, $params, $status, $method);
    }
    if ($priceMin !== null) {
        $where[] = 'CAST(price AS DECIMAL(20,0)) >= ?';
        $params[] = $priceMin;
    }
    if ($priceMax !== null) {
        $where[] = 'CAST(price AS DECIMAL(20,0)) <= ?';
        $params[] = $priceMax;
    }
    panel_payment_append_time_range($where, $params, $fromFilter, $toFilter);
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$orderSQL = 'ORDER BY (' . panel_payment_time_sort_sql() . ') DESC, id DESC';

try {
    $total = db_count($pdo, "SELECT COUNT(*) FROM Payment_report $whereSQL", $params);
    $payments = db_fetchAll($pdo, "SELECT * FROM Payment_report $whereSQL $orderSQL LIMIT $perPage OFFSET $offset", $params);
} catch (Exception $e) {
    $total = 0;
    $payments = [];
    flash('error', 'خطای پایگاه داده در خواندن تراکنش‌ها: ' . $e->getMessage());
}
$totalPages = max(1, (int) ceil($total / $perPage));

$knownUsers = [];
$userIds = [];
foreach ($payments as $p) {
    $uid = trim((string) ($p['id_user'] ?? ''));
    if ($uid !== '' && $uid !== '0') {
        $userIds[$uid] = $uid;
    }
}
if ($userIds) {
    try {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = db_fetchAll($pdo, "SELECT id FROM user WHERE id IN ($placeholders)", array_values($userIds));
        foreach ($rows as $row) {
            $knownUsers[(string) $row['id']] = true;
        }
    } catch (Exception $e) {
    }
}

$totalSuccess = 0;
$totalCosts = 0;
$forecastIncome = 0;
$todayCount = 0;
$pendingCount = 0;
try {
    [$cardWhere, $cardParams] = payment_shared_filter_clauses(
        $search,
        $priceMin,
        $priceMax,
        $fromFilter,
        $toFilter,
        '',
        ''
    );

    $hideIncomeCards = ($tab === 'list' && $kind === 'expense')
        || ($tab === 'list' && $kind === '' && ($category !== '' || $expenseStatus !== '') && $status === '' && $method === '');
    $hideCostCards = ($tab === 'list' && $kind === 'income')
        || ($tab === 'list' && $kind === '' && ($status !== '' || $method !== '') && $category === '' && $expenseStatus === '');

    $successWhere = array_merge(["payment_Status = 'paid'"], $cardWhere);
    $successParams = $cardParams;
    payment_append_income_filters($successWhere, $successParams, $status, $tab === 'costs' ? '' : $method);
    $successSQL = 'WHERE ' . implode(' AND ', $successWhere);
    $totalSuccess = $hideIncomeCards ? 0 : (int) db_query(
        $pdo,
        "SELECT COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) FROM Payment_report $successSQL",
        $successParams
    )->fetchColumn();

    $costWhere = array_merge(["payment_Status = 'cost'"], $cardWhere);
    $costParams = $cardParams;
    payment_append_expense_filters($costWhere, $costParams, in_array($tab, ['costs', 'list'], true) ? $category : '', $tab === 'list' ? $expenseStatus : '');
    $costSQL = 'WHERE ' . implode(' AND ', $costWhere);
    $totalCosts = $hideCostCards ? 0 : (int) db_query(
        $pdo,
        "SELECT COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) FROM Payment_report $costSQL",
        $costParams
    )->fetchColumn();

    $forecastIncome = (int) round(forecast_monthly_paid_income($pdo));
    if ($tab === 'costs' || ($tab === 'list' && $kind === 'expense')) {
        $todayWhere = "payment_Status = 'cost'";
    } elseif ($tab === 'income' || ($tab === 'list' && $kind === 'income')) {
        $todayWhere = "payment_Status != 'cost'";
    } else {
        $todayWhere = '1=1';
    }
    $tehran = new DateTimeZone('Asia/Tehran');
    $dayStart = new DateTime('today', $tehran);
    $dayEnd = (clone $dayStart)->modify('+1 day');
    $todayStart = $dayStart->getTimestamp();
    $todayEnd = $dayEnd->getTimestamp();
    $todayDtStart = $dayStart->format('Y/m/d H:i:s');
    $todayDtEnd = (clone $dayEnd)->modify('-1 second')->format('Y/m/d H:i:s');
    $todayCount = db_count(
        $pdo,
        "SELECT COUNT(*) FROM Payment_report
         WHERE $todayWhere
           AND (
             (time REGEXP '^[0-9]{9,}$' AND CAST(time AS UNSIGNED) >= ? AND CAST(time AS UNSIGNED) < ?)
             OR (time NOT REGEXP '^[0-9]{9,}$' AND COALESCE(
                   STR_TO_DATE(time, '%Y-%m-%d %H:%i:%s'),
                   STR_TO_DATE(time, '%Y/%m/%d %H:%i:%s')
                 ) BETWEEN ? AND ?)
           )",
        [$todayStart, $todayEnd, $todayDtStart, $todayDtEnd]
    );
    $pendingCount = db_count($pdo, "SELECT COUNT(*) FROM Payment_report WHERE Payment_Method = 'cart to cart' AND payment_Status = 'waiting'");
} catch (Exception $e) {
}
$netIncome = $totalSuccess - $totalCosts;
$cardsFiltered = $search !== '' || $priceMin !== null || $priceMax !== null || $fromFilter || $toFilter
    || $method !== '' || $category !== '' || $kind !== '' || $expenseStatus !== ''
    || ($tab !== 'costs' && $status !== '');
$successMeta = $cardsFiltered ? 'بر اساس فیلترهای انتخاب‌شده' : 'از ابتدای فعالیت';
$costMeta = $cardsFiltered ? 'بر اساس فیلترهای انتخاب‌شده' : 'هزینه شده';
$netMeta = $cardsFiltered ? 'بر اساس فیلترهای انتخاب‌شده' : 'درآمد منهای هزینه';

$statusMap = panel_payment_status_meta();
$listStatusMap = $statusMap;
unset($listStatusMap['cost']);
$filterStatusMap = [
    'paid' => $listStatusMap['paid'],
    'manual' => ['tag-mint', 'فاکتور دستی'],
] + $listStatusMap;

$methodOptions = [];
try {
    $methodRows = db_fetchAll(
        $pdo,
        "SELECT DISTINCT Payment_Method FROM Payment_report
         WHERE Payment_Method IS NOT NULL AND Payment_Method != '' AND Payment_Method != 'cost'
         ORDER BY Payment_Method"
    );
    foreach ($methodRows as $row) {
        $key = (string) ($row['Payment_Method'] ?? '');
        if ($key === '') {
            continue;
        }
        $methodOptions[$key] = panel_payment_method_label($key, $pdo);
    }
} catch (Exception $e) {
}
foreach (panel_income_category_map($pdo) as $key => $lbl) {
    if (!isset($methodOptions[$key])) {
        $methodOptions[$key] = $lbl;
    }
}
if ($method !== '' && !isset($methodOptions[$method]) && $method !== 'cost') {
    $methodOptions[$method] = panel_payment_method_label($method, $pdo);
}
asort($methodOptions, SORT_STRING);

// Sheet picker: income categories first (editable in settings), then system gateways/methods.
$sheetMethodOptions = panel_payment_income_method_options($pdo);
foreach ($methodOptions as $k => $lbl) {
    if ($k === 'cost') {
        continue;
    }
    if (!isset($sheetMethodOptions[$k])) {
        $sheetMethodOptions[$k] = $lbl;
    }
}
$pinnedMethods = [];
foreach (array_keys(panel_income_category_map($pdo)) as $pinKey) {
    if (isset($sheetMethodOptions[$pinKey])) {
        $pinnedMethods[$pinKey] = $sheetMethodOptions[$pinKey];
        unset($sheetMethodOptions[$pinKey]);
    }
}
$sheetMethodOptions = $pinnedMethods + $sheetMethodOptions;
$categoryOptions = panel_expense_category_map($pdo);
if ($category !== '' && !isset($categoryOptions[$category])) {
    $categoryOptions[$category] = panel_expense_category_label($pdo, $category);
}
$nowJalali = jalali_tehran_format(time(), 'Y/m/d H:i', 'en');

$activeFilterCount = 0;
if ($tab !== 'pending') {
    if ($tab !== 'costs' && $status !== '') {
        $activeFilterCount++;
    }
    if ($tab === 'list' && $kind !== '') {
        $activeFilterCount++;
    }
    if ($tab === 'list' && $expenseStatus !== '') {
        $activeFilterCount++;
    }
    if ($priceMin !== null) {
        $activeFilterCount++;
    }
    if ($priceMax !== null) {
        $activeFilterCount++;
    }
    if ($fromFilter) {
        $activeFilterCount++;
    }
    if ($toFilter) {
        $activeFilterCount++;
    }
    if ($tab !== 'costs' && $method !== '') {
        $activeFilterCount++;
    }
    if ($tab !== 'income' && $category !== '') {
        $activeFilterCount++;
    }
}
$clearFiltersUrl = payment_redirect_url($tab, $search !== '' ? ['q' => $search] : []);
$financialExportUrl = 'payment_export.php?' . http_build_query([
    '_csrf' => csrf_token(),
    'from' => $fromInput,
    'to' => $toInput,
], '', '&', PHP_QUERY_RFC3986);

$pageTitle = 'مالی';
$pageLede = 'گزارش پرداخت‌ها، فاکتور دستی، هزینه‌ها و درآمد خالص.';
$activeNav = 'payment';
include __DIR__ . '/inc/layout_head.php';
?>
<?php if ($tab !== 'pending'): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css">
<style>
  .datepicker-plot-area { z-index: 3000 !important; }
  #paymentFilterModal .modal { overflow: visible; }
  .pay-sheet-row td { vertical-align: middle; }
  .pay-sheet-row .pay-edit { display: none; width: 100%; min-width: 0; }
  .pay-sheet-row .pay-cell-input { height: 32px; padding: 0 8px; font-size: .8rem; }
  .pay-sheet-row.is-editing .pay-view { display: none; }
  .pay-sheet-row.is-editing .pay-edit { display: block; }
  .pay-dd-trigger{display:inline-flex;align-items:center;gap:4px;max-width:100%;border:0;padding:0;background:transparent;font:inherit;color:inherit;cursor:pointer;text-align:right}
  .pay-dd-caret{opacity:.65;font-size:.68rem;flex-shrink:0;line-height:1}
  .pay-sheet-menu{position:fixed;z-index:4000;min-width:180px;max-width:min(280px,calc(100vw - 16px));max-height:min(320px,70vh);overflow:auto;padding:6px;border:1px solid var(--bd);border-radius:12px;background:var(--sf);box-shadow:0 10px 28px rgba(0,0,0,.18);display:flex;flex-direction:column;gap:4px}
  .pay-sheet-menu[hidden]{display:none!important}
  .pay-sheet-menu-item{display:flex;align-items:center;width:100%;padding:7px 8px;border:0;border-radius:8px;background:transparent;cursor:pointer;text-align:right;font:inherit;color:var(--text);font-size:.8rem}
  .pay-sheet-menu-item:hover,.pay-sheet-menu-item.active{background:var(--sf2)}
  .pay-sheet-menu-empty{padding:10px 8px;font-size:.78rem;color:var(--text-dim);text-align:center}
  .pay-user-item{flex-direction:column;align-items:flex-start;gap:2px}
  .pay-user-item-title{font-size:.8rem;line-height:1.4}
  .pay-price-input{text-align:left;font-variant-numeric:tabular-nums}
  .pay-sheet-row .pay-actions { white-space: nowrap; }
  .pay-sheet-row .pay-actions { display: flex; gap: 4px; align-items: center; }
  .pay-sheet-row .pay-btn-save,
  .pay-sheet-row .pay-btn-delete { display: none; }
  .pay-sheet-row.is-editing .pay-btn-edit { display: none; }
  .pay-sheet-row.is-editing .pay-btn-save,
  .pay-sheet-row.is-editing .pay-btn-delete { display: inline-flex; }
  .pay-sheet-row.is-new { background: color-mix(in srgb, var(--accent) 8%, transparent); }
  .pay-oid { color: var(--accent); font-size: .78rem; }
  .pay-note-view { font-size: .78rem; max-width: 180px; }
  .pay-time-view { font-size: .78rem; color: var(--text-dim); white-space: nowrap; }
  .pay-method-view { font-size: .8rem; }
  .card .tbl-wrap { overflow-x: auto; overflow-y: visible; }
  .pay-time-edit { display: none; align-items: center; gap: 4px; }
  .pay-sheet-row.is-editing .pay-time-edit.pay-edit { display: flex; }
  .pay-time-edit .pay-time-input { flex: 1; min-width: 0; }
  .pay-time-now { flex-shrink: 0; font-size: .72rem; padding: 0 8px; height: 32px; }
  .pay-filter-group { grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 12px; border: 1px solid var(--bd); border-radius: 10px; background: var(--sf); }
  .pay-filter-group-title { grid-column: 1 / -1; font-size: .75rem; font-weight: 700; color: var(--mute); }
  .pay-btn-export {
    color: #c05621;
    border-color: color-mix(in srgb, #c05621 50%, var(--bd));
    background: color-mix(in srgb, #c05621 12%, transparent);
  }
  .pay-btn-export:hover { background: color-mix(in srgb, #c05621 20%, transparent); }
  .pay-btn-import {
    color: #2f855a;
    border-color: color-mix(in srgb, #2f855a 50%, var(--bd));
    background: color-mix(in srgb, #2f855a 12%, transparent);
  }
  .pay-btn-import:hover { background: color-mix(in srgb, #2f855a 20%, transparent); }
  #paymentImportModal .modal { width: min(1100px, 96vw); max-width: 1100px; overflow: visible; }
  .pay-import-drop {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
    padding: 28px 16px; border: 1px dashed var(--bd); border-radius: 12px; background: var(--sf); cursor: pointer;
  }
  .pay-import-drop strong { font-size: .9rem; }
  .pay-import-drop span { font-size: .78rem; color: var(--mute); }
  .pay-import-file-name { margin-top: 10px; font-size: .8rem; color: var(--text-dim); }
  .pay-import-stats { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
  .pay-import-table-wrap { max-height: min(58vh, 560px); overflow: auto; border: 1px solid var(--bd); border-radius: 10px; }
  .pay-import-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
  .pay-import-table th, .pay-import-table td { padding: 6px 8px; border-bottom: 1px solid var(--bd); vertical-align: middle; }
  .pay-import-table th { position: sticky; top: 0; background: var(--sf); z-index: 1; font-size: .72rem; color: var(--mute); }
  .pay-import-table .input, .pay-import-table .select { height: 32px; padding: 0 8px; font-size: .78rem; width: 100%; }
  .pay-import-row-warn { background: color-mix(in srgb, #c05621 10%, transparent); }
  .pay-import-cat-missing { color: #c05621; font-weight: 700; }
  .pay-import-hint { font-size: .75rem; color: var(--mute); line-height: 1.6; }
  .pay-import-error { color: #c53030; font-size: .8rem; margin-top: 8px; }
</style>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="display:flex;gap:4px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:4px;flex-wrap:wrap">
    <a href="payment.php" class="btn btn-sm <?= $tab === 'list' ? 'btn-primary' : 'btn-ghost' ?>">همه تراکنش‌ها</a>
    <a href="payment.php?tab=income" class="btn btn-sm <?= $tab === 'income' ? 'btn-primary' : 'btn-ghost' ?>">درآمدها</a>
    <a href="payment.php?tab=pending" class="btn btn-sm <?= $tab === 'pending' ? 'btn-primary' : 'btn-ghost' ?>">
      رسید در انتظار
      <?php if ($pendingCount > 0): ?>
        <span class="tag tag-warn" style="margin-right:6px;font-size:.7rem"><?= $pendingCount ?></span>
      <?php endif; ?>
    </a>
    <a href="payment.php?tab=costs" class="btn btn-sm <?= $tab === 'costs' ? 'btn-primary' : 'btn-ghost' ?>">هزینه‌ها</a>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="settings.php?tab=finance" class="btn btn-ghost btn-sm"><?= icon('wallet', 14) ?> دسته‌های مالی</a>
    <a href="payment_methods.php" class="btn btn-ghost btn-sm"><?= icon('settings', 14) ?> درگاه‌های پرداخت</a>
  </div>
</div>

<?php if ($tab !== 'pending'): ?>
<div class="stats" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px">
  <div class="stat success">
    <div class="stat-label">جمع تراکنش‌های موفق</div>
    <div class="stat-num"><?= number_format($totalSuccess) ?><small>تومان</small></div>
    <div class="stat-meta"><?= $successMeta ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">درآمد پیش‌بینی‌شده ماهانه</div>
    <div class="stat-num"><?= number_format($forecastIncome) ?><small>تومان</small></div>
    <div class="stat-meta">بر اساس ۲۸ روز اخیر</div>
  </div>
  <div class="stat warn">
    <div class="stat-label">جمع هزینه‌ها</div>
    <div class="stat-num"><?= number_format($totalCosts) ?><small>تومان</small></div>
    <div class="stat-meta"><?= $costMeta ?></div>
  </div>
  <div class="stat <?= $netIncome >= 0 ? 'ok' : 'no' ?>">
    <div class="stat-label">درآمد خالص</div>
    <div class="stat-num"><?= number_format($netIncome) ?><small>تومان</small></div>
    <div class="stat-meta"><?= $netMeta ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">تعداد کل</div>
    <div class="stat-num"><?= number_format($total) ?></div>
    <div class="stat-meta"><?= $tab === 'costs' ? 'رکورد هزینه' : ($tab === 'income' ? 'رکورد درآمد' : 'رکورد تراکنش') ?></div>
  </div>
  <div class="stat warn">
    <div class="stat-label">امروز</div>
    <div class="stat-num"><?= number_format($todayCount) ?></div>
    <div class="stat-meta"><?= $tab === 'costs' ? 'هزینه امروز' : ($tab === 'income' ? 'درآمد امروز' : 'تراکنش جدید امروز') ?></div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="toolbar">
    <div class="toolbar-title">
      <?php
      if ($tab === 'pending') {
          echo 'رسیدهای کارت‌به‌کارت در انتظار';
      } elseif ($tab === 'costs') {
          echo 'هزینه‌ها';
      } elseif ($tab === 'income') {
          echo 'درآمدها';
      } else {
          echo 'همه تراکنش‌ها';
      }
      ?>
      <small>(<?= number_format($total) ?>)</small>
    </div>
    <?php if ($tab === 'pending' && $total > 0): ?>
      <form method="POST" onsubmit="return confirm('همه رسیدهای در انتظار رد شوند؟')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="reject_all">
        <button type="submit" class="btn btn-no btn-sm">حذف همه</button>
      </form>
    <?php elseif ($tab !== 'pending'): ?>
    <div class="toolbar-end pay-toolbar">
      <div class="pay-toolbar-actions">
        <button type="button" class="btn btn-ghost btn-sm pay-btn-import" id="payImportOpenBtn">
          <?= icon('arrow-up', 14) ?> ورود دیتا با اکسل
        </button>
        <a href="<?= htmlspecialchars($financialExportUrl, ENT_QUOTES) ?>" class="btn btn-ghost btn-sm pay-btn-export">
          <?= icon('arrow-down', 14) ?> خروجی اکسل مالی
        </a>
        <button type="button" class="btn btn-ghost btn-sm" onclick="openModal('paymentFilterModal')">
          <?= icon('filter', 14) ?> فیلترها
          <?php if ($activeFilterCount > 0): ?>
            <span class="tag tag-info" style="margin-right:4px"><?= $activeFilterCount ?></span>
          <?php endif; ?>
        </button>
          <button type="button" class="btn btn-primary btn-sm" id="payAddRowBtn"><?= icon('plus', 14) ?> افزودن</button>
        <?php if ($search || $activeFilterCount > 0): ?>
          <a href="<?= htmlspecialchars($clearFiltersUrl) ?>" class="btn btn-ghost btn-sm pay-toolbar-clear">پاک</a>
        <?php endif; ?>
      </div>
      <form method="GET" class="pay-toolbar-search">
        <?php if (in_array($tab, ['costs', 'income'], true)): ?>
          <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <?php endif; ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
        <input type="hidden" name="price_min" value="<?= $priceMin !== null ? (int) $priceMin : '' ?>">
        <input type="hidden" name="price_max" value="<?= $priceMax !== null ? (int) $priceMax : '' ?>">
        <input type="hidden" name="from" value="<?= htmlspecialchars($fromInput) ?>">
        <input type="hidden" name="to" value="<?= htmlspecialchars($toInput) ?>">
        <input type="hidden" name="method" value="<?= htmlspecialchars($method) ?>">
        <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
        <input type="hidden" name="kind" value="<?= htmlspecialchars($kind) ?>">
        <input type="hidden" name="expense_status" value="<?= htmlspecialchars($expenseStatus) ?>">
        <div class="search-box">
          <?= icon('search', 14) ?>
          <input type="text" name="q" placeholder="<?= $tab === 'costs' ? 'شناسه، یادداشت...' : 'آیدی کاربر، شماره تراکنش یا یادداشت...' ?>"
            value="<?= htmlspecialchars($search) ?>">
          <button type="button" class="search-clear">✕</button>
          <button type="submit" class="search-btn">جستجو</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-lg<?= $tab !== 'pending' ? ' pay-sheet-table' : '' ?>">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>شناسه</th>
          <th>مبلغ</th>
          <th><?php
          if ($tab === 'costs') {
              echo 'دسته هزینه';
          } elseif ($tab === 'list') {
              echo 'روش / دسته';
          } else {
              echo 'روش پرداخت';
          }
          ?></th>
          <th>یادداشت</th>
          <th>تاریخ</th>
          <th>وضعیت</th>
          <th>عملیات</th>
        </tr>
      </thead>
      <tbody id="paySheetBody">
        <?php if (empty($payments)): ?>
          <tr class="pay-empty-row">
            <td colspan="9">
              <div class="empty">
                <div class="empty-mark">—</div>
                <p><?php
                if ($tab === 'pending') {
                    echo 'رسید در انتظاری نیست';
                } elseif ($tab === 'costs') {
                    echo 'هزینه‌ای ثبت نشده';
                } elseif ($tab === 'income') {
                    echo 'درآمدی یافت نشد';
                } else {
                    echo 'تراکنشی یافت نشد';
                }
                ?></p>
              </div>
            </td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($payments as $p):
            $st = $p['payment_Status'] ?? '';
            [$cls, $lbl] = $statusMap[$st] ?? ['tag-plain', $st ?: '—'];
            $methodKey = (string) ($p['Payment_Method'] ?? '');
            $methodLabel = panel_payment_method_label($methodKey, $pdo);
            $categoryKey = panel_expense_resolve_slug($pdo, (string) ($p['expense_category'] ?? ''));
            $categoryLabel = panel_expense_category_label($pdo, $categoryKey);
            $oid = (string) ($p['id_order'] ?? '');
            $hasProduct = panel_payment_row_has_product($p);
            $uid = trim((string) ($p['id_user'] ?? ''));
            $note = trim((string) ($p['note'] ?? ''));
            $price = (int) ($p['price'] ?? 0);
            $jalaliTime = panel_payment_time_to_jalali($p['time'] ?? '');
            $isCostRow = $tab === 'costs' || panel_payment_is_cost($p);
            if ($tab === 'pending' && $methodKey === 'manual invoice') {
                [$cls, $lbl] = ['tag-mint', 'فاکتور دستی'];
            }
            ?>
            <?php if ($tab === 'pending'): ?>
            <tr>
              <td style="color:var(--text-dim)"><?= $i++ ?></td>
              <td>
                <?php if ($uid === '' || $uid === '0'): ?>
                  <span style="color:var(--text-dim)">بدون کاربر</span>
                <?php elseif (!empty($knownUsers[$uid])): ?>
                  <a href="user.php?id=<?= htmlspecialchars($uid) ?>" class="cell-mono" style="color:var(--accent)">
                    <?= htmlspecialchars($uid) ?>
                  </a>
                <?php else: ?>
                  <span><?= htmlspecialchars($uid) ?></span>
                <?php endif; ?>
              </td>
              <td class="cell-mono" style="color:var(--accent);font-size:.78rem">
                <?= htmlspecialchars(trunc((string) $oid, 22)) ?>
              </td>
              <td class="cell-strong cell-num"><?= number_format($price) ?> <span
                  style="color:var(--text-dim);font-weight:400;font-size:.72rem">ت</span></td>
              <td style="font-size:.8rem"><?= htmlspecialchars($methodLabel) ?></td>
              <td style="font-size:.78rem;max-width:180px" title="<?= htmlspecialchars($note) ?>">
                <?= $note !== '' ? htmlspecialchars(trunc($note, 40)) : '<span style="color:var(--text-dim)">—</span>' ?>
              </td>
              <td style="font-size:.78rem;color:var(--text-dim);white-space:nowrap">
                <?= $jalaliTime !== '' ? htmlspecialchars($jalaliTime) : htmlspecialchars(safe_date($p['time'] ?? null, 'Y/m/d H:i')) ?>
              </td>
              <td><span class="tag <?= $cls ?>"><?= $lbl ?></span></td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="confirm">
                    <input type="hidden" name="order_id" value="<?= htmlspecialchars($oid) ?>">
                    <button type="submit" class="btn btn-primary btn-sm">تأیید</button>
                  </form>
                  <button type="button" class="btn btn-no btn-sm"
                    onclick="openRejectModal('<?= htmlspecialchars($oid, ENT_QUOTES) ?>')">رد</button>
                  <form method="POST" style="display:inline" onsubmit="return confirm('حذف بدون اطلاع کاربر؟')">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="dismiss">
                    <input type="hidden" name="order_id" value="<?= htmlspecialchars($oid) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">حذف</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php else: ?>
            <tr class="pay-sheet-row<?= $isCostRow ? ' is-cost' : '' ?>"
              data-order-id="<?= htmlspecialchars($oid, ENT_QUOTES) ?>"
              data-status="<?= htmlspecialchars((string) $st, ENT_QUOTES) ?>"
              data-method="<?= htmlspecialchars($methodKey, ENT_QUOTES) ?>"
              data-category="<?= htmlspecialchars($categoryKey, ENT_QUOTES) ?>"
              data-has-product="<?= $hasProduct ? '1' : '0' ?>">
              <td class="pay-idx" style="color:var(--text-dim)"><?= $i++ ?></td>
              <td>
                <span class="pay-view pay-user-view">
                  <?php if ($uid === '' || $uid === '0'): ?>
                    <span style="color:var(--text-dim)">بدون کاربر</span>
                  <?php elseif (!empty($knownUsers[$uid])): ?>
                    <a href="user.php?id=<?= htmlspecialchars($uid) ?>" class="cell-mono" style="color:var(--accent)">
                      <?= htmlspecialchars($uid) ?>
                    </a>
                  <?php else: ?>
                    <span><?= htmlspecialchars($uid) ?></span>
                  <?php endif; ?>
                </span>
                <input class="input pay-edit pay-cell-input pay-user-input" type="text"
                  value="<?= htmlspecialchars($uid === '0' ? '' : $uid) ?>" placeholder="آیدی یا یوزرنیم" autocomplete="off">
              </td>
              <td class="cell-mono pay-oid"><?= htmlspecialchars($oid) ?></td>
              <td>
                <span class="pay-view cell-strong cell-num pay-price-view"><?= number_format($price) ?> <span
                    style="color:var(--text-dim);font-weight:400;font-size:.72rem">ت</span></span>
                <input class="input pay-edit pay-cell-input pay-price-input" type="text" inputmode="numeric"
                  dir="ltr" autocomplete="off" placeholder="0"
                  value="<?= htmlspecialchars($price > 0 ? number_format($price) : '') ?>">
              </td>
              <td class="pay-method-view">
                <?php if ($isCostRow): ?>
                  <button type="button" class="pay-dd-trigger" data-pay-menu="category">
                    <span class="pay-method-label"><?= htmlspecialchars($categoryLabel) ?></span>
                    <span class="pay-dd-caret">▾</span>
                  </button>
                  <input type="hidden" class="pay-method-value" value="<?= htmlspecialchars($categoryKey) ?>">
                <?php else: ?>
                  <button type="button" class="pay-dd-trigger" data-pay-menu="method">
                    <span class="pay-method-label"><?= htmlspecialchars($methodLabel) ?></span>
                    <span class="pay-dd-caret">▾</span>
                  </button>
                  <input type="hidden" class="pay-method-value" value="<?= htmlspecialchars($methodKey) ?>">
                <?php endif; ?>
              </td>
              <td>
                <span class="pay-view pay-note-view" title="<?= htmlspecialchars($note) ?>">
                  <?= $note !== '' ? htmlspecialchars(trunc($note, 40)) : '<span style="color:var(--text-dim)">—</span>' ?>
                </span>
                <input class="input pay-edit pay-cell-input pay-note-input" type="text"
                  value="<?= htmlspecialchars($note) ?>" placeholder="یادداشت">
              </td>
              <td>
                <span class="pay-view pay-time-view"><?= htmlspecialchars($jalaliTime !== '' ? $jalaliTime : '—') ?></span>
                <div class="pay-edit pay-time-edit">
                  <input class="input pay-cell-input jalali-datetime-picker pay-time-input" type="text"
                    value="<?= htmlspecialchars($jalaliTime) ?>" placeholder="تاریخ و ساعت" autocomplete="off">
                  <button type="button" class="btn btn-ghost btn-sm pay-time-now" title="تاریخ و ساعت الان">اکنون</button>
                </div>
              </td>
              <td>
                <?php if ($isCostRow): ?>
                  <span class="tag <?= $cls ?> pay-status-tag"><?= $lbl ?></span>
                <?php else: ?>
                  <button type="button" class="pay-dd-trigger" data-pay-menu="status">
                    <span class="tag <?= $cls ?> pay-status-tag"><?= $lbl ?></span>
                    <span class="pay-dd-caret">▾</span>
                  </button>
                  <input type="hidden" class="pay-status-value" value="<?= htmlspecialchars((string) $st) ?>">
                <?php endif; ?>
              </td>
              <td>
                <div class="pay-actions">
                  <button type="button" class="btn btn-ghost btn-sm btn-icon pay-btn-edit" title="ویرایش"><?= icon('edit', 14) ?></button>
                  <button type="button" class="btn btn-primary btn-sm btn-icon pay-btn-save" title="ذخیره"><?= icon('check', 14) ?></button>
                  <button type="button" class="btn btn-no btn-sm btn-icon pay-btn-delete" title="حذف"><?= icon('trash', 14) ?></button>
                </div>
              </td>
            </tr>
            <?php endif; ?>
          <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="tbl-foot">
    <span><?= number_format($total) ?> رکورد · صفحه <?= $page ?> از <?= $totalPages ?></span>
    <div class="pager">
      <?php
      $qs = static function (int $p) use ($tab, $search, $status, $priceMin, $priceMax, $fromInput, $toInput, $method, $category, $kind, $expenseStatus): string {
          if ($tab === 'pending') {
              return htmlspecialchars('payment.php?tab=pending&page=' . $p, ENT_QUOTES);
          }
          return htmlspecialchars(payment_redirect_url($tab, [
              'q' => $search,
              'status' => $status,
              'price_min' => $priceMin,
              'price_max' => $priceMax,
              'from' => $fromInput,
              'to' => $toInput,
              'method' => $method,
              'category' => $category,
              'kind' => $kind,
              'expense_status' => $expenseStatus,
              'page' => $p,
          ]), ENT_QUOTES);
      };
      ?>
      <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
      <?php for ($p2 = max(1, $page - 2); $p2 <= min($totalPages, $page + 2); $p2++): ?>
        <a class="<?= $p2 === $page ? 'cur' : '' ?>" href="<?= $qs($p2) ?>"><?= $p2 ?></a>
      <?php endfor; ?>
      <a class="<?= $page >= $totalPages ? 'dis' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($tab === 'pending'): ?>
<div class="modal" id="rejectModal">
  <div class="modal-box" style="max-width:420px">
    <div class="modal-head">
      <h3>رد پرداخت</h3>
      <button type="button" class="icon-btn" onclick="closeModal('rejectModal')">✕</button>
    </div>
    <form method="POST" class="modal-body">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="order_id" id="rejectOrderId" value="">
      <div class="field">
        <label>دلیل (برای کاربر ارسال می‌شود)</label>
        <textarea name="reason" class="input" rows="3" placeholder="اختیاری"></textarea>
      </div>
      <button type="submit" class="btn btn-no">رد کردن</button>
    </form>
  </div>
</div>
<script>
function openRejectModal(orderId) {
  document.getElementById('rejectOrderId').value = orderId;
  openModal('rejectModal');
}
</script>
<?php elseif ($tab !== 'costs'): ?>
<div class="modal-veil" id="statusSideModal">
  <div class="modal">
    <div class="modal-head">
      <h3>تغییر وضعیت پرداخت</h3>
      <button type="button" class="modal-x" onclick="closeModal('statusSideModal')"><?= icon('close', 14) ?></button>
    </div>
    <div class="modal-body">
      <div id="rejectInvoiceWrap" style="margin-bottom:12px">
        <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.6">
          <input type="checkbox" id="rejectInvoiceCheck" value="1" style="width:16px;height:16px;margin-top:3px">
          <span>وضعیت فاکتور/سفارش مرتبط هم «رد شده» شود؟</span>
        </label>
        <p style="font-size:.75rem;color:var(--mute);margin-top:8px;line-height:1.6">
          برای اینکه از آمار سفارشات تلگرام هم خارج شود.
        </p>
      </div>
      <div id="removeProductWrap" style="display:none">
        <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.6">
          <input type="checkbox" id="removeProductCheck" value="1" style="width:16px;height:16px;margin-top:3px">
          <span>سرویس ساخته‌شده برای این پرداخت هم حذف شود؟</span>
        </label>
        <p style="font-size:.75rem;color:var(--mute);margin-top:8px;line-height:1.6">
          فقط برای خرید سرویس (نه تمدید/شارژ کیف پول). در صورت انتخاب، سرویس از پنل و ربات حذف می‌شود.
        </p>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-primary" id="statusSideConfirm">ادامه</button>
      <button type="button" class="btn btn-ghost" id="statusSideCancel">انصراف</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($tab !== 'pending'): ?>
<div class="modal-veil" id="paymentFilterModal">
  <div class="modal">
    <div class="modal-head">
      <h3>فیلترها</h3>
      <button type="button" class="modal-x" onclick="closeModal('paymentFilterModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="GET">
      <div class="modal-body">
        <?php if (in_array($tab, ['costs', 'income'], true)): ?>
          <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <?php endif; ?>
        <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
        <div class="form-grid">
          <?php if ($tab === 'list'): ?>
          <div class="field" style="grid-column:1/-1">
            <label class="lbl">نوع تراکنش</label>
            <select name="kind" class="select" style="width:100%">
              <option value="" <?= $kind === '' ? 'selected' : '' ?>>همه (درآمد و هزینه)</option>
              <option value="income" <?= $kind === 'income' ? 'selected' : '' ?>>فقط درآمد</option>
              <option value="expense" <?= $kind === 'expense' ? 'selected' : '' ?>>فقط هزینه</option>
            </select>
          </div>
          <div class="pay-filter-group">
            <div class="pay-filter-group-title">درآمد</div>
            <div class="field">
              <label class="lbl">وضعیت درآمد</label>
              <select name="status" class="select" style="width:100%">
                <option value="">همه وضعیت‌ها</option>
                <?php foreach ($filterStatusMap as $k => [$_, $lbl]): ?>
                  <option value="<?= htmlspecialchars($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label class="lbl">روش پرداخت</label>
              <select name="method" class="select" style="width:100%">
                <option value="">همه روش‌ها</option>
                <?php foreach ($methodOptions as $k => $lbl): ?>
                  <option value="<?= htmlspecialchars($k) ?>" <?= $method === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="pay-filter-group">
            <div class="pay-filter-group-title">هزینه</div>
            <div class="field">
              <label class="lbl">وضعیت هزینه</label>
              <select name="expense_status" class="select" style="width:100%">
                <option value="">همه وضعیت‌ها</option>
                <option value="cost" <?= $expenseStatus === 'cost' ? 'selected' : '' ?>><?= htmlspecialchars($statusMap['cost'][1] ?? 'هزینه شده') ?></option>
              </select>
            </div>
            <div class="field">
              <label class="lbl">دسته هزینه</label>
              <select name="category" class="select" style="width:100%">
                <option value="">همه دسته‌ها</option>
                <?php foreach ($categoryOptions as $k => $lbl): ?>
                  <option value="<?= htmlspecialchars($k) ?>" <?= $category === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php elseif ($tab !== 'costs'): ?>
          <div class="field">
            <label class="lbl">وضعیت</label>
            <select name="status" class="select" style="width:100%">
              <option value="">همه وضعیت‌ها</option>
              <?php foreach ($filterStatusMap as $k => [$_, $lbl]): ?>
                <option value="<?= htmlspecialchars($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="lbl">روش پرداخت</label>
            <select name="method" class="select" style="width:100%">
              <option value="">همه روش‌ها</option>
              <?php foreach ($methodOptions as $k => $lbl): ?>
                <option value="<?= htmlspecialchars($k) ?>" <?= $method === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php else: ?>
          <div class="field">
            <label class="lbl">دسته هزینه</label>
            <select name="category" class="select" style="width:100%">
              <option value="">همه دسته‌ها</option>
              <?php foreach ($categoryOptions as $k => $lbl): ?>
                <option value="<?= htmlspecialchars($k) ?>" <?= $category === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="field">
            <label class="lbl">حداقل مبلغ</label>
            <input type="number" name="price_min" class="input" min="0" step="1" placeholder="تومان"
              value="<?= $priceMin !== null ? (int) $priceMin : '' ?>">
          </div>
          <div class="field">
            <label class="lbl">حداکثر مبلغ</label>
            <input type="number" name="price_max" class="input" min="0" step="1" placeholder="تومان"
              value="<?= $priceMax !== null ? (int) $priceMax : '' ?>">
          </div>
          <div class="field">
            <label class="lbl">از تاریخ و ساعت</label>
            <div style="position:relative">
              <input class="input jalali-datetime-picker" style="padding-left:30px" type="text" name="from"
                placeholder="انتخاب تاریخ و ساعت" value="<?= htmlspecialchars($fromInput) ?>"
                aria-label="تاریخ و ساعت شروع شمسی به وقت تهران" autocomplete="off" readonly>
              <span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);pointer-events:none">🗓</span>
            </div>
          </div>
          <div class="field">
            <label class="lbl">تا تاریخ و ساعت</label>
            <div style="position:relative">
              <input class="input jalali-datetime-picker" style="padding-left:30px" type="text" name="to"
                placeholder="انتخاب تاریخ و ساعت" value="<?= htmlspecialchars($toInput) ?>"
                aria-label="تاریخ و ساعت پایان شمسی به وقت تهران" autocomplete="off" readonly>
              <span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);pointer-events:none">🗓</span>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary">اعمال فیلتر</button>
        <a class="btn btn-ghost" href="<?= htmlspecialchars($clearFiltersUrl) ?>">پاک کردن</a>
        <button type="button" class="btn btn-ghost" onclick="closeModal('paymentFilterModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="paymentImportModal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="payImportTitle">ورود دیتا با اکسل</h3>
      <button type="button" class="modal-x" onclick="closeModal('paymentImportModal')"><?= icon('close', 14) ?></button>
    </div>
    <div class="modal-body">
      <div id="payImportStepFile">
        <label class="pay-import-drop" for="payImportFile">
          <?= icon('arrow-up', 20) ?>
          <strong>انتخاب فایل CSV یا XLSX</strong>
          <span>حداکثر ۵ مگابایت — ستون‌ها مطابق نمونه مالی</span>
        </label>
        <input type="file" id="payImportFile" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden>
        <div class="pay-import-file-name" id="payImportFileName"></div>
      </div>
      <div id="payImportStepRate" hidden>
        <p class="pay-import-hint">نرخ تبدیل برای سطرهایی که واحد آن‌ها دلار است استفاده می‌شود. اگر فایل فقط تومان دارد می‌توانید این فیلد را خالی بگذارید.</p>
        <div class="field" style="margin-top:12px">
          <label class="lbl">هر ۱ دلار چند تومان؟</label>
          <input type="number" class="input" id="payImportUsdRate" min="1" step="1" placeholder="مثلاً 100000">
        </div>
      </div>
      <div id="payImportStepPreview" hidden>
        <div class="pay-import-stats" id="payImportStats"></div>
        <p class="pay-import-hint">مبالغ به تومان تبدیل شده‌اند. قبل از ورود به دیتابیس همه فیلدها را بررسی و در صورت نیاز ویرایش کنید. سطرهای بدون دسته باید دستی انتخاب شوند.</p>
        <div class="pay-import-table-wrap" style="margin-top:10px">
          <table class="pay-import-table">
            <thead>
              <tr>
                <th style="width:42px">#</th>
                <th style="width:110px">نوع</th>
                <th style="width:170px">تاریخ</th>
                <th style="width:140px">مبلغ (تومان)</th>
                <th>یادداشت</th>
                <th style="width:180px">دسته‌بندی</th>
              </tr>
            </thead>
            <tbody id="payImportPreviewBody"></tbody>
          </table>
        </div>
      </div>
      <div class="pay-import-error" id="payImportError" hidden></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" id="payImportBackBtn" hidden>بازگشت</button>
      <button type="button" class="btn btn-primary" id="payImportNextBtn" disabled>ادامه</button>
      <button type="button" class="btn btn-ghost" onclick="closeModal('paymentImportModal')">انصراف</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($tab !== 'pending'): ?>
<?php
$sheetStatusJs = [];
foreach ($listStatusMap as $k => [$cls, $lbl]) {
    $sheetStatusJs[$k] = ['cls' => $cls, 'lbl' => $lbl];
}
$costStatusJs = $statusMap['cost'] ?? ['tag-plain', 'هزینه شده'];
?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js"></script>
<script>
window.PAYMENT_SHEET = <?= json_encode([
    'csrf' => csrf_token(),
    'tab' => $tab,
    'nowJalali' => $nowJalali,
    'emptyText' => $tab === 'costs' ? 'هزینه‌ای ثبت نشده' : ($tab === 'income' ? 'درآمدی یافت نشد' : 'تراکنشی یافت نشد'),
    'statusOptions' => $sheetStatusJs,
    'costStatus' => ['cls' => $costStatusJs[0], 'lbl' => $costStatusJs[1]],
    'methodOptions' => $sheetMethodOptions,
    'categoryOptions' => $categoryOptions,
    'defaultCategory' => panel_expense_default_slug(),
    'defaultMethod' => panel_income_default_slug(),
    'icons' => [
        'edit' => icon('edit', 14),
        'save' => icon('check', 14),
        'trash' => icon('trash', 14),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.PAYMENT_IMPORT = <?= json_encode([
    'csrf' => csrf_token(),
    'tab' => $tab,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= htmlspecialchars(panel_asset('js/payment_sheet.js')) ?>"></script>
<script src="<?= htmlspecialchars(panel_asset('js/payment_import.js')) ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
