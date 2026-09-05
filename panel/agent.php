<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/users_lib.php';
require_auth();
$pdo = panel_ensure_pdo();
agent_ensure_volume_columns();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: agents.php');
    exit;
}

$user = db_fetch($pdo, 'SELECT * FROM user WHERE id = ?', [$id]);
if (!$user) {
    flash('error', 'کاربر یافت نشد.');
    header('Location: agents.php');
    exit;
}

$agent = $user['agent'] ?? 'f';
if (!agent_is_reseller($agent)) {
    flash('warning', 'این کاربر نماینده نیست. ابتدا نقش نمایندگی بدهید.');
}

$bot = null;
try {
    $bot = db_fetch($pdo, 'SELECT * FROM botsaz WHERE id_user = ?', [(string) $id]);
} catch (Exception $e) {
}

$botSetting = [];
if ($bot && !empty($bot['setting'])) {
    $botSetting = json_decode($bot['setting'], true) ?: [];
}
$hidePanels = [];
if ($bot && !empty($bot['hide_panel'])) {
    $decoded = json_decode($bot['hide_panel'], true);
    $hidePanels = is_array($decoded) ? $decoded : [];
}

$allPanels = [];
try {
    $allPanels = db_fetchAll($pdo, "SELECT name_panel FROM marzban_panel ORDER BY name_panel ASC");
} catch (Exception $e) {
}

$balance = (int) ($user['Balance'] ?? 0);
$volRemaining = (int) ($user['agent_volume_remaining'] ?? 0);
$pricePerGb = (int) ($user['agent_price_per_gb'] ?? 0);
$maxBuy = (int) ($user['maxbuyagent'] ?? 0);
$username = ($user['username'] ?? '') === 'none' ? '' : ($user['username'] ?? '');
$expire = $user['expire'] ?? null;
$expireLabel = $expire ? date('Y/m/d H:i', (int) $expire) : 'بدون انقضا';

$isN2 = ($agent === 'n2');
$usesCategoryWhitelist = function_exists('agent_uses_category_whitelist')
    ? agent_uses_category_whitelist($agent)
    : in_array($agent, ['n', 'n2'], true);
$volumeConsumed = agent_is_reseller($agent) ? agent_sum_volume_consumed($id, $agent) : 0.0;
$volumeLifetime = function_exists('agent_consumed_total') ? agent_consumed_total($id, $user) : $volumeConsumed;
$priceTiers = (!$isN2 && function_exists('agent_decode_price_tiers'))
    ? agent_decode_price_tiers($user)
    : [];
if (!$isN2 && empty($priceTiers) && $pricePerGb > 0) {
    $priceTiers = [['upto_tb' => null, 'price_per_gb' => $pricePerGb]];
}
$currentPricePerGb = (!$isN2 && function_exists('agent_current_price_per_gb'))
    ? agent_current_price_per_gb($user, $volumeConsumed)
    : $pricePerGb;
