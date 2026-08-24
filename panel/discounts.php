<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();
$pdo = panel_ensure_pdo();
discount_sell_ensure_schema();
product_discount_ensure_schema();
$discountTab = (($_GET['tab'] ?? 'codes') === 'product') ? 'product' : 'codes';

function discount_agent_label(string $agent): string
{
  return match ($agent) {
    'f' => 'کاربر عادی',
    'n' => 'نماینده',
    'n2' => 'نماینده پیشرفته',
    'allusers' => 'همه کاربران',
    default => $agent !== '' ? $agent : '—',
  };
}

function discount_type_label(string $type): string
{
  return match ($type) {
    'buy' => 'خرید',
    'extend' => 'تمدید',
    'all' => 'خرید و تمدید',
    default => $type !== '' ? $type : '—',
  };
}

function discount_normalize_time(?string $hoursRaw): string
{
  $hoursRaw = trim((string) $hoursRaw);
  if ($hoursRaw === '' || !ctype_digit($hoursRaw)) {
    return '0';
  }
  $hours = (int) $hoursRaw;
  return $hours === 0 ? '0' : (string) (time() + ($hours * 3600));
}

function discount_expiry_label(?string $time): string
{
  if ($time === null || $time === '' || $time === '0') {
    return 'نامحدود';
  }
  if (!is_numeric($time)) {
    return (string) $time;
  }
  $ts = (int) $time;
  if ($ts <= 0) {
    return 'نامحدود';
  }
  if ($ts < time()) {
    return 'منقضی (' . date('Y/m/d H:i', $ts) . ')';
  }
  return date('Y/m/d H:i', $ts);
}

function discount_post_scope(string $key, string $allToken = 'all'): string
{
  $raw = $_POST[$key] ?? [];
  if (!is_array($raw)) {
    $raw = [$raw];
  }
  return discount_sell_encode_scope($raw, $allToken);
}

function discount_collect_fields(): array
{
  $code = strtolower(trim((string) ($_POST['code'] ?? '')));
  $percent = trim((string) ($_POST['percent'] ?? ''));
  $limitUse = trim((string) ($_POST['limit_use'] ?? ''));
  $useUser = trim((string) ($_POST['useuser'] ?? ''));
  $agent = trim((string) ($_POST['agent'] ?? 'allusers'));
  $usefirst = trim((string) ($_POST['usefirst'] ?? '0'));
  $type = trim((string) ($_POST['type'] ?? 'all'));
  $codePanel = discount_post_scope('code_panel', '/all');
  $codeProduct = discount_post_scope('code_product', 'all');
  $codeCategory = discount_post_scope('code_category', 'all');
  $timeHours = trim((string) ($_POST['time_hours'] ?? '0'));

  return compact('code', 'percent', 'limitUse', 'useUser', 'agent', 'usefirst', 'type', 'codePanel', 'codeProduct', 'codeCategory', 'timeHours');
}

