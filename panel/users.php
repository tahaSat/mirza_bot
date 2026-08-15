<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/users_lib.php';
require_auth();
$pdo = panel_ensure_pdo();
$currentPanelAdmin = db_fetch($pdo, 'SELECT id_admin, username, rule FROM admin WHERE username = ?', [$_SESSION['admin_user'] ?? '']);
$canManageAdmins = ($currentPanelAdmin['rule'] ?? '') === 'administrator';
$bulkChargeJob = null;
$bulkChargeRemaining = 0;
$bulkChargeFile = dirname(__DIR__) . '/cronbot/gift';
$bulkChargeQueueFile = dirname(__DIR__) . '/cronbot/username.json';
if (is_file($bulkChargeFile)) {
    $activeBulkJob = json_decode((string) file_get_contents($bulkChargeFile), true);
    if (is_array($activeBulkJob) && !empty($activeBulkJob['bulk_service_charge'])) {
        $bulkChargeJob = $activeBulkJob;
        if (is_file($bulkChargeQueueFile)) {
            $remainingServices = json_decode((string) file_get_contents($bulkChargeQueueFile), true);
            $bulkChargeRemaining = is_array($remainingServices) ? count($remainingServices) : 0;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = $_POST['action'] ?? '';
    $redirectView = 'admins';
    if (!$canManageAdmins) {
        flash('error', 'فقط مدیر اصلی می‌تواند ادمین‌ها را مدیریت کند.');
        if ($action === 'reset_all_test_limits') {
            $redirectView = 'users';
        }
    } elseif ($action === 'reset_all_test_limits') {
        $redirectView = 'users';
        $limit = trim($_POST['limit'] ?? '');
        if ($limit === '' || !ctype_digit($limit)) {
            flash('error', 'محدودیت اکانت تست باید عدد باشد.');
        } else {
            try {
                ensureColumnExistsForUpdate('user', 'time_usertest', '0');
                db_query($pdo, "UPDATE user SET limit_usertest = ?, time_usertest = '0'", [$limit]);
                db_query($pdo, 'UPDATE setting SET limit_usertest_all = ?', [$limit]);
                $affected = db_count($pdo, 'SELECT COUNT(*) FROM user');
                flash('success', 'محدودیت اکانت تست همه کاربران (' . number_format($affected) . ' نفر) به ' . number_format((int) $limit) . ' ریست شد.');
            } catch (Exception $e) {
                error_log('users.php reset_all_test_limits: ' . $e->getMessage());
                flash('error', 'ریست محدودیت اکانت تست ناموفق بود.');
            }
        }
    } elseif ($action === 'add_admin') {
        $adminId = trim($_POST['admin_id'] ?? '');
        $adminUsername = trim($_POST['admin_username'] ?? '');
        $password = $_POST['admin_password'] ?? '';
        $adminRule = $_POST['admin_rule'] ?? 'support';
        if (!preg_match('/^\d{4,20}$/', $adminId)) {
            flash('error', 'شناسه عددی تلگرام ادمین نامعتبر است.');
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $adminUsername)) {
            flash('error', 'نام کاربری پنل باید ۳ تا ۱۰۰ کاراکتر و فقط شامل حروف، عدد، نقطه، خط تیره یا زیرخط باشد.');
        } elseif (mb_strlen($password, 'UTF-8') < 8) {
            flash('error', 'رمز عبور باید حداقل ۸ کاراکتر باشد.');
        } elseif (!in_array($adminRule, ['administrator', 'support', 'Seller'], true)) {
            flash('error', 'نقش ادمین نامعتبر است.');
        } else {
            try {
                db_query(
                    $pdo,
                    'INSERT INTO admin (id_admin, username, password, rule) VALUES (?, ?, ?, ?)',
                    [$adminId, $adminUsername, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $adminRule]
                );
                flash('success', 'ادمین جدید اضافه شد.');
            } catch (PDOException $e) {
                flash('error', 'شناسه تلگرام یا نام کاربری پنل تکراری است.');
            }
        }
    } elseif ($action === 'remove_admin') {
        $adminId = trim($_POST['admin_id'] ?? '');
        $target = db_fetch($pdo, 'SELECT id_admin, username, rule FROM admin WHERE id_admin = ?', [$adminId]);
        if (!$target) {
            flash('error', 'ادمین موردنظر یافت نشد.');
        } elseif ($target['id_admin'] === ($currentPanelAdmin['id_admin'] ?? '')) {
            flash('error', 'امکان حذف حساب ادمین فعلی وجود ندارد.');
        } elseif ($target['rule'] === 'administrator' && db_count($pdo, "SELECT COUNT(*) FROM admin WHERE rule = 'administrator'") <= 1) {
            flash('error', 'آخرین مدیر اصلی قابل حذف نیست.');
        } else {
            db_query($pdo, 'DELETE FROM admin WHERE id_admin = ?', [$adminId]);
            flash('success', 'ادمین حذف شد.');
        }
    }
    header('Location: users.php' . ($redirectView === 'admins' ? '?view=admins' : ''));
    exit;
}

$defaultTestLimit = '1';
try {
    $settingRow = db_fetch($pdo, 'SELECT limit_usertest_all FROM setting LIMIT 1');
    if ($settingRow && isset($settingRow['limit_usertest_all']) && $settingRow['limit_usertest_all'] !== '') {
        $defaultTestLimit = (string) $settingRow['limit_usertest_all'];
    }
} catch (Exception $e) {
}

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$role = $_GET['role'] ?? '';
$userFilters = panel_user_segment_from_request();
$userFiltersActive = panel_user_segment_active($userFilters);
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
            $where[] = "(u.id LIKE ? OR COALESCE(u.username,'') LIKE ? OR COALESCE(u.namecustom,'') LIKE ? OR COALESCE(u.number,'') LIKE ?)";
            $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
        }
        if ($status === 'block') {
            $where[] = "LOWER(u.User_Status) = 'block'";
        } elseif ($status === 'active') {
            $where[] = "(u.User_Status IS NULL OR u.User_Status = '' OR LOWER(u.User_Status) != 'block')";
        }
        if ($role !== '') {
            $where[] = "u.agent = ?";
            $params[] = $role;
        }
        $seg = panel_user_segment_query_parts($userFilters, $userFiltersActive);
        foreach ($seg['where'] as $clause) {
            $where[] = $clause;
        }
        $params = array_merge($params, $seg['params']);
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $selectExtra = $seg['select'] ? ', ' . implode(', ', $seg['select']) : '';
        $fromSQL = "FROM user u {$seg['joins']}";
        $total = db_count($pdo, "SELECT COUNT(*) $fromSQL $whereSQL", $params);
        $users = db_fetchAll($pdo, "SELECT u.*$selectExtra $fromSQL $whereSQL ORDER BY u.register DESC LIMIT $perPage OFFSET $offset", $params);
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
            <?php if ($view === 'admins' && $canManageAdmins): ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addAdminModal')"><?= icon('plus', 14) ?> افزودن ادمین</button>
            <?php endif; ?>
            <?php if ($view === 'users' && $canManageAdmins): ?>
                <?php if ($bulkChargeJob): ?>
                    <span class="tag tag-warn">
                        شارژ همگانی در حال اجرا · <?= number_format($bulkChargeRemaining) ?> باقی‌مانده
                    </span>
                    <form method="POST" action="bulk_service_charge_action.php"
                        onsubmit="return confirm('عملیات شارژ همگانی سرویس‌ها لغو شود؟')">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="cancel">
                        <button type="submit" class="btn btn-no btn-sm"><?= icon('close', 13) ?> لغو عملیات</button>
                    </form>
                <?php else: ?>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openModal('bulkServiceChargeModal')">
                        <?= icon('plus', 14) ?> شارژ همگانی سرویس‌ها
                    </button>
                <?php endif; ?>
                <button type="button" class="btn btn-ghost btn-sm" onclick="openModal('resetTestLimitModal')">
                    <?= icon('users', 14) ?> ریست محدودیت اکانت تست
                </button>
            <?php endif; ?>

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
            <select name="test" class="select" style="width:auto"
                onchange="document.getElementById('usersForm').submit()">
                <option value="">اکانت تست: همه</option>
                <option value="yes" <?= $userFilters['test'] === 'yes' ? 'selected' : '' ?>>دارای اکانت تست</option>
                <option value="no" <?= $userFilters['test'] === 'no' ? 'selected' : '' ?>>بدون اکانت تست</option>
            </select>
            <input class="input" type="number" name="min_buys" min="0" step="1" inputmode="numeric"
                placeholder="حداقل خرید"
                style="width:110px"
                value="<?= $userFilters['min_buys'] !== null ? (int) $userFilters['min_buys'] : '' ?>"
                onchange="document.getElementById('usersForm').submit()">
            <input class="input" type="number" name="min_extends" min="0" step="1" inputmode="numeric"
                placeholder="حداقل تمدید"
                style="width:110px"
                value="<?= $userFilters['min_extends'] !== null ? (int) $userFilters['min_extends'] : '' ?>"
                onchange="document.getElementById('usersForm').submit()">
            <?php endif; ?>

            <div class="search-box users-search">
                <?= icon('search', 15) ?>
                <input type="text" name="q" placeholder="<?= $view === 'admins' ? 'آیدی، یوزرنیم یا نقش...' : 'آیدی، یوزرنیم، نام، شماره...' ?>"
                    value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                <button type="button" class="search-clear">✕</button>
                <button type="submit" class="search-btn">جستجو</button>
            </div>

            <?php if ($search || ($view === 'users' && ($status || $role || $userFiltersActive))): ?>
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
                        <?php if ($canManageAdmins && $admin['id_admin'] !== ($currentPanelAdmin['id_admin'] ?? '')): ?>
                            <div class="data-row-actions">
                                <form method="POST" onsubmit="return confirm('ادمین «<?= htmlspecialchars($admin['username']) ?>» حذف شود؟')">
                                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="remove_admin">
                                    <input type="hidden" name="admin_id" value="<?= htmlspecialchars($admin['id_admin']) ?>">
                                    <button class="btn btn-no btn-sm btn-icon" title="حذف ادمین" type="submit"><?= icon('trash', 14) ?></button>
                                </form>
                            </div>
                        <?php endif; ?>
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
                            <?php if ($userFiltersActive): ?>
                                <div class="data-field">
                                    <span class="data-field-label">خرید</span>
                                    <span class="data-field-val cn"><?= number_format((int) ($u['buy_count'] ?? 0)) ?></span>
                                </div>
                                <div class="data-field">
                                    <span class="data-field-label">تمدید</span>
                                    <span class="data-field-val cn"><?= number_format((int) ($u['extend_count'] ?? 0)) ?></span>
                                </div>
                                <div class="data-field">
                                    <span class="data-field-label">اکانت تست</span>
                                    <span class="data-field-val"><?= ((int) ($u['test_count'] ?? 0)) > 0 ? 'دارد' : 'ندارد' ?></span>
                                </div>
                            <?php endif; ?>
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
                . '&test=' . urlencode($userFilters['test'])
                . '&min_buys=' . urlencode($userFilters['min_buys'] !== null ? (string) $userFilters['min_buys'] : '')
                . '&min_extends=' . urlencode($userFilters['min_extends'] !== null ? (string) $userFilters['min_extends'] : '')
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

