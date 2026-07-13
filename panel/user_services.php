<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/users_lib.php';
require_auth();
$pdo = panel_ensure_pdo();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: users.php');
    exit;
}

$user = db_fetch($pdo, "SELECT * FROM user WHERE id = ?", [$id]);
if (!$user) {
    flash('error', 'کاربر یافت نشد.');
    header('Location: users.php');
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$total = panel_count_user_services($pdo, $id);
$services = panel_fetch_user_services($pdo, $id, $perPage, $offset);
$totalPages = max(1, (int) ceil($total / $perPage));

$displayName = panel_user_display_name($user);
$pageTitle = 'سرویس‌های ' . $displayName;
$activeNav = 'users';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px"
    class="fade-up">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="users.php" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 14) ?> فهرست کاربران</a>
        <a href="user.php?id=<?= $id ?>" class="btn btn-ghost btn-sm"><?= icon('user', 14) ?> مدیریت کاربر</a>
    </div>
    <span class="tag tag-info"><?= number_format($total) ?> سرویس فعال</span>
</div>

<div class="card fade-up">
    <div class="card-head">
        <div>
            <div class="card-title">سرویس‌های <?= htmlspecialchars($displayName) ?></div>
            <div class="card-subtitle">مثل لیست «سرویس‌های خریداری‌شده» در ربات تلگرام</div>
        </div>
    </div>

    <div class="tbl-wrap">
        <table class="tbl-lg">
            <thead>
                <tr>
                    <th>#</th>
                    <th>نام کاربری سرویس</th>
                    <th>محصول</th>
                    <th>پنل</th>
                    <th>حجم</th>
                    <th>زمان</th>
                    <th>تاریخ خرید</th>
                    <th>وضعیت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($services)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty" style="padding:36px">
                                <p>این کاربر سرویس فعالی ندارد</p>
                            </div>
                        </td>
                    </tr>
                <?php else:
                    $i = $offset + 1;
                    foreach ($services as $svc):
                        [$tagClass, $label] = panel_invoice_status_label(panel_invoice_get_status($svc));
                        $isTest = ($svc['name_product'] ?? '') === 'سرویس تست';
                        $volUnit = $isTest ? ' مگابایت' : ' گیگابایت';
                        $timeUnit = $isTest ? ' ساعت' : ' روز';
                        ?>
                        <tr>
                            <td class="cf"><?= $i++ ?></td>
                            <td>
                                <span class="cm" style="color:var(--ac)"><?= htmlspecialchars($svc['username'] ?? '—') ?></span>
                                <?php if (!empty($svc['note']) && $svc['note'] !== 'none'): ?>
                                    <div class="cf" style="margin-top:2px"><?= htmlspecialchars(trunc($svc['note'], 24)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="cs"><?= htmlspecialchars(trunc($svc['name_product'] ?? '—', 24)) ?></td>
                            <td class="cf"><?= htmlspecialchars($svc['Service_location'] ?? '—') ?></td>
                            <td class="cn cf"><?= htmlspecialchars(($svc['Volume'] ?? '—') . $volUnit) ?></td>
                            <td class="cn cf"><?= htmlspecialchars(($svc['Service_time'] ?? '—') . $timeUnit) ?></td>
                            <td class="cf"><?= safe_date($svc['time_sell'] ?? null, 'Y/m/d') ?></td>
                            <td><span class="tag <?= $tagClass ?>"><?= $label ?></span></td>
                            <td>
                                <a href="invoice.php?q=<?= urlencode($svc['username'] ?? '') ?>" class="btn btn-ghost btn-sm btn-icon"
                                    title="جستجو در سفارشات">
                                    <?= icon('search', 13) ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="tbl-foot">
            <span><?= number_format($total) ?> سرویس · صفحه <?= $page ?> از <?= $totalPages ?></span>
            <div class="pager">
                <?php $qs = fn($p) => '?id=' . $id . '&page=' . $p; ?>
                <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                    <a class="<?= $p === $page ? 'cur' : '' ?>" href="<?= $qs($p) ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a class="<?= $page >= $totalPages ? 'dis' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