function discount_validate_fields(array $f, bool $requireCode = true): ?string
{
  if ($requireCode && $f['code'] === '') {
    return 'کد تخفیف الزامی است.';
  }
  if ($requireCode && !preg_match('/^[A-Za-z\d]+$/', $f['code'])) {
    return 'کد فقط می‌تواند شامل حروف انگلیسی و عدد باشد.';
  }
  if ($f['percent'] === '' || !ctype_digit($f['percent']) || (int) $f['percent'] < 1 || (int) $f['percent'] > 100) {
    return 'درصد تخفیف باید عددی بین ۱ تا ۱۰۰ باشد.';
  }
  if ($f['limitUse'] === '' || !ctype_digit($f['limitUse']) || (int) $f['limitUse'] < 1) {
    return 'محدودیت کل استفاده نامعتبر است.';
  }
  if ($f['useUser'] === '' || !ctype_digit($f['useUser']) || (int) $f['useUser'] < 1) {
    return 'محدودیت استفاده هر کاربر نامعتبر است.';
  }
  if ((int) $f['useUser'] > (int) $f['limitUse']) {
    return 'محدودیت هر کاربر نباید بیشتر از محدودیت کل باشد.';
  }
  if (!in_array($f['agent'], ['f', 'n', 'n2', 'allusers'], true)) {
    return 'گروه کاربری نامعتبر است.';
  }
  if (!in_array($f['usefirst'], ['0', '1'], true)) {
    return 'نوع محدودیت خرید نامعتبر است.';
  }
  if (!in_array($f['type'], ['buy', 'extend', 'all'], true)) {
    return 'نوع کاربرد کد نامعتبر است.';
  }
  if ($f['timeHours'] !== '' && !ctype_digit($f['timeHours'])) {
    return 'مدت اعتبار باید عدد ساعت باشد (۰ = نامحدود).';
  }
  return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
  csrf_check_post();
  $f = discount_collect_fields();
  $err = discount_validate_fields($f, true);
  if ($err) {
    flash('error', $err);
    header('Location: discounts.php');
    exit;
  }
  if (db_count($pdo, 'SELECT COUNT(*) FROM DiscountSell WHERE codeDiscount = ?', [$f['code']])) {
    flash('error', 'این کد تخفیف قبلاً ثبت شده است.');
    header('Location: discounts.php');
    exit;
  }
  try {
    db_query(
      $pdo,
      'INSERT INTO DiscountSell (codeDiscount, usedDiscount, price, limitDiscount, agent, usefirst, useuser, code_panel, code_product, code_category, time, type)
       VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [
        $f['code'],
        $f['percent'],
        $f['limitUse'],
        $f['agent'],
        $f['usefirst'],
        $f['useUser'],
        $f['codePanel'],
        $f['codeProduct'],
        $f['codeCategory'],
        discount_normalize_time($f['timeHours']),
        $f['usefirst'] === '1' ? 'all' : $f['type'],
      ]
    );
    flash('success', 'کد تخفیف «' . $f['code'] . '» ساخته شد.');
  } catch (Exception $e) {
    flash('error', 'خطای پایگاه داده: ' . $e->getMessage());
  }
  header('Location: discounts.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
  csrf_check_post();
  $id = (int) ($_POST['edit_id'] ?? 0);
  $f = discount_collect_fields();
  $err = discount_validate_fields($f, true);
  if (!$id) {
    flash('error', 'شناسه نامعتبر است.');
    header('Location: discounts.php');
    exit;
  }
  if ($err) {
    flash('error', $err);
    header('Location: discounts.php');
    exit;
  }
  $old = db_fetch($pdo, 'SELECT * FROM DiscountSell WHERE id = ?', [$id]);
  if (!$old) {
    flash('error', 'کد تخفیف یافت نشد.');
    header('Location: discounts.php');
    exit;
  }
  if (strcasecmp((string) $old['codeDiscount'], $f['code']) !== 0
    && db_count($pdo, 'SELECT COUNT(*) FROM DiscountSell WHERE codeDiscount = ?', [$f['code']])) {
    flash('error', 'کد دیگری با این نام وجود دارد.');
    header('Location: discounts.php');
    exit;
  }

  $updateTime = array_key_exists('update_time', $_POST);
  $timeValue = $updateTime ? discount_normalize_time($f['timeHours']) : (string) ($old['time'] ?? '0');
  $typeValue = $f['usefirst'] === '1' ? 'all' : $f['type'];

  try {
    db_query(
      $pdo,
      'UPDATE DiscountSell SET
        codeDiscount = ?, price = ?, limitDiscount = ?, agent = ?, usefirst = ?,
        useuser = ?, code_panel = ?, code_product = ?, code_category = ?, time = ?, type = ?
       WHERE id = ?',
      [
        $f['code'],
        $f['percent'],
        $f['limitUse'],
        $f['agent'],
        $f['usefirst'],
        $f['useUser'],
        $f['codePanel'],
        $f['codeProduct'],
        $f['codeCategory'],
        $timeValue,
        $typeValue,
        $id,
      ]
    );
    if (strcasecmp((string) $old['codeDiscount'], $f['code']) !== 0) {
      db_query($pdo, 'UPDATE Giftcodeconsumed SET code = ? WHERE code = ?', [$f['code'], $old['codeDiscount']]);
      try {
        db_query($pdo, 'UPDATE DiscountSellUsage SET code = ? WHERE code = ?', [$f['code'], $old['codeDiscount']]);
      } catch (Exception $e) {
      }
    }
    flash('success', 'کد تخفیف ویرایش شد.');
  } catch (Exception $e) {
    flash('error', 'خطا: ' . $e->getMessage());
  }
  header('Location: discounts.php');
  exit;
}

if (isset($_GET['delete'])) {
  csrf_check_get();
  $id = (int) $_GET['delete'];
  $row = db_fetch($pdo, 'SELECT codeDiscount FROM DiscountSell WHERE id = ?', [$id]);
  if ($row) {
    $code = $row['codeDiscount'];
    db_query($pdo, 'DELETE FROM Giftcodeconsumed WHERE code = ?', [$code]);
    try {
      db_query($pdo, 'DELETE FROM DiscountSellUsage WHERE code = ?', [$code]);
    } catch (Exception $e) {
    }
    db_query($pdo, 'DELETE FROM DiscountSell WHERE id = ?', [$id]);
    flash('success', 'کد تخفیف «' . $code . '» حذف شد.');
  } else {
    flash('error', 'کد تخفیف یافت نشد.');
  }
  header('Location: discounts.php');
  exit;
}

function product_discount_redirect(): void
{
  header('Location: discounts.php?tab=product');
  exit;
}

function product_discount_collect_fields(): array
{
  $status = trim((string) ($_POST['pd_status'] ?? 'active'));
  $valueRaw = trim((string) ($_POST['pd_value'] ?? ''));
  $percentRaw = trim((string) ($_POST['pd_percent'] ?? ''));
  $products = $_POST['pd_products'] ?? [];
  if (!is_array($products)) {
    $products = [$products];
  }
  $products = product_discount_decode_products($products);
  $useLimitRaw = trim((string) ($_POST['pd_use_limit'] ?? ''));
  return compact('status', 'valueRaw', 'percentRaw', 'products', 'useLimitRaw');
}

function product_discount_validate_fields(array $f): ?string
{
  if (!in_array($f['status'], ['active', 'inactive'], true)) {
    return 'وضعیت نامعتبر است.';
  }
  $hasValue = $f['valueRaw'] !== '';
  $hasPercent = $f['percentRaw'] !== '';
  if ($hasValue && $hasPercent) {
    return 'فقط یکی از مبلغ یا درصد را وارد کنید.';
  }
  if (!$hasValue && !$hasPercent) {
    return 'مبلغ یا درصد تخفیف را وارد کنید.';
  }
  if ($hasValue) {
    if (!ctype_digit($f['valueRaw']) || (int) $f['valueRaw'] < 1) {
      return 'مبلغ تخفیف باید عدد بزرگ‌تر از صفر باشد.';
    }
  } else {
    if (!ctype_digit($f['percentRaw']) || (int) $f['percentRaw'] < 1 || (int) $f['percentRaw'] > 100) {
      return 'درصد تخفیف باید عددی بین ۱ تا ۱۰۰ باشد.';
    }
  }
  if ($f['products'] === []) {
    return 'حداقل یک محصول را انتخاب کنید.';
  }
  if ($f['useLimitRaw'] !== '') {
    if (!ctype_digit($f['useLimitRaw']) || (int) $f['useLimitRaw'] < 1) {
      return 'محدودیت تعداد استفاده باید عدد بزرگ‌تر از صفر باشد یا خالی بماند.';
    }
  }
  return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['product_add', 'product_edit'], true)) {
  csrf_check_post();
  $action = (string) $_POST['action'];
  $f = product_discount_collect_fields();
  $err = product_discount_validate_fields($f);
  if ($action === 'product_edit') {
    $id = (int) ($_POST['pd_id'] ?? 0);
    if (!$id) {
      flash('error', 'شناسه نامعتبر است.');
      product_discount_redirect();
    }
  }
  if ($err) {
    flash('error', $err);
    product_discount_redirect();
  }
  $type = $f['valueRaw'] !== '' ? 'value' : 'percent';
  $amount = (int) ($type === 'value' ? $f['valueRaw'] : $f['percentRaw']);
  $productsJson = product_discount_encode_products($f['products']);
  $useLimit = $f['useLimitRaw'] === '' ? null : (int) $f['useLimitRaw'];
  try {
    if ($action === 'product_add') {
      db_query(
        $pdo,
        'INSERT INTO ProductDiscount (status, type, amount, products, use_limit, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$f['status'], $type, $amount, $productsJson, $useLimit, time()]
      );
      flash('success', 'تخفیف روی محصول ثبت شد.');
    } else {
      $id = (int) ($_POST['pd_id'] ?? 0);
      $old = db_fetch($pdo, 'SELECT id FROM ProductDiscount WHERE id = ?', [$id]);
      if (!$old) {
        flash('error', 'تخفیف یافت نشد.');
        product_discount_redirect();
      }
      db_query(
        $pdo,
        'UPDATE ProductDiscount SET status = ?, type = ?, amount = ?, products = ?, use_limit = ? WHERE id = ?',
        [$f['status'], $type, $amount, $productsJson, $useLimit, $id]
      );
      flash('success', 'تخفیف روی محصول ویرایش شد.');
    }
  } catch (Exception $e) {
    flash('error', 'خطای پایگاه داده: ' . $e->getMessage());
  }
  product_discount_redirect();
}

