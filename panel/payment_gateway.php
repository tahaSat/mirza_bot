<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_once __DIR__ . '/inc/panels_lib.php';
require_administrator();

$gid = $_GET['g'] ?? '';
$gw = PAYMENT_GATEWAYS[$gid] ?? null;
if (!$gw) {
    flash('error', 'درگاه نامعتبر است.');
    header('Location: payment_methods.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        foreach ($gw['fields'] as $field) {
            $key = $field['key'];
            if ($field['type'] === 'toggle') {
                $on = !empty($_POST['toggle_' . $key]);
                pay_set($pdo, $key, $on ? $field['on'] : $field['off']);
            } elseif (isset($_POST[$key])) {
                pay_set($pdo, $key, trim((string) $_POST[$key]));
            }
        }
        if (!empty($gw['textbot_key']) && isset($_POST['gateway_display_name'])) {
            pay_textbot_set($pdo, $gw['textbot_key'], trim($_POST['gateway_display_name']));
        }
        flash('success', 'تنظیمات درگاه ذخیره شد.');
        header('Location: payment_gateway.php?g=' . urlencode($gid));
        exit;
    }

    if ($action === 'add_card' && !empty($gw['has_cards'])) {
        $r = pay_add_card($pdo, $_POST['cardnumber'] ?? '', $_POST['namecard'] ?? '');
        flash($r['ok'] ? 'success' : 'error', $r['msg']);
        header('Location: payment_gateway.php?g=cart');
        exit;
    }

    if ($action === 'delete_card' && !empty($gw['has_cards'])) {
        pay_delete_card($pdo, $_POST['cardnumber'] ?? '');
        flash('success', 'شماره کارت حذف شد.');
        header('Location: payment_gateway.php?g=cart');
        exit;
    }
}

$enabled = pay_gateway_enabled($gw);
$displayName = pay_textbot_get($pdo, $gw['textbot_key'] ?? '', $gw['label']);
$cards = !empty($gw['has_cards']) ? pay_list_cards($pdo) : [];

$pageTitle = 'تنظیمات ' . $gw['label'];
$pageLede = ($enabled ? 'فعال' : 'غیرفعال') . ' — همان گزینه‌های تنظیمات این درگاه در ربات تلگرام.';
$activeNav = 'payment_methods';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="margin-bottom:14px" class="fade-up">
  <a href="payment_methods.php" class="btn btn-ghost btn-sm">← همه درگاه‌ها</a>
</div>

<div class="two-col">
  <div class="card fade-up">
    <div class="card-head">
      <div>
        <div class="card-title"><?= htmlspecialchars($gw['label']) ?></div>
        <div class="card-subtitle">
          <span class="tag <?= $enabled ? 'tag-ok' : 'tag-plain' ?>"><?= $enabled ? 'فعال' : 'غیرفعال' ?></span>
        </div>
      </div>
      <form method="POST" action="payment_methods.php">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="gateway" value="<?= htmlspecialchars($gid) ?>">
        <button type="submit" class="btn btn-ghost btn-sm"><?= $enabled ? 'خاموش کردن' : 'فعال کردن' ?></button>
      </form>
    </div>
    <form method="POST" class="card-body">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save">
      <div style="display:flex;flex-direction:column;gap:14px">
        <?php if (!empty($gw['textbot_key'])): ?>
          <div class="field">
            <label>نام دکمه در ربات</label>
            <input type="text" name="gateway_display_name" class="input" value="<?= htmlspecialchars($displayName) ?>">
          </div>
        <?php endif; ?>

        <?php foreach ($gw['fields'] as $field):
          $val = pay_get($pdo, $field['key'], $field['off'] ?? '');
          if ($field['type'] === 'toggle'):
            $isOn = ($val === ($field['on'] ?? ''));
            ?>
            <div class="field" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
              <label style="margin:0"><?= htmlspecialchars($field['label']) ?></label>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" name="toggle_<?= htmlspecialchars($field['key']) ?>" value="1" <?= $isOn ? 'checked' : '' ?>>
                <span class="tag <?= $isOn ? 'tag-ok' : 'tag-plain' ?>"><?= $isOn ? 'روشن' : 'خاموش' ?></span>
              </label>
            </div>
          <?php else: ?>
            <div class="field">
              <label><?= htmlspecialchars($field['label']) ?></label>
              <input type="<?= $field['type'] === 'number' ? 'number' : 'text' ?>" name="<?= htmlspecialchars($field['key']) ?>"
                class="input" value="<?= htmlspecialchars($val) ?>" <?= $field['type'] === 'number' ? 'min="0"' : '' ?>>
            </div>
          <?php endif;
        endforeach; ?>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:16px"><?= icon('check', 14) ?> ذخیره تنظیمات</button>
    </form>
  </div>

  <?php if (!empty($gw['has_cards'])): ?>
    <div class="card fade-up d1">
      <div class="card-head">
        <div class="card-title">شماره‌های کارت</div>
        <div class="card-subtitle">چند کارت — به کاربر به‌صورت تصادفی نمایش داده می‌شود</div>
      </div>
      <form method="POST" class="card-body" style="border-bottom:1px solid var(--bd);padding-bottom:16px;margin-bottom:16px">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_card">
        <div class="field">
          <label>شماره کارت</label>
          <input type="text" name="cardnumber" class="input" inputmode="numeric" required placeholder="6037...">
        </div>
        <div class="field">
          <label>نام صاحب کارت</label>
          <input type="text" name="namecard" class="input" required>
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> افزودن</button>
      </form>
      <?php if (empty($cards)): ?>
        <div class="empty" style="padding:24px"><p>کارتی ثبت نشده</p></div>
      <?php else: ?>
        <div class="kv-list">
          <?php foreach ($cards as $c): ?>
            <div class="kv" style="align-items:center">
              <div>
                <div class="kv-val cm" style="font-size:.82rem"><?= htmlspecialchars($c['cardnumber']) ?></div>
                <div style="font-size:.75rem;color:var(--mute)"><?= htmlspecialchars($c['namecard']) ?></div>
              </div>
              <form method="POST" onsubmit="return confirm('حذف این کارت؟')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete_card">
                <input type="hidden" name="cardnumber" value="<?= htmlspecialchars($c['cardnumber']) ?>">
                <button type="submit" class="btn btn-no btn-sm">حذف</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
