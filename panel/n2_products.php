<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();
panel_require_n2();

$agentId = panel_n2_agent_id();
$allCategories = agent_own_list_categories($agentId, false);
$categories = agent_own_list_categories($agentId, true);
$products = agent_own_list_products($agentId);
$renormSeen = [];
foreach ($products as $productRow) {
    $catKey = trim((string) ($productRow['category'] ?? ''));
    if (isset($renormSeen[$catKey])) {
        continue;
    }
    $renormSeen[$catKey] = true;
    try {
        agent_own_renormalize_category_sort_orders($agentId, $catKey);
    } catch (Throwable $e) {
    }
}
$products = agent_own_list_products($agentId);
$panels = agent_n2_visible_panels($agentId);

$categoryActive = [];
foreach ($allCategories as $cat) {
    $remark = trim((string) ($cat['remark'] ?? ''));
    $status = strtolower(trim((string) ($cat['status'] ?? 'active')));
    $categoryActive[$remark] = ($status === '' || $status === 'active');
}

$productsByCategory = [];
foreach ($products as $productRow) {
    $catKey = trim((string) ($productRow['category'] ?? ''));
    $productsByCategory[$catKey][] = $productRow;
}

$categorySections = [];
$seenCategories = [];
foreach ($allCategories as $cat) {
    $remark = trim((string) ($cat['remark'] ?? ''));
    if (!empty($productsByCategory[$remark])) {
        $categorySections[] = [
            'key' => $remark,
            'label' => $remark,
            'active' => !empty($categoryActive[$remark]),
            'products' => $productsByCategory[$remark],
        ];
        $seenCategories[$remark] = true;
    }
}
foreach ($productsByCategory as $catKey => $categoryProducts) {
    if (isset($seenCategories[$catKey])) {
        continue;
    }
    $categorySections[] = [
        'key' => $catKey,
        'label' => $catKey === '' ? 'بدون دسته‌بندی' : $catKey,
        'active' => !empty($categoryActive[$catKey]),
        'products' => $categoryProducts,
    ];
}

$pageTitle = 'محصولات';
$pageLede = 'محصولات اختصاصی ربات فروش شما. ترتیب نمایش در هر دسته با کشیدن و رها کردن تنظیم می‌شود.';
$activeNav = 'n2_products';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="font-size:.85rem;color:var(--mute)"><?= count($products) ?> محصول</div>
  <button class="btn btn-primary" onclick="openModal('addModal')" <?= empty($categories) ? 'disabled' : '' ?>>
    <?= icon('plus', 14) ?> افزودن محصول
  </button>
</div>

<?php if (empty($categories)): ?>
<div class="notice notice-warn fade-up">ابتدا از صفحه دسته‌بندی‌ها یک دسته بسازید.</div>
<?php endif; ?>
<?php if (empty($panels)): ?>
<div class="notice notice-warn fade-up">هنوز پنل فعالی برای ربات فروش شما در دسترس نیست. از مدیر بخواهید پنل را برای نماینده فعال کند.</div>
<?php endif; ?>

