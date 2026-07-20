<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/support_lib.php';
require_auth();
$pdo = panel_ensure_pdo();
$currentAdmin = db_fetch($pdo, 'SELECT id_admin, username FROM admin WHERE username = ?', [$_SESSION['admin_user'] ?? '']);

$tab = $_GET['tab'] ?? 'unanswered';
if (!in_array($tab, ['unanswered', 'all', 'Answered', 'close'], true)) {
    $tab = 'unanswered';
}
$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$userId = trim($_GET['user_id'] ?? '');

function support_inbox_url(array $overrides = []): string
{
    $params = array_merge([
        'tab' => $GLOBALS['tab'],
        'q' => $GLOBALS['search'],
        'page' => $GLOBALS['page'],
        'user_id' => $GLOBALS['userId'],
    ], $overrides);
    $params = array_filter($params, fn($value) => $value !== '' && $value !== null);
    return 'support.php?' . http_build_query($params);
}

function support_media_markup(array $media): string
{
    $html = '';
    foreach ($media as $item) {
        $url = 'support_media.php?id=' . (int) $item['id'];
        $name = htmlspecialchars($item['file_name'] ?: 'فایل پیوست', ENT_QUOTES, 'UTF-8');
        $type = $item['media_type'];
        if ($type === 'photo') {
            $html .= '<a class="support-media-photo" href="' . $url . '" target="_blank" rel="noopener"><img src="' . $url . '" loading="lazy" alt="' . $name . '"></a>';
        } elseif ($type === 'video') {
            $html .= '<video class="support-media-video" controls preload="none"><source src="' . $url . '"></video>';
        } elseif (in_array($type, ['audio', 'voice'], true)) {
            $html .= '<audio class="support-media-audio" controls preload="none"><source src="' . $url . '"></audio>';
        } else {
            $html .= '<a class="support-media-file" href="' . $url . '" target="_blank" rel="noopener">📎 ' . $name . '</a>';
        }
    }
    return $html;
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
        $uploadResult = panel_support_prepare_upload($_FILES['attachment'] ?? []);
        $upload = $uploadResult['upload'] ?? null;
        if (!$uploadResult['ok']) {
            flash('error', $uploadResult['msg']);
        } elseif ($reply === '' && !$upload) {
            flash('error', 'متن پاسخ یا فایل را وارد کنید.');
        } elseif (mb_strlen($reply, 'UTF-8') > 3500) {
            flash('error', 'متن پاسخ نباید بیشتر از ۳۵۰۰ کاراکتر باشد.');
        } elseif (!in_array($ticket['status'], panel_support_unanswered_statuses(), true)) {
            flash('warning', 'این پیام پیش‌تر پاسخ داده یا بسته شده است.');
        } else {
            $result = panel_support_send_reply($ticket, $reply, $upload);
            if ($result['ok']) {
                db_query(
                    $pdo,
                    "UPDATE support_message
                     SET status = 'Answered', result = ?, answered_by_admin_id = ?,
                         answered_by_admin_username = ?, answered_at = ?
                     WHERE id = ?",
                    [$reply, $currentAdmin['id_admin'] ?? '', $currentAdmin['username'] ?? '', date('Y/m/d H:i:s'), $ticket['id']]
                );
                support_store_media($pdo, (int) $ticket['id'], 'out', $result['media'] ?? []);
                flash('success', $result['msg']);
            } else {
                flash('error', $result['msg']);
            }
        }
    } elseif ($action === 'close') {
        db_query($pdo, "UPDATE support_message SET status = 'close' WHERE id = ?", [$ticket['id']]);
        flash('success', 'پیام پشتیبانی بسته شد.');
    }

    header('Location: ' . support_inbox_url(['user_id' => $ticket['iduser'] ?? null, 'page' => null]));
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
    $groupSql = "SELECT s.iduser FROM support_message s LEFT JOIN user u ON u.id = s.iduser $whereSql GROUP BY s.iduser";
    $total = db_count($pdo, "SELECT COUNT(*) FROM ($groupSql) grouped_tickets", $params);
    $tickets = db_fetchAll(
        $pdo,
        "SELECT s.*, u.username, u.namecustom
         FROM support_message s
         INNER JOIN (
             SELECT MAX(s.id) AS id
             FROM support_message s
             LEFT JOIN user u ON u.id = s.iduser
             $whereSql
             GROUP BY s.iduser
         ) latest ON latest.id = s.id
         LEFT JOIN user u ON u.id = s.iduser
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
$conversation = $userId !== '' ? db_fetchAll(
    $pdo,
    "SELECT s.*, u.username, u.namecustom
     FROM support_message s
     LEFT JOIN user u ON u.id = s.iduser
     WHERE s.iduser = ?
     ORDER BY s.id ASC",
    [$userId]
) : [];
$mediaByMessage = [];
if ($conversation && support_ensure_media_table($pdo)) {
    $messageIds = array_map(fn($message) => (int) $message['id'], $conversation);
    try {
        $media = db_fetchAll($pdo, 'SELECT * FROM support_media WHERE message_id IN (' . implode(',', $messageIds) . ') ORDER BY id ASC');
        foreach ($media as $item) {
            $mediaByMessage[(int) $item['message_id']][$item['direction']][] = $item;
        }
    } catch (PDOException $e) {
        error_log('Unable to load support media: ' . $e->getMessage());
    }
}
$ticket = $conversation[0] ?? null;
$replyTicket = null;
foreach (array_reverse($conversation) as $message) {
    if (in_array($message['status'], panel_support_unanswered_statuses(), true)) {
        $replyTicket = $message;
        break;
    }
}

$pageTitle = 'صندوق پشتیبانی';
$pageLede = 'پیام‌های ثبت‌شده در بخش پشتیبانی ربات و پاسخ به کاربران.';
$activeNav = 'support';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="support-shell <?= $userId !== '' ? 'support-chat-open' : '' ?> fade-up">
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
            <a class="<?= $tab === 'unanswered' ? 'active' : '' ?>" href="<?= support_inbox_url(['tab' => 'unanswered', 'page' => null, 'user_id' => null]) ?>">پاسخ‌نداده <b><?= $unansweredCount ?></b></a>
            <a class="<?= $tab === 'all' ? 'active' : '' ?>" href="<?= support_inbox_url(['tab' => 'all', 'page' => null, 'user_id' => null]) ?>">همه</a>
            <a class="<?= $tab === 'Answered' ? 'active' : '' ?>" href="<?= support_inbox_url(['tab' => 'Answered', 'page' => null, 'user_id' => null]) ?>">پاسخ داده‌شده</a>
            <a class="<?= $tab === 'close' ? 'active' : '' ?>" href="<?= support_inbox_url(['tab' => 'close', 'page' => null, 'user_id' => null]) ?>">بسته‌شده</a>
        </div>

        <div class="support-ticket-list">
            <?php if (!$tickets): ?>
                <div class="empty"><p>پیامی برای نمایش وجود ندارد.</p></div>
            <?php endif; ?>
            <?php foreach ($tickets as $item):
                [$tagClass, $statusLabel] = panel_support_status_info($item['status']);
                $displayName = !empty($item['user_name']) ? $item['user_name'] : (($item['namecustom'] && $item['namecustom'] !== 'none') ? $item['namecustom'] : (($item['username'] && $item['username'] !== 'none') ? '@' . $item['username'] : 'کاربر ناشناس'));
                $userHandle = ($item['username'] && $item['username'] !== 'none') ? '@' . $item['username'] : '';
                if ($userHandle === $displayName) {
                    $userHandle = '';
                }
                ?>
                <a class="support-ticket <?= $userId === (string) $item['iduser'] ? 'selected' : '' ?>" href="<?= support_inbox_url(['user_id' => $item['iduser']]) ?>">
                    <div class="support-ticket-head">
                        <div class="support-contact">
                            <strong><?= htmlspecialchars($displayName) ?></strong>
                            <?php if ($userHandle): ?><small><?= htmlspecialchars($userHandle) ?></small><?php endif; ?>
                        </div>
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

    <?php if ($userId !== ''): ?>
        <a class="support-sheet-backdrop" href="<?= support_inbox_url(['user_id' => null]) ?>" aria-label="بستن گفتگو"></a>
        <script>document.body.classList.add('support-sheet-open');</script>
    <?php endif; ?>
    <section class="card support-conversation">
        <?php if (!$ticket): ?>
            <div class="empty support-empty"><p>یک پیام را از فهرست انتخاب کنید.</p></div>
        <?php else:
            [$tagClass, $statusLabel] = panel_support_status_info($conversation[count($conversation) - 1]['status']);
            $displayName = !empty($ticket['user_name']) ? $ticket['user_name'] : (($ticket['namecustom'] && $ticket['namecustom'] !== 'none') ? $ticket['namecustom'] : (($ticket['username'] && $ticket['username'] !== 'none') ? '@' . $ticket['username'] : 'کاربر ناشناس'));
            $adminId = (string) ($replyTicket['idsupport'] ?? $conversation[count($conversation) - 1]['idsupport'] ?? '—');
            ?>
            <div class="support-conversation-head">
                <div>
                    <h2><?= htmlspecialchars($displayName) ?></h2>
                    <a href="user.php?id=<?= urlencode($ticket['iduser']) ?>">مشاهده پروفایل کاربر</a>
                </div>
                <div class="support-head-actions">
                    <span class="tag <?= $tagClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                    <a class="support-back" href="<?= support_inbox_url(['user_id' => null]) ?>"><?= icon('arrow-left', 15) ?> بازگشت</a>
                </div>
            </div>
            <div class="support-meta">
                <span>تعداد پیام‌ها: <?= count($conversation) ?></span>
                <span>آخرین پیام: <?= htmlspecialchars($conversation[count($conversation) - 1]['time']) ?></span>
            </div>
            <div class="support-identities">
                <span><small>شناسه کاربر</small><b><?= htmlspecialchars($ticket['iduser']) ?></b></span>
                <span><small>شناسه ادمین دپارتمان</small><b><?= htmlspecialchars($adminId) ?></b></span>
            </div>
            <div class="support-messages">
                <?php foreach ($conversation as $message): ?>
                    <div class="support-bubble from-user">
                        <small>کاربر · <?= htmlspecialchars($message['time']) ?> · <?= htmlspecialchars($message['name_departman']) ?></small>
                        <div><?= nl2br(htmlspecialchars($message['text'])) ?></div>
                        <?= support_media_markup($mediaByMessage[(int) $message['id']]['in'] ?? []) ?>
                    </div>
                    <?php if (trim((string) $message['result']) !== '' || !empty($mediaByMessage[(int) $message['id']]['out'])): ?>
                        <div class="support-bubble from-admin">
                            <?php
                            $replyAdminName = $message['answered_by_admin_username'] ?? '';
                            $replyAdminId = $message['answered_by_admin_id'] ?? '';
                            ?>
                            <small>
                                <?= $replyAdminName !== '' ? 'ادمین ' . htmlspecialchars($replyAdminName) : 'پاسخ ادمین (قدیمی)' ?>
                                <?= $replyAdminId !== '' ? ' · ' . htmlspecialchars($replyAdminId) : '' ?>
                            </small>
                            <?php if (trim((string) $message['result']) !== ''): ?><div><?= nl2br(htmlspecialchars($message['result'])) ?></div><?php endif; ?>
                            <?= support_media_markup($mediaByMessage[(int) $message['id']]['out'] ?? []) ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php if ($replyTicket): ?>
                <form method="POST" class="support-reply" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="tracking" value="<?= htmlspecialchars($replyTicket['Tracking']) ?>">
                    <textarea class="textarea" name="reply" maxlength="3500" placeholder="پاسخ خود را برای کاربر بنویسید..."></textarea>
                    <label class="support-attachment-picker">
                        <input type="file" name="attachment" onchange="this.nextElementSibling.textContent=this.files[0] ? this.files[0].name : '📎 افزودن فایل'">
                        <span>📎 افزودن فایل</span>
                    </label>
                    <button class="btn btn-primary" type="submit"><?= icon('message', 15) ?> ارسال پاسخ</button>
                </form>
                <form method="POST" class="support-close-form">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="tracking" value="<?= htmlspecialchars($replyTicket['Tracking']) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">بستن بدون پاسخ</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
