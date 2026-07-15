<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/**
 * True when category.description exists. Never fatals if the column is missing.
 */
function category_has_description_column(PDO $pdo): bool
{
  static $has = null;
  if ($has !== null) {
    return $has;
  }
  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM category LIKE 'description'");
    $has = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $has = false;
  }
  return $has;
}

$hasDescriptionCol = category_has_description_column($pdo);

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
    if ($hasDescriptionCol) {
      db_query($pdo, "INSERT INTO category (remark, description) VALUES (?, ?)", [$remark, $description !== '' ? $description : null]);
    } else {
      db_query($pdo, "INSERT INTO category (remark) VALUES (?)", [$remark]);
      if ($description !== '') {
        flash('warning', 'دسته‌بندی ذخیره شد، ولی ستون description در دیتابیس نیست. ابتدا SQL مهاجرت را اجرا کنید.');
        header('Location: categories.php');
        exit;
      }
    }
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
    $old = db_fetch($pdo, "SELECT remark FROM category WHERE id = ?", [$id]);
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
      if ($hasDescriptionCol) {
        db_query($pdo, "UPDATE category SET remark = ?, description = ? WHERE id = ?", [$remark, $description !== '' ? $description : null, $id]);
      } else {
        db_query($pdo, "UPDATE category SET remark = ? WHERE id = ?", [$remark, $id]);
        if ($description !== '') {
          flash('warning', 'نام دسته ذخیره شد، ولی ستون description در دیتابیس نیست. ابتدا SQL مهاجرت را اجرا کنید.');
          if ($old['remark'] !== $remark) {
            db_query($pdo, "UPDATE product SET category = ? WHERE category = ?", [$remark, $old['remark']]);
          }
          header('Location: categories.php');
          exit;
        }
      }
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
$pageLede = 'مدیریت دسته‌بندی محصولات فروشگاه ربات.';
$activeNav = 'categories';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <div style="font-size:.85rem;color:var(--mute)"><?= count($categories) ?> دسته‌بندی</div>
    <a href="product.php" class="btn btn-ghost btn-sm"><?= icon('package', 14) ?> محصولات</a>
  </div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 14) ?> افزودن دسته‌بندی</button>
</div>

<?php if (!$hasDescriptionCol): ?>
<div class="card fade-up" style="margin-bottom:14px;border-color:#f0ad4e">
  <p style="margin:0;color:var(--mute);font-size:.9rem">ستون <code>description</code> هنوز در دیتابیس نیست. فایل <code>sql/add_category_description.sql</code> را اجرا کنید (یا یک بار وب‌هوک ربات را صدا بزنید تا <code>table.php</code> آن را اضافه کند).</p>
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
            <th>تعداد محصول</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1;
          foreach ($categories as $c): ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cs"><?= htmlspecialchars($c['remark'] ?? '') ?></td>
              <td class="cn" style="max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($c['description'] ?? '') ?>"><?= !empty($c['description']) ? htmlspecialchars(trunc($c['description'], 60)) : '<span style="color:var(--mute)">—</span>' ?></td>
              <td class="cn"><?= $productCounts[$c['remark']] ?? 0 ?></td>
              <td>
                <div style="display:flex;gap:5px">
                  <button class="btn btn-ghost btn-sm btn-icon" title="ویرایش"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode([
                      'id' => $c['id'] ?? '',
                      'remark' => $c['remark'] ?? '',
                      'description' => $c['description'] ?? '',
                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
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

<div class="modal-veil" id="addModal">
  <div class="modal">
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
          <textarea name="description" class="input" rows="4" placeholder="اگر پر شود، به‌جای پیام پیش‌فرض انتخاب سرویس در ربات نمایش داده می‌شود"></textarea>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> ذخیره</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="editModal">
  <div class="modal">
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
          <textarea name="description" id="edit_description" class="input" rows="4" placeholder="اگر پر شود، به‌جای پیام پیش‌فرض انتخاب سرویس در ربات نمایش داده می‌شود"></textarea>
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
window.openEditModal = function (c) {
  document.getElementById('edit_id').value = c.id || '';
  document.getElementById('edit_remark').value = c.remark || '';
  document.getElementById('edit_description').value = c.description || '';
  openModal('editModal');
};
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