if (isset($_GET['product_delete'])) {
  csrf_check_get();
  $id = (int) $_GET['product_delete'];
  $row = db_fetch($pdo, 'SELECT id FROM ProductDiscount WHERE id = ?', [$id]);
  if ($row) {
    db_query($pdo, 'DELETE FROM ProductDiscount WHERE id = ?', [$id]);
    flash('success', 'تخفیف روی محصول حذف شد.');
  } else {
    flash('error', 'تخفیف یافت نشد.');
  }
  product_discount_redirect();
}

if (isset($_GET['product_toggle'])) {
  csrf_check_get();
  $id = (int) $_GET['product_toggle'];
  $row = db_fetch($pdo, 'SELECT status FROM ProductDiscount WHERE id = ?', [$id]);
  if ($row) {
    $next = (($row['status'] ?? '') === 'active') ? 'inactive' : 'active';
    db_query($pdo, 'UPDATE ProductDiscount SET status = ? WHERE id = ?', [$next, $id]);
    flash('success', $next === 'active' ? 'تخفیف فعال شد.' : 'تخفیف غیرفعال شد.');
  } else {
    flash('error', 'تخفیف یافت نشد.');
  }
  product_discount_redirect();
}

$search = trim($_GET['q'] ?? '');
$usageCode = trim((string) ($_GET['usage'] ?? ''));
$params = [];
$whereSQL = '';
if ($search !== '') {
  $whereSQL = 'WHERE codeDiscount LIKE ?';
  $params = ['%' . $search . '%'];
}

try {
  $discounts = db_fetchAll($pdo, "SELECT * FROM DiscountSell $whereSQL ORDER BY id DESC", $params);
} catch (Exception $e) {
  $discounts = [];
}

$usageStats = [];
try {
  $rows = db_fetchAll(
    $pdo,
    "SELECT code,
            COUNT(*) AS total,
            SUM(CASE WHEN type = 'buy' THEN 1 ELSE 0 END) AS buys,
            SUM(CASE WHEN type = 'extend' THEN 1 ELSE 0 END) AS extends,
            COUNT(DISTINCT id_user) AS users
     FROM DiscountSellUsage
     GROUP BY code"
  );
  foreach ($rows as $r) {
    $usageStats[$r['code']] = [
      'total' => (int) $r['total'],
      'buys' => (int) $r['buys'],
      'extends' => (int) $r['extends'],
      'users' => (int) $r['users'],
    ];
  }
} catch (Exception $e) {
}

$usageRows = [];
$usageDetail = null;
$productBreakdown = [];
if ($usageCode !== '') {
  $usageDetail = db_fetch($pdo, 'SELECT * FROM DiscountSell WHERE codeDiscount = ?', [$usageCode]);
  try {
    $usageRows = db_fetchAll(
      $pdo,
      'SELECT * FROM DiscountSellUsage WHERE code = ? ORDER BY id DESC LIMIT 200',
      [$usageCode]
    );
    $productBreakdown = db_fetchAll(
      $pdo,
      "SELECT COALESCE(NULLIF(name_product, ''), COALESCE(code_product, 'نامشخص')) AS product_label,
              COUNT(*) AS cnt
       FROM DiscountSellUsage
       WHERE code = ?
       GROUP BY product_label
       ORDER BY cnt DESC
       LIMIT 20",
      [$usageCode]
    );
  } catch (Exception $e) {
    $usageRows = [];
  }
}

$products = [];
$panels = [];
$categories = [];
try {
  $products = db_fetchAll($pdo, 'SELECT code_product, name_product, category FROM product ORDER BY name_product');
} catch (Exception $e) {
}
try {
  $panels = db_fetchAll($pdo, "SELECT code_panel, name_panel FROM marzban_panel WHERE status = 'active' ORDER BY name_panel");
} catch (Exception $e) {
  try {
    $panels = db_fetchAll($pdo, 'SELECT code_panel, name_panel FROM marzban_panel ORDER BY name_panel');
  } catch (Exception $e2) {
  }
}
try {
  $categories = db_fetchAll($pdo, 'SELECT remark, status FROM category ORDER BY remark');
} catch (Exception $e) {
}
$categoryNames = [];
foreach ($categories as $c) {
  $categoryNames[$c['remark']] = $c['remark'];
}
foreach ($products as $p) {
  $cat = trim((string) ($p['category'] ?? ''));
  if ($cat !== '' && !isset($categoryNames[$cat])) {
    $categories[] = ['remark' => $cat];
    $categoryNames[$cat] = $cat;
  }
}
usort($categories, fn($a, $b) => strcmp((string) $a['remark'], (string) $b['remark']));

$productNames = [];
foreach ($products as $p) {
  $productNames[$p['code_product']] = $p['name_product'];
}
$panelNames = [];
foreach ($panels as $p) {
  $panelNames[$p['code_panel']] = $p['name_panel'];
}

$productDiscounts = [];
try {
  $productDiscounts = db_fetchAll($pdo, 'SELECT * FROM ProductDiscount ORDER BY id DESC');
} catch (Exception $e) {
  $productDiscounts = [];
}

$categoryActiveMap = [];
foreach ($categories as $c) {
  $remark = (string) ($c['remark'] ?? '');
  $status = $c['status'] ?? 'active';
  $categoryActiveMap[$remark] = ($status === '' || $status === 'active');
}
$productsByCategory = [];
foreach ($products as $p) {
  $catKey = trim((string) ($p['category'] ?? ''));
  $productsByCategory[$catKey][] = $p;
}
$productPickerSections = [];
$seenPickerCats = [];
foreach ($categories as $c) {
  $remark = (string) ($c['remark'] ?? '');
  if ($remark === '' || empty($categoryActiveMap[$remark]) || empty($productsByCategory[$remark])) {
    continue;
  }
  $productPickerSections[] = [
    'key' => $remark,
    'label' => $remark,
    'products' => $productsByCategory[$remark],
  ];
  $seenPickerCats[$remark] = true;
}
if (!empty($productsByCategory[''])) {
  $productPickerSections[] = [
    'key' => '',
    'label' => 'بدون دسته‌بندی',
    'products' => $productsByCategory[''],
  ];
}

$pageTitle = $discountTab === 'product' ? 'تخفیف روی محصول' : 'کدهای تخفیف';
$pageLede = $discountTab === 'product'
  ? 'تخفیف مبلغی یا درصدی روی محصولات انتخاب‌شده، بدون نیاز به کد.'
  : 'ساخت و مدیریت کد تخفیف با فیلتر چندتایی روی محصول، دسته‌بندی و پنل.';
$activeNav = 'discounts';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="display:flex;gap:4px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:4px;flex-wrap:wrap">
    <a href="discounts.php" class="btn btn-sm <?= $discountTab === 'codes' ? 'btn-primary' : 'btn-ghost' ?>">کدهای تخفیف</a>
    <a href="discounts.php?tab=product" class="btn btn-sm <?= $discountTab === 'product' ? 'btn-primary' : 'btn-ghost' ?>">تخفیف روی محصول</a>
  </div>
