<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();
panel_require_n2();

$agentId = panel_n2_agent_id();
$categories = agent_own_list_categories($agentId, true);
$products = agent_own_list_products($agentId);
$panels = agent_n2_visible_panels($agentId);

$pageTitle = 'محصولات';
$pageLede = 'محصولات اختصاصی ربات فروش شما. حجم دلخواه برای نماینده پیشرفته غیرفعال است.';
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
    <div class="tbl-wrap">
      <table class="tbl-lg">
        <thead>
          <tr>
            <th>#</th>
            <th>نام</th>
            <th>دسته</th>
            <th>پنل</th>
            <th>حجم</th>
            <th>مدت</th>
            <th>قیمت</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1;
          foreach ($products as $p): ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cs"><?= htmlspecialchars($p['name_product'] ?? '') ?></td>
              <td><?= htmlspecialchars($p['category'] ?? '—') ?></td>
              <td><?= (($p['Location'] ?? '/all') === '/all') ? 'همه پنل‌های فعال' : htmlspecialchars((string) $p['Location']) ?></td>
              <td class="cn"><?= htmlspecialchars((string) ($p['Volume_constraint'] ?? '0')) ?> GB</td>
              <td class="cn"><?= ((int) ($p['Service_time'] ?? 0)) === 0 ? 'نامحدود' : ((int) $p['Service_time'] . ' روز') ?></td>
              <td class="cn"><?= number_format((int) ($p['price_product'] ?? 0)) ?> ت</td>
              <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                  <button type="button" class="btn btn-ghost btn-sm" onclick="openPanelModal(<?= (int) ($p['id'] ?? 0) ?>, <?= htmlspecialchars(json_encode((string) ($p['Location'] ?? '/all')), ENT_QUOTES) ?>)">پنل</button>
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

<div class="modal-veil" id="panelModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-head">
      <h3>اتصال پنل</h3>
      <button class="modal-x" onclick="closeModal('panelModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST" action="n2_product_action.php">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="set_panel">
        <input type="hidden" name="product_id" id="panel_product_id">
        <div class="field">
          <label>پنل *</label>
          <select name="namepanel" id="panel_name" class="input" required>
            <option value="/all">همه پنل‌های فعال</option>
            <?php foreach ($panels as $pl): ?>
              <option value="<?= htmlspecialchars((string) ($pl['name_panel'] ?? '')) ?>"><?= htmlspecialchars((string) ($pl['name_panel'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary">ذخیره</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('panelModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>
<script>
window.openPanelModal = function (id, location) {
  document.getElementById('panel_product_id').value = id || '';
  var sel = document.getElementById('panel_name');
  if (sel) {
    sel.value = location || '/all';
    if (sel.value !== (location || '/all')) {
      sel.value = '/all';
    }
  }
  openModal('panelModal');
};
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