<?php if ($view === 'users' && $canManageAdmins): ?>
<div class="modal-veil" id="resetTestLimitModal">
    <div class="modal">
        <div class="modal-head">
            <h3>ریست محدودیت اکانت تست همه کاربران</h3>
            <button class="modal-x" type="button" onclick="closeModal('resetTestLimitModal')"><?= icon('close', 14) ?></button>
        </div>
        <form method="POST" id="resetTestLimitForm">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reset_all_test_limits">
                <div class="field">
                    <label>تعداد مجاز اکانت تست</label>
                    <input class="input" type="number" name="limit" min="0" step="1" inputmode="numeric"
                        value="<?= htmlspecialchars($defaultTestLimit) ?>" required>
                    <span class="field-hint">این مقدار برای همه کاربران اعمال می‌شود و دوره محدودیت آن‌ها هم ریست می‌گردد.</span>
                </div>
                <div style="margin:0;padding:10px 12px;border:1px solid var(--warn);border-radius:var(--r);color:var(--warn);font-size:.8rem;line-height:1.8">
                    محدودیت پیش‌فرض سیستم نیز به همین عدد به‌روز می‌شود.
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn btn-primary" type="submit">ریست همه کاربران</button>
                <button class="btn btn-ghost" type="button" onclick="closeModal('resetTestLimitModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var form = document.getElementById('resetTestLimitForm');
    if (!form) return;
    form.addEventListener('submit', function (event) {
        if (!window.confirm('محدودیت اکانت تست همه کاربران ریست شود؟')) {
            event.preventDefault();
        }
    });
}());
</script>
<?php endif; ?>

