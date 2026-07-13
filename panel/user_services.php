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

$panels = [];
$products = [];
try {
    $panels = db_fetchAll($pdo, "SELECT name_panel FROM marzban_panel WHERE status = 'active' ORDER BY name_panel");
    if (!$panels) {
        $panels = db_fetchAll($pdo, "SELECT name_panel FROM marzban_panel ORDER BY name_panel");
    }
    $products = db_fetchAll($pdo, "SELECT name_product, Location FROM product ORDER BY name_product");
} catch (Throwable $e) {
    error_log('user_services.php: ' . $e->getMessage());
}

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
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addServiceModal')">
            <?= icon('plus', 13) ?> افزودن سرویس
        </button>
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
                    <th style="width:88px"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($services)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty" style="padding:36px">
                                <p>این کاربر سرویس فعالی ندارد</p>
                                <button type="button" class="btn btn-primary btn-sm" style="margin-top:12px" onclick="openModal('addServiceModal')">
                                    <?= icon('plus', 13) ?> افزودن اولین سرویس
                                </button>
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
                                <div style="display:flex;gap:4px">
                                    <a href="invoice.php?q=<?= urlencode($svc['username'] ?? '') ?>" class="btn btn-ghost btn-sm btn-icon"
                                        title="جستجو در سفارشات">
                                        <?= icon('search', 13) ?>
                                    </a>
                                    <button type="button" class="btn btn-no btn-sm btn-icon btn-remove-service"
                                        title="حذف سرویس"
                                        data-invoice="<?= htmlspecialchars($svc['id_invoice'] ?? '') ?>"
                                        data-username="<?= htmlspecialchars($svc['username'] ?? '') ?>">
                                        <?= icon('trash', 13) ?>
                                    </button>
                                </div>
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

<div class="modal-veil" id="addServiceModal">
    <div class="modal">
        <div class="modal-head">
            <h3>افزودن سرویس برای کاربر</h3>
            <button type="button" class="modal-x" onclick="closeModal('addServiceModal')"><?= icon('close', 14) ?></button>
        </div>
        <form method="POST" action="user_service_action.php" id="addServiceForm">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_service">
                <input type="hidden" name="user_id" value="<?= $id ?>">
                <div class="field">
                    <label>نام کاربری سرویس</label>
                    <input type="text" name="username" class="input cm" pattern="[A-Za-z0-9_]{3,32}" minlength="3" maxlength="32" required
                        placeholder="مثلاً user_5016" autocomplete="off">
                    <span class="field-hint">۳ تا ۳۲ کاراکتر — حروف انگلیسی، عدد و _</span>
                </div>
                <div class="field">
                    <label>پنل / لوکیشن</label>
                    <select name="panel" id="servicePanel" class="select" required>
                        <option value="">انتخاب پنل...</option>
                        <?php foreach ($panels as $p): ?>
                            <option value="<?= htmlspecialchars($p['name_panel']) ?>"><?= htmlspecialchars($p['name_panel']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>محصول</label>
                    <select name="product" id="serviceProduct" class="select" required disabled>
                        <option value="">ابتدا پنل را انتخاب کنید</option>
                    </select>
                </div>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> ایجاد سرویس</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('addServiceModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-veil" id="removeServiceModal">
    <div class="modal">
        <div class="modal-head">
            <h3>حذف سرویس</h3>
            <button type="button" class="modal-x" onclick="closeModal('removeServiceModal')"><?= icon('close', 14) ?></button>
        </div>
        <form method="POST" action="user_service_action.php" id="removeServiceForm">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="remove_service">
                <input type="hidden" name="user_id" value="<?= $id ?>">
                <input type="hidden" name="id_invoice" id="removeInvoiceId" value="">
                <p id="removeServiceText" style="font-size:.88rem;color:var(--mute);line-height:1.7;margin-bottom:14px"></p>
                <label style="display:flex;align-items:center;gap:8px;font-size:.85rem;cursor:pointer">
                    <input type="checkbox" name="refund" value="1" style="width:16px;height:16px">
                    بازگشت مبلغ سرویس به کیف پول کاربر
                </label>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-no"><?= icon('trash', 13) ?> حذف سرویس</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('removeServiceModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>

<script>
window.__serviceProducts = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="js/user_services.js"></script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
