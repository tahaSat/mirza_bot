<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/panels_lib.php';
require_once __DIR__ . '/inc/affiliates_lib.php';
require_administrator();
$pdo = panel_ensure_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    csrf_check_post();
    try {
        affiliates_lib_save_settings($pdo, [
            'status' => !empty($_POST['status']),
            'percentage' => $_POST['percentage'] ?? '0',
            'status_commission' => !empty($_POST['status_commission']),
            'discount' => !empty($_POST['discount']),
            'price_discount' => $_POST['price_discount'] ?? '0',
            'porsant_one_buy' => !empty($_POST['porsant_one_buy']),
        ]);
        flash('success', 'تنظیمات پورسانت ذخیره شد.');
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    } catch (Exception $e) {
        error_log('affiliates save_settings: ' . $e->getMessage());
        flash('error', 'ذخیره تنظیمات ناموفق بود.');
    }
    header('Location: affiliates.php');
    exit;
}

$settings = [
    'status' => 'offaffiliates',
    'percentage' => '0',
    'status_commission' => 'oncommission',
    'discount' => 'onDiscountaffiliates',
    'price_discount' => '0',
    'porsant_one_buy' => 'off_buy_porsant',
];
try {
    $settings = affiliates_lib_settings($pdo);
} catch (Throwable $e) {
    error_log('affiliates settings: ' . $e->getMessage());
}
$listSearch = trim((string) ($_GET['q'] ?? ''));
$listPage = max(1, (int) ($_GET['page'] ?? 1));
$histSearch = trim((string) ($_GET['hq'] ?? ''));
$histPage = max(1, (int) ($_GET['hpage'] ?? 1));
$perPage = 25;

$listResult = ['rows' => [], 'total' => 0];
$histResult = ['rows' => [], 'total' => 0];
try {
    $listResult = affiliates_lib_list_referrers($pdo, $listSearch, $perPage, ($listPage - 1) * $perPage);
} catch (Throwable $e) {
    error_log('affiliates list_referrers: ' . $e->getMessage());
}
try {
    $histResult = affiliates_lib_list_history($pdo, $histSearch, $perPage, ($histPage - 1) * $perPage);
} catch (Throwable $e) {
    error_log('affiliates list_history: ' . $e->getMessage());
}
$listTotal = (int) $listResult['total'];
$histTotal = (int) $histResult['total'];
$listPages = max(1, (int) ceil($listTotal / $perPage));
$histPages = max(1, (int) ceil($histTotal / $perPage));
if ($listPage > $listPages) {
    $listPage = $listPages;
}
if ($histPage > $histPages) {
    $histPage = $histPages;
}

$systemOn = $settings['status'] === 'onaffiliates';
$commissionOn = $settings['status_commission'] === 'oncommission';
$giftOn = $settings['discount'] === 'onDiscountaffiliates';
$firstBuyOnly = $settings['porsant_one_buy'] === 'on_buy_porsant';

$pageTitle = 'پورسانت دعوت';
$pageLede = 'درصد پورسانت، هدیه استارت، و لیست افرادی که از دعوت استفاده کرده‌اند.';
$activeNav = 'referral';
$referralTab = 'commission';
include __DIR__ . '/inc/layout_head.php';
include __DIR__ . '/inc/referral_nav.php';
?>

<div class="card fade-up" style="margin-bottom:16px">
  <div class="card-head">
    <div>
      <div class="card-title">تنظیمات پورسانت</div>
      <div class="card-subtitle">همان تنظیمات بخش زیرمجموعه‌گیری ربات</div>
    </div>
    <span class="tag <?= $systemOn ? 'tag-ok' : 'tag-no' ?>">
      <?= $systemOn ? 'سیستم فعال' : 'سیستم غیرفعال' ?>
    </span>
  </div>
  <form method="POST" class="card-body">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save_settings">
    <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));margin-bottom:16px">
      <label style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;cursor:pointer">
        <input type="checkbox" name="status" value="1" <?= $systemOn ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--ac)">
        <span style="font-size:.85rem">فعال بودن سیستم دعوت</span>
      </label>
      <label style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;cursor:pointer">
        <input type="checkbox" name="status_commission" value="1" <?= $commissionOn ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--ac)">
        <span style="font-size:.85rem">پورسانت بعد از خرید</span>
      </label>
      <label style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;cursor:pointer">
        <input type="checkbox" name="porsant_one_buy" value="1" <?= $firstBuyOnly ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--ac)">
        <span style="font-size:.85rem">پورسانت فقط برای خرید اول</span>
      </label>
      <label style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;cursor:pointer">
        <input type="checkbox" name="discount" value="1" <?= $giftOn ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--ac)">
        <span style="font-size:.85rem">هدیه استارت</span>
      </label>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;max-width:480px">
      <div class="field">
        <label>درصد پورسانت</label>
        <input type="number" name="percentage" class="input" min="0" max="100" value="<?= htmlspecialchars($settings['percentage']) ?>" required>
      </div>
      <div class="field">
        <label>مبلغ هدیه استارت (تومان)</label>
        <input type="number" name="price_discount" class="input" min="0" value="<?= htmlspecialchars($settings['price_discount'] === 'none' ? '0' : (string) $settings['price_discount']) ?>">
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:14px"><?= icon('check', 14) ?> ذخیره تنظیمات</button>
  </form>
