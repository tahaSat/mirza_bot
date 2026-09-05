<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_auth();
panel_require_n2();
$pdo = panel_ensure_pdo();

$token = panel_n2_bot_token();
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;
$payments = [];
$total = 0;

if ($token !== '') {
    try {
        $total = db_count($pdo, 'SELECT COUNT(*) FROM Payment_report WHERE bottype = ?', [$token]);
        $payments = db_fetchAll(
            $pdo,
            'SELECT * FROM Payment_report WHERE bottype = ? ORDER BY time DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            [$token]
        );
    } catch (Exception $e) {
    }
}

$statusMap = [
    'paid' => ['tag-ok', 'موفق'],
    'waiting' => ['tag-warn', 'در انتظار'],
    'reject' => ['tag-no', 'رد شده'],
    'unpaid' => ['tag-warn', 'پرداخت‌نشده'],
];

$pageTitle = 'تراکنش‌ها';
$pageLede = 'پرداخت‌های ثبت‌شده در ربات فروش شما.';
$activeNav = 'n2_payments';
include __DIR__ . '/inc/layout_head.php';
?>

<?php if ($token === ''): ?>
<div class="notice notice-warn fade-up">ربات فروش یافت نشد.</div>
<?php endif; ?>

<div class="card fade-up d1">
  <div class="toolbar">
    <div class="toolbar-title">تراکنش‌ها <small>(<?= number_format($total) ?>)</small></div>
  </div>
  <div class="tbl-wrap">
    <table class="tbl-md">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>شناسه</th>
          <th>روش</th>
          <th>مبلغ</th>
          <th>تاریخ</th>
          <th>وضعیت</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($payments)): ?>
          <tr>
            <td colspan="7"><div class="empty" style="padding:40px"><p>تراکنشی ثبت نشده</p></div></td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($payments as $pay):
            [$cls, $lbl] = $statusMap[$pay['payment_Status'] ?? ''] ?? ['tag-plain', $pay['payment_Status'] ?? '—'];
            ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cm"><?= htmlspecialchars((string) ($pay['id_user'] ?? '—')) ?></td>
              <td class="cm"><?= htmlspecialchars(trunc((string) ($pay['id_order'] ?? '—'), 22)) ?></td>
              <td><?= htmlspecialchars(panel_payment_method_label((string) ($pay['Payment_Method'] ?? ''))) ?></td>
              <td class="cn"><?= number_format((int) ($pay['price'] ?? 0)) ?> ت</td>
              <td class="cf"><?= htmlspecialchars((string) ($pay['time'] ?? '—')) ?></td>
              <td><span class="tag <?= $cls ?>"><?= htmlspecialchars($lbl) ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