$consumedTb = $volumeConsumed / (function_exists('agent_gb_per_tb') ? agent_gb_per_tb() : 1024);
$allCategories = [];
$enabledCategories = [];
$agentPurchases = [];
$agentPurchaseTotal = 0;
$ownCategories = [];
$ownProducts = [];
if ($isN2) {
    agent_ensure_n2_tables();
    $ownCategories = agent_own_list_categories($id);
    $ownProducts = agent_own_list_products($id);
}
if ($usesCategoryWhitelist || $isN2) {
    agent_ensure_n2_tables();
    try {
        $allCategories = db_fetchAll($pdo, 'SELECT id, remark FROM category ORDER BY remark ASC');
    } catch (Exception $e) {
        $allCategories = [];
    }
    try {
        $agentIdKey = function_exists('agent_n2_agent_id') ? agent_n2_agent_id($id) : (string) $id;
        $rows = db_fetchAll($pdo, 'SELECT category, enabled FROM agent_n2_category WHERE agent_id = ? OR agent_id = ?', [$agentIdKey, (string) $id]);
        foreach ($rows as $r) {
            if ((int) ($r['enabled'] ?? 0) === 1) {
                $enabledCategories[$r['category']] = true;
            }
        }
    } catch (Exception $e) {
    }

    // Purchases: n2 uses dedicated log; n agents use invoices (same fields for the table)
    try {
        $agentIdKey = function_exists('agent_n2_agent_id') ? agent_n2_agent_id($id) : (string) $id;
        if ($isN2) {
            $agentPurchases = db_fetchAll(
                $pdo,
                'SELECT * FROM agent_n2_purchase WHERE agent_id = ? OR agent_id = ? ORDER BY created_at DESC LIMIT 100',
                [$agentIdKey, (string) $id]
            );
            $agentPurchaseTotal = (int) db_count(
                $pdo,
                'SELECT COUNT(*) FROM agent_n2_purchase WHERE agent_id = ? OR agent_id = ?',
                [$agentIdKey, (string) $id]
            );
        } else {
            $invRows = db_fetchAll(
                $pdo,
                "SELECT id_invoice, name_product, Volume, Service_time, Service_location, username, price_product, time_sell, Status
                 FROM invoice
                 WHERE id_user = ?
                   AND name_product != 'سرویس تست'
                   AND Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold')
                 ORDER BY CAST(time_sell AS UNSIGNED) DESC
                 LIMIT 100",
                [(string) $id]
            );
            foreach ($invRows as $inv) {
                $ts = (int) ($inv['time_sell'] ?? 0);
                $agentPurchases[] = [
                    'created_at' => $ts,
                    'name_product' => $inv['name_product'] ?? '',
                    'volume' => $inv['Volume'] ?? '',
                    'service_time' => $inv['Service_time'] ?? '',
                    'panel' => $inv['Service_location'] ?? '',
                    'username_service' => $inv['username'] ?? '',
                    'id_invoice' => $inv['id_invoice'] ?? '',
                    'price_product' => $inv['price_product'] ?? '0',
                    'status' => $inv['Status'] ?? '',
                ];
            }
            $agentPurchaseTotal = (int) db_count(
                $pdo,
                "SELECT COUNT(*) FROM invoice
                 WHERE id_user = ?
                   AND name_product != 'سرویس تست'
                   AND Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold')",
                [(string) $id]
            );
        }
    } catch (Exception $e) {
        $agentPurchases = [];
        $agentPurchaseTotal = 0;
    }
}

$tokenMasked = '';
if ($bot && !empty($bot['bot_token'])) {
    $tok = $bot['bot_token'];
    $tokenMasked = strlen($tok) > 12 ? substr($tok, 0, 8) . '…' . substr($tok, -4) : '••••';
}

$pageTitle = 'نماینده #' . $id;
$activeNav = 'agents';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px" class="fade-up">
    <a href="agents.php" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 14) ?> فهرست نمایندگان</a>
    <a href="user.php?id=<?= $id ?>" class="btn btn-ghost btn-sm">پروفایل کاربر</a>
</div>

<div class="stats u-stats fade-up" style="margin-bottom:18px">
    <?php if (!$isN2): ?>
    <div class="stat">
        <div class="stat-label">موجودی</div>
        <div class="stat-num"><?= number_format($balance) ?><small>ت</small></div>
    </div>
    <div class="stat">
        <div class="stat-label">قیمت فعلی هر گیگ</div>
        <div class="stat-num"><?= number_format($currentPricePerGb) ?><small>ت</small></div>
    </div>
    <div class="stat">
        <div class="stat-label">مصرف تجمعی</div>
        <div class="stat-num"><?= number_format($consumedTb, 2) ?><small>TB</small></div>
    </div>
    <?php else: ?>
    <div class="stat">
        <div class="stat-label">موجودی حجم</div>
        <div class="stat-num"><?= number_format($volRemaining) ?><small>GB</small></div>
    </div>
    <div class="stat">
        <div class="stat-label">سقف منفی</div>
        <div class="stat-num"><?= $maxBuy === 0 ? '∞' : number_format($maxBuy) ?><small>GB</small></div>
    </div>
    <?php endif; ?>
    <div class="stat">
        <div class="stat-label"><?= $isN2 ? 'دسته‌های خود نماینده' : 'دسته‌های فعال' ?></div>
        <div class="stat-num"><?= number_format($isN2 ? count($ownCategories) : count($enabledCategories)) ?></div>
    </div>
    <?php if ($usesCategoryWhitelist || $isN2): ?>
    <div class="stat">
        <div class="stat-label">خریدها</div>
        <div class="stat-num"><?= number_format($agentPurchaseTotal) ?></div>
    </div>
    <?php endif; ?>
    <div class="stat">
        <div class="stat-label"><?= $isN2 ? 'شمارنده مصرف' : 'حجم مصرفی ساخت سرویس' ?></div>
        <div class="stat-num"><?= number_format($volumeConsumed) ?><small>GB</small></div>
    </div>
    <?php if ($isN2): ?>
    <div class="stat">
        <div class="stat-label">مصرف کل</div>
        <div class="stat-num"><?= number_format($volumeLifetime) ?><small>GB</small></div>
    </div>
    <?php endif; ?>
    <div class="stat">
        <div class="stat-label">نقش</div>
        <div class="stat-num" style="font-size:1rem">
            <span class="tag <?= user_role_tag($agent) ?>"><?= user_role_label($agent) ?></span>
        </div>
    </div>