</div>

<?php if ($discountTab === 'product'): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="font-size:.85rem;color:var(--mute)"><?= count($productDiscounts) ?> تخفیف محصول</div>
  <button class="btn btn-primary" onclick="openProductDiscountModal()"><?= icon('plus', 14) ?> افزودن تخفیف</button>
</div>

<div class="card fade-up d1">
  <div class="toolbar">
    <div class="toolbar-title">تخفیف روی محصول <small>(<?= count($productDiscounts) ?>)</small></div>
  </div>
  <?php if (empty($productDiscounts)): ?>
    <div class="empty" style="padding:60px 20px">
      <p>هنوز تخفیفی روی محصول ثبت نکرده‌اید</p>
      <button class="btn btn-primary" style="margin-top:14px" onclick="openProductDiscountModal()"><?= icon('plus', 14) ?> ساخت اولین تخفیف</button>
    </div>
  <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl-lg">
        <thead>
          <tr>
            <th>#</th>
            <th>وضعیت</th>
            <th>نوع</th>
            <th>مقدار</th>
            <th>باقی‌مانده</th>
            <th>محصولات</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php $pi = 1; foreach ($productDiscounts as $pd):
            $pdProducts = product_discount_decode_products($pd['products'] ?? '');
            $pdType = (string) ($pd['type'] ?? 'value');
            $pdAmount = (int) ($pd['amount'] ?? 0);
            $pdStatus = (string) ($pd['status'] ?? 'inactive');
            $pdUseLimit = $pd['use_limit'] ?? null;
            $pdUseLabel = ($pdUseLimit === null || $pdUseLimit === '') ? 'نامحدود' : (string) (int) $pdUseLimit;
            $editPayload = [
              'id' => (int) ($pd['id'] ?? 0),
              'status' => $pdStatus,
              'type' => $pdType,
              'amount' => $pdAmount,
              'products' => $pdProducts,
              'use_limit' => ($pdUseLimit === null || $pdUseLimit === '') ? '' : (int) $pdUseLimit,
            ];
          ?>
            <tr>
              <td class="cf"><?= $pi++ ?></td>
              <td>
                <span class="tag <?= $pdStatus === 'active' ? 'tag-ok' : 'tag-warn' ?>"><?= $pdStatus === 'active' ? 'فعال' : 'غیرفعال' ?></span>
              </td>
              <td class="cn"><?= $pdType === 'percent' ? 'درصد' : 'مبلغ' ?></td>
              <td class="cn"><?= $pdType === 'percent' ? ($pdAmount . '٪') : (number_format($pdAmount) . ' ت') ?></td>
              <td class="cn"><?= htmlspecialchars($pdUseLabel) ?></td>
              <td class="cn" style="font-size:.78rem;max-width:280px;line-height:1.45">
                <?= htmlspecialchars(discount_scope_label($pdProducts, $productNames, '—', 3)) ?>
                <div style="color:var(--mute)"><?= count($pdProducts) ?> محصول</div>
              </td>
              <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                  <button class="btn btn-ghost btn-sm btn-icon" title="ویرایش"
                    onclick="openProductDiscountModal(<?= htmlspecialchars(json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
                    <?= icon('edit', 13) ?>
                  </button>
                  <a href="discounts.php?tab=product&product_toggle=<?= (int) $pd['id'] ?>&_csrf=<?= csrf_token() ?>"
                    class="btn btn-ghost btn-sm" title="تغییر وضعیت">
                    <?= $pdStatus === 'active' ? 'غیرفعال' : 'فعال' ?>
                  </a>
                  <a href="discounts.php?tab=product&product_delete=<?= (int) $pd['id'] ?>&_csrf=<?= csrf_token() ?>"
                    class="btn btn-no btn-sm btn-icon" title="حذف"
                    data-confirm="حذف این تخفیف روی محصول؟">
                    <?= icon('trash', 13) ?>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<style>
.pd-xor-row{display:flex;align-items:end;gap:10px;flex-wrap:wrap}
.pd-xor-row .field{flex:1;min-width:140px}
.pd-xor-or{color:var(--mute);font-size:.85rem;padding-bottom:10px;font-weight:600}
.pd-picker{max-height:320px;overflow:auto;border:1px solid var(--line);border-radius:10px;background:var(--card-2, transparent)}
.pd-picker .product-order-group{border-bottom:1px solid var(--line);margin:0}
.pd-picker .product-order-group:last-child{border-bottom:0}
.pd-picker .product-order-group-head{padding:10px 12px}
.pd-picker .product-order-group-head-start{gap:8px}
.pd-prod-list{display:flex;flex-direction:column;gap:6px;padding:8px 12px 12px 36px}
.pd-prod-item{display:flex;align-items:center;gap:8px;font-size:.82rem;margin:0;cursor:pointer}
.pd-cat-cb,.pd-prod-cb{width:16px;height:16px;flex-shrink:0}
</style>

