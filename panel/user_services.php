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
$perPage = 10;
$offset = ($page - 1) * $perPage;
$total = panel_count_user_services($pdo, $id);
$services = panel_fetch_user_services($pdo, $id, $perPage, $offset);
$totalPages = max(1, (int) ceil($total / $perPage));

$panels = [];
$products = [];
$panelsMeta = [];
try {
    $panelRows = db_fetchAll($pdo, "SELECT * FROM marzban_panel ORDER BY name_panel");
    $activePanels = array_values(array_filter($panelRows, static function ($row) {
        return ($row['status'] ?? '') === 'active';
    }));
    $panels = $activePanels ?: $panelRows;
    $products = db_fetchAll(
        $pdo,
        "SELECT name_product, Location, price_product, Volume_constraint, Service_time FROM product ORDER BY name_product"
    );
    $userAgent = (string) ($user['agent'] ?? 'f');
    foreach ($panelRows as $pr) {
        $name = (string) ($pr['name_panel'] ?? '');
        if ($name === '') {
            continue;
        }
        $method = (string) ($pr['MethodUsername'] ?? '');
        $monthOpts = [];
        foreach (panel_custom_months($pr) as $opt) {
            $m = (int) $opt['months'];
            $monthOpts[] = ['months' => $m, 'label' => $m . ' ماهه'];
        }
        $panelsMeta[$name] = [
            'method' => $method,
            'asksUsername' => panel_method_asks_custom_username($method),
            'customEnabled' => (($pr['type'] ?? '') !== 'Manualsale'),
            'customLabel' => panel_custom_button_text($pr),
            'months' => $monthOpts,
            'minVolume' => (int) panel_agent_field($pr, 'mainvolume', $userAgent, '1'),
            'maxVolume' => (int) panel_agent_field($pr, 'maxvolume', $userAgent, '1000'),
        ];
    }
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
                    <th>مصرف حجم</th>
                    <th>باقیمانده زمان</th>
                    <th>تاریخ خرید</th>
                    <th>وضعیت</th>
                    <th style="width:156px"></th>
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
                        ?>
                        <tr data-invoice="<?= htmlspecialchars($svc['id_invoice'] ?? '') ?>">
                            <td class="cf"><?= $i++ ?></td>
                            <td>
                                <span class="cm" style="color:var(--ac)"><?= htmlspecialchars($svc['username'] ?? '—') ?></span>
                                <?php if (!empty($svc['note']) && $svc['note'] !== 'none'): ?>
                                    <div class="cf" style="margin-top:2px"><?= htmlspecialchars(trunc($svc['note'], 24)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="cs"><?= htmlspecialchars(trunc($svc['name_product'] ?? '—', 24)) ?></td>
                            <td class="cf"><?= htmlspecialchars($svc['Service_location'] ?? '—') ?></td>
                            <td class="cn cf js-usage-volume"><span class="usage-pending" aria-label="در حال بارگذاری"></span></td>
                            <td class="cn cf js-usage-time"><span class="usage-pending" aria-hidden="true"></span></td>
                            <td class="cf"><?= safe_date($svc['time_sell'] ?? null, 'Y/m/d') ?></td>
                            <td><span class="tag <?= $tagClass ?>"><?= $label ?></span></td>
                            <td>
                                <div style="display:flex;gap:4px">
                                    <a href="invoice.php?q=<?= urlencode($svc['username'] ?? '') ?>" class="btn btn-ghost btn-sm btn-icon"
                                        title="جستجو در سفارشات">
                                        <?= icon('search', 13) ?>
                                    </a>
                                    <button type="button" class="btn btn-ghost btn-sm btn-icon btn-extend-service"
                                        title="تمدید سرویس"
                                        data-invoice="<?= htmlspecialchars($svc['id_invoice'] ?? '') ?>"
                                        data-username="<?= htmlspecialchars($svc['username'] ?? '') ?>"
                                        data-panel="<?= htmlspecialchars($svc['Service_location'] ?? '') ?>">
                                        <?= icon('refresh', 13) ?>
                                    </button>
                                    <button type="button" class="btn btn-ghost btn-sm btn-icon btn-refund-service"
                                        title="مرجوعی"
                                        data-invoice="<?= htmlspecialchars($svc['id_invoice'] ?? '') ?>"
                                        data-username="<?= htmlspecialchars($svc['username'] ?? '') ?>"
                                        data-price="<?= (int) ($svc['price_product'] ?? 0) ?>">
                                        <?= icon('block', 13) ?>
                                    </button>
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
                <div id="customServiceFields" hidden>
                    <div class="field">
                        <label>حجم (گیگابایت)</label>
                        <input type="number" name="custom_gb" id="customGb" class="input" min="1" step="1">
                        <span class="field-hint" id="customGbHint"></span>
                    </div>
                    <div class="field">
                        <label>مدت سرویس</label>
                        <select name="custom_months" id="customMonths" class="select">
                            <option value="">انتخاب مدت...</option>
                        </select>
                    </div>
                </div>
                <p id="usernameAutoHint" class="field-hint" style="margin:0 0 12px">نام کاربری طبق روش نام‌گذاری پنل به‌صورت خودکار ساخته می‌شود.</p>
                <div class="field" id="serviceUsernameField" hidden>
                    <label>نام کاربری سرویس</label>
                    <input type="text" name="username" id="serviceUsername" class="input cm" pattern="[A-Za-z0-9_]{3,32}" minlength="3" maxlength="32"
                        placeholder="مثلاً user_5016" autocomplete="off">
                    <span class="field-hint">فقط برای روش «نام کاربری دلخواه» روی این پنل</span>
                </div>
                <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.6">
                    <input type="checkbox" name="record_payment" value="1" checked style="width:16px;height:16px;margin-top:3px">
                    <span>پرداخت جدید ثبت شود؟ <span class="cf">(سفارش توسط ادمین)</span></span>
                </label>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> ایجاد سرویس</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('addServiceModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-veil" id="extendServiceModal">
    <div class="modal">
        <div class="modal-head">
            <h3>تمدید سرویس</h3>
            <button type="button" class="modal-x" onclick="closeModal('extendServiceModal')"><?= icon('close', 14) ?></button>
        </div>
        <form method="POST" action="user_service_action.php" id="extendServiceForm">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="extend_service">
                <input type="hidden" name="user_id" value="<?= $id ?>">
                <input type="hidden" name="id_invoice" id="extendInvoiceId" value="">
                <p id="extendServiceText" style="font-size:.88rem;color:var(--mute);line-height:1.7;margin-bottom:14px"></p>
                <div class="field">
                    <label>محصول تمدید</label>
                    <select name="product" id="extendProduct" class="select" required>
                        <option value="">انتخاب محصول...</option>
                    </select>
                    <span class="field-hint" id="extendProductHint"></span>
                </div>
                <div id="extendCustomFields" hidden>
                    <div class="field">
                        <label>حجم (گیگابایت)</label>
                        <input type="number" name="custom_gb" id="extendCustomGb" class="input" min="1" step="1">
                        <span class="field-hint" id="extendCustomGbHint"></span>
                    </div>
                    <div class="field">
                        <label>مدت سرویس</label>
                        <select name="custom_months" id="extendCustomMonths" class="select">
                            <option value="">انتخاب مدت...</option>
                        </select>
                    </div>
                </div>
                <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.6">
                    <input type="checkbox" name="record_payment" value="1" checked style="width:16px;height:16px;margin-top:3px">
                    <span>پرداخت جدید ثبت شود؟ <span class="cf">(تمدید توسط ادمین)</span></span>
                </label>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-primary"><?= icon('refresh', 13) ?> تمدید سرویس</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('extendServiceModal')">انصراف</button>
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

<div class="modal-veil" id="refundServiceModal">
    <div class="modal">
        <div class="modal-head">
            <h3>مرجوعی سرویس</h3>
            <button type="button" class="modal-x" onclick="closeModal('refundServiceModal')"><?= icon('close', 14) ?></button>
        </div>
        <form method="POST" action="user_service_action.php" id="refundServiceForm">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="refund_service">
                <input type="hidden" name="user_id" value="<?= $id ?>">
                <input type="hidden" name="back" value="user_services.php?id=<?= $id ?>">
                <input type="hidden" name="id_invoice" id="refundInvoiceId" value="">
                <p id="refundServiceText" style="font-size:.88rem;color:var(--mute);line-height:1.7;margin-bottom:14px"></p>
                <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.6;margin-bottom:10px">
                    <input type="checkbox" name="credit_wallet" id="refundCreditWallet" value="1" style="width:16px;height:16px;margin-top:3px">
                    <span id="refundCreditWalletLabel">مبلغ سرویس به کیف پول کاربر بازگردانده شود؟</span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.6">
                    <input type="checkbox" name="disable_product" id="refundDisableProduct" value="1" checked style="width:16px;height:16px;margin-top:3px">
                    <span>سرویس در پنل ساب‌لینک و ربات غیرفعال شود؟</span>
                </label>
                <p style="font-size:.75rem;color:var(--mute);margin-top:8px;line-height:1.6">
                    رکورد سفارش باقی می‌ماند. در صورت تأیید غیرفعال‌سازی، وضعیت سرویس «غیرفعال توسط ادمین» می‌گردد.
                </p>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-no"><?= icon('block', 13) ?> ثبت مرجوعی</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('refundServiceModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>

<script>
window.__serviceProducts = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;
window.__servicePanels = <?= json_encode($panelsMeta, JSON_UNESCAPED_UNICODE) ?>;
window.__customServiceToken = <?= json_encode(admin_custom_service_product_token(), JSON_UNESCAPED_UNICODE) ?>;
window.__serviceUsage = {
    userId: <?= (int) $id ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<style>
.usage-pending{display:inline-block;width:12px;height:12px;border:2px solid var(--line,#334155);border-top-color:var(--ac,#38bdf8);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle}
</style>
<script src="<?= htmlspecialchars(panel_asset('js/user_services.js')) ?>"></script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
