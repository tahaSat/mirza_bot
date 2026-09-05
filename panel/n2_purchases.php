<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();
panel_require_n2();
$pdo = panel_ensure_pdo();

$token = panel_n2_bot_token();
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;
$invoices = [];
$total = 0;

if ($token !== '') {
    try {
        $total = db_count($pdo, 'SELECT COUNT(*) FROM invoice WHERE bottype = ?', [$token]);
        $invoices = db_fetchAll(
            $pdo,
            'SELECT * FROM invoice WHERE bottype = ? ORDER BY time_sell DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            [$token]
        );
    } catch (Exception $e) {
    }
}

$statusMap = [
    'active' => ['tag-ok', 'فعال'],
    'end_of_time' => ['tag-warn', 'منقضی'],
    'end_of_volume' => ['tag-no', 'اتمام حجم'],
    'sendedwarn' => ['tag-warn', 'اخطار'],
    'send_on_hold' => ['tag-plain', 'در انتظار'],
    'unpaid' => ['tag-warn', 'پرداخت‌نشده'],
];

$pageTitle = 'خریدها';
$pageLede = 'سفارش‌های ثبت‌شده در ربات فروش شما.';
$activeNav = 'n2_purchases';
include __DIR__ . '/inc/layout_head.php';
?>

<?php if ($token === ''): ?>
<div class="notice notice-warn fade-up">ربات فروش یافت نشد.</div>
<?php endif; ?>

<div class="card fade-up d1">
  <div class="toolbar">
    <div class="toolbar-title">سفارشات <small>(<?= number_format($total) ?>)</small></div>
  </div>
  <div class="tbl-wrap">
    <table class="tbl-md">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>محصول</th>
          <th>حجم</th>
          <th>قیمت</th>
          <th>تاریخ</th>
          <th>وضعیت</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($invoices)): ?>
          <tr>
            <td colspan="7"><div class="empty" style="padding:40px"><p>سفارشی ثبت نشده</p></div></td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($invoices as $inv):
            [$cls, $lbl] = $statusMap[$inv['Status'] ?? ''] ?? ['tag-plain', $inv['Status'] ?? '—'];
            ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cm"><?= htmlspecialchars((string) ($inv['id_user'] ?? '—')) ?></td>
              <td><?= htmlspecialchars(trunc((string) ($inv['name_product'] ?? '—'), 36)) ?></td>
              <td class="cn"><?= htmlspecialchars((string) ($inv['Volume'] ?? '—')) ?></td>
              <td class="cn"><?= number_format((int) ($inv['price_product'] ?? 0)) ?> ت</td>
              <td class="cf"><?= htmlspecialchars((string) ($inv['time_sell'] ?? '—')) ?></td>
              <td><span class="tag <?= $cls ?>"><?= htmlspecialchars($lbl) ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
