<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_auth();

$pdo = panel_ensure_pdo();
panel_payment_ensure_note_column($pdo);

$tab = $_GET['tab'] ?? 'list';
if (!in_array($tab, ['list', 'pending', 'costs'], true)) {
    $tab = 'list';
}

function payment_redirect_url(string $tab, array $extra = []): string
{
    if ($tab === 'pending') {
        return 'payment.php?tab=pending';
    }
    $qs = array_filter([
        'tab' => $tab === 'costs' ? 'costs' : '',
        'q' => $extra['q'] ?? '',
        'status' => $extra['status'] ?? '',
        'price_min' => $extra['price_min'] ?? '',
        'price_max' => $extra['price_max'] ?? '',
        'from' => $extra['from'] ?? '',
        'to' => $extra['to'] ?? '',
        'method' => $extra['method'] ?? '',
        'page' => $extra['page'] ?? '',
    ], static fn($v) => $v !== null && $v !== '');
    return $qs ? ('payment.php?' . http_build_query($qs)) : 'payment.php';
}

function payment_shared_filter_clauses(
    string $search,
    $priceMin,
    $priceMax,
    ?array $fromFilter,
    ?array $toFilter,
    string $method = ''
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = $_POST['action'] ?? '';
    $orderId = trim($_POST['order_id'] ?? '');
    $redirect = payment_redirect_url($tab === 'pending' ? 'pending' : ($tab === 'costs' ? 'costs' : 'list'), [
        'q' => trim((string) ($_POST['q'] ?? '')),
        'status' => trim((string) ($_POST['status_filter'] ?? '')),
        'price_min' => isset($_POST['price_min']) && $_POST['price_min'] !== '' ? (int) $_POST['price_min'] : '',
        'price_max' => isset($_POST['price_max']) && $_POST['price_max'] !== '' ? (int) $_POST['price_max'] : '',
        'from' => trim((string) ($_POST['from'] ?? '')),
        'to' => trim((string) ($_POST['to'] ?? '')),
        'method' => trim((string) ($_POST['method'] ?? '')),
        'page' => !empty($_POST['page']) ? (int) $_POST['page'] : '',
    ]);

    if ($action === 'confirm' && $orderId !== '') {
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
            (string) ($_POST['new_status'] ?? ''),
            !empty($_POST['remove_product']),
            !empty($_POST['reject_invoice'])
        );
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
            'credit_wallet' => !empty($_POST['credit_wallet']),
        ]);
        flash($r['ok'] ? 'success' : 'error', $r['msg']);
        $redirect = $r['ok'] ? 'payment.php?status=manual' : 'payment.php';
    } elseif ($action === 'add_cost') {
        $r = panel_payment_add_cost($pdo, [
            'amount' => $_POST['amount'] ?? 0,
            'time' => $_POST['time'] ?? '',
            'id_order' => $_POST['id_order'] ?? '',
            'note' => $_POST['note'] ?? '',
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
    if ($method !== '') {
        $where[] = 'Payment_Method = ?';
        $params[] = $method;
    }
    panel_payment_append_time_range($where, $params, $fromFilter, $toFilter);
} else {
    $where[] = "payment_Status != 'cost'";
    if ($search !== '') {
        $where[] = "(`id_user` LIKE ? OR `id_order` LIKE ? OR COALESCE(`note`,'') LIKE ?)";
        $params = ["%$search%", "%$search%", "%$search%"];
    }
    if ($status === 'manual') {
        $where[] = "Payment_Method = 'manual invoice'";
    } elseif ($status !== '') {
        $where[] = "payment_Status = ?";
        $params[] = $status;
    }
    if ($method !== '') {
        $where[] = 'Payment_Method = ?';
        $params[] = $method;
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
    [$cardWhere, $cardParams] = payment_shared_filter_clauses($search, $priceMin, $priceMax, $fromFilter, $toFilter, $method);

    $successWhere = array_merge(["payment_Status = 'paid'"], $cardWhere);
    $successParams = $cardParams;
    if ($status === 'manual') {
        $successWhere[] = "Payment_Method = 'manual invoice'";
    }
    $successSQL = 'WHERE ' . implode(' AND ', $successWhere);
    $totalSuccess = (int) db_query(
        $pdo,
        "SELECT COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) FROM Payment_report $successSQL",
        $successParams
    )->fetchColumn();

    $costWhere = array_merge(["payment_Status = 'cost'"], $cardWhere);
    $costSQL = 'WHERE ' . implode(' AND ', $costWhere);
    $totalCosts = (int) db_query(
        $pdo,
        "SELECT COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) FROM Payment_report $costSQL",
        $cardParams
    )->fetchColumn();

    $forecastIncome = (int) round(forecast_monthly_paid_income($pdo));
    $todayWhere = $tab === 'costs' ? "payment_Status = 'cost'" : "payment_Status != 'cost'";
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
$cardsFiltered = $search !== '' || $priceMin !== null || $priceMax !== null || $fromFilter || $toFilter || $method !== '' || ($tab !== 'costs' && $status !== '');
$successMeta = $cardsFiltered ? 'بر اساس فیلترهای انتخاب‌شده' : 'از ابتدای فعالیت';
$costMeta = $cardsFiltered ? 'بر اساس فیلترهای انتخاب‌شده' : 'هزینه شده';
$netMeta = $cardsFiltered ? 'بر اساس فیلترهای انتخاب‌شده' : 'درآمد منهای هزینه';

$statusMap = [
    'paid' => ['tag-ok', 'پرداخت شده'],
    'Unpaid' => ['tag-no', 'پرداخت نشده'],
    'expire' => ['tag-plain', 'منقضی'],
    'reject' => ['tag-no', 'رد شده'],
    'refunded' => ['tag-no', 'مرجوعی'],
    'waiting' => ['tag-warn', 'در انتظار'],
    'cost' => ['tag-plain', 'هزینه شده'],
];
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
        $methodOptions[$key] = panel_payment_method_label($key);
    }
} catch (Exception $e) {
}
if (!isset($methodOptions['manual invoice'])) {
    $methodOptions['manual invoice'] = panel_payment_method_label('manual invoice');
}
if ($method !== '' && !isset($methodOptions[$method]) && $method !== 'cost') {
    $methodOptions[$method] = panel_payment_method_label($method);
}
asort($methodOptions, SORT_STRING);

$activeFilterCount = 0;
if ($tab !== 'pending') {
    if ($tab !== 'costs' && $status !== '') {
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
    if ($method !== '') {
        $activeFilterCount++;
    }
}
$clearFiltersUrl = $tab === 'costs' ? 'payment.php?tab=costs' : 'payment.php';
if ($search !== '') {
    $clearFiltersUrl .= (str_contains($clearFiltersUrl, '?') ? '&' : '?') . 'q=' . urlencode($search);
}

$pageTitle = 'مالی';
$pageLede = 'گزارش پرداخت‌ها، فاکتور دستی، هزینه‌ها و درآمد خالص.';
$activeNav = 'payment';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="display:flex;gap:4px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:4px;flex-wrap:wrap">
    <a href="payment.php" class="btn btn-sm <?= $tab === 'list' ? 'btn-primary' : 'btn-ghost' ?>">همه تراکنش‌ها</a>
    <a href="payment.php?tab=pending" class="btn btn-sm <?= $tab === 'pending' ? 'btn-primary' : 'btn-ghost' ?>">
      رسید در انتظار
      <?php if ($pendingCount > 0): ?>
        <span class="tag tag-warn" style="margin-right:6px;font-size:.7rem"><?= $pendingCount ?></span>
      <?php endif; ?>
    </a>
    <a href="payment.php?tab=costs" class="btn btn-sm <?= $tab === 'costs' ? 'btn-primary' : 'btn-ghost' ?>">هزینه‌ها</a>
  </div>
  <a href="payment_methods.php" class="btn btn-ghost btn-sm"><?= icon('settings', 14) ?> درگاه‌های پرداخت</a>
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
    <div class="stat-meta"><?= $tab === 'costs' ? 'رکورد هزینه' : 'رکورد تراکنش' ?></div>
  </div>
  <div class="stat warn">
    <div class="stat-label">امروز</div>
    <div class="stat-num"><?= number_format($todayCount) ?></div>
    <div class="stat-meta"><?= $tab === 'costs' ? 'هزینه امروز' : 'تراکنش جدید امروز' ?></div>
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
      } else {
          echo 'گزارش تراکنش‌ها';
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
        <button type="button" class="btn btn-ghost btn-sm" onclick="openModal('paymentFilterModal')">
          <?= icon('filter', 14) ?> فیلترها
          <?php if ($activeFilterCount > 0): ?>
            <span class="tag tag-info" style="margin-right:4px"><?= $activeFilterCount ?></span>
          <?php endif; ?>
        </button>
        <?php if ($tab === 'costs'): ?>
          <button type="button" class="btn btn-primary btn-sm" onclick="openModal('costModal')"><?= icon('plus', 14) ?> افزودن هزینه</button>
        <?php else: ?>
          <button type="button" class="btn btn-primary btn-sm" onclick="openModal('manualModal')"><?= icon('plus', 14) ?> افزودن فاکتور دستی</button>
        <?php endif; ?>
        <?php if ($search || $activeFilterCount > 0): ?>
          <a href="<?= $tab === 'costs' ? 'payment.php?tab=costs' : 'payment.php' ?>" class="btn btn-ghost btn-sm pay-toolbar-clear">پاک</a>
        <?php endif; ?>
      </div>
      <form method="GET" class="pay-toolbar-search">
        <?php if ($tab === 'costs'): ?>
          <input type="hidden" name="tab" value="costs">
        <?php endif; ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
        <input type="hidden" name="price_min" value="<?= $priceMin !== null ? (int) $priceMin : '' ?>">
        <input type="hidden" name="price_max" value="<?= $priceMax !== null ? (int) $priceMax : '' ?>">
        <input type="hidden" name="from" value="<?= htmlspecialchars($fromInput) ?>">
        <input type="hidden" name="to" value="<?= htmlspecialchars($toInput) ?>">
        <input type="hidden" name="method" value="<?= htmlspecialchars($method) ?>">
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
    <table class="tbl-lg">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>شناسه</th>
          <th>مبلغ</th>
          <th>روش پرداخت</th>
          <th>یادداشت</th>
          <th>تاریخ</th>
          <th>وضعیت</th>
          <th>عملیات</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($payments)): ?>
          <tr>
            <td colspan="9">
              <div class="empty">
                <div class="empty-mark">—</div>
                <p><?php
                if ($tab === 'pending') {
                    echo 'رسید در انتظاری نیست';
                } elseif ($tab === 'costs') {
                    echo 'هزینه‌ای ثبت نشده';
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
            if (($p['Payment_Method'] ?? '') === 'manual invoice') {
                [$cls, $lbl] = ['tag-mint', 'فاکتور دستی'];
            }
            $method = panel_payment_method_label($p['Payment_Method'] ?? '');
            $oid = $p['id_order'] ?? '';
            $hasProduct = strncmp((string) ($p['id_invoice'] ?? ''), 'getconfigafterpay|', 18) === 0;
            $uid = trim((string) ($p['id_user'] ?? ''));
            $note = trim((string) ($p['note'] ?? ''));
            ?>
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
              <td class="cell-strong cell-num"><?= number_format((int) ($p['price'] ?? 0)) ?> <span
                  style="color:var(--text-dim);font-weight:400;font-size:.72rem">ت</span></td>
              <td style="font-size:.8rem"><?= htmlspecialchars($method) ?></td>
              <td style="font-size:.78rem;max-width:180px" title="<?= htmlspecialchars($note) ?>">
                <?= $note !== '' ? htmlspecialchars(trunc($note, 40)) : '<span style="color:var(--text-dim)">—</span>' ?>
              </td>
              <td style="font-size:.78rem;color:var(--text-dim);white-space:nowrap">
                <?= safe_date($p['time'] ?? null, 'Y/m/d H:i') ?>
              </td>
              <td><span class="tag <?= $cls ?>"><?= $lbl ?></span></td>
              <td>
                <?php if ($tab === 'pending'): ?>
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
                <?php elseif ($tab === 'costs'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('این هزینه حذف شود؟')">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="delete_cost">
                  <input type="hidden" name="order_id" value="<?= htmlspecialchars($oid) ?>">
                  <button type="submit" class="btn btn-no btn-sm">حذف</button>
                </form>
                <?php else: ?>
                <button type="button" class="btn btn-ghost btn-sm"
                  onclick="openStatusModal(
                    '<?= htmlspecialchars($oid, ENT_QUOTES) ?>',
                    '<?= htmlspecialchars($st, ENT_QUOTES) ?>',
                    <?= $hasProduct ? 'true' : 'false' ?>
                  )">تغییر وضعیت</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="tbl-foot">
    <span><?= number_format($total) ?> رکورد · صفحه <?= $page ?> از <?= $totalPages ?></span>
    <div class="pager">
      <?php
      if ($tab === 'pending') {
          $base = 'payment.php?tab=pending';
      } else {
          $base = payment_redirect_url($tab, [
              'q' => $search,
              'status' => $status,
              'price_min' => $priceMin,
              'price_max' => $priceMax,
              'from' => $fromInput,
              'to' => $toInput,
              'method' => $method,
          ]);
      }
      $qs = fn($p) => $base . (str_contains($base, '?') ? '&' : '?') . 'page=' . $p;
      ?>
      <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
      <?php for ($p2 = max(1, $page - 2); $p2 <= min($totalPages, $page + 2); $p2++): ?>
        <a class="<?= $p2 === $page ? 'active' : '' ?>" href="<?= $qs($p2) ?>"><?= $p2 ?></a>
      <?php endfor; ?>
      <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
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
<?php elseif ($tab === 'costs'): ?>
<div class="modal-veil" id="costModal">
  <div class="modal">
    <div class="modal-head">
      <h3>افزودن هزینه</h3>
      <button type="button" class="modal-x" onclick="closeModal('costModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_cost">
        <div class="field" style="margin-bottom:14px">
          <label class="lbl">مبلغ (تومان)</label>
          <input type="number" name="amount" class="input" min="1" step="1" required placeholder="مثلاً ۵۰۰۰۰۰">
        </div>
        <div class="field" style="margin-bottom:14px">
          <label class="lbl">تاریخ</label>
          <input type="datetime-local" name="time" class="input">
        </div>
        <div class="field" style="margin-bottom:14px">
          <label class="lbl">شناسه</label>
          <input type="text" name="id_order" class="input" placeholder="خالی = تولید خودکار">
        </div>
        <div class="field">
          <label class="lbl">یادداشت</label>
          <textarea name="note" class="input" rows="3" placeholder="توضیح هزینه"></textarea>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary">ثبت هزینه</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('costModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>
<?php else: ?>
<div class="modal-veil" id="manualModal">
  <div class="modal">
    <div class="modal-head">
      <h3>افزودن فاکتور دستی</h3>
      <button type="button" class="modal-x" onclick="closeModal('manualModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_manual">
        <div class="field" style="margin-bottom:14px">
          <label class="lbl">مبلغ (تومان)</label>
          <input type="number" name="amount" class="input" min="1" step="1" required placeholder="مثلاً ۱۵۰۰۰۰">
        </div>
        <div class="field" style="margin-bottom:14px">
          <label class="lbl">تاریخ</label>
          <input type="datetime-local" name="time" class="input">
        </div>
        <div class="field" style="margin-bottom:14px">
          <label class="lbl">شناسه</label>
          <input type="text" name="id_order" class="input" placeholder="خالی = تولید خودکار">
        </div>
        <div class="field" style="margin-bottom:14px">
          <label class="lbl">کاربر</label>
          <input type="text" name="id_user" class="input" placeholder="آیدی تلگرام یا نام — اختیاری">
        </div>
        <div class="field" style="margin-bottom:14px">
          <label class="lbl">یادداشت</label>
          <textarea name="note" class="input" rows="3" placeholder="یادداشت ادمین"></textarea>
        </div>
        <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.6">
          <input type="checkbox" name="credit_wallet" value="1" style="width:16px;height:16px;margin-top:3px">
          <span>افزودن به کیف پول کاربر (فقط اگر آیدی کاربر ربات معتبر باشد)</span>
        </label>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary">ثبت فاکتور</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('manualModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>
<div class="modal-veil" id="statusModal">
  <div class="modal">
    <div class="modal-head">
      <h3>تغییر وضعیت پرداخت</h3>
      <button type="button" class="modal-x" onclick="closeModal('statusModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST" id="statusForm">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="set_status">
        <input type="hidden" name="order_id" id="statusOrderId" value="">
        <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
        <input type="hidden" name="status_filter" value="<?= htmlspecialchars($status) ?>">
        <input type="hidden" name="price_min" value="<?= $priceMin !== null ? (int) $priceMin : '' ?>">
        <input type="hidden" name="price_max" value="<?= $priceMax !== null ? (int) $priceMax : '' ?>">
        <input type="hidden" name="from" value="<?= htmlspecialchars($fromInput) ?>">
        <input type="hidden" name="to" value="<?= htmlspecialchars($toInput) ?>">
        <input type="hidden" name="method" value="<?= htmlspecialchars($method) ?>">
        <input type="hidden" name="page" value="<?= (int) $page ?>">
        <div class="field" style="margin-bottom:14px">
          <label class="lbl">وضعیت جدید</label>
          <select name="new_status" id="statusNewSelect" class="select" style="width:100%">
            <?php foreach ($listStatusMap as $k => [$_, $lbl]): ?>
              <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="rejectInvoiceWrap" style="display:none;margin-bottom:12px">
          <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.6">
            <input type="checkbox" name="reject_invoice" id="rejectInvoiceCheck" value="1" style="width:16px;height:16px;margin-top:3px">
            <span>وضعیت فاکتور/سفارش مرتبط هم «رد شده» شود؟</span>
          </label>
          <p style="font-size:.75rem;color:var(--mute);margin-top:8px;line-height:1.6">
            برای اینکه از آمار سفارشات تلگرام هم خارج شود.
          </p>
        </div>
        <div id="refundInvoiceNote" style="display:none;margin-bottom:12px">
          <p style="font-size:.8rem;color:var(--text);line-height:1.7;background:var(--sf);border:1px solid var(--bd);border-radius:8px;padding:10px 12px">
            این پرداخت «مرجوعی» می‌شود (بازگشت وجه به مشتری). سرویس در ربات حذف نمی‌شود.
          </p>
        </div>
        <div id="removeProductWrap" style="display:none">
          <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.6">
            <input type="checkbox" name="remove_product" id="removeProductCheck" value="1" style="width:16px;height:16px;margin-top:3px">
            <span id="removeProductLabel">سرویس ساخته‌شده برای این پرداخت هم حذف شود؟</span>
          </label>
          <p id="removeProductHint" style="font-size:.75rem;color:var(--mute);margin-top:8px;line-height:1.6">
            فقط برای خرید سرویس (نه تمدید/شارژ کیف پول). در صورت انتخاب، سرویس از پنل و ربات حذف می‌شود.
          </p>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary">ذخیره وضعیت</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('statusModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var currentStatus = '';
  var hasProduct = false;
  var selectEl = document.getElementById('statusNewSelect');
  var wrap = document.getElementById('removeProductWrap');
  var check = document.getElementById('removeProductCheck');
  var label = document.getElementById('removeProductLabel');
  var hint = document.getElementById('removeProductHint');
  var rejectWrap = document.getElementById('rejectInvoiceWrap');
  var rejectCheck = document.getElementById('rejectInvoiceCheck');
  var refundNote = document.getElementById('refundInvoiceNote');

  function syncRejectPrompts() {
    var leavingPaidToReject = currentStatus === 'paid' && selectEl.value === 'reject';
    var toRefund = selectEl.value === 'refunded';
    rejectWrap.style.display = leavingPaidToReject ? 'block' : 'none';
    if (!leavingPaidToReject) rejectCheck.checked = false;
    if (refundNote) refundNote.style.display = toRefund ? 'block' : 'none';

    var showRemove = hasProduct && (leavingPaidToReject || toRefund);
    wrap.style.display = showRemove ? 'block' : 'none';
    if (toRefund && hasProduct) {
      check.checked = true;
      label.textContent = 'سرویس در پنل ساب‌لینک و ربات غیرفعال شود؟';
      hint.textContent = 'کاربر از ساب‌لینک قطع می‌شود. رکورد سفارش باقی می‌ماند و وضعیت سرویس «غیرفعال توسط ادمین» می‌شود.';
    } else if (leavingPaidToReject && hasProduct) {
      check.checked = false;
      label.textContent = 'سرویس ساخته‌شده برای این پرداخت هم حذف شود؟';
      hint.textContent = 'فقط برای خرید سرویس (نه تمدید/شارژ کیف پول). در صورت انتخاب، سرویس از پنل و ربات حذف می‌شود.';
    } else {
      check.checked = false;
    }
  }

  window.openStatusModal = function (orderId, status, product) {
    currentStatus = status || '';
    hasProduct = !!product;
    document.getElementById('statusOrderId').value = orderId;
    selectEl.value = currentStatus;
    syncRejectPrompts();
    openModal('statusModal');
  };

  selectEl.addEventListener('change', syncRejectPrompts);
})();
</script>
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
        <?php if ($tab === 'costs'): ?>
          <input type="hidden" name="tab" value="costs">
        <?php endif; ?>
        <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
        <div class="form-grid">
          <?php if ($tab !== 'costs'): ?>
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
            <input type="datetime-local" name="from" class="input" value="<?= htmlspecialchars($fromInput) ?>">
          </div>
          <div class="field">
            <label class="lbl">تا تاریخ و ساعت</label>
            <input type="datetime-local" name="to" class="input" value="<?= htmlspecialchars($toInput) ?>">
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
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
