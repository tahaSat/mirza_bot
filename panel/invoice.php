<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once dirname(__DIR__) . '/jdf.php';
require_auth();
$pdo = panel_ensure_pdo();

$search = trim($_GET['q'] ?? '');

$status = $_GET['status'] ?? '';
$serviceType = $_GET['service_type'] ?? '';
$fromDateTime = trim($_GET['from'] ?? '');
$toDateTime = trim($_GET['to'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

/**
 * Convert a Jalali date entered in Tehran local time to a UTC Unix timestamp.
 * Unix timestamps are timezone-neutral, so this can be compared directly with
 * UTC timestamps stored in the database.
 */
function invoice_jalali_tehran_timestamp(string $date, string $time, bool $endOfDay = false): ?int
{
  $date = trim((string) tr_num($date, 'en'));
  $time = trim((string) tr_num($time, 'en'));

  if (!preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $date, $dateParts)) {
    return null;
  }

  if ($time === '') {
    $time = $endOfDay ? '23:59:59' : '00:00:00';
  } elseif (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
    $time .= ':00';
  }

  if (!preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $time, $timeParts)) {
    return null;
  }

  [$jy, $jm, $jd] = [(int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[3]];
  [$hour, $minute, $second] = [(int) $timeParts[1], (int) $timeParts[2], (int) $timeParts[3]];
  if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 || $hour > 23 || $minute > 59 || $second > 59) {
    return null;
  }

  [$gy, $gm, $gd] = jalali_to_gregorian($jy, $jm, $jd);
  if (!checkdate($gm, $gd, $gy) || gregorian_to_jalali($gy, $gm, $gd) !== [$jy, $jm, $jd]) {
    return null;
  }

  $tehran = new DateTimeZone('Asia/Tehran');
  $dateTime = DateTimeImmutable::createFromFormat(
    '!Y-n-j H:i:s',
    "$gy-$gm-$gd " . sprintf('%02d:%02d:%02d', $hour, $minute, $second),
    $tehran
  );

  return $dateTime instanceof DateTimeImmutable ? $dateTime->getTimestamp() : null;
}

function invoice_parse_jalali_filter(string $value, bool $endOfDay = false): ?int
{
  $value = trim((string) tr_num($value, 'en'));
  if (!preg_match('/^(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2})(?:\s+(\d{1,2}:\d{2}(?::\d{2})?))?$/', $value, $parts)) {
    return null;
  }

  return invoice_jalali_tehran_timestamp($parts[1], $parts[2] ?? '', $endOfDay);
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
  'extends_not_user' => 'تمدید',
  'extend_user' => 'تمدید',
  'transfertouser' => 'انتقال سفارش به کاربر دیگر',
];

$recordsSQL = "
  SELECT id_user, username, name_product AS product_name, price_product AS price,
         time_sell AS transaction_time, CAST(time_sell AS UNSIGNED) AS transaction_epoch,
         Status AS transaction_status, 'order' AS service_type
  FROM invoice
  UNION ALL
  SELECT id_user, username, value AS product_name, price,
         time AS transaction_time,
         CASE
           WHEN time REGEXP '^[0-9]{9,}$' THEN CAST(time AS UNSIGNED)
           ELSE COALESCE(
             UNIX_TIMESTAMP(STR_TO_DATE(time, '%Y-%m-%d %H:%i:%s')),
             UNIX_TIMESTAMP(STR_TO_DATE(time, '%Y/%m/%d %H:%i:%s'))
           )
         END AS transaction_epoch,
         status AS transaction_status, type AS service_type
  FROM service_other
";

