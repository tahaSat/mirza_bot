<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_once __DIR__ . '/inc/users_lib.php';
require_once dirname(__DIR__) . '/jdf.php';
require_auth();
$pdo = panel_ensure_pdo();
panel_migrate_unpaid_status_case($pdo);

$search = trim($_GET['q'] ?? '');

$tab = ($_GET['tab'] ?? 'orders') === 'payments' ? 'payments' : 'orders';
$status = $_GET['status'] ?? '';
if ($tab === 'orders' && $status === 'Unpaid') {
  $status = 'unpaid';
}
$serviceType = $_GET['service_type'] ?? '';
$fromDateTime = trim($_GET['from'] ?? '');
$toDateTime = trim($_GET['to'] ?? '');
$productFilter = trim($_GET['product'] ?? '');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check_post();
  $action = $_POST['action'] ?? '';

  $redirectTab = (($_POST['redirect_tab'] ?? '') === 'payments') ? 'payments' : 'orders';
  $redirectQs = [
    'tab' => $redirectTab,
    'q' => trim((string) ($_POST['q'] ?? $search)),
    'status' => trim((string) ($_POST['status_filter'] ?? $status)),
    'service_type' => trim((string) ($_POST['service_type'] ?? $serviceType)),
    'product' => trim((string) ($_POST['product'] ?? ($_GET['product'] ?? ''))),
    'price_min' => trim((string) ($_POST['price_min'] ?? ($_GET['price_min'] ?? ''))),
    'price_max' => trim((string) ($_POST['price_max'] ?? ($_GET['price_max'] ?? ''))),
    'from' => trim((string) ($_POST['from'] ?? $fromDateTime)),
    'to' => trim((string) ($_POST['to'] ?? $toDateTime)),
    'page' => (int) ($_POST['page'] ?? $page),
  ];

  if ($action === 'set_status') {
    $orderId = trim((string) ($_POST['order_id'] ?? ''));
    $redirectQs['tab'] = 'payments';
    if ($orderId !== '') {
      $r = panel_payment_set_status(
        $pdo,
        $orderId,
        (string) ($_POST['new_status'] ?? ''),
        !empty($_POST['remove_product']),
        !empty($_POST['reject_invoice'])
      );
      flash($r['ok'] ? 'success' : 'error', $r['msg']);
    }
  } elseif ($action === 'update_invoice') {
    $redirectQs['tab'] = 'orders';
    $idInvoice = trim((string) ($_POST['record_id'] ?? ''));
    $r = panel_update_invoice_record($pdo, $idInvoice, [
      'Status' => $_POST['invoice_status'] ?? '',
      'name_product' => $_POST['name_product'] ?? '',
      'price_product' => $_POST['price_product'] ?? '',
      'Volume' => $_POST['Volume'] ?? '',
      'Service_time' => $_POST['Service_time'] ?? '',
      'username' => $_POST['username'] ?? '',
      'note' => $_POST['note'] ?? '',
      'Service_location' => $_POST['Service_location'] ?? '',
    ]);
    flash($r['ok'] ? 'success' : 'error', $r['msg']);
  } elseif ($action === 'update_service_other') {
    $redirectQs['tab'] = 'orders';
    $r = panel_update_service_other_record($pdo, (int) ($_POST['record_id'] ?? 0), [
      'status' => $_POST['invoice_status'] ?? '',
      'value' => $_POST['name_product'] ?? '',
      'price' => $_POST['price_product'] ?? '',
      'username' => $_POST['username'] ?? '',
    ]);
    flash($r['ok'] ? 'success' : 'error', $r['msg']);
  }

  header('Location: invoice.php?' . http_build_query(array_filter($redirectQs, static fn($v) => $v !== '' && $v !== 0)));
  exit;
}

/**
 * Convert a Jalali date entered in Tehran local time to a UTC Unix timestamp.
 * Unix timestamps are timezone-neutral, so this can be compared directly with
 * UTC timestamps stored in the database.
 */
