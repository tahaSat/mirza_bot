<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();
$pdo = panel_ensure_pdo();

$totalUsers = 0;
$newToday = 0;
$totalRevenue = 0;
$activeNow = 0;
$pendingPay = 0;
$txToday = 0;

try {
    $totalUsers = db_count($pdo, "SELECT COUNT(*) FROM user");
    $newToday = db_count($pdo, "SELECT COUNT(*) FROM user WHERE register > ?", [strtotime('today')]);
} catch (Exception $e) {
}

try {
    $totalRevenue = (int) db_query($pdo, "SELECT COALESCE(SUM(price_product),0) FROM invoice WHERE Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold')")->fetchColumn();
    $activeNow = db_count($pdo, "SELECT COUNT(*) FROM invoice WHERE Status='active'");
} catch (Exception $e) {
}

try {
    $pendingPay = db_count($pdo, "SELECT COUNT(*) FROM Payment_report WHERE payment_Status='waiting'");
    $txToday = db_count($pdo, "SELECT COUNT(*) FROM Payment_report WHERE time > ?", [strtotime('today')]);
} catch (Exception $e) {
}

$recentInvoices = [];
$recentUsers = [];
try {
    $recentInvoices = db_fetchAll($pdo, "SELECT * FROM invoice ORDER BY time_sell DESC LIMIT 8");
} catch (Exception $e) {
}
try {
    $recentUsers = db_fetchAll($pdo, "SELECT * FROM user ORDER BY register DESC LIMIT 8");
} catch (Exception $e) {
}

$pageTitle = 'داشبورد';
$activeNav = 'dashboard';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div class="stats fade-up">
    <div class="stat">
        <div class="stat-label">کل کاربران</div>
        <div class="stat-num"><?= number_format($totalUsers) ?></div>
        <div class="stat-meta"><?= $newToday > 0 ? '<span class="up">+' . $newToday . ' امروز</span>' : 'بدون تغییر' ?>
        </div>
    </div>
    <div class="stat ok">
        <div class="stat-label">درآمد کل</div>
        <div class="stat-num">
            <?= $totalRevenue >= 1_000_000
                ? number_format($totalRevenue / 1_000_000, 1) . '<small>M ت</small>'
                : number_format($totalRevenue) . '<small>ت</small>' ?>
        </div>
        <div class="stat-meta">مجموع فروش</div>
    </div>
    <div class="stat warn">
        <div class="stat-label">سرویس فعال</div>
        <div class="stat-num"><?= number_format($activeNow) ?></div>
    </div>
    <div class="stat <?= $pendingPay > 0 ? 'no' : '' ?>">
        <div class="stat-label"><?= $pendingPay > 0 ? 'پرداخت در انتظار' : 'تراکنش امروز' ?></div>
        <div class="stat-num" style="<?= $pendingPay > 0 ? 'color:var(--no)' : '' ?>">
            <?= number_format($pendingPay > 0 ? $pendingPay : $txToday) ?>
        </div>
        <div class="stat-meta">
            <?= $pendingPay > 0 ? '<a href="payment.php?tab=pending" style="color:var(--no)">بررسی ←</a>' : 'ثبت‌شده' ?>
        </div>
    </div>
</div>

<div class="two-col dash-cols">
    <div class="card fade-up d1">
        <div class="card-head">
            <div>
                <div class="card-title">آخرین سفارشات</div>
                <div class="card-subtitle"><?= count($recentInvoices) ?> مورد اخیر</div>
            </div>
            <a href="invoice.php" class="btn-link" style="font-size:.78rem">همه ←</a>
        </div>
        <?php
        $statusMap = [
            'active' => ['tag-ok', 'فعال'],
            'end_of_time' => ['tag-warn', 'منقضی'],
            'end_of_volume' => ['tag-no', 'اتمام حجم'],
            'sendedwarn' => ['tag-warn', 'اخطار'],
            'send_on_hold' => ['tag-plain', 'در انتظار'],
        ];
        if (empty($recentInvoices)): ?>
            <div class="empty" style="padding:24px"><p>سفارشی ثبت نشده</p></div>
        <?php else: ?>
            <div class="data-list">
                <?php foreach ($recentInvoices as $inv):
                    [$tagClass, $label] = $statusMap[$inv['Status'] ?? ''] ?? ['tag-plain', $inv['Status'] ?? '—'];
                    ?>
                    <div class="data-row">
                        <div class="data-row-body">
                            <div class="data-row-head">
                                <div class="data-row-title"><?= htmlspecialchars(trunc($inv['name_product'] ?? '—', 36)) ?></div>
                                <span class="tag <?= $tagClass ?>"><?= $label ?></span>
                            </div>
                            <div class="data-row-fields">
                                <div class="data-field">
                                    <span class="data-field-label">کاربر</span>
                                    <span class="data-field-val cm">
                                        <a href="user.php?id=<?= (int) ($inv['id_user'] ?? 0) ?>"><?= htmlspecialchars($inv['id_user'] ?? '—') ?></a>
                                    </span>
                                </div>
                                <div class="data-field">
                                    <span class="data-field-label">مبلغ</span>
                                    <span class="data-field-val cn"><?= number_format((int) ($inv['price_product'] ?? 0)) ?> ت</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card fade-up d2">
        <div class="card-head">
            <div>
                <div class="card-title">آخرین کاربران</div>
                <div class="card-subtitle"><?= count($recentUsers) ?> مورد اخیر</div>
            </div>
            <a href="users.php" class="btn-link" style="font-size:.78rem">همه ←</a>
        </div>
        <?php if (empty($recentUsers)): ?>
            <div class="empty" style="padding:24px"><p>کاربری ثبت نشده</p></div>
        <?php else: ?>
            <div class="data-list">
                <?php foreach ($recentUsers as $u):
                    $agent = $u['agent'] ?? 'f';
                    $isBlocked = ($u['User_Status'] ?? '') === 'block';
                    $name = $u['namecustom'] ?? '';
                    if ($name === 'none')
                        $name = '';
                    $uname = $u['username'] ?? '';
                    if ($uname === 'none')
                        $uname = '';
                    $displayName = $name ?: ($uname ? '@' . $uname : 'کاربر #' . $u['id']);
                    ?>
                    <div class="data-row">
                        <div class="data-row-body">
                            <div class="data-row-head">
                                <div class="data-row-title">
                                    <a href="user.php?id=<?= (int) $u['id'] ?>"><?= htmlspecialchars($displayName) ?></a>
                                </div>
                                <?php if ($isBlocked): ?>
                                    <span class="tag tag-no">مسدود</span>
                                <?php else: ?>
                                    <span class="tag <?= user_role_tag($agent) ?>"><?= user_role_label($agent) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="data-row-fields">
                                <div class="data-field">
                                    <span class="data-field-label">آیدی</span>
                                    <span class="data-field-val cm"><?= htmlspecialchars($u['id']) ?></span>
                                </div>
                                <div class="data-field">
                                    <span class="data-field-label">موجودی</span>
                                    <span class="data-field-val cn"><?= number_format((int) ($u['Balance'] ?? 0)) ?> ت</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>