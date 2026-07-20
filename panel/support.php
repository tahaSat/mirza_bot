<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/support_lib.php';
require_auth();
$pdo = panel_ensure_pdo();

$tab = $_GET['tab'] ?? 'unanswered';
if (!in_array($tab, ['unanswered', 'all', 'Answered', 'close'], true)) {
    $tab = 'unanswered';
}
$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$tracking = trim($_GET['tracking'] ?? '');

function support_inbox_url(array $overrides = []): string
{
    $params = array_merge([
        'tab' => $GLOBALS['tab'],
        'q' => $GLOBALS['search'],
        'page' => $GLOBALS['page'],
    ], $overrides);
    $params = array_filter($params, fn($value) => $value !== '' && $value !== null);
    return 'support.php?' . http_build_query($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = $_POST['action'] ?? '';
    $tracking = trim($_POST['tracking'] ?? '');
    $ticket = $tracking !== '' ? db_fetch($pdo, 'SELECT * FROM support_message WHERE Tracking = ? ORDER BY id DESC LIMIT 1', [$tracking]) : null;

    if (!$ticket) {
        flash('error', 'پیام پشتیبانی یافت نشد.');
    } elseif ($action === 'reply') {
        $reply = trim($_POST['reply'] ?? '');
        if ($reply === '') {
            flash('error', 'متن پاسخ را وارد کنید.');
        } elseif (mb_strlen($reply, 'UTF-8') > 3500) {
            flash('error', 'متن پاسخ نباید بیشتر از ۳۵۰۰ کاراکتر باشد.');
        } elseif (!in_array($ticket['status'], panel_support_unanswered_statuses(), true)) {
            flash('warning', 'این پیام پیش‌تر پاسخ داده یا بسته شده است.');
        } else {
            $result = panel_support_send_reply($ticket, $reply);
            if ($result['ok']) {
                db_query(
                    $pdo,
                    "UPDATE support_message SET status = 'Answered', result = ? WHERE id = ?",
                    [$reply, $ticket['id']]
                );
                flash('success', $result['msg']);
            } else {
                flash('error', $result['msg']);
            }
        }
    } elseif ($action === 'close') {
        db_query($pdo, "UPDATE support_message SET status = 'close' WHERE id = ?", [$ticket['id']]);
        flash('success', 'پیام پشتیبانی بسته شد.');
    }

    header('Location: ' . support_inbox_url(['tracking' => $tracking, 'page' => null]));
    exit;
}

$where = [];
$params = [];
if ($tab === 'unanswered') {
    $statuses = panel_support_unanswered_statuses();
    $where[] = 's.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
    $params = $statuses;
} elseif ($tab !== 'all') {
    $where[] = 's.status = ?';
    $params[] = $tab;
}
if ($search !== '') {
    $where[] = "(s.iduser LIKE ? OR s.Tracking LIKE ? OR COALESCE(u.username, '') LIKE ? OR COALESCE(u.namecustom, '') LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$offset = ($page - 1) * $perPage;

try {
    $total = db_count($pdo, "SELECT COUNT(*) FROM support_message s LEFT JOIN user u ON u.id = s.iduser $whereSql", $params);
    $tickets = db_fetchAll(
        $pdo,
        "SELECT s.*, u.username, u.namecustom
         FROM support_message s
         LEFT JOIN user u ON u.id = s.iduser
         $whereSql
         ORDER BY s.id DESC
         LIMIT $perPage OFFSET $offset",
        $params
    );
} catch (Throwable $e) {
    $total = 0;
    $tickets = [];
    flash('error', 'خواندن پیام‌های پشتیبانی با خطا روبه‌رو شد.');
}

$totalPages = max(1, (int) ceil($total / $perPage));
$unansweredCount = panel_support_unanswered_count($pdo);
$ticket = $tracking !== '' ? db_fetch(
    $pdo,
    "SELECT s.*, u.username, u.namecustom
     FROM support_message s
     LEFT JOIN user u ON u.id = s.iduser
     WHERE s.Tracking = ?
     ORDER BY s.id DESC LIMIT 1",
    [$tracking]
) : null;

$pageTitle = 'صندوق پشتیبانی';
$pageLede = 'پیام‌های ثبت‌شده در بخش پشتیبانی ربات و پاسخ به کاربران.';
$activeNav = 'support';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="support-shell fade-up">
    <section class="card support-list">
        <div class="toolbar support-toolbar">
            <div class="toolbar-title"><?= icon('message', 17) ?> صندوق ورودی <small>(<?= number_format($total) ?>)</small></div>
            <form method="GET" class="search-box support-search">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                <?= icon('search', 15) ?>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="آیدی، نام یا کد پیگیری">
                <button type="submit" class="search-btn">جستجو</button>
            </form>
        </div>

        <div class="support-tabs">
            <a class="<?= $tab === 'unanswered' ? 'active' : '' ?>" href="<?= support_inbox_url(['tab' => 'unanswered', 'page' => null, 'tracking' => null]) ?>">پاسخ‌نداده <b><?= $unansweredCount ?></b></a>
            <a class="<?= $tab === 'all' ? 'active' : '' ?>" href="<?= support_inbox_url(['tab' => 'all', 'page' => null, 'tracking' => null]) ?>">همه</a>
            <a class="<?= $tab === 'Answered' ? 'active' : '' ?>" href="<?= support_inbox_url(['tab' => 'Answered', 'page' => null, 'tracking' => null]) ?>">پاسخ داده‌شده</a>
            <a class="<?= $tab === 'close' ? 'active' : '' ?>" href="<?= support_inbox_url(['tab' => 'close', 'page' => null, 'tracking' => null]) ?>">بسته‌شده</a>
        </div>

        <div class="support-ticket-list">
            <?php if (!$tickets): ?>
                <div class="empty"><p>پیامی برای نمایش وجود ندارد.</p></div>
            <?php endif; ?>
            <?php foreach ($tickets as $item):
                [$tagClass, $statusLabel] = panel_support_status_info($item['status']);
                $displayName = ($item['namecustom'] && $item['namecustom'] !== 'none') ? $item['namecustom'] : (($item['username'] && $item['username'] !== 'none') ? '@' . $item['username'] : 'کاربر #' . $item['iduser']);
                ?>
                <a class="support-ticket <?= $tracking === $item['Tracking'] ? 'selected' : '' ?>" href="<?= support_inbox_url(['tracking' => $item['Tracking']]) ?>">
                    <div class="support-ticket-head">
                        <strong><?= htmlspecialchars($displayName) ?></strong>
                        <span class="tag <?= $tagClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                    </div>
                    <p><?= htmlspecialchars(trunc($item['text'], 80)) ?></p>
                    <small><?= htmlspecialchars($item['name_departman']) ?> · <?= htmlspecialchars($item['time']) ?></small>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($total > 0): ?>
            <div class="tbl-foot">
                <span>صفحه <?= $page ?> از <?= $totalPages ?></span>
                <div class="pager">
                    <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= support_inbox_url(['page' => max(1, $page - 1)]) ?>">‹</a>
                    <?php for ($number = max(1, $page - 2); $number <= min($totalPages, $page + 2); $number++): ?>
                        <a class="<?= $number === $page ? 'cur' : '' ?>" href="<?= support_inbox_url(['page' => $number]) ?>"><?= $number ?></a>
                    <?php endfor; ?>
                    <a class="<?= $page >= $totalPages ? 'dis' : '' ?>" href="<?= support_inbox_url(['page' => min($totalPages, $page + 1)]) ?>">›</a>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <section class="card support-conversation">
        <?php if (!$ticket): ?>
            <div class="empty support-empty"><p>یک پیام را از فهرست انتخاب کنید.</p></div>
        <?php else:
            [$tagClass, $statusLabel] = panel_support_status_info($ticket['status']);
            $displayName = ($ticket['namecustom'] && $ticket['namecustom'] !== 'none') ? $ticket['namecustom'] : (($ticket['username'] && $ticket['username'] !== 'none') ? '@' . $ticket['username'] : 'کاربر #' . $ticket['iduser']);
            $canReply = in_array($ticket['status'], panel_support_unanswered_statuses(), true);
            ?>
            <div class="support-conversation-head">
                <div>
                    <h2><?= htmlspecialchars($displayName) ?></h2>
                    <a href="user.php?id=<?= urlencode($ticket['iduser']) ?>">مشاهده پروفایل کاربر</a>
                </div>
                <span class="tag <?= $tagClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
            </div>
            <div class="support-meta">
                <span>دپارتمان: <?= htmlspecialchars($ticket['name_departman']) ?></span>
                <span>پیگیری: <b><?= htmlspecialchars($ticket['Tracking']) ?></b></span>
                <span><?= htmlspecialchars($ticket['time']) ?></span>
            </div>
            <div class="support-messages">
                <div class="support-bubble from-user">
                    <small>پیام کاربر</small>
                    <div><?= nl2br(htmlspecialchars($ticket['text'])) ?></div>
                </div>
                <?php if (trim((string) $ticket['result']) !== ''): ?>
                    <div class="support-bubble from-admin">
                        <small>پاسخ پشتیبانی</small>
                        <div><?= nl2br(htmlspecialchars($ticket['result'])) ?></div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($canReply): ?>
                <form method="POST" class="support-reply">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="tracking" value="<?= htmlspecialchars($ticket['Tracking']) ?>">
                    <textarea class="textarea" name="reply" required maxlength="3500" placeholder="پاسخ خود را برای کاربر بنویسید..."></textarea>
                    <button class="btn btn-primary" type="submit"><?= icon('message', 15) ?> ارسال پاسخ</button>
                </form>
                <form method="POST" class="support-close-form">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="tracking" value="<?= htmlspecialchars($ticket['Tracking']) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">بستن بدون پاسخ</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
