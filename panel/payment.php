<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_auth();

$tab = $_GET['tab'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = $_POST['action'] ?? '';
    $orderId = trim($_POST['order_id'] ?? '');
    $redirect = 'payment.php?tab=' . ($tab === 'pending' ? 'pending' : 'list');
    if ($tab !== 'pending') {
        $qs = [];
        if (!empty($_POST['q'])) {
            $qs['q'] = trim((string) $_POST['q']);
        }
        if (!empty($_POST['status_filter'])) {
            $qs['status'] = trim((string) $_POST['status_filter']);
        }
        if (isset($_POST['price_min']) && $_POST['price_min'] !== '') {
            $qs['price_min'] = (int) $_POST['price_min'];
        }
        if (isset($_POST['price_max']) && $_POST['price_max'] !== '') {
            $qs['price_max'] = (int) $_POST['price_max'];
        }
        if (!empty($_POST['page'])) {
            $qs['page'] = (int) $_POST['page'];
        }
        if ($qs) {
            $redirect = 'payment.php?' . http_build_query($qs);
        }
    }

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
} else {
    if ($search !== '') {
        $where[] = "(`id_user` LIKE ? OR `id_order` LIKE ?)";
        $params = ["%$search%", "%$search%"];
    }
    if ($status !== '') {
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

$totalSuccess = 0;
$todayCount = 0;
$pendingCount = 0;
try {
    $totalSuccess = (int) db_query($pdo, "SELECT COALESCE(SUM(price),0) FROM Payment_report WHERE payment_Status ='paid'")->fetchColumn();
    $todayCount = db_count($pdo, "SELECT COUNT(*) FROM Payment_report WHERE time > ?", [strtotime('today')]);
    $pendingCount = db_count($pdo, "SELECT COUNT(*) FROM Payment_report WHERE Payment_Method = 'cart to cart' AND payment_Status = 'waiting'");
} catch (Exception $e) {
}

$statusMap = [
    'paid' => ['tag-ok', 'پرداخت شده'],
    'Unpaid' => ['tag-no', 'پرداخت نشده'],
    'expire' => ['tag-plain', 'منقضی'],
    'reject' => ['tag-no', 'رد شده'],
    'waiting' => ['tag-warn', 'در انتظار'],
];

$pageTitle = 'تراکنش‌ها';
$pageLede = 'گزارش پرداخت‌ها و تأیید رسید کارت‌به‌کارت (مثل «💵 رسیدهای تأیید نشده» در ربات).';
$activeNav = 'payment';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="display:flex;gap:4px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:4px">
    <a href="payment.php" class="btn btn-sm <?= $tab !== 'pending' ? 'btn-primary' : 'btn-ghost' ?>">همه تراکنش‌ها</a>
    <a href="payment.php?tab=pending" class="btn btn-sm <?= $tab === 'pending' ? 'btn-primary' : 'btn-ghost' ?>">
      رسید در انتظار
      <?php if ($pendingCount > 0): ?>
        <span class="tag tag-warn" style="margin-right:6px;font-size:.7rem"><?= $pendingCount ?></span>
      <?php endif; ?>
    </a>
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
    <div class="stat-label">تعداد کل</div>
    <div class="stat-num"><?= number_format($total) ?></div>
    <div class="stat-meta">رکورد تراکنش</div>
  </div>
  <div class="stat warn">
    <div class="stat-label">امروز</div>
    <div class="stat-num"><?= number_format($todayCount) ?></div>
    <div class="stat-meta">تراکنش جدید امروز</div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="toolbar">
    <div class="toolbar-title">
      <?= $tab === 'pending' ? 'رسیدهای کارت‌به‌کارت در انتظار' : 'گزارش تراکنش‌ها' ?>
      <small>(<?= number_format($total) ?>)</small>
    </div>
    <?php if ($tab === 'pending' && $total > 0): ?>
      <form method="POST" onsubmit="return confirm('همه رسیدهای در انتظار رد شوند؟')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="reject_all">
        <button type="submit" class="btn btn-no btn-sm">حذف همه</button>
      </form>
    <?php elseif ($tab !== 'pending'): ?>
    <form method="GET" class="toolbar-end" style="flex-wrap:wrap;gap:8px">
      <select name="status" class="select" style="width:auto" onchange="this.form.submit()">
        <option value="">همه وضعیت‌ها</option>
        <?php foreach ($statusMap as $k => [$_, $lbl]): ?>
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
        <input type="text" name="q" placeholder="آیدی کاربر یا شماره تراکنش..."
          value="<?= htmlspecialchars($search) ?>">
        <button type="button" class="search-clear">✕</button>
        <button type="submit" class="search-btn">جستجو</button>
      </div>
      <?php if ($search || $status || $priceMin !== null || $priceMax !== null): ?>
        <a href="payment.php" class="btn-link" style="font-size:.78rem">پاک</a>
      <?php endif; ?>
    </form>
    <?php endif; ?>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-lg">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>شناسه تراکنش</th>
          <th>مبلغ</th>
          <th>روش پرداخت</th>
          <th>تاریخ</th>
          <th>وضعیت</th>
          <th>عملیات</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($payments)): ?>
          <tr>
            <td colspan="8">
              <div class="empty">
                <div class="empty-mark">—</div>
                <p><?= $tab === 'pending' ? 'رسید در انتظاری نیست' : 'تراکنشی یافت نشد' ?></p>
              </div>
            </td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($payments as $p):
            $st = $p['payment_Status'] ?? '';
            [$cls, $lbl] = $statusMap[$st] ?? ['tag-plain', $st ?: '—'];
            $method = panel_payment_method_label($p['Payment_Method'] ?? '');
            $oid = $p['id_order'] ?? '';
            $hasProduct = strncmp((string) ($p['id_invoice'] ?? ''), 'getconfigafterpay|', 18) === 0;
            ?>
            <tr>
              <td style="color:var(--text-dim)"><?= $i++ ?></td>
              <td>
                <a href="user.php?id=<?= (int) ($p['id_user'] ?? 0) ?>" class="cell-mono" style="color:var(--accent)">
                  <?= htmlspecialchars($p['id_user'] ?? '—') ?>
                </a>
              </td>
              <td class="cell-mono" style="color:var(--accent);font-size:.78rem">
                <?= htmlspecialchars(trunc((string) $oid, 22)) ?>
              </td>
              <td class="cell-strong cell-num"><?= number_format((int) ($p['price'] ?? 0)) ?> <span
                  style="color:var(--text-dim);font-weight:400;font-size:.72rem">ت</span></td>
              <td style="font-size:.8rem"><?= htmlspecialchars($method) ?></td>
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
<?php else: ?>
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
            <?php foreach ($statusMap as $k => [$_, $lbl]): ?>
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
