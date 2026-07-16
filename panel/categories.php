<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

function category_column_exists(PDO $pdo, string $column): bool
{
  static $cache = [];
  if (array_key_exists($column, $cache)) {
    return $cache[$column];
  }
  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM category LIKE " . $pdo->quote($column));
    $cache[$column] = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $cache[$column] = false;
  }
  return $cache[$column];
}

function category_post_agent_json(string $prefix, string $default = '0'): string
{
  return category_encode_agent_json([
    'f' => trim((string) ($_POST[$prefix . '_f'] ?? $default)),
    'n' => trim((string) ($_POST[$prefix . '_n'] ?? $default)),
    'n2' => trim((string) ($_POST[$prefix . '_n2'] ?? $default)),
  ]);
}

function category_post_customvolume_json(): string
{
  return category_encode_agent_json([
    'f' => !empty($_POST['custom_f']) ? '1' : '0',
    'n' => !empty($_POST['custom_n']) ? '1' : '0',
    'n2' => !empty($_POST['custom_n2']) ? '1' : '0',
  ]);
}

$hasDescriptionCol = category_column_exists($pdo, 'description');
$hasCustomCol = category_column_exists($pdo, 'customvolume');

$defaultCustomOff = category_encode_agent_json(['f' => '0', 'n' => '0', 'n2' => '0']);
$defaultPrice = category_encode_agent_json(['f' => '4000', 'n' => '4000', 'n2' => '4000']);
$defaultMainVol = category_encode_agent_json(['f' => '1', 'n' => '1', 'n2' => '1']);
$defaultMaxVol = category_encode_agent_json(['f' => '1000', 'n' => '1000', 'n2' => '1000']);
$defaultMainTime = category_encode_agent_json(['f' => '1', 'n' => '1', 'n2' => '1']);
$defaultMaxTime = category_encode_agent_json(['f' => '365', 'n' => '365', 'n2' => '365']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
  csrf_check_post();
  $remark = trim($_POST['remark'] ?? '');
  $description = trim($_POST['description'] ?? '');
  if ($remark === '') {
    flash('error', 'نام دسته‌بندی الزامی است.');
    header('Location: categories.php');
    exit;
  }
  if (db_count($pdo, "SELECT COUNT(*) FROM category WHERE remark = ?", [$remark])) {
    flash('error', 'دسته‌بندی با این نام قبلاً ثبت شده.');
    header('Location: categories.php');
    exit;
  }
  try {
    $cols = ['remark'];
    $vals = [$remark];
    if ($hasDescriptionCol) {
      $cols[] = 'description';
      $vals[] = $description !== '' ? $description : null;
    }
    if ($hasCustomCol) {
      $cols = array_merge($cols, ['customvolume', 'pricecustomvolume', 'pricecustomtime', 'mainvolume', 'maxvolume', 'maintime', 'maxtime']);
      $vals = array_merge($vals, [
        category_post_customvolume_json(),
        category_post_agent_json('pricecustomvolume', '4000'),
        category_post_agent_json('pricecustomtime', '4000'),
        category_post_agent_json('mainvolume', '1'),
        category_post_agent_json('maxvolume', '1000'),
        category_post_agent_json('maintime', '1'),
        category_post_agent_json('maxtime', '365'),
      ]);
    }
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    db_query($pdo, 'INSERT INTO category (' . implode(',', $cols) . ") VALUES ($placeholders)", $vals);
    flash('success', 'دسته‌بندی «' . $remark . '» اضافه شد.');
  } catch (Exception $e) {
    flash('error', 'خطای پایگاه داده: ' . $e->getMessage());
  }
  header('Location: categories.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
  csrf_check_post();
  $id = (int) ($_POST['edit_id'] ?? 0);
  $remark = trim($_POST['remark'] ?? '');
  $description = trim($_POST['description'] ?? '');
  if ($id && $remark !== '') {
    $old = db_fetch($pdo, "SELECT * FROM category WHERE id = ?", [$id]);
    if (!$old) {
      flash('error', 'دسته‌بندی یافت نشد.');
      header('Location: categories.php');
      exit;
    }
    if ($old['remark'] !== $remark && db_count($pdo, "SELECT COUNT(*) FROM category WHERE remark = ?", [$remark])) {
      flash('error', 'دسته‌بندی با این نام قبلاً ثبت شده.');
      header('Location: categories.php');
      exit;
    }
    try {
      $sets = ['remark = ?'];
      $params = [$remark];
      if ($hasDescriptionCol) {
        $sets[] = 'description = ?';
        $params[] = $description !== '' ? $description : null;
      }
      if ($hasCustomCol) {
        $sets[] = 'customvolume = ?';
        $params[] = category_post_customvolume_json();
        $sets[] = 'pricecustomvolume = ?';
        $params[] = category_post_agent_json('pricecustomvolume', '4000');
        $sets[] = 'pricecustomtime = ?';
        $params[] = category_post_agent_json('pricecustomtime', '4000');
        $sets[] = 'mainvolume = ?';
        $params[] = category_post_agent_json('mainvolume', '1');
        $sets[] = 'maxvolume = ?';
        $params[] = category_post_agent_json('maxvolume', '1000');
        $sets[] = 'maintime = ?';
        $params[] = category_post_agent_json('maintime', '1');
        $sets[] = 'maxtime = ?';
        $params[] = category_post_agent_json('maxtime', '365');
      }
      $params[] = $id;
      db_query($pdo, 'UPDATE category SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
      if ($old['remark'] !== $remark) {
        db_query($pdo, "UPDATE product SET category = ? WHERE category = ?", [$remark, $old['remark']]);
      }
      flash('success', 'دسته‌بندی ویرایش شد.');
    } catch (Exception $e) {
      flash('error', 'خطا: ' . $e->getMessage());
    }
  }
  header('Location: categories.php');
  exit;
}

if (isset($_GET['delete'])) {
  csrf_check_get();
  $id = (int) $_GET['delete'];
  $row = db_fetch($pdo, "SELECT remark FROM category WHERE id = ?", [$id]);
  if ($row) {
    $used = db_count($pdo, "SELECT COUNT(*) FROM product WHERE category = ?", [$row['remark']]);
    if ($used > 0) {
      flash('warning', 'دسته‌بندی حذف شد. ' . $used . ' محصول هنوز به این دسته ارجاع دارند.');
    } else {
      flash('success', 'دسته‌بندی حذف شد.');
    }
    db_query($pdo, "DELETE FROM category WHERE id = ?", [$id]);
  }
  header('Location: categories.php');
  exit;
}

$search = trim($_GET['q'] ?? '');
$params = [];
$whereSQL = '';
if ($search !== '') {
  $whereSQL = 'WHERE remark LIKE ?';
  $params = ["%$search%"];
}

try {
  $categories = db_fetchAll($pdo, "SELECT * FROM category $whereSQL ORDER BY id", $params);
} catch (Exception $e) {
  $categories = [];
}

$productCounts = [];
try {
  $rows = db_fetchAll($pdo, "SELECT category, COUNT(*) AS cnt FROM product WHERE category IS NOT NULL AND category != '' GROUP BY category");
  foreach ($rows as $r) {
    $productCounts[$r['category']] = (int) $r['cnt'];
  }
} catch (Exception $e) {
}

$pageTitle = 'دسته‌بندی‌ها';
$pageLede = 'مدیریت دسته‌بندی محصولات و سرویس دلخواه (حجم/زمان).';
$activeNav = 'categories';
include __DIR__ . '/inc/layout_head.php';

function category_custom_badge(array $c): string
{
  $cv = category_decode_agent_json($c['customvolume'] ?? null, '0');
  $on = [];
  foreach (['f', 'n', 'n2'] as $k) {
    if (($cv[$k] ?? '0') === '1') {
      $on[] = $k;
    }
  }
  return $on ? implode(',', $on) : '';
}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <div style="font-size:.85rem;color:var(--mute)"><?= count($categories) ?> دسته‌بندی</div>
    <a href="product.php" class="btn btn-ghost btn-sm"><?= icon('package', 14) ?> محصولات</a>
  </div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 14) ?> افزودن دسته‌بندی</button>
</div>

<?php if (!$hasCustomCol): ?>
<div class="card fade-up" style="margin-bottom:14px;border-color:#f0ad4e">
  <p style="margin:0;color:var(--mute);font-size:.9rem">ستون‌های سرویس دلخواه روی <code>category</code> هنوز نیستند. فایل <code>sql/add_category_custom_volume.sql</code> را اجرا کنید یا یک بار وب‌هوک ربات را صدا بزنید.</p>
</div>
<?php endif; ?>

<div class="card fade-up d1">
  <div class="toolbar">
    <div class="toolbar-title">فهرست دسته‌بندی‌ها <small>(<?= count($categories) ?>)</small></div>
    <form method="GET" class="toolbar-end">
      <div class="search-box" style="min-width:220px">
        <?= icon('search', 14) ?>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="جستجو...">
        <button type="button" class="search-clear">✕</button>
      </div>
      <button type="submit" class="btn btn-ghost btn-sm">فیلتر</button>
    </form>
  </div>

  <?php if (empty($categories)): ?>
    <div class="empty" style="padding:60px 20px">
      <p><?= $search !== '' ? 'دسته‌بندی‌ای یافت نشد' : 'هنوز دسته‌بندی ثبت نکرده‌اید' ?></p>
      <?php if ($search === ''): ?>
        <button class="btn btn-primary" style="margin-top:14px" onclick="openModal('addModal')"><?= icon('plus', 14) ?> اضافه کردن اولین دسته</button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl-lg">
        <thead>
          <tr>
            <th>#</th>
            <th>نام دسته</th>
            <th>توضیحات</th>
            <th>سرویس دلخواه</th>
            <th>تعداد محصول</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1;
          foreach ($categories as $c):
            $customOn = category_custom_badge($c);
            $editPayload = [
              'id' => $c['id'] ?? '',
              'remark' => $c['remark'] ?? '',
              'description' => $c['description'] ?? '',
              'customvolume' => category_decode_agent_json($c['customvolume'] ?? null, '0'),
              'pricecustomvolume' => category_decode_agent_json($c['pricecustomvolume'] ?? null, '4000'),
              'pricecustomtime' => category_decode_agent_json($c['pricecustomtime'] ?? null, '4000'),
              'mainvolume' => category_decode_agent_json($c['mainvolume'] ?? null, '1'),
              'maxvolume' => category_decode_agent_json($c['maxvolume'] ?? null, '1000'),
              'maintime' => category_decode_agent_json($c['maintime'] ?? null, '1'),
              'maxtime' => category_decode_agent_json($c['maxtime'] ?? null, '365'),
            ];
          ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cs"><?= htmlspecialchars($c['remark'] ?? '') ?></td>
              <td class="cn" style="max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($c['description'] ?? '') ?>"><?= !empty($c['description']) ? htmlspecialchars(trunc($c['description'], 40)) : '<span style="color:var(--mute)">—</span>' ?></td>
              <td class="cn"><?= $customOn !== '' ? htmlspecialchars($customOn) : '<span style="color:var(--mute)">خاموش</span>' ?></td>
              <td class="cn"><?= $productCounts[$c['remark']] ?? 0 ?></td>
              <td>
                <div style="display:flex;gap:5px">
                  <button class="btn btn-ghost btn-sm btn-icon" title="ویرایش"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
                    <?= icon('edit', 13) ?>
                  </button>
                  <a href="categories.php?delete=<?= (int) $c['id'] ?>&_csrf=<?= csrf_token() ?>"
                    class="btn btn-no btn-sm btn-icon" title="حذف"
                    data-confirm="حذف دسته‌بندی «<?= htmlspecialchars($c['remark']) ?>»؟">
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
function category_custom_form_fields(string $idPrefix = ''): void
{
  $pid = $idPrefix !== '' ? $idPrefix . '_' : '';
  ?>
  <div class="field" style="margin-top:8px">
    <label>سرویس دلخواه (حجم و زمان)</label>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px">
      <?php foreach (['f' => 'گروه f', 'n' => 'گروه n', 'n2' => 'گروه n2'] as $k => $label): ?>
        <label style="display:flex;align-items:center;gap:6px;font-size:.85rem">
          <input type="checkbox" name="custom_<?= $k ?>" id="<?= $pid ?>custom_<?= $k ?>" value="1">
          <?= htmlspecialchars($label) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="tbl-wrap" style="margin-top:10px">
    <table class="tbl">
      <thead><tr><th>عنوان</th><th>f</th><th>n</th><th>n2</th></tr></thead>
      <tbody>
        <?php
        $rows = [
          ['قیمت هر گیگ (تومان)', 'pricecustomvolume', '4000'],
          ['قیمت هر روز (تومان)', 'pricecustomtime', '4000'],
          ['حداقل حجم (GB)', 'mainvolume', '1'],
          ['حداکثر حجم (GB)', 'maxvolume', '1000'],
          ['حداقل زمان (روز)', 'maintime', '1'],
          ['حداکثر زمان (روز)', 'maxtime', '365'],
        ];
        foreach ($rows as [$title, $prefix, $def]):
        ?>
          <tr>
            <td class="cs"><?= htmlspecialchars($title) ?></td>
            <?php foreach (['f', 'n', 'n2'] as $ag): ?>
              <td><input type="number" name="<?= $prefix ?>_<?= $ag ?>" id="<?= $pid ?><?= $prefix ?>_<?= $ag ?>" class="input" style="min-width:80px" value="<?= htmlspecialchars($def) ?>"></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
}
?>

<div class="modal-veil" id="addModal">
  <div class="modal" style="max-width:720px">
    <div class="modal-head">
      <h3>افزودن دسته‌بندی</h3>
      <button class="modal-x" onclick="closeModal('addModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add">
        <div class="field">
          <label>نام دسته‌بندی *</label>
          <input type="text" name="remark" class="input" placeholder="مثلاً: VPN، پکیج ماهانه، ..." required>
        </div>
        <div class="field">
          <label>توضیحات (اختیاری)</label>
          <textarea name="description" class="input" rows="3" placeholder="پیام بعد از انتخاب دسته در ربات"></textarea>
        </div>
        <?php if ($hasCustomCol): category_custom_form_fields('add'); endif; ?>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> ذخیره</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="editModal">
  <div class="modal" style="max-width:720px">
    <div class="modal-head">
      <h3>ویرایش دسته‌بندی</h3>
      <button class="modal-x" onclick="closeModal('editModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="edit_id" id="edit_id">
        <div class="field">
          <label>نام دسته‌بندی *</label>
          <input type="text" name="remark" id="edit_remark" class="input" required>
        </div>
        <div class="field">
          <label>توضیحات (اختیاری)</label>
          <textarea name="description" id="edit_description" class="input" rows="3"></textarea>
        </div>
        <?php if ($hasCustomCol): category_custom_form_fields('edit'); endif; ?>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> ذخیره تغییرات</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>

<script>
window.openEditModal = function (c) {
  document.getElementById('edit_id').value = c.id || '';
  document.getElementById('edit_remark').value = c.remark || '';
  document.getElementById('edit_description').value = c.description || '';
  var cv = c.customvolume || {};
  ['f', 'n', 'n2'].forEach(function (k) {
    var el = document.getElementById('edit_custom_' + k);
    if (el) el.checked = String(cv[k] || '0') === '1';
  });
  ['pricecustomvolume', 'pricecustomtime', 'mainvolume', 'maxvolume', 'maintime', 'maxtime'].forEach(function (prefix) {
    var vals = c[prefix] || {};
    ['f', 'n', 'n2'].forEach(function (k) {
      var el = document.getElementById('edit_' + prefix + '_' + k);
      if (el) el.value = vals[k] != null ? vals[k] : '';
    });
  });
  openModal('editModal');
};
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
