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
    if ($tab === 'costs') {
        $qs = array_filter([
            'tab' => 'costs',
            'q' => $extra['q'] ?? '',
            'price_min' => $extra['price_min'] ?? '',
            'price_max' => $extra['price_max'] ?? '',
            'page' => $extra['page'] ?? '',
        ], static fn($v) => $v !== null && $v !== '');
        return 'payment.php?' . http_build_query($qs);
    }
    $qs = array_filter([
        'q' => $extra['q'] ?? '',
        'status' => $extra['status'] ?? '',
        'price_min' => $extra['price_min'] ?? '',
        'price_max' => $extra['price_max'] ?? '',
        'page' => $extra['page'] ?? '',
    ], static fn($v) => $v !== null && $v !== '');
    return $qs ? ('payment.php?' . http_build_query($qs)) : 'payment.php';
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
        $redirect = 'payment.php';
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
    if ($priceMin !== null) {
        $where[] = 'CAST(price AS DECIMAL(20,0)) >= ?';
        $params[] = $priceMin;
    }
    if ($priceMax !== null) {
        $where[] = 'CAST(price AS DECIMAL(20,0)) <= ?';
        $params[] = $priceMax;
    }
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$orderSQL = "ORDER BY time DESC";

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
    $totalSuccess = (int) db_query($pdo, "SELECT COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) FROM Payment_report WHERE payment_Status ='paid'")->fetchColumn();
    $totalCosts = (int) db_query($pdo, "SELECT COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) FROM Payment_report WHERE payment_Status ='cost'")->fetchColumn();
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

$statusMap = [
    'paid' => ['tag-ok', 'پرداخت شده'],
    'Unpaid' => ['tag-no', 'پرداخت نشده'],
    'expire' => ['tag-plain', 'منقضی'],
    'reject' => ['tag-no', 'رد شده'],
    'waiting' => ['tag-warn', 'در انتظار'],
    'cost' => ['tag-plain', 'هزینه شده'],
];
$listStatusMap = $statusMap;
unset($listStatusMap['cost']);
$filterStatusMap = [
    'paid' => $listStatusMap['paid'],
    'manual' => ['tag-ok', 'فاکتور دستی'],
] + $listStatusMap;

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
    <div class="stat-meta">از ابتدای فعالیت</div>
  </div>
  <div class="stat">
    <div class="stat-label">درآمد پیش‌بینی‌شده ماهانه</div>
    <div class="stat-num"><?= number_format($forecastIncome) ?><small>تومان</small></div>
    <div class="stat-meta">بر اساس ۲۸ روز اخیر</div>
  </div>
  <div class="stat warn">
    <div class="stat-label">جمع هزینه‌ها</div>
    <div class="stat-num"><?= number_format($totalCosts) ?><small>تومان</small></div>
    <div class="stat-meta">هزینه شده</div>
  </div>
  <div class="stat <?= $netIncome >= 0 ? 'ok' : 'no' ?>">
    <div class="stat-label">درآمد خالص</div>
    <div class="stat-num"><?= number_format($netIncome) ?><small>تومان</small></div>
    <div class="stat-meta">درآمد منهای هزینه</div>
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
    <?php elseif ($tab === 'costs'): ?>
    <div class="toolbar-end" style="flex-wrap:wrap;gap:8px">
      <form method="GET" class="toolbar-end" style="flex-wrap:wrap;gap:8px">
        <input type="hidden" name="tab" value="costs">
        <input type="number" name="price_min" class="select" style="width:120px" min="0" step="1"
          placeholder="حداقل مبلغ"
          value="<?= $priceMin !== null ? (int) $priceMin : '' ?>">
        <input type="number" name="price_max" class="select" style="width:120px" min="0" step="1"
          placeholder="حداکثر مبلغ"
          value="<?= $priceMax !== null ? (int) $priceMax : '' ?>">
        <div class="search-box" style="min-width:230px">
          <?= icon('search', 14) ?>
          <input type="text" name="q" placeholder="شناسه، یادداشت..."
            value="<?= htmlspecialchars($search) ?>">
          <button type="button" class="search-clear">✕</button>
          <button type="submit" class="search-btn">جستجو</button>
        </div>
        <?php if ($search || $priceMin !== null || $priceMax !== null): ?>
          <a href="payment.php?tab=costs" class="btn-link" style="font-size:.78rem">پاک</a>
        <?php endif; ?>
      </form>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('costModal')"><?= icon('plus', 14) ?> افزودن هزینه</button>
    </div>
    <?php elseif ($tab !== 'pending'): ?>
    <div class="toolbar-end" style="flex-wrap:wrap;gap:8px">
      <form method="GET" class="toolbar-end" style="flex-wrap:wrap;gap:8px">
        <select name="status" class="select" style="width:auto" onchange="this.form.submit()">
          <option value="">همه وضعیت‌ها</option>
          <?php foreach ($filterStatusMap as $k => [$_, $lbl]): ?>
            <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <input type="number" name="price_min" class="select" style="width:120px" min="0" step="1"
          placeholder="حداقل مبلغ"
          value="<?= $priceMin !== null ? (int) $priceMin : '' ?>">
        <input type="number" name="price_max" class="select" style="width:120px" min="0" step="1"
          placeholder="حداکثر مبلغ"
          value="<?= $priceMax !== null ? (int) $priceMax : '' ?>">
        <div class="search-box" style="min-width:230px">
          <?= icon('search', 14) ?>
          <input type="text" name="q" placeholder="آیدی کاربر، شماره تراکنش یا یادداشت..."
            value="<?= htmlspecialchars($search) ?>">
          <button type="button" class="search-clear">✕</button>
          <button type="submit" class="search-btn">جستجو</button>
        </div>
        <?php if ($search || $status || $priceMin !== null || $priceMax !== null): ?>
          <a href="payment.php" class="btn-link" style="font-size:.78rem">پاک</a>
        <?php endif; ?>
      </form>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('manualModal')"><?= icon('plus', 14) ?> افزودن فاکتور دستی</button>
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
                [$cls, $lbl] = ['tag-ok', 'فاکتور دستی'];
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
      } elseif ($tab === 'costs') {
          $base = 'payment.php?' . http_build_query(array_filter([
              'tab' => 'costs',
              'q' => $search,
              'price_min' => $priceMin,
              'price_max' => $priceMax,
          ], static fn($v) => $v !== null && $v !== ''));
      } else {
          $base = 'payment.php?' . http_build_query(array_filter([
              'q' => $search,
              'status' => $status,
              'price_min' => $priceMin,
              'price_max' => $priceMax,
          ], static fn($v) => $v !== null && $v !== ''));
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
        <div id="removeProductWrap" style="display:none">
          <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.6">
            <input type="checkbox" name="remove_product" id="removeProductCheck" value="1" style="width:16px;height:16px;margin-top:3px">
            <span>سرویس ساخته‌شده برای این پرداخت هم حذف شود؟</span>
          </label>
          <p style="font-size:.75rem;color:var(--mute);margin-top:8px;line-height:1.6">
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
  var rejectWrap = document.getElementById('rejectInvoiceWrap');
  var rejectCheck = document.getElementById('rejectInvoiceCheck');

  function syncRejectPrompts() {
    var leavingPaidToReject = currentStatus === 'paid' && selectEl.value === 'reject';
    rejectWrap.style.display = leavingPaidToReject ? 'block' : 'none';
    if (!leavingPaidToReject) rejectCheck.checked = false;
    var showRemove = hasProduct && leavingPaidToReject;
    wrap.style.display = showRemove ? 'block' : 'none';
    if (!showRemove) check.checked = false;
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

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