</div>

<div class="card fade-up d1" id="list" style="margin-bottom:16px">
  <div class="toolbar" style="flex-wrap:wrap;gap:10px">
    <div class="toolbar-title">لیست دعوت‌کنندگان <small>(<?= number_format($listTotal) ?>)</small></div>
    <form method="GET" class="toolbar-end">
      <?php if ($histSearch !== ''): ?><input type="hidden" name="hq" value="<?= htmlspecialchars($histSearch) ?>"><?php endif; ?>
      <?php if ($histPage > 1): ?><input type="hidden" name="hpage" value="<?= (int) $histPage ?>"><?php endif; ?>
      <div class="search-box" style="min-width:240px">
        <?= icon('search', 15) ?>
        <input type="text" name="q" value="<?= htmlspecialchars($listSearch) ?>" placeholder="آیدی، یوزرنیم یا نام..." autocomplete="off">
        <button type="submit" class="search-btn">جستجو</button>
      </div>
      <?php if ($listSearch !== ''): ?>
        <a href="affiliates.php<?= $histSearch !== '' ? ('?hq=' . urlencode($histSearch) . ($histPage > 1 ? '&hpage=' . (int) $histPage : '') . '#list') : '#list' ?>" class="btn-link" style="font-size:.78rem">پاک کردن</a>
      <?php endif; ?>
    </form>
  </div>
  <?php if (empty($listResult['rows'])): ?>
    <div class="empty" style="padding:48px 20px">
      <p><?= $listSearch !== '' ? 'نتیجه‌ای یافت نشد.' : 'هنوز کسی دعوت ثبت‌شده‌ای ندارد.' ?></p>
    </div>
  <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl-lg">
        <thead>
          <tr>
            <th>شناسه</th>
            <th>یوزرنیم</th>
            <th>نام</th>
            <th>تعداد دعوت</th>
            <th>خریدار سرویس</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($listResult['rows'] as $row): ?>
            <tr>
              <td class="cm"><?= htmlspecialchars((string) $row['id']) ?></td>
              <td><?= !empty($row['username']) ? '@' . htmlspecialchars($row['username']) : '—' ?></td>
              <td><?= !empty($row['namecustom']) ? htmlspecialchars($row['namecustom']) : '—' ?></td>
              <td><?= number_format((int) $row['affiliatescount']) ?></td>
              <td><?= number_format((int) $row['buyer_count']) ?></td>
              <td>
                <a href="user.php?id=<?= (int) $row['id'] ?>" class="btn btn-ghost btn-sm btn-icon" title="مشاهده کاربر"><?= icon('eye', 14) ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($listPages > 1): ?>
      <div class="tbl-foot">
        <span><?= number_format($listTotal) ?> نفر · صفحه <?= $listPage ?> از <?= $listPages ?></span>
        <div class="pager">
          <?php
          $listQs = fn($p) => 'affiliates.php?q=' . urlencode($listSearch)
              . '&hq=' . urlencode($histSearch)
              . '&hpage=' . (int) $histPage
              . '&page=' . $p
              . '#list';
          ?>
          <a class="<?= $listPage <= 1 ? 'dis' : '' ?>" href="<?= $listQs(max(1, $listPage - 1)) ?>">‹</a>
          <?php for ($p = max(1, $listPage - 2); $p <= min($listPages, $listPage + 2); $p++): ?>
            <a class="<?= $p === $listPage ? 'cur' : '' ?>" href="<?= $listQs($p) ?>"><?= $p ?></a>
          <?php endfor; ?>
          <a class="<?= $listPage >= $listPages ? 'dis' : '' ?>" href="<?= $listQs(min($listPages, $listPage + 1)) ?>">›</a>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card fade-up d2" id="history">
  <div class="toolbar" style="flex-wrap:wrap;gap:10px">
    <div class="toolbar-title">تاریخچه دعوت‌ها <small>(<?= number_format($histTotal) ?>)</small></div>
    <form method="GET" class="toolbar-end">
      <?php if ($listSearch !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($listSearch) ?>"><?php endif; ?>
      <?php if ($listPage > 1): ?><input type="hidden" name="page" value="<?= (int) $listPage ?>"><?php endif; ?>
      <div class="search-box" style="min-width:240px">
        <?= icon('search', 15) ?>
        <input type="text" name="hq" value="<?= htmlspecialchars($histSearch) ?>" placeholder="آیدی یا یوزرنیم معرف/دعوت‌شده..." autocomplete="off">
        <button type="submit" class="search-btn">جستجو</button>
      </div>
      <?php if ($histSearch !== ''): ?>
        <a href="affiliates.php<?= $listSearch !== '' ? ('?q=' . urlencode($listSearch) . ($listPage > 1 ? '&page=' . (int) $listPage : '') . '#history') : '#history' ?>" class="btn-link" style="font-size:.78rem">پاک کردن</a>
      <?php endif; ?>
    </form>
  </div>
  <?php if (empty($histResult['rows'])): ?>
    <div class="empty" style="padding:48px 20px">
      <p><?= $histSearch !== '' ? 'نتیجه‌ای یافت نشد.' : 'هنوز تاریخچه دعوتی ثبت نشده است.' ?></p>
    </div>
  <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl-lg">
        <thead>
          <tr>
            <th>معرف</th>
            <th>آیدی معرف</th>
            <th>دعوت‌شده</th>
            <th>آیدی دعوت‌شده</th>
            <th>زمان</th>
            <th>هدیه استارت</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($histResult['rows'] as $row): ?>
            <?php $giftClaimed = (string) ($row['get_gift'] ?? '') === '1' || $row['get_gift'] === true || $row['get_gift'] === 1; ?>
            <tr>
              <td>
                <?php if (!empty($row['reagent'])): ?>
                  <a href="user.php?id=<?= (int) $row['reagent'] ?>" class="btn-link"><?= !empty($row['referrer_username']) ? '@' . htmlspecialchars($row['referrer_username']) : htmlspecialchars((string) $row['reagent']) ?></a>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="cm"><?= htmlspecialchars((string) ($row['reagent'] ?? '')) ?></td>
              <td>
                <?php if (!empty($row['user_id'])): ?>
                  <a href="user.php?id=<?= (int) $row['user_id'] ?>" class="btn-link"><?= !empty($row['invited_username']) ? '@' . htmlspecialchars($row['invited_username']) : htmlspecialchars((string) $row['user_id']) ?></a>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="cm"><?= htmlspecialchars((string) ($row['user_id'] ?? '')) ?></td>
              <td class="cf"><?= htmlspecialchars((string) ($row['time'] ?? '—')) ?></td>
              <td>
                <span class="tag <?= $giftClaimed ? 'tag-ok' : '' ?>"><?= $giftClaimed ? 'دریافت شده' : 'دریافت نشده' ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($histPages > 1): ?>
      <div class="tbl-foot">
        <span><?= number_format($histTotal) ?> رویداد · صفحه <?= $histPage ?> از <?= $histPages ?></span>
        <div class="pager">
          <?php
          $histQs = fn($p) => 'affiliates.php?q=' . urlencode($listSearch)
              . '&page=' . (int) $listPage
              . '&hq=' . urlencode($histSearch)
              . '&hpage=' . $p
              . '#history';
          ?>
          <a class="<?= $histPage <= 1 ? 'dis' : '' ?>" href="<?= $histQs(max(1, $histPage - 1)) ?>">‹</a>
          <?php for ($p = max(1, $histPage - 2); $p <= min($histPages, $histPage + 2); $p++): ?>
            <a class="<?= $p === $histPage ? 'cur' : '' ?>" href="<?= $histQs($p) ?>"><?= $p ?></a>
          <?php endfor; ?>
          <a class="<?= $histPage >= $histPages ? 'dis' : '' ?>" href="<?= $histQs(min($histPages, $histPage + 1)) ?>">›</a>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