function invoice_jalali_tehran_timestamp(string $date, string $time, bool $endOfDay = false): ?int
{
  return jalali_tehran_timestamp($date, $time, $endOfDay);
}

function invoice_parse_jalali_filter(string $value, bool $endOfDay = false): ?int
{
  return jalali_tehran_parse($value, $endOfDay);
}

$fromTimestamp = null;
$toTimestamp = null;
$dateFilterError = '';
if ($fromDateTime !== '') {
  $fromTimestamp = invoice_parse_jalali_filter($fromDateTime);
  if ($fromTimestamp === null) {
    $dateFilterError = 'تاریخ و ساعت شروع معتبر نیست.';
  }
}
if ($toDateTime !== '') {
  $toTimestamp = invoice_parse_jalali_filter($toDateTime, true);
  if ($toTimestamp === null) {
    $dateFilterError = 'تاریخ و ساعت پایان معتبر نیست.';
  }
}
if ($dateFilterError === '' && $fromTimestamp !== null && $toTimestamp !== null && $fromTimestamp > $toTimestamp) {
  $dateFilterError = 'زمان شروع باید قبل از زمان پایان باشد.';
}
if ($dateFilterError !== '') {
  flash('error', $dateFilterError);
  $fromTimestamp = $toTimestamp = null;
}

$serviceTypeMap = [
  'order' => 'خرید سرویس',
  'change_location' => 'تغییر لوکیشن',
  'extra_user' => 'افزایش حجم',
  'extra_time_user' => 'افزایش زمان',
  'extend_user' => 'تمدید',
  'extend_user_by_admin' => 'تمدید توسط ادمین',
  'transfertouser' => 'انتقال سفارش به کاربر دیگر',
];
$serviceTypeLabelMap = $serviceTypeMap + [
  'extends_not_user' => 'تمدید',
];

$paymentServiceTypeMap = [
  'order' => 'خرید سرویس',
  'extend_user' => 'تمدید',
  'extend_user_by_admin' => 'تمدید توسط ادمین',
  'extra_user' => 'افزایش حجم',
  'extra_time_user' => 'افزایش زمان',
  'wallet' => 'شارژ کیف پول',
];

$orderStatusMap = [
  'active' => ['tag-ok', 'فعال'],
  'end_of_time' => ['tag-warn', 'اعلان پایان زمان'],
  'end_of_volume' => ['tag-no', 'اعلان پایان حجم'],
  'sendedwarn' => ['tag-warn', 'ارسال تمامی اعلان ها'],
  'send_on_hold' => ['tag-plain', 'اعلان متصنل نشدن ارسال شده'],
  'unpaid' => ['tag-plain', 'پرداخت نشده'],
  'Unsuccessful' => ['tag-plain', 'خطا دریافت اطلاعات'],
  'paid' => ['tag-ok', 'پرداخت شده'],
  'done' => ['tag-ok', 'انجام شده'],
  'pending' => ['tag-warn', 'در انتظار'],
  'reject' => ['tag-no', 'رد شده'],
  'removebyadmin' => ['tag-no', 'حذف توسط ادمین'],
  'removedbyadmin' => ['tag-no', 'حذف با تایید ادمین'],
  'disablebyadmin' => ['tag-no', 'غیرفعال توسط ادمین'],
];
$orderStatusLabelMap = $orderStatusMap;
$orderStatusLabelMap['Unpaid'] = ['tag-plain', 'پرداخت نشده'];

$paymentStatusMap = [
  'paid' => ['tag-ok', 'پرداخت شده'],
  'Unpaid' => ['tag-no', 'پرداخت نشده'],
  'waiting' => ['tag-warn', 'در انتظار تأیید'],
  'reject' => ['tag-no', 'رد شده'],
  'expire' => ['tag-plain', 'منقضی'],
];

