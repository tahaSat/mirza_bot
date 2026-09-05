<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();
panel_require_n2();

$agentId = panel_n2_agent_id();
$categories = agent_own_list_categories($agentId);
$products = agent_own_list_products($agentId);
$productCounts = [];
foreach ($products as $prod) {
    $cat = (string) ($prod['category'] ?? '');
    if ($cat === '') {
        continue;
    }
    $productCounts[$cat] = ($productCounts[$cat] ?? 0) + 1;
}

$pageTitle = 'دسته‌بندی‌ها';
$pageLede = 'دسته‌بندی‌های کاتالوگ ربات فروش شما.';
$activeNav = 'n2_categories';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="font-size:.85rem;color:var(--mute)"><?= count($categories) ?> دسته‌بندی</div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 14) ?> افزودن دسته‌بندی</button>
</div>

<div class="card fade-up d1">
  <?php if (empty($categories)): ?>
    <div class="empty" style="padding:60px 20px">
      <p>هنوز دسته‌بندی ثبت نکرده‌اید</p>
      <button class="btn btn-primary" style="margin-top:14px" onclick="openModal('addModal')"><?= icon('plus', 14) ?> اضافه کردن اولین دسته</button>
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
            <th>وضعیت</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1;
          foreach ($categories as $c):
            $isActive = (($c['status'] ?? 'active') === '' || ($c['status'] ?? 'active') === 'active');
            ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cs"><?= htmlspecialchars($c['remark'] ?? '') ?></td>
              <td class="cn"><?= !empty($c['description']) ? htmlspecialchars(trunc((string) $c['description'], 40)) : '<span style="color:var(--mute)">—</span>' ?></td>
              <td class="cn"><?= $productCounts[$c['remark'] ?? ''] ?? 0 ?></td>
              <td><span class="tag <?= $isActive ? 'tag-ok' : 'tag-warn' ?>"><?= $isActive ? 'فعال' : 'غیرفعال' ?></span></td>
              <td>
                <a href="n2_category_action.php?delete=<?= (int) ($c['id'] ?? 0) ?>&_csrf=<?= csrf_token() ?>"
                  class="btn btn-no btn-sm btn-icon" title="حذف"
                  data-confirm="حذف دسته‌بندی «<?= htmlspecialchars((string) ($c['remark'] ?? '')) ?>»؟">
                  <?= icon('trash', 13) ?>
                </a>
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
      <h3>افزودن دسته‌بندی</h3>
      <button class="modal-x" onclick="closeModal('addModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST" action="n2_category_action.php">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add">
        <div class="field">
          <label>نام دسته‌بندی *</label>
          <input type="text" name="remark" class="input" placeholder="مثلاً ماهانه" required maxlength="80">
        </div>
        <div class="field">
          <label>توضیحات (اختیاری)</label>
          <textarea name="description" class="input" rows="3"></textarea>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> ذخیره</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
