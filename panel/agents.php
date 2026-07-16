<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/users_lib.php';
require_auth();
$pdo = panel_ensure_pdo();
agent_ensure_volume_columns();

$search = trim($_GET['q'] ?? '');
$role = $_GET['role'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ["u.agent IN ('n', 'n2')"];
$params = [];

if ($search !== '') {
    $where[] = "(u.id LIKE ? OR COALESCE(u.username,'') LIKE ? OR COALESCE(u.namecustom,'') LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%"];
}

if ($role === 'n' || $role === 'n2') {
    $where[] = 'u.agent = ?';
    $params[] = $role;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

try {
    $total = db_count($pdo, "SELECT COUNT(*) FROM user u $whereSQL", $params);
    $agents = db_fetchAll(
        $pdo,
        "SELECT u.*, b.username AS bot_username, b.bot_token
         FROM user u
         LEFT JOIN botsaz b ON b.id_user = u.id
         $whereSQL
         ORDER BY u.register DESC
         LIMIT $perPage OFFSET $offset",
        $params
    );
} catch (Exception $e) {
    $total = 0;
    $agents = [];
    error_log('agents.php: ' . $e->getMessage());
}

$totalPages = max(1, (int) ceil($total / $perPage));

$agentCount = 0;
$agentAdvCount = 0;
try {
    $agentCount = db_count($pdo, "SELECT COUNT(*) FROM user WHERE agent='n'");
    $agentAdvCount = db_count($pdo, "SELECT COUNT(*) FROM user WHERE agent='n2'");
} catch (Exception $e) {
}

$pageTitle = 'نمایندگان';
$pageLede = 'مدیریت نمایندگان، سهمیه حجم و ربات فروش.';
$activeNav = 'agents';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="card fade-up">
    <div class="toolbar">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <div class="toolbar-title">نمایندگان <small>(<?= number_format($total) ?>)</small></div>
            <?php if ($agentCount > 0): ?>
                <a href="?role=n" class="tag tag-info" style="cursor:pointer"><?= $agentCount ?> نماینده</a>
            <?php endif; ?>
            <?php if ($agentAdvCount > 0): ?>
                <a href="?role=n2" class="tag tag-warn" style="cursor:pointer"><?= $agentAdvCount ?> پیشرفته</a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openModal('promoteModal')">
                <?= icon('plus', 13) ?> افزودن نماینده
            </button>
        </div>

        <form method="GET" id="agentsForm" class="toolbar-end">
            <select name="role" class="select" style="width:auto"
                onchange="document.getElementById('agentsForm').submit()">
                <option value="">همه نقش‌ها</option>
                <option value="n" <?= $role === 'n' ? 'selected' : '' ?>>نماینده</option>
                <option value="n2" <?= $role === 'n2' ? 'selected' : '' ?>>نماینده پیشرفته</option>
            </select>
            <div class="search-box" style="min-width:240px">
                <?= icon('search', 15) ?>
                <input type="text" name="q" placeholder="آیدی، یوزرنیم..."
                    value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                <button type="submit" class="search-btn">جستجو</button>
            </div>
            <?php if ($search || $role): ?>
                <a href="agents.php" class="btn-link" style="font-size:.78rem">پاک کردن</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="tbl-wrap">
        <table class="tbl-xl">
            <thead>
                <tr>
                    <th>#</th>
                    <th>آیدی</th>
                    <th>یوزرنیم</th>
                    <th>نقش</th>
                    <th>موجودی</th>
                    <th>حجم باقیمانده</th>
                    <th>قیمت هر گیگ</th>
                    <th>ربات فروش</th>
                    <th>انقضا</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($agents)): ?>
                    <tr>
                        <td colspan="10">
                            <div class="empty" style="padding:36px"><p>نماینده‌ای یافت نشد</p></div>
                        </td>
                    </tr>
                <?php else:
                    $rowNum = $offset;
                    foreach ($agents as $a):
                        $rowNum++;
                        $uid = (int) $a['id'];
                        $uname = ($a['username'] ?? '') === 'none' ? '' : ($a['username'] ?? '');
                        $vol = (int) ($a['agent_volume_remaining'] ?? 0);
                        $ppg = (int) ($a['agent_price_per_gb'] ?? 0);
                        $hasBot = !empty($a['bot_username']);
                        $expire = $a['expire'] ?? null;
                        $expireLabel = $expire ? date('Y/m/d', (int) $expire) : '—';
                        ?>
                        <tr>
                            <td class="cf"><?= $rowNum ?></td>
                            <td><span class="cm"><?= $uid ?></span></td>
                            <td><?= $uname ? '@' . htmlspecialchars($uname) : '—' ?></td>
                            <td><span class="tag <?= user_role_tag($a['agent'] ?? 'f') ?>"><?= user_role_label($a['agent'] ?? 'f') ?></span></td>
                            <td><?= number_format((int) ($a['Balance'] ?? 0)) ?></td>
                            <td><?= number_format($vol) ?> GB</td>
                            <td><?= number_format($ppg) ?></td>
                            <td>
                                <?php if ($hasBot): ?>
                                    <span class="tag tag-ok">@<?= htmlspecialchars($a['bot_username']) ?></span>
                                <?php else: ?>
                                    <span class="tag tag-plain">ندارد</span>
                                <?php endif; ?>
                            </td>
                            <td class="cs"><?= htmlspecialchars($expireLabel) ?></td>
                            <td>
                                <a href="agent.php?id=<?= $uid ?>" class="btn btn-ghost btn-sm">مدیریت</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="tbl-foot">
            <div class="pager">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="?page=<?= $p ?>&q=<?= urlencode($search) ?>&role=<?= urlencode($role) ?>"
                        class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="modal-veil" id="promoteModal">
    <div class="modal">
        <div class="modal-head">
            <h3>افزودن نماینده</h3>
            <button class="modal-x" onclick="closeModal('promoteModal')"><?= icon('close', 14) ?></button>
        </div>
        <form method="POST" action="agent_action.php">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="promote">
                <input type="hidden" name="back" value="agents.php">
                <div class="field">
                    <label>آیدی عددی تلگرام</label>
                    <input type="text" name="telegram_id" class="input" required placeholder="مثلاً 123456789" inputmode="numeric">
                </div>
                <div class="field">
                    <label>نقش</label>
                    <select name="new_role" class="select" required>
                        <option value="n">نماینده (n)</option>
                        <option value="n2">نماینده پیشرفته (n2)</option>
                    </select>
                </div>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-primary">ثبت</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('promoteModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