if ($tab === 'payments') {
  $recordsSQL = "
    SELECT id_user, id_order, price, Payment_Method AS payment_method,
           id_invoice, time AS transaction_time,
           CASE
             WHEN time REGEXP '^[0-9]{9,}$' THEN CAST(time AS UNSIGNED)
             ELSE COALESCE(
               UNIX_TIMESTAMP(STR_TO_DATE(time, '%Y-%m-%d %H:%i:%s')),
               UNIX_TIMESTAMP(STR_TO_DATE(time, '%Y/%m/%d %H:%i:%s'))
             )
           END AS transaction_epoch,
           payment_Status AS transaction_status,
           CASE
             WHEN Payment_Method = 'extend by admin' THEN 'extend_user_by_admin'
             WHEN id_invoice LIKE 'getconfigafterpay|%' THEN 'order'
             WHEN id_invoice LIKE 'getextenduser|%' THEN 'extend_user'
             WHEN id_invoice LIKE 'getextravolumeuser|%' THEN 'extra_user'
             WHEN id_invoice LIKE 'getextratimeuser|%' THEN 'extra_time_user'
             ELSE 'wallet'
           END AS service_type
    FROM Payment_report
    WHERE payment_Status != 'cost'
  ";
} else {
  // Normalize string collations — invoice vs service_other (and string literals) often differ.
  $recordsSQL = "
    SELECT
      CONVERT(id_invoice USING utf8mb4) COLLATE utf8mb4_unicode_ci AS record_id,
      CONVERT(id_user USING utf8mb4) COLLATE utf8mb4_unicode_ci AS id_user,
      CONVERT(username USING utf8mb4) COLLATE utf8mb4_unicode_ci AS username,
      CONVERT(name_product USING utf8mb4) COLLATE utf8mb4_unicode_ci AS product_name,
      CONVERT(price_product USING utf8mb4) COLLATE utf8mb4_unicode_ci AS price,
      CONVERT(time_sell USING utf8mb4) COLLATE utf8mb4_unicode_ci AS transaction_time,
      CAST(time_sell AS UNSIGNED) AS transaction_epoch,
      CONVERT(Status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS transaction_status,
      CONVERT('order' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS service_type,
      CONVERT('invoice' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS record_source,
      CONVERT(COALESCE(Volume,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS volume,
      CONVERT(COALESCE(Service_time,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS service_time,
      CONVERT(COALESCE(note,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS note,
      CONVERT(COALESCE(Service_location,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS service_location
    FROM invoice
    UNION ALL
    SELECT
      CONVERT(CAST(id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS record_id,
      CONVERT(id_user USING utf8mb4) COLLATE utf8mb4_unicode_ci AS id_user,
      CONVERT(username USING utf8mb4) COLLATE utf8mb4_unicode_ci AS username,
      CONVERT(value USING utf8mb4) COLLATE utf8mb4_unicode_ci AS product_name,
      CONVERT(price USING utf8mb4) COLLATE utf8mb4_unicode_ci AS price,
      CONVERT(time USING utf8mb4) COLLATE utf8mb4_unicode_ci AS transaction_time,
      CASE
        WHEN time REGEXP '^[0-9]{9,}$' THEN CAST(time AS UNSIGNED)
        ELSE COALESCE(
          UNIX_TIMESTAMP(STR_TO_DATE(time, '%Y-%m-%d %H:%i:%s')),
          UNIX_TIMESTAMP(STR_TO_DATE(time, '%Y/%m/%d %H:%i:%s'))
        )
      END AS transaction_epoch,
      CONVERT(status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS transaction_status,
      CONVERT(type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS service_type,
      CONVERT('service_other' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS record_source,
      CONVERT('' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS volume,
      CONVERT('' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS service_time,
      CONVERT('' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS note,
      CONVERT('' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS service_location
    FROM service_other
  ";
}

$where = [];
$params = [];
if ($search !== '') {
  if ($tab === 'payments') {
    $where[] = "(id_user LIKE ? OR COALESCE(id_order,'') LIKE ? OR COALESCE(payment_method,'') LIKE ? OR COALESCE(id_invoice,'') LIKE ?)";
  } else {
    $where[] = "(id_user LIKE ? OR COALESCE(product_name,'') LIKE ? OR COALESCE(username,'') LIKE ? OR COALESCE(service_type,'') LIKE ?)";
  }
  $params = array_fill(0, 4, "%$search%");
}
if ($status !== '') {
  $where[] = "transaction_status = ?";
  $params[] = $status;
}
if ($serviceType !== '') {
  $where[] = "service_type = ?";
  $params[] = $serviceType;
}
if ($fromTimestamp !== null) {
  $where[] = "transaction_epoch >= ?";
  $params[] = $fromTimestamp;
}
if ($toTimestamp !== null) {
  $where[] = "transaction_epoch <= ?";
  $params[] = $toTimestamp;
}
if ($tab === 'orders') {
  if ($productFilter !== '') {
    $where[] = "product_name = ?";
    $params[] = $productFilter;
  }
  if ($priceMin !== null) {
    $where[] = "CAST(price AS DECIMAL(20,0)) >= ?";
    $params[] = $priceMin;
  }
  if ($priceMax !== null) {
    $where[] = "CAST(price AS DECIMAL(20,0)) <= ?";
    $params[] = $priceMax;
  }
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
  // Date strings in service_other are UTC; make MySQL interpret them as UTC.
  $pdo->exec("SET time_zone = '+00:00'");
  $total = db_count($pdo, "SELECT COUNT(*) FROM ($recordsSQL) AS records $whereSQL", $params);
  $invoices = db_fetchAll($pdo, "SELECT * FROM ($recordsSQL) AS records $whereSQL ORDER BY transaction_epoch DESC LIMIT $perPage OFFSET $offset", $params);
} catch (Exception $e) {
  $total = 0;
  $invoices = [];
  flash('error', 'خطای پایگاه داده: ' . $e->getMessage());
}
$totalPages = max(1, (int) ceil($total / $perPage));

$statusMap = $tab === 'payments' ? $paymentStatusMap : $orderStatusMap;
$statusLabelMap = $tab === 'payments' ? $paymentStatusMap : $orderStatusLabelMap;
$activeServiceTypeMap = $tab === 'payments' ? $paymentServiceTypeMap : $serviceTypeMap;
$activeServiceTypeLabelMap = $tab === 'payments' ? $paymentServiceTypeMap : $serviceTypeLabelMap;

$productOptions = [];
try {
  $productOptions = db_fetchAll(
    $pdo,
    "SELECT name_product FROM (
       SELECT DISTINCT name_product FROM product WHERE name_product IS NOT NULL AND name_product != ''
       UNION
       SELECT DISTINCT name_product FROM invoice WHERE name_product IS NOT NULL AND name_product != ''
     ) AS products ORDER BY name_product"
  );
} catch (Exception $e) {
  $productOptions = [];
}

$pageTitle = 'سفارشات';
$pageLede = 'فهرست کلیه سفارشات ثبت‌شده در ربات.';
$activeNav = 'invoice';
include __DIR__ . '/inc/layout_head.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css">

<div style="display:flex;gap:4px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:4px;width:max-content;max-width:100%;margin-bottom:14px" class="fade-up">
  <a href="invoice.php?tab=orders" class="btn btn-sm <?= $tab === 'orders' ? 'btn-primary' : 'btn-ghost' ?>">سفارشات جاری</a>
  <a href="invoice.php?tab=payments" class="btn btn-sm <?= $tab === 'payments' ? 'btn-primary' : 'btn-ghost' ?>">گزارش پرداخت‌ها</a>
</div>

<div class="card fade-up">
  <div class="toolbar">
    <div class="toolbar-title">
      <?= $tab === 'payments' ? 'گزارش پرداخت‌ها' : 'سفارشات جاری' ?>
      <small>(<?= number_format($total) ?>)</small>
    </div>
    <form method="GET" id="invoiceForm" class="toolbar-end" style="flex-wrap:wrap;gap:8px">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
      <select name="service_type" class="select" style="width:auto"
        onchange="document.getElementById('invoiceForm').submit()">
        <option value="">همه نوع سرویس‌ها</option>
        <?php foreach ($activeServiceTypeMap as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= $serviceType === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" class="select" style="width:auto"
        onchange="document.getElementById('invoiceForm').submit()">
        <option value="">همه وضعیت‌ها</option>
        <?php foreach ($statusMap as $k => [$_, $lbl]): ?>
          <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($tab === 'orders'): ?>
      <select name="product" class="select" style="width:auto;max-width:180px"
        onchange="document.getElementById('invoiceForm').submit()">
        <option value="">همه محصولات</option>
        <?php foreach ($productOptions as $pRow):
          $pname = $pRow['name_product'] ?? '';
          if ($pname === '') continue;
        ?>
          <option value="<?= htmlspecialchars($pname) ?>" <?= $productFilter === $pname ? 'selected' : '' ?>>
            <?= htmlspecialchars(trunc($pname, 28)) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="number" name="price_min" class="select" style="width:110px" min="0" step="1"
        placeholder="حداقل مبلغ"
        value="<?= $priceMin !== null ? (int) $priceMin : '' ?>">
      <input type="number" name="price_max" class="select" style="width:110px" min="0" step="1"
        placeholder="حداکثر مبلغ"
        value="<?= $priceMax !== null ? (int) $priceMax : '' ?>">
      <?php endif; ?>
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
        <label style="font-size:.76rem;color:var(--text2)">از</label>
        <div style="position:relative">
          <input class="select jalali-datetime-picker" style="width:180px;padding-left:30px" type="text" name="from"
            placeholder="انتخاب تاریخ و ساعت" value="<?= htmlspecialchars($fromDateTime) ?>"
            aria-label="تاریخ و ساعت شروع شمسی به وقت تهران" autocomplete="off" readonly>
          <span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);pointer-events:none">🗓</span>
        </div>
        <label style="font-size:.76rem;color:var(--text2)">تا</label>
        <div style="position:relative">
          <input class="select jalali-datetime-picker" style="width:180px;padding-left:30px" type="text" name="to"
            placeholder="انتخاب تاریخ و ساعت" value="<?= htmlspecialchars($toDateTime) ?>"
            aria-label="تاریخ و ساعت پایان شمسی به وقت تهران" autocomplete="off" readonly>
          <span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);pointer-events:none">🗓</span>
        </div>
      </div>
      <div class="search-box" style="min-width:240px">
        <?= icon('search', 14) ?>
        <input type="text" name="q"
          placeholder="<?= $tab === 'payments' ? 'آیدی کاربر یا شناسه تراکنش...' : 'آیدی کاربر، نام محصول...' ?>"
          value="<?= htmlspecialchars($search) ?>"
          autocomplete="off">
        <button type="button" class="search-clear">✕</button>
        <button type="submit" class="search-btn">جستجو</button>
      </div>
      <?php if ($search || $status || $serviceType || $fromDateTime || $toDateTime || $productFilter || $priceMin !== null || $priceMax !== null): ?>
        <a href="invoice.php?tab=<?= urlencode($tab) ?>" class="btn-link" style="font-size:.78rem">پاک کردن</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-md">
      <thead>
        <?php if ($tab === 'payments'): ?>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>شناسه تراکنش</th>
          <th>نوع سرویس</th>
          <th>روش پرداخت</th>
          <th>مبلغ</th>
          <th>تاریخ</th>
          <th>وضعیت</th>
          <th>عملیات</th>
        </tr>
        <?php else: ?>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>محصول</th>
          <th>نوع سرویس</th>
          <th>قیمت</th>
          <th>تاریخ</th>
          <th>وضعیت</th>
          <th>عملیات</th>
        </tr>
        <?php endif; ?>
      </thead>
      <tbody>
        <?php if (empty($invoices)): ?>
          <tr>
            <td colspan="<?= $tab === 'payments' ? 9 : 8 ?>">
              <div class="empty">
                <svg class="ill" viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <rect x="30" y="15" width="100" height="90" rx="8" fill="var(--sf3)" />
                  <rect x="45" y="35" width="70" height="8" rx="4" fill="var(--bds)" />
                  <rect x="45" y="52" width="50" height="6" rx="3" fill="var(--bd)" />
                  <rect x="45" y="66" width="60" height="6" rx="3" fill="var(--bd)" />
                  <rect x="45" y="80" width="35" height="6" rx="3" fill="var(--bd)" />
                </svg>
                <p>
                  <?= $tab === 'payments'
                    ? ($search ? 'پرداختی با این جستجو یافت نشد' : 'هنوز گزارشی ثبت نشده')
                    : ($search ? 'سفارشی با این جستجو یافت نشد' : 'هنوز سفارشی ثبت نشده') ?>
                </p>
              </div>
            </td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($invoices as $inv):
            $st = $inv['transaction_status'] ?? '';
            [$cls, $lbl] = $statusLabelMap[$st] ?? ['tag-plain', $st ?: '—'];
            $typeLabel = $activeServiceTypeLabelMap[$inv['service_type'] ?? ''] ?? ($inv['service_type'] ?? '—');
            ?>
            <?php if ($tab === 'payments'): ?>
            <?php
              $oid = (string) ($inv['id_order'] ?? '');
              $hasProduct = ($inv['service_type'] ?? '') === 'order';
            ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cm">
                <a href="user.php?id=<?= urlencode((string) ($inv['id_user'] ?? '')) ?>" style="color:var(--ac)">
                  <?= htmlspecialchars($inv['id_user'] ?? '—') ?>
                </a>
              </td>
              <td class="cm" style="font-size:.78rem"><?= htmlspecialchars(trunc($oid !== '' ? $oid : '—', 22)) ?></td>
              <td style="font-size:.82rem;color:var(--text2)"><?= htmlspecialchars($typeLabel) ?></td>
              <td style="font-size:.8rem"><?= htmlspecialchars(panel_payment_method_label($inv['payment_method'] ?? '')) ?></td>
              <td class="cn cs"><?= number_format((int) ($inv['price'] ?? 0)) ?> <span class="cf">ت</span></td>
              <td class="cf">
                <?= !empty($inv['transaction_epoch'])
                  ? jdate('Y/m/d H:i', (int) $inv['transaction_epoch'], '', 'Asia/Tehran', 'fa')
                  : '—' ?>
              </td>
              <td><span class="tag <?= $cls ?>"><?= $lbl ?></span></td>
              <td>
                <button type="button" class="btn btn-ghost btn-sm"
                  onclick="openStatusModal(
                    '<?= htmlspecialchars($oid, ENT_QUOTES) ?>',
                    '<?= htmlspecialchars($st, ENT_QUOTES) ?>',
                    <?= $hasProduct ? 'true' : 'false' ?>
                  )">تغییر وضعیت</button>
              </td>
            </tr>
            <?php else: ?>
            <?php
              $recordId = (string) ($inv['record_id'] ?? '');
              $recordSource = (string) ($inv['record_source'] ?? 'invoice');
              $editPayload = [
                'record_id' => $recordId,
                'record_source' => $recordSource,
                'id_user' => (string) ($inv['id_user'] ?? ''),
                'username' => (string) ($inv['username'] ?? ''),
                'product_name' => (string) ($inv['product_name'] ?? ''),
                'price' => (string) ($inv['price'] ?? ''),
                'status' => (string) $st,
                'volume' => (string) ($inv['volume'] ?? ''),
                'service_time' => (string) ($inv['service_time'] ?? ''),
                'note' => (string) ($inv['note'] ?? ''),
                'service_location' => (string) ($inv['service_location'] ?? ''),
              ];
            ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cm">
                <a href="user.php?id=<?= urlencode((string) ($inv['id_user'] ?? '')) ?>" style="color:var(--ac)">
                  <?= htmlspecialchars($inv['id_user'] ?? '—') ?>
                </a>
              </td>
              <td class="cs"><?= htmlspecialchars(trunc($inv['product_name'] ?? '—', 28)) ?></td>
              <td style="font-size:.82rem;color:var(--text2)"><?= htmlspecialchars($typeLabel) ?></td>
              <td class="cn cs"><?= number_format((int) ($inv['price'] ?? 0)) ?> <span class="cf">ت</span></td>
              <td class="cf">
                <?= !empty($inv['transaction_epoch'])
                  ? jdate('Y/m/d H:i', (int) $inv['transaction_epoch'], '', 'Asia/Tehran', 'fa')
                  : '—' ?>
              </td>
              <td><span class="tag <?= $cls ?>"><?= $lbl ?></span></td>
              <td>
                <button type="button" class="btn btn-ghost btn-sm"
                  data-invoice="<?= htmlspecialchars(json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"
                  onclick="openInvoiceEditModal(this)">ویرایش</button>
              </td>
            </tr>
            <?php endif; ?>
          <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="tbl-foot">
    <span><?= number_format($total) ?> رکورد · صفحه <?= $page ?> از <?= $totalPages ?></span>
    <div class="pager">
      <?php
      $qs = fn($p) => '?' . http_build_query(array_filter([
        'tab' => $tab,
        'q' => $search,
        'status' => $status,
        'service_type' => $serviceType,
        'product' => $productFilter,
        'price_min' => $priceMin,
        'price_max' => $priceMax,
        'from' => $fromDateTime,
        'to' => $toDateTime,
        'page' => $p,
      ], static fn($v) => $v !== null && $v !== ''));
      ?>
      <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
      <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
        <a class="<?= $p === $page ? 'cur' : '' ?>" href="<?= $qs($p) ?>"><?= $p ?></a>
      <?php endfor; ?>
      <a class="<?= $page >= $totalPages ? 'dis' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js"></script>
<script>
  $(function () {
    $('.jalali-datetime-picker').persianDatepicker({
      calendarType: 'persian',
      format: 'YYYY/MM/DD HH:mm',
      initialValue: false,
      initialValueType: 'persian',
      autoClose: false,
      responsive: true,
      observer: false,
      navigator: { scroll: { enabled: false } },
      toolbox: {
        calendarSwitch: { enabled: false },
        todayButton: { enabled: true },
        submitButton: { enabled: true }
      },
      timePicker: {
        enabled: true,
        second: { enabled: false },
        meridian: { enabled: false }
      }
    });
  });
</script>

<?php if ($tab === 'payments'): ?>
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
        <input type="hidden" name="redirect_tab" value="payments">
        <input type="hidden" name="order_id" id="statusOrderId" value="">
        <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
        <input type="hidden" name="status_filter" value="<?= htmlspecialchars($status) ?>">
        <input type="hidden" name="service_type" value="<?= htmlspecialchars($serviceType) ?>">
        <input type="hidden" name="product" value="<?= htmlspecialchars($productFilter) ?>">
        <input type="hidden" name="price_min" value="<?= $priceMin !== null ? (int) $priceMin : '' ?>">
        <input type="hidden" name="price_max" value="<?= $priceMax !== null ? (int) $priceMax : '' ?>">
        <input type="hidden" name="from" value="<?= htmlspecialchars($fromDateTime) ?>">
        <input type="hidden" name="to" value="<?= htmlspecialchars($toDateTime) ?>">
        <input type="hidden" name="page" value="<?= (int) $page ?>">
        <div class="field" style="margin-bottom:14px">
          <label class="lbl">وضعیت جدید</label>
          <select name="new_status" id="statusNewSelect" class="select" style="width:100%">
            <?php foreach ($paymentStatusMap as $k => [$_, $lbl]): ?>
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
            فقط برای خرید سرویس. در صورت انتخاب، سرویس از پنل و ربات حذف می‌شود.
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

<?php if ($tab === 'orders'): ?>
<div class="modal-veil" id="invoiceEditModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-head">
      <h3>ویرایش سفارش</h3>
      <button type="button" class="modal-x" onclick="closeModal('invoiceEditModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST" id="invoiceEditForm">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" id="invoiceEditAction" value="update_invoice">
        <input type="hidden" name="redirect_tab" value="orders">
        <input type="hidden" name="record_id" id="editRecordId" value="">
        <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
        <input type="hidden" name="status_filter" value="<?= htmlspecialchars($status) ?>">
        <input type="hidden" name="service_type" value="<?= htmlspecialchars($serviceType) ?>">
        <input type="hidden" name="product" value="<?= htmlspecialchars($productFilter) ?>">
        <input type="hidden" name="price_min" value="<?= $priceMin !== null ? (int) $priceMin : '' ?>">
        <input type="hidden" name="price_max" value="<?= $priceMax !== null ? (int) $priceMax : '' ?>">
        <input type="hidden" name="from" value="<?= htmlspecialchars($fromDateTime) ?>">
        <input type="hidden" name="to" value="<?= htmlspecialchars($toDateTime) ?>">
        <input type="hidden" name="page" value="<?= (int) $page ?>">

        <div class="field" style="margin-bottom:10px">
          <label class="lbl">وضعیت</label>
          <select name="invoice_status" id="editStatus" class="select" style="width:100%">
            <?php foreach ($orderStatusMap as $k => [$_, $lbl]): ?>
              <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="lbl">محصول / مقدار</label>
          <input type="text" name="name_product" id="editProduct" class="select" style="width:100%" list="invoiceProductList">
          <datalist id="invoiceProductList">
            <?php foreach ($productOptions as $pRow):
              $pname = $pRow['name_product'] ?? '';
              if ($pname === '') continue;
            ?>
              <option value="<?= htmlspecialchars($pname) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="lbl">قیمت (تومان)</label>
          <input type="number" name="price_product" id="editPrice" class="select" style="width:100%" min="0" step="1">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label class="lbl">نام کاربری سرویس</label>
          <input type="text" name="username" id="editUsername" class="select" style="width:100%">
        </div>
        <div id="invoiceOnlyFields">
          <div class="field" style="margin-bottom:10px">
            <label class="lbl">حجم</label>
            <input type="text" name="Volume" id="editVolume" class="select" style="width:100%">
          </div>
          <div class="field" style="margin-bottom:10px">
            <label class="lbl">مدت (روز)</label>
            <input type="text" name="Service_time" id="editServiceTime" class="select" style="width:100%">
          </div>
          <div class="field" style="margin-bottom:10px">
            <label class="lbl">لوکیشن / پنل</label>
            <input type="text" name="Service_location" id="editLocation" class="select" style="width:100%">
          </div>
          <div class="field" style="margin-bottom:10px">
            <label class="lbl">یادداشت</label>
            <input type="text" name="note" id="editNote" class="select" style="width:100%">
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary">ذخیره</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('invoiceEditModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>
<script>
window.openInvoiceEditModal = function (btn) {
  var data;
  try { data = JSON.parse(btn.getAttribute('data-invoice') || '{}'); } catch (e) { data = {}; }
  var isInvoice = (data.record_source || 'invoice') === 'invoice';
  document.getElementById('invoiceEditAction').value = isInvoice ? 'update_invoice' : 'update_service_other';
  document.getElementById('editRecordId').value = data.record_id || '';
  document.getElementById('editStatus').value = data.status || '';
  document.getElementById('editProduct').value = data.product_name || '';
  document.getElementById('editPrice').value = data.price || '';
  document.getElementById('editUsername').value = data.username || '';
  document.getElementById('editVolume').value = data.volume || '';
  document.getElementById('editServiceTime').value = data.service_time || '';
  document.getElementById('editLocation').value = data.service_location || '';
  document.getElementById('editNote').value = data.note || '';
  document.getElementById('invoiceOnlyFields').style.display = isInvoice ? 'block' : 'none';
  openModal('invoiceEditModal');
};
</script>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>