<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_once __DIR__ . '/inc/panels_lib.php';
require_administrator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $gid = $_POST['gateway'] ?? '';
        $r = pay_toggle_gateway($pdo, $gid);
        if ($r) {
            flash('success', 'وضعیت درگاه به‌روز شد.');
        } else {
            flash('error', 'درگاه نامعتبر.');
        }
        header('Location: payment_methods.php');
        exit;
    }

    if ($action === 'save_global') {
        pay_set($pdo, 'minbalance', trim($_POST['minbalance'] ?? '20000'));
        pay_set($pdo, 'maxbalance', trim($_POST['maxbalance'] ?? '1000000'));
        flash('success', 'محدودیت شارژ کیف پول ذخیره شد.');
        header('Location: payment_methods.php');
        exit;
    }
}

$gateways = [];
foreach (PAYMENT_GATEWAYS as $id => $gw) {
    $gateways[] = [
        'id' => $id,
        'label' => $gw['label'],
        'enabled' => pay_gateway_enabled($gw),
        'display_name' => pay_textbot_get($pdo, $gw['textbot_key'] ?? '', $gw['label']),
    ];
}

$minBalance = pay_get($pdo, 'minbalance', '20000');
$maxBalance = pay_get($pdo, 'maxbalance', '1000000');

$pageTitle = 'درگاه‌های پرداخت';
$pageLede = 'فعال/غیرفعال کردن روش‌های پرداخت و تنظیم API — همان بخش «💎 مالی» ربات.';
$activeNav = 'payment_methods';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px" class="fade-up">
  <a href="payment.php" class="btn btn-ghost btn-sm"><?= icon('card', 14) ?> تراکنش‌ها</a>
  <a href="payment.php?tab=pending" class="btn btn-ghost btn-sm">رسیدهای در انتظار</a>
</div>

<div class="card fade-up" style="margin-bottom:16px">
  <div class="card-head">
    <div>
      <div class="card-title">محدودیت شارژ کیف پول</div>
      <div class="card-subtitle">حداقل و حداکثر مبلغ شارژ برای همه درگاه‌ها</div>
    </div>
  </div>
  <form method="POST" class="card-body">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save_global">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:480px">
      <div class="field">
        <label>حداقل (تومان)</label>
        <input type="number" name="minbalance" class="input" value="<?= htmlspecialchars($minBalance) ?>" min="0">
      </div>
      <div class="field">
        <label>حداکثر (تومان)</label>
        <input type="number" name="maxbalance" class="input" value="<?= htmlspecialchars($maxBalance) ?>" min="0">
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:12px"><?= icon('check', 14) ?> ذخیره</button>
  </form>
</div>

<div class="card fade-up d1">
  <div class="card-head">
    <div class="card-title">روش‌های پرداخت</div>
  </div>
  <div class="tbl-wrap">
    <table class="tbl-lg">
      <thead>
        <tr>
          <th>درگاه</th>
          <th>نام نمایشی در ربات</th>
          <th>وضعیت</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($gateways as $g): ?>
          <tr>
            <td class="cell-strong"><?= htmlspecialchars($g['label']) ?></td>
            <td style="font-size:.8rem;color:var(--mute)"><?= htmlspecialchars(trunc($g['display_name'], 40)) ?></td>
            <td>
              <span class="tag <?= $g['enabled'] ? 'tag-ok' : 'tag-plain' ?>">
                <?= $g['enabled'] ? 'فعال' : 'غیرفعال' ?>
              </span>
            </td>
            <td style="white-space:nowrap">
              <form method="POST" style="display:inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="gateway" value="<?= htmlspecialchars($g['id']) ?>">
                <button type="submit" class="btn btn-ghost btn-sm">
                  <?= $g['enabled'] ? 'خاموش' : 'روشن' ?>
                </button>
              </form>
              <a href="payment_gateway.php?g=<?= urlencode($g['id']) ?>" class="btn btn-primary btn-sm">تنظیمات</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
