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
$volumeConsumed = agent_is_reseller($agent) ? agent_sum_volume_consumed($id, $agent) : 0.0;
$allCategories = [];
$enabledCategories = [];
$n2Purchases = [];
if ($isN2) {
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
    try {
        $agentIdKey = function_exists('agent_n2_agent_id') ? agent_n2_agent_id($id) : (string) $id;
        $n2Purchases = db_fetchAll($pdo, 'SELECT * FROM agent_n2_purchase WHERE agent_id = ? OR agent_id = ? ORDER BY created_at DESC LIMIT 100', [$agentIdKey, (string) $id]);
    } catch (Exception $e) {
        $n2Purchases = [];
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
        <div class="stat-label">حجم باقیمانده</div>
        <div class="stat-num"><?= number_format($volRemaining) ?><small>GB</small></div>
    </div>
    <div class="stat">
        <div class="stat-label">قیمت هر گیگ</div>
        <div class="stat-num"><?= number_format($pricePerGb) ?><small>ت</small></div>
    </div>
    <?php else: ?>
    <div class="stat">
        <div class="stat-label">دسته‌های فعال</div>
        <div class="stat-num"><?= number_format(count($enabledCategories)) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">خریدهای ثبت‌شده</div>
        <div class="stat-num"><?= number_format(count($n2Purchases)) ?></div>
    </div>
    <?php endif; ?>
    <div class="stat">
        <div class="stat-label">حجم مصرفی ساخت سرویس</div>
        <div class="stat-num"><?= number_format($volumeConsumed) ?><small>GB</small></div>
    </div>
    <div class="stat">
        <div class="stat-label">نقش</div>
        <div class="stat-num" style="font-size:1rem">
            <span class="tag <?= user_role_tag($agent) ?>"><?= user_role_label($agent) ?></span>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px" class="fade-up">

    <div class="card">
        <div class="card-head"><strong>نقش و انقضا</strong></div>
        <div style="padding:16px;display:flex;flex-direction:column;gap:12px">
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
        <div class="card-head"><strong>سهمیه حجم و قیمت</strong></div>
        <div style="padding:16px;display:flex;flex-direction:column;gap:14px">
            <form method="POST" action="agent_action.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="set_volume_remaining">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                <div class="field" style="flex:1;margin:0">
                    <label>تنظیم حجم باقیمانده (GB)</label>
                    <input type="number" name="volume" class="input" min="0" value="<?= $volRemaining ?>" required>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">تنظیم</button>
            </form>
            <form method="POST" action="agent_action.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_volume">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                <div class="field" style="flex:1;margin:0">
                    <label>افزودن حجم (GB)</label>
                    <input type="number" name="volume" class="input" min="1" value="10" required>
                </div>
                <button type="submit" class="btn btn-ok btn-sm">افزودن</button>
            </form>
            <form method="POST" action="agent_action.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="set_price_per_gb">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                <div class="field" style="flex:1;margin:0">
                    <label>قیمت هر گیگ (تومان)</label>
                    <input type="number" name="price" class="input" min="0" value="<?= $pricePerGb ?>" required>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">ذخیره</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><strong>موجودی و سقف خرید</strong></div>
        <div style="padding:16px;display:flex;flex-direction:column;gap:12px">
            <button type="button" class="btn btn-ok btn-sm" onclick="openModal('addBalModal')">افزایش موجودی</button>
            <button type="button" class="btn btn-no btn-sm" onclick="openModal('lowBalModal')">کسر موجودی</button>
            <?php if ($agent === 'n2'): ?>
                <form method="POST" action="agent_action.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="set_max_buy">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                    <div class="field" style="flex:1;margin:0">
                        <label>سقف خرید منفی (۰ = نامحدود)</label>
                        <input type="number" name="max" class="input" min="0" value="<?= $maxBuy ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">ذخیره</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="card" style="grid-column:1/-1">
        <div class="card-head"><strong>دسته‌بندی‌های مجاز نماینده پیشرفته</strong></div>
        <div style="padding:16px">
            <p class="cf" style="margin-bottom:12px">دسته‌هایی که این نماینده می‌تواند ببیند و از محصولات داخلشان (بدون اعتبار) بخرد را فعال کنید. در ربات، اول دسته و بعد محصولات همان دسته نمایش داده می‌شود.</p>
            <form method="POST" action="agent_action.php">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="set_n2_categories">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="back" value="agent.php?id=<?= $id ?>">
                <?php if (empty($allCategories)): ?>
                    <p class="cf">دسته‌بندی‌ای ثبت نشده است.</p>
                <?php else: ?>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;max-height:360px;overflow:auto;padding:4px">
                        <?php foreach ($allCategories as $c):
                            $remark = (string) ($c['remark'] ?? '');
                            if ($remark === '') continue;
                            $checked = !empty($enabledCategories[$remark]);
                            ?>
                            <label class="tag <?= $checked ? 'tag-ok' : 'tag-plain' ?>" style="cursor:pointer;max-width:100%">
                                <input type="checkbox" name="categories[]" value="<?= htmlspecialchars($remark) ?>" <?= $checked ? 'checked' : '' ?> style="margin-left:4px">
                                <?= htmlspecialchars($remark) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:12px">ذخیره دسته‌های فعال</button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card" style="grid-column:1/-1">
        <div class="card-head"><strong>لیست خریدهای نماینده</strong></div>
        <div style="padding:16px;overflow:auto">
            <?php if (empty($n2Purchases)): ?>
                <p class="cf">خریدی ثبت نشده است.</p>
            <?php else: ?>
                <table class="table" style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr>
                            <th style="text-align:right;padding:8px">تاریخ</th>
                            <th style="text-align:right;padding:8px">محصول</th>
                            <th style="text-align:right;padding:8px">حجم</th>
                            <th style="text-align:right;padding:8px">زمان</th>
                            <th style="text-align:right;padding:8px">پنل</th>
                            <th style="text-align:right;padding:8px">یوزرنیم</th>
                            <th style="text-align:right;padding:8px">فاکتور</th>
                            <th style="text-align:right;padding:8px">قیمت کاتالوگ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($n2Purchases as $pur): ?>
                            <tr>
                                <td style="padding:8px"><?= htmlspecialchars(date('Y/m/d H:i', (int) ($pur['created_at'] ?? 0))) ?></td>
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
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card" style="grid-column:1/-1">
        <div class="card-head"><strong>ربات فروش نماینده</strong></div>
        <div style="padding:16px">
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

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