<?php if ($view === 'users' && $canManageAdmins && !$bulkChargeJob): ?>
<div class="modal-veil" id="bulkServiceChargeModal">
    <div class="modal">
        <div class="modal-head">
            <h3>شارژ همگانی سرویس‌ها</h3>
            <button class="modal-x" type="button" onclick="closeModal('bulkServiceChargeModal')"><?= icon('close', 14) ?></button>
        </div>
        <form method="POST" action="bulk_service_charge_action.php" id="bulkServiceChargeForm">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="start">

                <div class="field">
                    <label>گروه کاربری</label>
                    <select class="select" name="agent" required>
                        <option value="all">همه کاربران</option>
                        <option value="f">کاربران گروه f</option>
                        <option value="n">کاربران گروه n</option>
                        <option value="n2">کاربران گروه n2</option>
                    </select>
                    <span class="field-hint">فقط کاربرانی که خرید و سرویس فعال دارند انتخاب می‌شوند.</span>
                </div>

                <div class="field">
                    <label>نوع کاربران</label>
                    <select class="select" name="service_type" id="bulkServiceType" required>
                        <option value="volume">کاربران حجمی — افزایش حجم</option>
                        <option value="day">کاربران نامحدود — افزایش زمان</option>
                    </select>
                </div>

                <div class="field">
                    <label id="bulkServiceValueLabel">حجم افزایشی (گیگابایت)</label>
                    <input class="input" type="number" name="value" id="bulkServiceValue"
                        min="1" step="1" inputmode="numeric" required>
                    <span class="field-hint" id="bulkServiceValueHint">این مقدار به تمام سرویس‌های حجمی فعال اضافه می‌شود.</span>
                </div>

                <div class="field">
                    <label>پیام ارسالی</label>
                    <textarea class="input" name="message" rows="5" maxlength="4000" required
                        placeholder="پیامی که پس از شارژ موفق هر سرویس برای کاربر ارسال می‌شود"></textarea>
                    <span class="field-hint">برای هر سرویس که با موفقیت شارژ شود، این پیام یک‌بار ارسال خواهد شد.</span>
                </div>

                <div style="margin:0;padding:10px 12px;border:1px solid var(--warn);border-radius:var(--r);color:var(--warn);font-size:.8rem;line-height:1.8">
                    عملیات روی تمام سرویس‌های فعال مطابق فیلتر اجرا می‌شود و ممکن است چند دقیقه زمان ببرد.
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn btn-primary" type="submit">تایید و شروع عملیات</button>
                <button class="btn btn-ghost" type="button" onclick="closeModal('bulkServiceChargeModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var type = document.getElementById('bulkServiceType');
    var label = document.getElementById('bulkServiceValueLabel');
    var hint = document.getElementById('bulkServiceValueHint');
    var form = document.getElementById('bulkServiceChargeForm');
    if (!type || !label || !hint || !form) return;

    function updateValueCopy() {
        var isVolume = type.value === 'volume';
        label.textContent = isVolume ? 'حجم افزایشی (گیگابایت)' : 'زمان افزایشی (روز)';
        hint.textContent = isVolume
            ? 'این مقدار به تمام سرویس‌های حجمی فعال اضافه می‌شود.'
            : 'این مقدار به تمام سرویس‌های نامحدود فعال اضافه می‌شود.';
    }

    type.addEventListener('change', updateValueCopy);
    form.addEventListener('submit', function (event) {
        var kind = type.value === 'volume' ? 'حجم' : 'زمان';
        if (!window.confirm('افزایش ' + kind + ' برای تمام سرویس‌های فعال مطابق فیلتر آغاز شود؟')) {
            event.preventDefault();
        }
    });
    updateValueCopy();
}());
</script>
<?php endif; ?>