</div>

<div class="agent-page fade-up">

    <div class="agent-top">
        <div class="card">
            <div class="card-head"><strong>نقش و انقضا</strong></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
                <div class="cf">آیدی: <span class="cm"><?= $id ?></span>
                    <?php if ($username): ?> · @<?= htmlspecialchars($username) ?><?php endif; ?>
                </div>
                <div class="cf">انقضا: <?= htmlspecialchars($expireLabel) ?></div>
                <form method="POST" action="agent_action.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="set_role">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                    <div class="field" style="flex:1;min-width:140px;margin:0">
                        <label>نقش</label>
                        <select name="new_role" class="select">
                            <option value="n" <?= $agent === 'n' ? 'selected' : '' ?>>نماینده (n)</option>
                            <option value="n2" <?= $agent === 'n2' ? 'selected' : '' ?>>پیشرفته (n2)</option>
                            <option value="f">حذف نمایندگی (f)</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">ذخیره نقش</button>
                </form>
                <button type="button" class="btn btn-ghost btn-sm" onclick="openModal('expireModal')">تنظیم انقضا</button>
            </div>
        </div>

        <?php if (!$isN2): ?>
        <div class="card">
            <div class="card-head"><strong>موجودی کیف پول</strong></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
                <div class="cf">موجودی فعلی: <strong><?= number_format($balance) ?></strong> تومان</div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="button" class="btn btn-ok btn-sm" onclick="openModal('addBalModal')">افزایش موجودی</button>
                    <button type="button" class="btn btn-no btn-sm" onclick="openModal('lowBalModal')">کسر موجودی</button>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-head"><strong>موجودی و سقف حجم</strong></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
                <div class="cf">موجودی حجم: <strong><?= number_format($volRemaining) ?></strong> گیگابایت</div>
                <div class="cf">شمارنده مصرف: <strong><?= number_format($volumeConsumed) ?></strong> گیگ · مصرف کل: <strong><?= number_format($volumeLifetime) ?></strong> گیگ</div>
                <p class="cf" style="margin:0;font-size:.8rem">هر خرید محصول اختصاصی این نماینده، حجم همان محصول را از سهمیه کم می‌کند. اگر سقف منفی صفر باشد محدودیتی برای منفی شدن نیست.</p>
                <p class="cf" style="margin:0;font-size:.8rem">شمارنده مصرف از زمان تبدیل به n2 شروع می‌شود و جدا از مصرف کل است.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="button" class="btn btn-ok btn-sm" onclick="openModal('addVolModal')">افزایش حجم</button>
                    <button type="button" class="btn btn-no btn-sm" onclick="openModal('lowVolModal')">کسر حجم</button>
                    <a href="agent_action.php?action=reset_n2_consumed&id=<?= $id ?>&_csrf=<?= csrf_token() ?>&back=<?= urlencode('agent.php?id=' . $id) ?>"
                        class="btn btn-ghost btn-sm" data-confirm="شمارنده مصرف دوره‌ای صفر شود؟ مصرف کل باقی می‌ماند.">صفر کردن شمارنده مصرف</a>
                </div>
                <form method="POST" action="agent_action.php" class="n2-quota-save" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="set_max_buy">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                    <input type="hidden" name="record_payment" value="0" class="n2-record-payment">
                    <input type="hidden" name="payment_amount" value="" class="n2-payment-amount">
                    <div class="field" style="flex:1;margin:0">
                        <label>سقف حجم منفی (گیگ — ۰ = نامحدود)</label>
                        <input type="number" name="max" class="input" min="0" value="<?= $maxBuy ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">ذخیره</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$isN2): ?>
    <div class="card">
        <div class="card-head"><strong>پله‌های قیمتی (پرداخت به‌ازای مصرف)</strong></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px">
            <p class="cf" style="margin:0">
                هزینه بر اساس حجم تجمعی خریداری‌شده محاسبه می‌شود (۱ ترابایت = ۱۰۲۴ گیگ).
                مثلاً تا ۱۰ TB یک قیمت، از ۱۰ تا ۳۰ TB قیمت دیگر، و بالاتر از ۳۰ TB قیمت نهایی.
                اگر یک خرید از مرز پله رد شود، کل همان خرید با قیمت پلهٔ بعدی حساب می‌شود (نه به‌صورت ترکیبی).
                سقف ترابایت هر پله قابل ویرایش است؛ آخرین پله را بدون سقف بگذارید (خالی = نامحدود).
            </p>
            <p class="cf" style="margin:0">مصرف فعلی: <strong><?= number_format($volumeConsumed, 2) ?> GB</strong> (≈ <?= number_format($consumedTb, 3) ?> TB) · قیمت جاری هر گیگ: <strong><?= number_format($currentPricePerGb) ?></strong> تومان</p>
            <form method="POST" action="agent_action.php" id="tiersForm">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="set_price_tiers">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                <div class="tbl-wrap">
                    <table class="table" style="width:100%;border-collapse:collapse" id="tiersTable">
                        <thead>
                            <tr>
                                <th style="text-align:right;padding:8px">از (تجمعی)</th>
                                <th style="text-align:right;padding:8px">تا سقف (TB)</th>
                                <th style="text-align:right;padding:8px">قیمت هر گیگ (تومان)</th>
                                <th style="text-align:right;padding:8px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $tierRows = $priceTiers;
                            if (empty($tierRows)) {
                                $tierRows = [
                                    ['upto_tb' => 10, 'price_per_gb' => $pricePerGb ?: 0],
                                    ['upto_tb' => 30, 'price_per_gb' => $pricePerGb ?: 0],
                                    ['upto_tb' => null, 'price_per_gb' => $pricePerGb ?: 0],
                                ];
                            }
                            $prevLabel = '۰';
                            foreach ($tierRows as $ti => $tier):
                                $uptoVal = $tier['upto_tb'];
                                $uptoAttr = $uptoVal === null ? '' : htmlspecialchars((string) $uptoVal);
                                ?>
                                <tr class="tier-row">
                                    <td style="padding:8px" class="tier-from cf"><?= htmlspecialchars($prevLabel) ?></td>
                                    <td style="padding:8px">
                                        <input type="number" name="upto_tb[]" class="input tier-upto" min="0" step="0.01" value="<?= $uptoAttr ?>" placeholder="خالی = نامحدود" style="min-width:120px">
                                    </td>
                                    <td style="padding:8px">
                                        <input type="number" name="price_per_gb[]" class="input" min="0" step="1" value="<?= (int) ($tier['price_per_gb'] ?? 0) ?>" required style="min-width:140px">
                                    </td>
                                    <td style="padding:8px">
                                        <button type="button" class="btn btn-ghost btn-sm" onclick="removeTierRow(this)">حذف</button>
                                    </td>
                                </tr>
                                <?php
                                $prevLabel = $uptoVal === null ? '—' : ((string) $uptoVal . ' TB');
                            endforeach;
                            ?>
                        </tbody>
                    </table>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="addTierRow()">افزودن پله</button>
                    <button type="submit" class="btn btn-primary btn-sm">ذخیره پله‌ها</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isN2): ?>
    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-title">محصولات اختصاصی نماینده</div>
                <div class="card-subtitle"><?= number_format(count($ownCategories)) ?> دسته · <?= number_format(count($ownProducts)) ?> محصول — ساخت از تلگرام</div>
            </div>
        </div>
        <div class="card-body">
            <p class="cf" style="margin-bottom:14px">ادمین اصلی و خود نماینده از ربات تلگرام (پنل نمایندگی یا ربات فروش) دسته و محصول می‌سازند. ربات‌های فروش قدیمی تا «تعمیر / بازسازی ربات» منوی جدید را نمی‌گیرند.</p>
            <?php if (empty($ownCategories) && empty($ownProducts)): ?>
                <p class="cf">هنوز دسته یا محصولی ساخته نشده است.</p>
            <?php else: ?>
                <?php if ($ownCategories): ?>
                    <div class="cf" style="margin-bottom:8px"><strong>دسته‌ها</strong></div>
                    <div class="agent-cat-grid" style="margin-bottom:16px">
                        <?php foreach ($ownCategories as $oc): ?>
                            <span class="agent-cat-item is-on"><span><?= htmlspecialchars((string) ($oc['remark'] ?? '')) ?></span></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($ownProducts): ?>
                    <div class="tbl-wrap">
                        <table class="table tbl-lg" style="width:100%;border-collapse:collapse">
                            <thead>
                                <tr>
                                    <th style="text-align:right;padding:8px">نام</th>
                                    <th style="text-align:right;padding:8px">دسته</th>
                                    <th style="text-align:right;padding:8px">حجم</th>
                                    <th style="text-align:right;padding:8px">زمان</th>
                                    <th style="text-align:right;padding:8px">قیمت</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ownProducts as $op): ?>
                                    <tr>
                                        <td style="padding:8px"><?= htmlspecialchars((string) ($op['name_product'] ?? '')) ?></td>
                                        <td style="padding:8px"><?= htmlspecialchars((string) ($op['category'] ?? '')) ?></td>
                                        <td style="padding:8px"><?= htmlspecialchars((string) ($op['Volume_constraint'] ?? '')) ?> GB</td>
                                        <td style="padding:8px"><?= htmlspecialchars((string) ($op['Service_time'] ?? '')) ?></td>
                                        <td style="padding:8px"><?= number_format((int) ($op['price_product'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($usesCategoryWhitelist): ?>
    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-title">دسته‌بندی‌های مجاز</div>
                <div class="card-subtitle"><?= number_format(count($enabledCategories)) ?> از <?= number_format(count($allCategories)) ?> فعال</div>
            </div>
        </div>
        <div class="card-body">
            <p class="cf" style="margin-bottom:14px">دسته‌هایی که این نماینده می‌تواند ببیند و از محصولات داخلشان بخرد را فعال کنید. هزینه هر خرید از کیف پول با پله‌های قیمتی محاسبه می‌شود.</p>
            <form method="POST" action="agent_action.php">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="set_n2_categories">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                <?php if (empty($allCategories)): ?>
                    <p class="cf">دسته‌بندی‌ای ثبت نشده است.</p>
                <?php else: ?>
                    <div class="agent-cat-grid">
                        <?php foreach ($allCategories as $c):
                            $remark = (string) ($c['remark'] ?? '');
                            if ($remark === '') continue;
                            $checked = !empty($enabledCategories[$remark]);
                            ?>
                            <label class="agent-cat-item <?= $checked ? 'is-on' : '' ?>">
                                <input type="checkbox" name="categories[]" value="<?= htmlspecialchars($remark) ?>" <?= $checked ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($remark) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:14px">ذخیره دسته‌های فعال</button>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($usesCategoryWhitelist || $isN2): ?>
    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-title">لیست خریدهای نماینده</div>
                <div class="card-subtitle"><?= number_format($agentPurchaseTotal) ?> خرید<?= $agentPurchaseTotal > count($agentPurchases) ? ' · نمایش ' . count($agentPurchases) . ' مورد اخیر' : '' ?></div>
            </div>
        </div>
        <div class="card-body" style="padding-top:0;padding-bottom:0">
            <?php if (empty($agentPurchases)): ?>
                <p class="cf" style="padding:16px 0">خریدی ثبت نشده است.</p>
            <?php else: ?>
                <div class="tbl-wrap">
                    <table class="table tbl-lg" style="width:100%;border-collapse:collapse">
                        <thead>
                            <tr>
                                <th style="text-align:right;padding:8px">تاریخ</th>
                                <th style="text-align:right;padding:8px">محصول</th>
                                <th style="text-align:right;padding:8px">حجم</th>
                                <th style="text-align:right;padding:8px">زمان</th>
                                <th style="text-align:right;padding:8px">پنل</th>
                                <th style="text-align:right;padding:8px">یوزرنیم</th>
                                <th style="text-align:right;padding:8px">فاکتور</th>
                                <th style="text-align:right;padding:8px"><?= $isN2 ? 'قیمت کاتالوگ' : 'مبلغ' ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agentPurchases as $pur):
                                $ts = (int) ($pur['created_at'] ?? 0);
                                $dateLabel = $ts > 0 ? date('Y/m/d H:i', $ts) : '—';
                                ?>
                                <tr>
                                    <td style="padding:8px"><?= htmlspecialchars($dateLabel) ?></td>
                                    <td style="padding:8px"><?= htmlspecialchars($pur['name_product'] ?? '') ?></td>
                                    <td style="padding:8px"><?= htmlspecialchars((string) ($pur['volume'] ?? '')) ?> GB</td>
                                    <td style="padding:8px"><?= htmlspecialchars((string) ($pur['service_time'] ?? '')) ?></td>
                                    <td style="padding:8px"><?= htmlspecialchars($pur['panel'] ?? '') ?></td>
                                    <td style="padding:8px" class="cm"><?= htmlspecialchars($pur['username_service'] ?? '') ?></td>
                                    <td style="padding:8px" class="cm"><?= htmlspecialchars($pur['id_invoice'] ?? '') ?></td>
                                    <td style="padding:8px"><?= number_format((int) ($pur['price_product'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-head"><strong>ربات فروش نماینده</strong></div>
        <div class="card-body">
            <?php if (!$bot): ?>
                <p class="cf" style="margin-bottom:12px">ربات فروش فعال نیست. توکن ربات را از BotFather بگیرید و فعال کنید.</p>
                    <form method="POST" action="agent_action.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;max-width:560px" id="createBotForm">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="create_bot">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                    <div class="field" style="flex:1;margin:0">
                        <label>توکن ربات</label>
                        <input type="text" name="token" class="input" required placeholder="123456:ABC-DEF...">
                    </div>
                    <button type="submit" class="btn btn-primary" id="createBotBtn">فعالسازی ربات</button>
                </form>
                <p class="cf" style="margin-top:8px;font-size:.8rem">پس از ارسال، حداکثر حدود ۱۵ ثانیه صبر کنید تا ارتباط با تلگرام انجام شود.</p>
                <script>
                (function () {
                    var f = document.getElementById('createBotForm');
                    var b = document.getElementById('createBotBtn');
                    if (!f || !b) return;
                    f.addEventListener('submit', function () {
                        b.disabled = true;
                        b.textContent = 'در حال ساخت…';
                    });
                })();
                </script>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:16px">
                    <div>
                        <div class="cf" style="font-size:.75rem">یوزرنیم</div>
                        <div><a href="https://t.me/<?= htmlspecialchars($bot['username']) ?>" target="_blank" rel="noopener">@<?= htmlspecialchars($bot['username']) ?></a></div>
                    </div>
                    <div>
                        <div class="cf" style="font-size:.75rem">توکن</div>
                        <div class="cm" style="word-break:break-all" id="botTokenDisplay"><?= htmlspecialchars($tokenMasked) ?></div>
                        <button type="button" class="btn btn-ghost btn-sm" style="margin-top:6px"
                            onclick="navigator.clipboard.writeText(<?= json_encode($bot['bot_token']) ?>).then(()=>this.textContent='کپی شد')">کپی توکن</button>
                    </div>
                    <div>
                        <div class="cf" style="font-size:.75rem">زمان ساخت</div>
                        <div><?= htmlspecialchars($bot['time'] ?? '—') ?></div>
                    </div>
                </div>

                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
                    <form method="POST" action="agent_action.php" style="display:flex;gap:8px;align-items:end">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="set_bot_min_volume">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                        <div class="field" style="margin:0">
                            <label>حداقل قیمت حجم (خرده)</label>
                            <input type="number" name="amount" class="input" min="0" value="<?= (int) ($botSetting['minpricevolume'] ?? 4000) ?>" required>
                        </div>
                        <button type="submit" class="btn btn-ghost btn-sm">ذخیره</button>
                    </form>
                    <form method="POST" action="agent_action.php" style="display:flex;gap:8px;align-items:end">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="set_bot_min_time">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                        <div class="field" style="margin:0">
                            <label>حداقل قیمت زمان (خرده)</label>
                            <input type="number" name="amount" class="input" min="0" value="<?= (int) ($botSetting['minpricetime'] ?? 4000) ?>" required>
                        </div>
                        <button type="submit" class="btn btn-ghost btn-sm">ذخیره</button>
                    </form>
                </div>

                <?php
                $botSetting = botsaz_normalize_setting($botSetting);
                $cardNumber = (string) ($botSetting['card_number'] ?? '');
                $cardHolder = (string) ($botSetting['card_holder'] ?? '');
                $cartInfo = (string) ($botSetting['cart_info'] ?? '');
                ?>
                <form method="POST" action="agent_action.php" style="margin-bottom:16px;padding:12px;border:1px solid var(--border, #333);border-radius:8px">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="set_bot_card_payment">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                    <div class="cf" style="margin-bottom:10px;font-weight:600">💳 پرداخت کارت به کارت (ربات نماینده)</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
                        <div class="field" style="margin:0">
                            <label>شماره کارت</label>
                            <input type="text" name="card_number" class="input" inputmode="numeric" maxlength="19" value="<?= htmlspecialchars($cardNumber) ?>" placeholder="6037...">
                        </div>
                        <div class="field" style="margin:0">
                            <label>نام صاحب کارت</label>
                            <input type="text" name="card_holder" class="input" maxlength="80" value="<?= htmlspecialchars($cardHolder) ?>" placeholder="نام و نام خانوادگی">
                        </div>
                    </div>
                    <div class="field" style="margin-top:12px">
                        <label>متن راهنمای پرداخت</label>
                        <textarea name="cart_info" class="input" rows="3" placeholder="پس از واریز رسید را ارسال کنید..."><?= htmlspecialchars($cartInfo) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:8px">ذخیره تنظیمات کارت</button>
                </form>

                <?php if (!empty($allPanels)): ?>
                    <form method="POST" action="agent_action.php" style="margin-bottom:16px">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="set_hide_panels">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                        <div class="field">
                            <label>پنل‌های مخفی برای این ربات</label>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">
                                <?php foreach ($allPanels as $pl):
                                    $name = $pl['name_panel'] ?? '';
                                    if ($name === '') continue;
                                    $checked = in_array($name, $hidePanels, true);
                                    ?>
                                    <label class="tag <?= $checked ? 'tag-no' : 'tag-plain' ?>" style="cursor:pointer">
                                        <input type="checkbox" name="panels[]" value="<?= htmlspecialchars($name) ?>" <?= $checked ? 'checked' : '' ?> style="margin-left:4px">
                                        <?= htmlspecialchars($name) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:8px">ذخیره پنل‌های مخفی</button>
                    </form>
                <?php endif; ?>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                    <a href="agent_action.php?action=repair_bot&id=<?= $id ?>&_csrf=<?= csrf_token() ?>&back=agent.php?id=<?= $id ?>"
                        class="btn btn-primary btn-sm" data-confirm="فایل‌های ربات از قالب دوباره ساخته و وبهوک تنظیم شود؟">تعمیر / بازسازی ربات</a>
                    <a href="agent_action.php?action=remove_bot&id=<?= $id ?>&_csrf=<?= csrf_token() ?>&back=agent.php?id=<?= $id ?>"
                        class="btn btn-no btn-sm" data-confirm="ربات فروش این نماینده حذف شود؟">حذف ربات فروش</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal-veil" id="expireModal">
    <div class="modal">
        <div class="modal-head"><h3>انقضای نمایندگی</h3><button class="modal-x" onclick="closeModal('expireModal')"><?= icon('close', 14) ?></button></div>
        <form method="POST" action="agent_action.php">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="set_expire">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                <div class="field">
                    <label>تعداد روز از امروز (۰ = حذف انقضا)</label>
                    <input type="number" name="days" class="input" min="0" value="30" required>
                </div>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-primary">تنظیم</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('expireModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-veil" id="addBalModal">
    <div class="modal">
        <div class="modal-head"><h3>افزایش موجودی</h3><button class="modal-x" onclick="closeModal('addBalModal')"><?= icon('close', 14) ?></button></div>
        <form method="POST" action="agent_action.php">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_balance">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                <div class="field">
                    <label>مبلغ (تومان)</label>
                    <input type="number" name="amount" class="input" min="1000" required>
                </div>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-ok">افزودن</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('addBalModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-veil" id="lowBalModal">
    <div class="modal">
        <div class="modal-head"><h3>کسر موجودی</h3><button class="modal-x" onclick="closeModal('lowBalModal')"><?= icon('close', 14) ?></button></div>
        <form method="POST" action="agent_action.php">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="low_balance">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                <div class="field">
                    <label>مبلغ (تومان)</label>
                    <input type="number" name="amount" class="input" min="1" required>
                </div>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-no">کسر</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('lowBalModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-veil" id="addVolModal">
    <div class="modal">
        <div class="modal-head"><h3>افزایش حجم</h3><button class="modal-x" onclick="closeModal('addVolModal')"><?= icon('close', 14) ?></button></div>
        <form method="POST" action="agent_action.php" class="n2-quota-save">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_volume">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                <input type="hidden" name="record_payment" value="0" class="n2-record-payment">
                <input type="hidden" name="payment_amount" value="" class="n2-payment-amount">
                <div class="field">
                    <label>حجم (گیگابایت)</label>
                    <input type="number" name="volume" class="input" min="1" required>
                </div>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-ok">افزودن</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('addVolModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-veil" id="n2PayAskModal">
    <div class="modal">
        <div class="modal-head"><h3>ثبت رکورد پرداخت</h3><button class="modal-x" onclick="closeModal('n2PayAskModal')"><?= icon('close', 14) ?></button></div>
        <div class="modal-body">
            <p class="cf" style="margin:0">رکورد پرداخت برای این سقف حجم ثبت شود؟</p>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-ok" id="n2PayYesBtn">بله</button>
            <button type="button" class="btn btn-ghost" id="n2PayNoBtn">خیر</button>
        </div>
    </div>
</div>

<div class="modal-veil" id="n2PayAmountModal">
    <div class="modal">
        <div class="modal-head"><h3>مبلغ دریافتی</h3><button class="modal-x" onclick="closeModal('n2PayAmountModal')"><?= icon('close', 14) ?></button></div>
        <div class="modal-body">
            <div class="field">
                <label>هزینه دریافتی چقدر بوده؟ (تومان)</label>
                <input type="number" id="n2PayAmountInput" class="input" min="1" step="1">
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-primary" id="n2PayAmountOk">ثبت و ذخیره</button>
            <button type="button" class="btn btn-ghost" onclick="closeModal('n2PayAmountModal')">انصراف</button>
        </div>
    </div>
</div>

<div class="modal-veil" id="lowVolModal">
    <div class="modal">
        <div class="modal-head"><h3>کسر حجم</h3><button class="modal-x" onclick="closeModal('lowVolModal')"><?= icon('close', 14) ?></button></div>
        <form method="POST" action="agent_action.php">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="low_volume">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                <div class="field">
                    <label>حجم (گیگابایت)</label>
                    <input type="number" name="volume" class="input" min="1" required>
                </div>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-no">کسر</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('lowVolModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>

<?php if (!$isN2): ?>
<script>
function refreshTierFromLabels() {
    const rows = document.querySelectorAll('#tiersTable tbody .tier-row');
    let prev = '۰';
    rows.forEach((row) => {
        const fromCell = row.querySelector('.tier-from');
        if (fromCell) fromCell.textContent = prev;
        const upto = row.querySelector('.tier-upto');
        const v = upto ? String(upto.value || '').trim() : '';
        prev = v === '' ? '—' : (v + ' TB');
    });
}
function addTierRow() {
    const tbody = document.querySelector('#tiersTable tbody');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.className = 'tier-row';
    tr.innerHTML = `
        <td style="padding:8px" class="tier-from cf">—</td>
        <td style="padding:8px">
            <input type="number" name="upto_tb[]" class="input tier-upto" min="0" step="0.01" value="" placeholder="خالی = نامحدود" style="min-width:120px">
        </td>
        <td style="padding:8px">
            <input type="number" name="price_per_gb[]" class="input" min="0" step="1" value="0" required style="min-width:140px">
        </td>
        <td style="padding:8px">
            <button type="button" class="btn btn-ghost btn-sm" onclick="removeTierRow(this)">حذف</button>
        </td>`;
    tbody.appendChild(tr);
    const upto = tr.querySelector('.tier-upto');
    if (upto) upto.addEventListener('input', refreshTierFromLabels);
    refreshTierFromLabels();
}
function removeTierRow(btn) {
    const tbody = document.querySelector('#tiersTable tbody');
    if (!tbody || tbody.querySelectorAll('.tier-row').length <= 1) return;
    const row = btn.closest('tr');
    if (row) row.remove();
    refreshTierFromLabels();
}
document.querySelectorAll('#tiersTable .tier-upto').forEach((el) => {
    el.addEventListener('input', refreshTierFromLabels);
});
</script>
<?php endif; ?>

<script>
document.querySelectorAll('.agent-cat-item input[type="checkbox"]').forEach((cb) => {
    cb.addEventListener('change', function () {
        this.closest('.agent-cat-item')?.classList.toggle('is-on', this.checked);
    });
});
<?php if ($isN2): ?>
(function () {
    var pendingForm = null;
    function submitQuota(form, record, amount) {
        var rec = form.querySelector('.n2-record-payment');
        var amt = form.querySelector('.n2-payment-amount');
        if (rec) rec.value = record ? '1' : '0';
        if (amt) amt.value = record ? String(amount || '') : '';
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
    }
    document.querySelectorAll('form.n2-quota-save').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.n2PayReady === '1') {
                form.dataset.n2PayReady = '';
                return;
            }
            e.preventDefault();
            pendingForm = form;
            if (typeof openModal === 'function') openModal('n2PayAskModal');
        });
    });
    var yesBtn = document.getElementById('n2PayYesBtn');
    var noBtn = document.getElementById('n2PayNoBtn');
    var amountOk = document.getElementById('n2PayAmountOk');
    var amountInput = document.getElementById('n2PayAmountInput');
    if (yesBtn) yesBtn.addEventListener('click', function () {
        if (typeof closeModal === 'function') closeModal('n2PayAskModal');
        if (amountInput) amountInput.value = '';
        if (typeof openModal === 'function') openModal('n2PayAmountModal');
        if (amountInput) amountInput.focus();
    });
    if (noBtn) noBtn.addEventListener('click', function () {
        if (typeof closeModal === 'function') closeModal('n2PayAskModal');
        if (!pendingForm) return;
        pendingForm.dataset.n2PayReady = '1';
        submitQuota(pendingForm, false, 0);
        pendingForm = null;
    });
    if (amountOk) amountOk.addEventListener('click', function () {
        var val = amountInput ? parseInt(amountInput.value, 10) : 0;
        if (!val || val < 1) {
            if (amountInput) amountInput.focus();
            return;
        }
        if (typeof closeModal === 'function') closeModal('n2PayAmountModal');
        if (!pendingForm) return;
        pendingForm.dataset.n2PayReady = '1';
        submitQuota(pendingForm, true, val);
        pendingForm = null;
    });
})();
<?php endif; ?>
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