$where = [];
$params = [];
if ($search !== '') {
  $where[] = "(id_user LIKE ? OR COALESCE(product_name,'') LIKE ? OR COALESCE(username,'') LIKE ? OR COALESCE(service_type,'') LIKE ?)";
  $params = ["%$search%", "%$search%", "%$search%"];
  $params[] = "%$search%";
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

$statusMap = [
  'active' => ['tag-ok', 'فعال'],
  'end_of_time' => ['tag-warn', 'اعلان پایان زمان'],
  'end_of_volume' => ['tag-no', 'اعلان پایان حجم'],
  'sendedwarn' => ['tag-warn', 'ارسال تمامی اعلان ها'],
  'send_on_hold' => ['tag-plain', 'اعلان متصنل نشدن ارسال شده'],
  'unpaid' => ['tag-plain', 'پرداخت نشده'],
  'Unsuccessful' => ['tag-plain', 'خطا دریافت اطلاعات'],
  'paid' => ['tag-ok', 'انجام شده'],
  'done' => ['tag-ok', 'انجام شده'],
  'pending' => ['tag-warn', 'در انتظار'],
  'reject' => ['tag-no', 'رد شده'],
];

$pageTitle = 'سفارشات';
$pageLede = 'فهرست کلیه سفارشات ثبت‌شده در ربات.';
$activeNav = 'invoice';
include __DIR__ . '/inc/layout_head.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css">

<div class="card fade-up">
  <div class="toolbar">
    <div class="toolbar-title">سفارشات <small>(<?= number_format($total) ?>)</small></div>
    <form method="GET" id="invoiceForm" class="toolbar-end">
      <select name="service_type" class="select" style="width:auto"
        onchange="document.getElementById('invoiceForm').submit()">
        <option value="">همه نوع سرویس‌ها</option>
        <?php foreach ($serviceTypeMap as $k => $lbl): ?>
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
        <input type="text" name="q" placeholder="آیدی کاربر، نام محصول..." value="<?= htmlspecialchars($search) ?>"
          autocomplete="off">
        <button type="button" class="search-clear">✕</button>
        <button type="submit" class="search-btn">جستجو</button>
      </div>
      <?php if ($search || $status || $serviceType || $fromDateTime || $toDateTime): ?>
        <a href="invoice.php" class="btn-link" style="font-size:.78rem">پاک کردن</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-md">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>محصول</th>
          <th>نوع سرویس</th>
          <th>قیمت</th>
          <th>تاریخ</th>
          <th>وضعیت</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($invoices)): ?>
          <tr>
            <td colspan="7">
              <div class="empty">
                <svg class="ill" viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <rect x="30" y="15" width="100" height="90" rx="8" fill="var(--sf3)" />
                  <rect x="45" y="35" width="70" height="8" rx="4" fill="var(--bds)" />
                  <rect x="45" y="52" width="50" height="6" rx="3" fill="var(--bd)" />
                  <rect x="45" y="66" width="60" height="6" rx="3" fill="var(--bd)" />
                  <rect x="45" y="80" width="35" height="6" rx="3" fill="var(--bd)" />
                </svg>
                <p><?= $search ? 'سفارشی با این جستجو یافت نشد' : 'هنوز سفارشی ثبت نشده' ?></p>
              </div>
            </td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($invoices as $inv):
            $st = $inv['transaction_status'] ?? '';
            [$cls, $lbl] = $statusMap[$st] ?? ['tag-plain', $st ?: '—'];
            $typeLabel = $serviceTypeMap[$inv['service_type'] ?? ''] ?? ($inv['service_type'] ?? '—');
            ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cm"><?= htmlspecialchars($inv['id_user'] ?? '—') ?></td>
              <td class="cs"><?= htmlspecialchars(trunc($inv['product_name'] ?? '—', 28)) ?></td>
              <td style="font-size:.82rem;color:var(--text2)"><?= htmlspecialchars($typeLabel) ?></td>
              <td class="cn cs"><?= number_format((int) ($inv['price'] ?? 0)) ?> <span class="cf">ت</span></td>
              <td class="cf">
                <?= !empty($inv['transaction_epoch'])
                  ? jdate('Y/m/d H:i', (int) $inv['transaction_epoch'], '', 'Asia/Tehran', 'fa')
                  : '—' ?>
              </td>
              <td><span class="tag <?= $cls ?>"><?= $lbl ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="tbl-foot">
    <span><?= number_format($total) ?> رکورد · صفحه <?= $page ?> از <?= $totalPages ?></span>
    <div class="pager">
      <?php
      $qs = fn($p) => '?' . http_build_query([
        'q' => $search,
        'status' => $status,
        'service_type' => $serviceType,
        'from' => $fromDateTime,
        'to' => $toDateTime,
        'page' => $p,
      ]);
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

<?php include __DIR__ . '/inc/layout_foot.php'; ?>