<?php if ($canManageAdmins): ?>
<div class="modal-veil" id="addAdminModal">
    <div class="modal">
        <div class="modal-head"><h3>افزودن ادمین</h3><button class="modal-x" type="button" onclick="closeModal('addAdminModal')"><?= icon('close', 14) ?></button></div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_admin">
                <div class="field">
                    <label>شناسه عددی تلگرام</label>
                    <input class="input" type="text" name="admin_id" inputmode="numeric" required>
                </div>
                <div class="field">
                    <label>نام کاربری پنل</label>
                    <input class="input" type="text" name="admin_username" autocomplete="username" required>
                </div>
                <div class="field">
                    <label>رمز عبور</label>
                    <input class="input" type="password" name="admin_password" autocomplete="new-password" minlength="8" required>
                </div>
                <div class="field">
                    <label>نقش</label>
                    <select class="select" name="admin_rule">
                        <option value="support">پشتیبان</option>
                        <option value="Seller">فروشنده</option>
                        <option value="administrator">مدیر اصلی</option>
                    </select>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn btn-primary" type="submit">افزودن</button>
                <button class="btn btn-ghost" type="button" onclick="closeModal('addAdminModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="js/users.js"></script>
<?php include __DIR__ . '/inc/layout_foot.php'; ?>