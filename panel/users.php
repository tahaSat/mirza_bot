<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/users_lib.php';
require_auth();
$pdo = panel_ensure_pdo();

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$role = $_GET['role'] ?? '';
$view = $_GET['view'] === 'admins' ? 'admins' : 'users';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

try {
    if ($view === 'admins') {
        $params = $search !== '' ? ["%$search%", "%$search%", "%$search%"] : [];
        $whereSQL = $search !== '' ? 'WHERE (id_admin LIKE ? OR username LIKE ? OR rule LIKE ?)' : '';
        $total = db_count($pdo, "SELECT COUNT(*) FROM admin $whereSQL", $params);
        $users = db_fetchAll($pdo, "SELECT id_admin, username, rule FROM admin $whereSQL ORDER BY username ASC LIMIT $perPage OFFSET $offset", $params);
    } else {
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = "(id LIKE ? OR COALESCE(username,'') LIKE ? OR COALESCE(namecustom,'') LIKE ? OR COALESCE(number,'') LIKE ?)";
            $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
        }
        if ($status === 'block') {
            $where[] = "LOWER(User_Status) = 'block'";
        } elseif ($status === 'active') {
            $where[] = "(User_Status IS NULL OR User_Status = '' OR LOWER(User_Status) != 'block')";
        }
        if ($role !== '') {
            $where[] = "agent = ?";
            $params[] = $role;
        }
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = db_count($pdo, "SELECT COUNT(*) FROM user $whereSQL", $params);
        $users = db_fetchAll($pdo, "SELECT * FROM user $whereSQL ORDER BY register DESC LIMIT $perPage OFFSET $offset", $params);
    }
} catch (Exception $e) {
    $total = 0;
    $users = [];
    error_log('users.php: ' . $e->getMessage());
}

$totalPages = max(1, (int) ceil($total / $perPage));

$blockedCount = 0;
$agentCount = 0;
$agentAdvCount = 0;

try {
    $blockedCount = db_count($pdo, "SELECT COUNT(*) FROM user WHERE LOWER(User_Status)='block'");
    $agentCount = db_count($pdo, "SELECT COUNT(*) FROM user WHERE agent='n'");
    $agentAdvCount = db_count($pdo, "SELECT COUNT(*) FROM user WHERE agent='n2'");
} catch (Exception $e) {
}

$serviceCounts = [];
if ($view === 'users') {
    foreach ($users as $u) {
        $serviceCounts[(int) $u['id']] = panel_count_user_services($pdo, $u['id']);
    }
}

