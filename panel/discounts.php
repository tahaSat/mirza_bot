<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();
$pdo = panel_ensure_pdo();
discount_sell_ensure_schema();

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
    db_query($pdo, 'DELETE FROM DiscountSell WHERE id = ?', [$id]);
    flash('success', 'کد تخفیف «' . $code . '» حذف شد.');
  } else {
    flash('error', 'کد تخفیف یافت نشد.');
  }
  header('Location: discounts.php');
  exit;
}

$search = trim($_GET['q'] ?? '');
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
  $categories = db_fetchAll($pdo, 'SELECT remark FROM category ORDER BY remark');
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

$pageTitle = 'کدهای تخفیف';
$pageLede = 'ساخت و مدیریت کد تخفیف با فیلتر چندتایی روی محصول، دسته‌بندی و پنل.';
$activeNav = 'discounts';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="font-size:.85rem;color:var(--mute)"><?= count($discounts) ?> کد تخفیف</div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 14) ?> افزودن کد تخفیف</button>
</div>

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
              <td class="cs"><code><?= htmlspecialchars($d['codeDiscount'] ?? '') ?></code></td>
              <td class="cn"><?= htmlspecialchars((string) ($d['price'] ?? '0')) ?>٪</td>
              <td class="cn"><?= $used ?> / <?= $limit ?></td>
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
                  <button class="btn btn-ghost btn-sm btn-icon" title="ویرایش"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
                    <?= icon('edit', 13) ?>
                  </button>
                  <a href="discounts.php?delete=<?= (int) $d['id'] ?>&_csrf=<?= csrf_token() ?>"
                    class="btn btn-no btn-sm btn-icon" title="حذف"
                    data-confirm="حذف کد تخفیف «<?= htmlspecialchars($d['codeDiscount'] ?? '') ?>»؟">
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