<div class="modal-veil" id="productDiscountModal">
  <div class="modal" style="max-width:760px">
    <div class="modal-head">
      <h3 id="pd_modal_title">افزودن تخفیف روی محصول</h3>
      <button class="modal-x" onclick="closeModal('productDiscountModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST" id="pdForm">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" id="pd_action" value="product_add">
        <input type="hidden" name="pd_id" id="pd_id" value="">
        <div class="field" style="margin-bottom:12px">
          <label>وضعیت</label>
          <select name="pd_status" id="pd_status" class="select">
            <option value="active">فعال</option>
            <option value="inactive">غیرفعال</option>
          </select>
        </div>
        <div class="pd-xor-row" style="margin-bottom:14px">
          <div class="field">
            <label>مبلغ تخفیف (تومان)</label>
            <input type="number" name="pd_value" id="pd_value" class="input" min="1" placeholder="مثلاً ۲۰۰۰۰">
          </div>
          <div class="pd-xor-or">یا</div>
          <div class="field">
            <label>درصد تخفیف</label>
            <input type="number" name="pd_percent" id="pd_percent" class="input" min="1" max="100" placeholder="مثلاً ۲۰">
          </div>
        </div>
        <div class="field" style="margin-bottom:14px">
          <label>محدودیت تعداد استفاده</label>
          <input type="number" name="pd_use_limit" id="pd_use_limit" class="input" min="1" placeholder="خالی = نامحدود">
          <small class="cf" style="display:block;margin-top:6px">با هر خرید موفق یک واحد کم می‌شود و در صفر تخفیف غیرفعال می‌گردد.</small>
        </div>
        <div class="field">
          <label>محصولات</label>
          <div class="pd-picker" id="pd_picker">
            <?php if (empty($productPickerSections)): ?>
              <div class="empty" style="padding:24px 12px">دسته‌بندی فعالی با محصول وجود ندارد.</div>
            <?php else: ?>
              <?php foreach ($productPickerSections as $section): ?>
                <details class="product-order-group" data-cat="<?= htmlspecialchars($section['key'], ENT_QUOTES) ?>">
                  <summary class="product-order-group-head">
                    <div class="product-order-group-head-start">
                      <input type="checkbox" class="pd-cat-cb" data-cat="<?= htmlspecialchars($section['key'], ENT_QUOTES) ?>" onclick="event.stopPropagation()">
                      <span class="product-order-group-chevron" aria-hidden="true"><?= icon('chevron-down', 16) ?></span>
                      <div class="product-order-group-title"><?= htmlspecialchars($section['label']) ?></div>
                    </div>
                    <div class="product-order-group-head-end">
                      <span class="tag tag-info"><?= count($section['products']) ?></span>
                    </div>
                  </summary>
                  <div class="pd-prod-list">
                    <?php foreach ($section['products'] as $pp):
                      $code = (string) ($pp['code_product'] ?? '');
                      if ($code === '') continue;
                    ?>
                      <label class="pd-prod-item">
                        <input type="checkbox" name="pd_products[]" class="pd-prod-cb" data-cat="<?= htmlspecialchars($section['key'], ENT_QUOTES) ?>" value="<?= htmlspecialchars($code) ?>">
                        <?= htmlspecialchars((string) ($pp['name_product'] ?? $code)) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <small class="cf" style="display:block;margin-top:6px">چک‌باکس دسته همه محصولات را انتخاب می‌کند. برای انتخاب تکی، عنوان دسته را باز کنید.</small>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary" id="pd_submit"><?= icon('plus', 13) ?> ذخیره</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('productDiscountModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var valueInput = document.getElementById('pd_value');
  var percentInput = document.getElementById('pd_percent');
  function syncXor() {
    if (!valueInput || !percentInput) return;
    if (document.activeElement === valueInput || (valueInput.value !== '' && percentInput.value === '')) {
      percentInput.disabled = valueInput.value !== '';
      if (valueInput.value !== '') percentInput.value = '';
    } else if (document.activeElement === percentInput || percentInput.value !== '') {
      valueInput.disabled = percentInput.value !== '';
      if (percentInput.value !== '') valueInput.value = '';
    } else {
      valueInput.disabled = false;
      percentInput.disabled = false;
    }
  }
  if (valueInput) valueInput.addEventListener('input', function () {
    if (this.value !== '') {
      percentInput.value = '';
      percentInput.disabled = true;
    } else {
      percentInput.disabled = false;
    }
  });
  if (percentInput) percentInput.addEventListener('input', function () {
    if (this.value !== '') {
      valueInput.value = '';
      valueInput.disabled = true;
    } else {
      valueInput.disabled = false;
    }
  });

  function updateCatBox(cat) {
    var picker = document.getElementById('pd_picker');
    if (!picker) return;
    var group = picker.querySelector('.product-order-group[data-cat="' + CSS.escape(cat) + '"]');
    if (!group) return;
    var catCb = group.querySelector('.pd-cat-cb');
    var items = group.querySelectorAll('.pd-prod-cb');
    if (!catCb || !items.length) return;
    var checked = 0;
    items.forEach(function (cb) { if (cb.checked) checked++; });
    catCb.checked = checked === items.length;
    catCb.indeterminate = checked > 0 && checked < items.length;
  }

  function updateAllCatBoxes() {
    document.querySelectorAll('#pd_picker .product-order-group').forEach(function (group) {
      updateCatBox(group.getAttribute('data-cat') || '');
    });
  }

  document.getElementById('pd_picker') && document.getElementById('pd_picker').addEventListener('change', function (e) {
    var t = e.target;
    if (!t) return;
    if (t.classList.contains('pd-cat-cb')) {
      var group = t.closest('.product-order-group');
      if (!group) return;
      group.querySelectorAll('.pd-prod-cb').forEach(function (cb) { cb.checked = t.checked; });
      t.indeterminate = false;
    } else if (t.classList.contains('pd-prod-cb')) {
      updateCatBox(t.getAttribute('data-cat') || '');
    }
  });

  window.openProductDiscountModal = function (row) {
    var form = document.getElementById('pdForm');
    var title = document.getElementById('pd_modal_title');
    var action = document.getElementById('pd_action');
    var idInput = document.getElementById('pd_id');
    var submit = document.getElementById('pd_submit');
    form.reset();
    valueInput.disabled = false;
    percentInput.disabled = false;
    document.querySelectorAll('#pd_picker .pd-prod-cb, #pd_picker .pd-cat-cb').forEach(function (cb) {
      cb.checked = false;
      cb.indeterminate = false;
    });
    document.querySelectorAll('#pd_picker .product-order-group').forEach(function (g) { g.open = false; });
    if (row && row.id) {
      title.textContent = 'ویرایش تخفیف روی محصول';
      action.value = 'product_edit';
      idInput.value = row.id;
      document.getElementById('pd_status').value = row.status || 'active';
      var useLimitInput = document.getElementById('pd_use_limit');
      if (useLimitInput) useLimitInput.value = row.use_limit || '';
      if (row.type === 'percent') {
        percentInput.value = row.amount || '';
        valueInput.value = '';
        valueInput.disabled = true;
        percentInput.disabled = false;
      } else {
        valueInput.value = row.amount || '';
        percentInput.value = '';
        percentInput.disabled = true;
        valueInput.disabled = false;
      }
      (row.products || []).forEach(function (code) {
        var cb = document.querySelector('#pd_picker .pd-prod-cb[value="' + CSS.escape(code) + '"]');
        if (cb) cb.checked = true;
      });
      updateAllCatBoxes();
      submit.innerHTML = <?= json_encode(icon('check', 13) . ' ذخیره تغییرات', JSON_UNESCAPED_UNICODE) ?>;
    } else {
      title.textContent = 'افزودن تخفیف روی محصول';
      action.value = 'product_add';
      idInput.value = '';
      document.getElementById('pd_status').value = 'active';
      submit.innerHTML = <?= json_encode(icon('plus', 13) . ' ذخیره', JSON_UNESCAPED_UNICODE) ?>;
    }
    openModal('productDiscountModal');
  };

  document.getElementById('pdForm').addEventListener('submit', function () {
    if (valueInput) valueInput.disabled = false;
    if (percentInput) percentInput.disabled = false;
  });
})();
</script>

<?php include __DIR__ . '/inc/layout_foot.php';
return;
endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="font-size:.85rem;color:var(--mute)"><?= count($discounts) ?> کد تخفیف</div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 14) ?> افزودن کد تخفیف</button>
</div>