$pageTitle = 'کاربران';
$pageLede = 'فهرست کاربران ربات.';
$activeNav = 'users';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="card fade-up">
    <div class="toolbar">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <div class="toolbar-title"><?= $view === 'admins' ? 'ادمین‌ها' : 'کاربران' ?> <small>(<?= number_format($total) ?>)</small></div>
            <a href="users.php" class="tag <?= $view === 'users' ? 'tag-info' : 'tag-plain' ?>" style="cursor:pointer">کاربران</a>
            <a href="users.php?view=admins" class="tag <?= $view === 'admins' ? 'tag-info' : 'tag-plain' ?>" style="cursor:pointer">ادمین‌ها</a>

            <?php if ($view === 'users' && $blockedCount > 0): ?>
                <a href="?status=block" class="tag tag-no" style="cursor:pointer"><?= $blockedCount ?> مسدود</a>
            <?php endif; ?>
            <?php if ($view === 'users' && $agentCount > 0): ?>
                <a href="?role=n" class="tag tag-info" style="cursor:pointer"><?= $agentCount ?> نماینده</a>
            <?php endif; ?>
            <?php if ($view === 'users' && $agentAdvCount > 0): ?>
                <a href="?role=n2" class="tag tag-warn" style="cursor:pointer"><?= $agentAdvCount ?> نماینده پیشرفته</a>
            <?php endif; ?>
        </div>

        <form method="GET" id="usersForm" class="toolbar-end">
            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
            <?php if ($view === 'users'): ?>
            <select name="status" class="select" style="width:auto"
                onchange="document.getElementById('usersForm').submit()">
                <option value="">همه وضعیت‌ها</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>فعال</option>
                <option value="block" <?= $status === 'block' ? 'selected' : '' ?>>مسدود</option>
            </select>

            <select name="role" class="select" style="width:auto"
                onchange="document.getElementById('usersForm').submit()">
                <option value="">همه گروه‌ها</option>
                <option value="f" <?= $role === 'f' ? 'selected' : '' ?>>کاربر عادی</option>
                <option value="n" <?= $role === 'n' ? 'selected' : '' ?>>نماینده</option>
                <option value="n2" <?= $role === 'n2' ? 'selected' : '' ?>>نماینده پیشرفته</option>
            </select>
            <?php endif; ?>

            <div class="search-box users-search">
                <?= icon('search', 15) ?>
                <input type="text" name="q" placeholder="<?= $view === 'admins' ? 'آیدی، یوزرنیم یا نقش...' : 'آیدی، یوزرنیم، نام، شماره...' ?>"
                    value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                <button type="button" class="search-clear">✕</button>
                <button type="submit" class="search-btn">جستجو</button>
            </div>

            <?php if ($search || ($view === 'users' && ($status || $role))): ?>
                <a href="users.php<?= $view === 'admins' ? '?view=admins' : '' ?>" class="btn-link" style="font-size:.78rem;white-space:nowrap">پاک کردن</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($view === 'admins'): ?>
        <?php if (empty($users)): ?>
            <div class="empty"><p><?= $search ? 'ادمینی یافت نشد' : 'هنوز ادمینی ثبت نشده' ?></p></div>
        <?php else: ?>
            <div class="data-list">
                <?php foreach ($users as $index => $admin): ?>
                    <div class="data-row">
                        <div class="data-row-body">
                            <div class="data-row-head">
                                <div class="data-row-title">
                                    <span class="data-row-index"><?= $offset + $index + 1 ?></span>
                                    <strong><?= htmlspecialchars($admin['username']) ?></strong>
                                </div>
                                <span class="tag tag-info"><?= htmlspecialchars($admin['rule']) ?></span>
                            </div>
                            <div class="data-row-fields">
                                <div class="data-field">
                                    <span class="data-field-label">شناسه تلگرام</span>
                                    <span class="data-field-val cm"><?= htmlspecialchars($admin['id_admin']) ?></span>
                                </div>
                                <div class="data-field">
                                    <span class="data-field-label">نام کاربری پنل</span>
                                    <span class="data-field-val cm"><?= htmlspecialchars($admin['username']) ?></span>
                                </div>
                                <div class="data-field">
                                    <span class="data-field-label">نقش</span>
                                    <span class="data-field-val"><?= htmlspecialchars($admin['rule']) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php elseif (empty($users)): ?>
        <div class="empty">
            <svg class="ill" viewBox="0 0 200 160" fill="none">
                <circle cx="100" cy="60" r="40" fill="var(--sf3)" />
                <circle cx="100" cy="47" r="18" fill="var(--bds)" />
                <path d="M62 105 Q100 88 138 105" stroke="var(--bds)" stroke-width="8"
                    stroke-linecap="round" fill="none" />
            </svg>
            <p><?= $search ? 'نتیجه‌ای یافت نشد' : 'هنوز کاربری ثبت نشده' ?></p>
        </div>
    <?php else: ?>
        <div class="data-list">
            <?php
            $i = $offset + 1;
            foreach ($users as $u):
                $agent = $u['agent'] ?? 'f';
                $isBlocked = panel_user_is_blocked($u);
                $name = $u['namecustom'] ?? '';
                if ($name === 'none')
                    $name = '';
                $uname = $u['username'] ?? '';
                if ($uname === 'none')
                    $uname = '';
                $serviceCount = $serviceCounts[(int) $u['id']] ?? 0;
                $displayName = $name ?: ($uname ? '@' . $uname : 'کاربر #' . $u['id']);
                $phone = (!empty($u['number']) && $u['number'] !== 'none') ? $u['number'] : '';
                ?>
                <div class="data-row user-data-row" role="link" tabindex="0"
                    data-user-url="user.php?id=<?= (int) $u['id'] ?>"
                    onclick="if (!event.target.closest('a,button')) window.location.href = this.dataset.userUrl"
                    onkeydown="if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('a,button')) { event.preventDefault(); window.location.href = this.dataset.userUrl; }">
                    <div class="data-row-body">
                        <div class="data-row-head">
                            <div class="data-row-title">
                                <span class="data-row-index"><?= $i++ ?></span>
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
                            <?php if ($uname): ?>
                                <div class="data-field">
                                    <span class="data-field-label">یوزرنیم</span>
                                    <span class="data-field-val cm" style="color:var(--ac)">@<?= htmlspecialchars($uname) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($phone): ?>
                                <div class="data-field">
                                    <span class="data-field-label">شماره</span>
                                    <span class="data-field-val cm"><?= htmlspecialchars($phone) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="data-field">
                                <span class="data-field-label">موجودی</span>
                                <span class="data-field-val cn"><?= number_format((int) ($u['Balance'] ?? 0)) ?> ت</span>
                            </div>
                            <div class="data-field">
                                <span class="data-field-label">سرویس</span>
                                <span class="data-field-val">
                                    <?php if ($serviceCount > 0): ?>
                                        <a href="user_services.php?id=<?= (int) $u['id'] ?>"><?= number_format($serviceCount) ?></a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="data-field">
                                <span class="data-field-label">ثبت‌نام</span>
                                <span class="data-field-val"><?= safe_date($u['register'] ?? null) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="data-row-actions">
                        <a href="user.php?id=<?= (int) $u['id'] ?>" class="btn btn-ghost btn-sm btn-icon"
                            title="مدیریت کاربر"><?= icon('eye', 14) ?></a>
                        <a href="user_services.php?id=<?= (int) $u['id'] ?>" class="btn btn-ghost btn-sm btn-icon"
                            title="سرویس‌های کاربر"><?= icon('package', 14) ?></a>
                        <?php if ($isBlocked): ?>
                            <a href="user_action.php?action=unblock&id=<?= (int) $u['id'] ?>&_csrf=<?= csrf_token() ?>&back=users.php"
                                class="btn btn-ok btn-sm btn-icon" title="رفع مسدودیت"
                                data-confirm="رفع مسدودیت کاربر <?= htmlspecialchars($name ?: $u['id']) ?>؟"><?= icon('check', 13) ?></a>
                        <?php else: ?>
                            <a href="user_action.php?action=block&id=<?= (int) $u['id'] ?>&_csrf=<?= csrf_token() ?>&back=users.php"
                                class="btn btn-no btn-sm btn-icon" title="مسدود کردن"
                                data-confirm="مسدود کردن کاربر <?= htmlspecialchars($name ?: $u['id']) ?>؟"><?= icon('block', 13) ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="tbl-foot">
        <span><?= number_format($total) ?> <?= $view === 'admins' ? 'ادمین' : 'کاربر' ?> · صفحه <?= $page ?> از <?= $totalPages ?></span>
        <div class="pager">
            <?php
            $qs = fn($p) => '?view=' . urlencode($view)
                . '&q=' . urlencode($search)
                . '&status=' . urlencode($status)
                . '&role=' . urlencode($role)
                . '&page=' . $p;
            ?>
            <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                <a class="<?= $p === $page ? 'cur' : '' ?>" href="<?= $qs($p) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="<?= $page >= $totalPages ? 'dis' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
        </div>
    </div>
</div>

<script src="js/users.js"></script>
<?php include __DIR__ . '/inc/layout_foot.php'; ?>