<div class="card fade-up d1">
  <?php if (empty($products)): ?>
    <div class="empty" style="padding:60px 20px">
      <p>هنوز محصولی ثبت نشده</p>
    </div>
  <?php else: ?>
    <div class="toolbar">
      <div class="toolbar-title">فهرست محصولات <small>(<?= count($products) ?>)</small></div>
      <div class="search-box" style="min-width:220px">
        <?= icon('search', 14) ?>
        <input type="text" placeholder="جستجو..." data-filter="prodOrder">
        <button type="button" class="search-clear">✕</button>
      </div>
    </div>
    <div id="prodOrder" class="product-order-list">
      <?php foreach ($categorySections as $section):
        $isActive = !empty($section['active']);
        ?>
        <details class="product-order-group fade-up<?= $isActive ? '' : ' is-inactive' ?>" data-category="<?= htmlspecialchars($section['key'], ENT_QUOTES) ?>">
          <summary class="product-order-group-head">
            <div class="product-order-group-head-start">
              <span class="product-order-group-chevron" aria-hidden="true"><?= icon('chevron-down', 16) ?></span>
              <div class="product-order-group-title"><?= htmlspecialchars($section['label']) ?></div>
            </div>
            <div class="product-order-group-head-end">
              <span class="tag <?= $isActive ? 'tag-ok' : 'tag-warn' ?>"><?= $isActive ? 'فعال' : 'غیرفعال' ?></span>
              <span class="tag tag-info"><?= count($section['products']) ?></span>
            </div>
          </summary>
          <div class="tbl-wrap">
            <table class="tbl-xl product-order-table">
              <thead>
                <tr>
                  <th style="width:42px"></th>
                  <th>ترتیب</th>
                  <th>نام</th>
                  <th>پنل</th>
                  <th>حجم</th>
                  <th>مدت</th>
                  <th>قیمت</th>
                  <th>عملیات</th>
                </tr>
              </thead>
              <tbody class="product-sortable" data-category="<?= htmlspecialchars($section['key'], ENT_QUOTES) ?>">
                <?php foreach ($section['products'] as $index => $p): ?>
                  <tr class="product-sort-row" data-id="<?= (int) ($p['id'] ?? 0) ?>">
                    <td class="product-sort-handle" title="کشیدن برای تغییر ترتیب"><?= icon('menu', 14) ?></td>
                    <td class="cn product-sort-index"><?= $index + 1 ?></td>
                    <td class="cs"><?= htmlspecialchars($p['name_product'] ?? '') ?></td>
                    <td><?= (($p['Location'] ?? '/all') === '/all') ? 'همه پنل‌های فعال' : htmlspecialchars((string) $p['Location']) ?></td>
                    <td class="cn"><?= htmlspecialchars((string) ($p['Volume_constraint'] ?? '0')) ?> GB</td>
                    <td class="cn"><?= ((int) ($p['Service_time'] ?? 0)) === 0 ? 'نامحدود' : ((int) $p['Service_time'] . ' روز') ?></td>
                    <td class="cn"><?= number_format((int) ($p['price_product'] ?? 0)) ?> ت</td>
                    <td>
                      <div style="display:flex;gap:5px;flex-wrap:wrap">
                        <button type="button" class="btn btn-ghost btn-sm btn-icon" title="ویرایش"
                          onclick='openN2EditModal(<?= json_encode([
                            'id' => (int) ($p['id'] ?? 0),
                            'name_product' => (string) ($p['name_product'] ?? ''),
                            'Location' => (string) ($p['Location'] ?? '/all'),
                            'category' => (string) ($p['category'] ?? ''),
                            'price_product' => (int) ($p['price_product'] ?? 0),
                            'Volume_constraint' => (int) ($p['Volume_constraint'] ?? 0),
                            'Service_time' => (int) ($p['Service_time'] ?? 0),
                            'note' => (string) ($p['note'] ?? ''),
                          ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                          <?= icon('edit', 13) ?>
                        </button>
                        <a href="n2_product_action.php?delete=<?= (int) ($p['id'] ?? 0) ?>&_csrf=<?= csrf_token() ?>"
                          class="btn btn-no btn-sm btn-icon" title="حذف"
                          data-confirm="حذف محصول «<?= htmlspecialchars((string) ($p['name_product'] ?? '')) ?>»؟">
                          <?= icon('trash', 13) ?>
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="modal-veil" id="addModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-head">
      <h3>افزودن محصول</h3>
      <button class="modal-x" onclick="closeModal('addModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST" action="n2_product_action.php">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add">
        <div class="field">
          <label>نام محصول *</label>
          <input type="text" name="name_product" class="input" required maxlength="150">
        </div>
        <div class="field">
          <label>پنل *</label>
          <select name="namepanel" class="input" required>
            <option value="/all">همه پنل‌های فعال</option>
            <?php foreach ($panels as $pl): ?>
              <option value="<?= htmlspecialchars((string) ($pl['name_panel'] ?? '')) ?>"><?= htmlspecialchars((string) ($pl['name_panel'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>دسته‌بندی *</label>
          <select name="category" class="input" required>
            <option value="">انتخاب کنید</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= htmlspecialchars((string) ($c['remark'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['remark'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>قیمت (تومان) *</label>
          <input type="number" name="price_product" class="input" min="0" step="1" required>
        </div>
        <div class="field">
          <label>حجم (گیگابایت) *</label>
          <input type="number" name="volume_product" class="input" min="1" step="1" required>
        </div>
        <div class="field">
          <label>مدت (روز — ۰ = نامحدود)</label>
          <input type="number" name="time_product" class="input" min="0" step="1" value="30">
        </div>
        <div class="field">
          <label>یادداشت (اختیاری)</label>
          <textarea name="note" class="input" rows="2"></textarea>
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
  <div class="modal" style="max-width:520px">
    <div class="modal-head">
      <h3>ویرایش محصول</h3>
      <button class="modal-x" onclick="closeModal('editModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST" action="n2_product_action.php">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="product_id" id="n2_edit_id">
        <div class="field">
          <label>نام محصول *</label>
          <input type="text" name="name_product" id="n2_edit_name" class="input" required maxlength="150">
        </div>
        <div class="field">
          <label>پنل *</label>
          <select name="namepanel" id="n2_edit_panel" class="input" required>
            <option value="/all">همه پنل‌های فعال</option>
            <?php foreach ($panels as $pl): ?>
              <option value="<?= htmlspecialchars((string) ($pl['name_panel'] ?? '')) ?>"><?= htmlspecialchars((string) ($pl['name_panel'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>دسته‌بندی *</label>
          <select name="category" id="n2_edit_cat" class="input" required>
            <option value="">انتخاب کنید</option>
            <?php foreach ($allCategories as $c): ?>
              <option value="<?= htmlspecialchars((string) ($c['remark'] ?? '')) ?>"><?= htmlspecialchars((string) ($c['remark'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>قیمت (تومان) *</label>
          <input type="number" name="price_product" id="n2_edit_price" class="input" min="0" step="1" required>
        </div>
        <div class="field">
          <label>حجم (گیگابایت) *</label>
          <input type="number" name="volume_product" id="n2_edit_volume" class="input" min="1" step="1" required>
        </div>
        <div class="field">
          <label>مدت (روز — ۰ = نامحدود)</label>
          <input type="number" name="time_product" id="n2_edit_time" class="input" min="0" step="1">
        </div>
        <div class="field">
          <label>یادداشت (اختیاری)</label>
          <textarea name="note" id="n2_edit_note" class="input" rows="2"></textarea>
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
window.openN2EditModal = function (p) {
  p = p || {};
  document.getElementById('n2_edit_id').value = p.id || '';
  document.getElementById('n2_edit_name').value = p.name_product || '';
  document.getElementById('n2_edit_price').value = p.price_product != null ? p.price_product : '';
  document.getElementById('n2_edit_volume').value = p.Volume_constraint != null ? p.Volume_constraint : '';
  document.getElementById('n2_edit_time').value = p.Service_time != null ? p.Service_time : '';
  document.getElementById('n2_edit_note').value = p.note || '';

  var catSel = document.getElementById('n2_edit_cat');
  if (catSel) {
    catSel.value = p.category || '';
  }

  var panelSel = document.getElementById('n2_edit_panel');
  if (panelSel) {
    var loc = p.Location || '/all';
    panelSel.value = loc;
    if (panelSel.value !== loc) {
      panelSel.value = '/all';
    }
  }
  openModal('editModal');
};
</script>
<script>
window.PRODUCT_CSRF = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;
window.PRODUCT_REORDER_URL = 'n2_product_action.php';
</script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script src="<?= htmlspecialchars(panel_asset('js/product.js')) ?>"></script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