<?php if ($usageCode !== ''): ?>
<div class="card fade-up" style="margin-bottom:16px">
  <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <div>
      <div class="card-title">آمار استفاده از کد <code><?= htmlspecialchars($usageCode) ?></code></div>
      <div class="card-subtitle">خرید و تمدیدهایی که با این کد انجام شده‌اند</div>
    </div>
    <a href="discounts.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 14) ?> بازگشت</a>
  </div>
  <?php
    $st = $usageStats[$usageCode] ?? ['total' => 0, 'buys' => 0, 'extends' => 0, 'users' => 0];
    $counterUsed = (int) ($usageDetail['usedDiscount'] ?? $st['total']);
  ?>
  <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px">
    <div><div class="cf" style="font-size:.75rem">کل استفاده</div><div class="cs" style="font-size:1.2rem"><?= $counterUsed ?></div></div>
    <div><div class="cf" style="font-size:.75rem">خرید ثبت‌شده</div><div class="cs" style="font-size:1.2rem"><?= $st['buys'] ?></div></div>
    <div><div class="cf" style="font-size:.75rem">تمدید ثبت‌شده</div><div class="cs" style="font-size:1.2rem"><?= $st['extends'] ?></div></div>
    <div><div class="cf" style="font-size:.75rem">کاربر یکتا</div><div class="cs" style="font-size:1.2rem"><?= $st['users'] ?></div></div>
  </div>
  <?php if (!empty($productBreakdown)): ?>
    <div class="tbl-wrap" style="margin-top:8px">
      <table class="tbl">
        <thead><tr><th>محصول</th><th>تعداد</th></tr></thead>
        <tbody>
          <?php foreach ($productBreakdown as $pb): ?>
            <tr>
              <td class="cs"><?= htmlspecialchars((string) $pb['product_label']) ?></td>
              <td class="cn"><?= (int) $pb['cnt'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <div class="tbl-wrap" style="margin-top:12px">
    <?php if (empty($usageRows)): ?>
      <div class="empty" style="padding:28px 16px">
        <p>هنوز جزئیات خریدی برای این کد ثبت نشده است.</p>
        <p style="color:var(--mute);font-size:.8rem;margin-top:6px">شمارنده کلی: <?= $counterUsed ?> — جزئیات از این به‌بعد برای استفاده‌های جدید ذخیره می‌شود.</p>
      </div>
    <?php else: ?>
      <table class="tbl-lg">
        <thead>
          <tr>
            <th>#</th>
            <th>کاربر</th>
            <th>نوع</th>
            <th>محصول</th>
            <th>پنل</th>
            <th>مبلغ</th>
            <th>زمان</th>
          </tr>
        </thead>
        <tbody>
          <?php $ui = 1; foreach ($usageRows as $u): ?>
            <tr>
              <td class="cf"><?= $ui++ ?></td>
              <td class="cm"><a href="user.php?id=<?= urlencode((string) $u['id_user']) ?>"><?= htmlspecialchars((string) $u['id_user']) ?></a></td>
              <td class="cn"><?= ($u['type'] ?? '') === 'extend' ? 'تمدید' : 'خرید' ?></td>
              <td class="cs"><?= htmlspecialchars((string) ($u['name_product'] ?: ($u['code_product'] ?: '—'))) ?></td>
              <td class="cn" style="font-size:.78rem"><?= htmlspecialchars((string) ($u['name_panel'] ?: ($u['code_panel'] ?: '—'))) ?></td>
              <td class="cn" style="font-size:.78rem">
                <?php if ($u['price_final'] !== null && $u['price_final'] !== ''): ?>
                  <?= number_format((int) $u['price_final']) ?> ت
                  <?php if ($u['price_original'] !== null && $u['price_original'] !== '' && (int) $u['price_original'] !== (int) $u['price_final']): ?>
                    <span class="cf">(<del><?= number_format((int) $u['price_original']) ?></del>)</span>
                  <?php endif; ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="cn" style="font-size:.75rem"><?= !empty($u['created_at']) ? date('Y/m/d H:i', (int) $u['created_at']) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card fade-up d1">
  <div class="toolbar">
    <div class="toolbar-title">فهرست کدهای تخفیف <small>(<?= count($discounts) ?>)</small></div>
    <form method="GET" class="toolbar-end">
      <div class="search-box" style="min-width:220px">
        <?= icon('search', 14) ?>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="جستجوی کد...">
        <button type="button" class="search-clear">✕</button>
      </div>
      <button type="submit" class="btn btn-ghost btn-sm">فیلتر</button>
    </form>
  </div>

  <?php if (empty($discounts)): ?>
    <div class="empty" style="padding:60px 20px">
      <p><?= $search !== '' ? 'کدی یافت نشد' : 'هنوز کد تخفیفی ثبت نکرده‌اید' ?></p>
      <?php if ($search === ''): ?>
        <button class="btn btn-primary" style="margin-top:14px" onclick="openModal('addModal')"><?= icon('plus', 14) ?> ساخت اولین کد</button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl-lg">
        <thead>
          <tr>
            <th>#</th>
            <th>کد</th>
            <th>درصد</th>
            <th>استفاده</th>
            <th>خرید / تمدید</th>
            <th>گروه</th>
            <th>محدوده</th>
            <th>انقضا</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1;
          foreach ($discounts as $d):
            $productVals = discount_scope_values($d['code_product'] ?? 'all');
            $panelVals = discount_scope_values($d['code_panel'] ?? '/all');
            $categoryVals = discount_scope_values($d['code_category'] ?? 'all');
            $used = (int) ($d['usedDiscount'] ?? 0);
            $limit = (int) ($d['limitDiscount'] ?? 0);
            $codeKey = (string) ($d['codeDiscount'] ?? '');
            $st = $usageStats[$codeKey] ?? ['total' => 0, 'buys' => 0, 'extends' => 0, 'users' => 0];
            $editPayload = [
              'id' => $d['id'] ?? '',
              'code' => $d['codeDiscount'] ?? '',
              'percent' => $d['price'] ?? '',
              'limit_use' => $d['limitDiscount'] ?? '',
              'useuser' => $d['useuser'] ?? '',
              'used' => $d['usedDiscount'] ?? '0',
              'agent' => $d['agent'] ?? 'allusers',
              'usefirst' => $d['usefirst'] ?? '0',
              'type' => $d['type'] ?? 'all',
              'code_panel' => $panelVals,
              'code_product' => $productVals,
              'code_category' => $categoryVals,
              'time' => $d['time'] ?? '0',
              'expiry_label' => discount_expiry_label($d['time'] ?? '0'),
            ];
          ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cs"><code><?= htmlspecialchars($codeKey) ?></code></td>
              <td class="cn"><?= htmlspecialchars((string) ($d['price'] ?? '0')) ?>٪</td>
              <td class="cn"><?= $used ?> / <?= $limit ?></td>
              <td class="cn" style="font-size:.78rem">
                <?= $st['buys'] ?> خرید · <?= $st['extends'] ?> تمدید
                <div style="color:var(--mute)"><?= $st['users'] ?> کاربر</div>
              </td>
              <td><span class="tag tag-plain"><?= htmlspecialchars(discount_agent_label((string) ($d['agent'] ?? ''))) ?></span></td>
              <td class="cn" style="font-size:.75rem;max-width:220px;line-height:1.45">
                <div><span style="color:var(--mute)">محصول:</span> <?= htmlspecialchars(discount_scope_label($productVals, $productNames, 'همه')) ?></div>
                <div><span style="color:var(--mute)">دسته:</span> <?= htmlspecialchars(discount_scope_label($categoryVals, $categoryNames, 'همه')) ?></div>
                <div><span style="color:var(--mute)">پنل:</span> <?= htmlspecialchars(discount_scope_label($panelVals, $panelNames, 'همه')) ?></div>
                <div style="color:var(--mute)">
                  <?= htmlspecialchars(discount_type_label((string) ($d['type'] ?? 'all'))) ?>
                  <?= ($d['usefirst'] ?? '0') === '1' ? ' · خرید اول' : '' ?>
                </div>
              </td>
              <td class="cn" style="font-size:.78rem"><?= htmlspecialchars(discount_expiry_label($d['time'] ?? '0')) ?></td>
              <td>
                <div style="display:flex;gap:5px">
                  <a href="discounts.php?usage=<?= urlencode($codeKey) ?>" class="btn btn-ghost btn-sm btn-icon" title="آمار استفاده">
                    <?= icon('chart', 13) ?>
                  </a>
                  <button class="btn btn-ghost btn-sm btn-icon" title="ویرایش"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
                    <?= icon('edit', 13) ?>
                  </button>
                  <a href="discounts.php?delete=<?= (int) $d['id'] ?>&_csrf=<?= csrf_token() ?>"
                    class="btn btn-no btn-sm btn-icon" title="حذف"
                    data-confirm="حذف کد تخفیف «<?= htmlspecialchars($codeKey) ?>»؟">
                    <?= icon('trash', 13) ?>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php
function discount_scope_checklist(string $name, string $prefix, array $items, string $valueKey, string $labelKey, string $allValue, string $sectionLabel): void
{
  $pid = $prefix !== '' ? $prefix . '_' : '';
  ?>
  <div class="field full">
    <label><?= htmlspecialchars($sectionLabel) ?> (چند انتخابی)</label>
    <div class="discount-scope-box" id="<?= $pid ?>scope_<?= htmlspecialchars($name) ?>">
      <label class="discount-scope-item discount-scope-all">
        <input type="checkbox" class="discount-scope-all-cb" data-group="<?= htmlspecialchars($name) ?>" data-prefix="<?= htmlspecialchars($prefix) ?>" value="<?= htmlspecialchars($allValue) ?>" checked>
        همه
      </label>
      <?php foreach ($items as $item):
        $val = (string) ($item[$valueKey] ?? '');
        $lab = (string) ($item[$labelKey] ?? $val);
        if ($val === '') continue;
      ?>
        <label class="discount-scope-item">
          <input type="checkbox" name="<?= htmlspecialchars($name) ?>[]" class="discount-scope-item-cb" data-group="<?= htmlspecialchars($name) ?>" data-prefix="<?= htmlspecialchars($prefix) ?>" value="<?= htmlspecialchars($val) ?>">
          <?= htmlspecialchars($lab) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <small class="cf" style="display:block;margin-top:4px">اگر «همه» انتخاب باشد، محدودیتی روی این بخش اعمال نمی‌شود. می‌توانید چند مورد را هم‌زمان انتخاب کنید.</small>
  </div>
  <?php
}

function discount_form_fields(string $prefix = ''): void
{
  global $products, $panels, $categories;
  $id = static function (string $name) use ($prefix): string {
    return $prefix !== '' ? $prefix . '_' . $name : $name;
  };
  ?>
  <div class="form-grid">
    <div class="field">
      <label>کد تخفیف *</label>
      <input type="text" name="code" id="<?= $id('code') ?>" class="input" placeholder="مثلاً summer20" pattern="[A-Za-z0-9]+" required>
      <small class="cf" style="display:block;margin-top:4px">فقط حروف انگلیسی و عدد</small>
    </div>
    <div class="field">
      <label>درصد تخفیف *</label>
      <input type="number" name="percent" id="<?= $id('percent') ?>" class="input" min="1" max="100" placeholder="۲۰" required>
    </div>
    <div class="field">
      <label>محدودیت کل استفاده *</label>
      <input type="number" name="limit_use" id="<?= $id('limit_use') ?>" class="input" min="1" placeholder="۱۰۰" required>
    </div>
    <div class="field">
      <label>محدودیت هر کاربر *</label>
      <input type="number" name="useuser" id="<?= $id('useuser') ?>" class="input" min="1" placeholder="۱" required>
    </div>
    <div class="field">
      <label>گروه کاربری</label>
      <select name="agent" id="<?= $id('agent') ?>" class="select">
        <option value="allusers">همه کاربران</option>
        <option value="f">کاربر عادی</option>
        <option value="n">نماینده</option>
        <option value="n2">نماینده پیشرفته</option>
      </select>
    </div>
    <div class="field">
      <label>محدودیت خرید</label>
      <select name="usefirst" id="<?= $id('usefirst') ?>" class="select" onchange="discountToggleType('<?= htmlspecialchars($prefix, ENT_QUOTES) ?>')">
        <option value="0">تمام خریدها</option>
        <option value="1">فقط خرید اول</option>
      </select>
    </div>
    <div class="field" id="<?= $id('type_wrap') ?>">
      <label>کاربرد کد</label>
      <select name="type" id="<?= $id('type') ?>" class="select">
        <option value="all">خرید و تمدید</option>
        <option value="buy">فقط خرید</option>
        <option value="extend">فقط تمدید</option>
      </select>
    </div>
    <div class="field">
      <label>مدت اعتبار (ساعت)</label>
      <input type="number" name="time_hours" id="<?= $id('time_hours') ?>" class="input" min="0" value="0" placeholder="۰ = نامحدود">
      <small class="cf" style="display:block;margin-top:4px">۰ یعنی بدون انقضا</small>
    </div>
    <?php
    discount_scope_checklist('code_panel', $prefix, $panels, 'code_panel', 'name_panel', '/all', 'پنل');
    discount_scope_checklist('code_category', $prefix, $categories, 'remark', 'remark', 'all', 'دسته‌بندی');
    discount_scope_checklist('code_product', $prefix, $products, 'code_product', 'name_product', 'all', 'محصول');
    ?>
  </div>
  <?php
}
?>

<style>
.discount-scope-box{
  max-height:160px;overflow:auto;border:1px solid var(--line);border-radius:10px;
  padding:8px 10px;display:flex;flex-direction:column;gap:6px;background:var(--card-2, transparent);
}
.discount-scope-item{display:flex;align-items:center;gap:8px;font-size:.82rem;margin:0;cursor:pointer}
.discount-scope-all{font-weight:600;padding-bottom:4px;border-bottom:1px solid var(--line);margin-bottom:2px}
</style>

<div class="modal-veil" id="addModal">
  <div class="modal" style="max-width:760px">
    <div class="modal-head">
      <h3>افزودن کد تخفیف</h3>
      <button class="modal-x" onclick="closeModal('addModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add">
        <?php discount_form_fields('add'); ?>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> ذخیره</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="editModal">
  <div class="modal" style="max-width:760px">
    <div class="modal-head">
      <h3>ویرایش کد تخفیف</h3>
      <button class="modal-x" onclick="closeModal('editModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="edit_id" id="edit_id">
        <div class="field" style="margin-bottom:12px">
          <label>تعداد استفاده فعلی</label>
          <input type="text" id="edit_used" class="input" disabled>
        </div>
        <?php discount_form_fields('edit'); ?>
        <div class="field" style="margin-top:12px">
          <label style="display:flex;align-items:center;gap:8px;font-size:.85rem">
            <input type="checkbox" name="update_time" id="edit_update_time" value="1" onchange="document.getElementById('edit_time_hours').disabled=!this.checked">
            تغییر مدت اعتبار
          </label>
          <small class="cf" id="edit_expiry_hint" style="display:block;margin-top:4px"></small>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> ذخیره تغییرات</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>

<script>
window.discountToggleType = function (prefix) {
  var usefirst = document.getElementById(prefix ? prefix + '_usefirst' : 'usefirst');
  var wrap = document.getElementById(prefix ? prefix + '_type_wrap' : 'type_wrap');
  if (!usefirst || !wrap) return;
  wrap.style.display = String(usefirst.value) === '1' ? 'none' : '';
};

function scopeBox(prefix, group) {
  return document.getElementById((prefix ? prefix + '_' : '') + 'scope_' + group);
}

function setScopeValues(prefix, group, values, allToken) {
  var box = scopeBox(prefix, group);
  if (!box) return;
  values = Array.isArray(values) ? values : [values];
  var isAll = !values.length || values.some(function (v) { return v === 'all' || v === '/all' || v === allToken; });
  var allCb = box.querySelector('.discount-scope-all-cb');
  var items = box.querySelectorAll('.discount-scope-item-cb');
  if (allCb) allCb.checked = isAll;
  items.forEach(function (cb) {
    cb.checked = !isAll && values.indexOf(cb.value) !== -1;
    cb.disabled = isAll;
  });
  // ensure missing selected values still appear
  if (!isAll) {
    values.forEach(function (v) {
      if (v === allToken || v === 'all' || v === '/all') return;
      var found = Array.prototype.some.call(items, function (cb) { return cb.value === v; });
      if (!found) {
        var label = document.createElement('label');
        label.className = 'discount-scope-item';
        label.innerHTML = '<input type="checkbox" name="' + group + '[]" class="discount-scope-item-cb" data-group="' + group + '" data-prefix="' + prefix + '" value="' + v.replace(/"/g, '&quot;') + '" checked> ' + v;
        box.appendChild(label);
      }
    });
  }
}

function wireScopeBoxes(root) {
  (root || document).querySelectorAll('.discount-scope-box').forEach(function (box) {
    if (box.dataset.wired === '1') return;
    box.dataset.wired = '1';
    box.addEventListener('change', function (e) {
      var t = e.target;
      if (!t || t.type !== 'checkbox') return;
      var allCb = box.querySelector('.discount-scope-all-cb');
      var items = box.querySelectorAll('.discount-scope-item-cb');
      if (t.classList.contains('discount-scope-all-cb')) {
        if (t.checked) {
          items.forEach(function (cb) { cb.checked = false; cb.disabled = true; });
        } else {
          items.forEach(function (cb) { cb.disabled = false; });
          var any = Array.prototype.some.call(items, function (cb) { return cb.checked; });
          if (!any && items[0]) items[0].checked = true;
        }
      } else if (t.classList.contains('discount-scope-item-cb')) {
        if (t.checked && allCb) {
          allCb.checked = false;
          items.forEach(function (cb) { cb.disabled = false; });
        }
        var anyChecked = Array.prototype.some.call(items, function (cb) { return cb.checked; });
        if (!anyChecked && allCb) {
          allCb.checked = true;
          items.forEach(function (cb) { cb.checked = false; cb.disabled = true; });
        }
      }
    });
  });
}

window.openEditModal = function (d) {
  document.getElementById('edit_id').value = d.id || '';
  document.getElementById('edit_code').value = d.code || '';
  document.getElementById('edit_percent').value = d.percent || '';
  document.getElementById('edit_limit_use').value = d.limit_use || '';
  document.getElementById('edit_useuser').value = d.useuser || '';
  document.getElementById('edit_used').value = (d.used || '0') + ' بار';
  document.getElementById('edit_agent').value = d.agent || 'allusers';
  document.getElementById('edit_usefirst').value = d.usefirst || '0';
  document.getElementById('edit_type').value = d.type || 'all';
  setScopeValues('edit', 'code_panel', d.code_panel || ['/all'], '/all');
  setScopeValues('edit', 'code_category', d.code_category || ['all'], 'all');
  setScopeValues('edit', 'code_product', d.code_product || ['all'], 'all');
  document.getElementById('edit_update_time').checked = false;
  document.getElementById('edit_time_hours').value = '0';
  document.getElementById('edit_time_hours').disabled = true;
  document.getElementById('edit_expiry_hint').textContent = 'انقضای فعلی: ' + (d.expiry_label || 'نامحدود');
  discountToggleType('edit');
  openModal('editModal');
};

document.addEventListener('DOMContentLoaded', function () {
  wireScopeBoxes(document);
  discountToggleType('add');
  discountToggleType('edit');
  setScopeValues('add', 'code_panel', ['/all'], '/all');
  setScopeValues('add', 'code_category', ['all'], 'all');
  setScopeValues('add', 'code_product', ['all'], 'all');
  var editHours = document.getElementById('edit_time_hours');
  if (editHours) editHours.disabled = true;
});

// When "all" is checked, still submit the all-token via a hidden input
document.querySelectorAll('#addModal form, #editModal form').forEach(function (form) {
  form.addEventListener('submit', function () {
    ['code_panel', 'code_category', 'code_product'].forEach(function (group) {
      var allToken = group === 'code_panel' ? '/all' : 'all';
      var boxes = form.querySelectorAll('[id$="scope_' + group + '"]');
      boxes.forEach(function (box) {
        var allCb = box.querySelector('.discount-scope-all-cb');
        if (allCb && allCb.checked) {
          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = group + '[]';
          hidden.value = allToken;
          form.appendChild(hidden);
        }
      });
    });
  });
});
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
