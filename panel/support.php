<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/support_lib.php';
require_auth();
$pdo = panel_ensure_pdo();
support_ensure_schema($pdo);
$currentAdmin = db_fetch($pdo, 'SELECT id_admin, username FROM admin WHERE username = ?', [$_SESSION['admin_user'] ?? '']);

$tab = $_GET['tab'] ?? 'unanswered';
if (!in_array($tab, ['unanswered', 'all', 'Answered', 'close', 'flagged'], true)) {
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
        $id = (int) $item['id'];
        $url = 'support_media.php?id=' . $id;
        $name = htmlspecialchars($item['file_name'] ?: 'فایل پیوست', ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars((string) $item['media_type'], ENT_QUOTES, 'UTF-8');
        $label = match ($item['media_type']) {
            'photo' => '🖼 مشاهده تصویر',
            'video' => '🎬 پخش ویدیو',
            'audio', 'voice' => '🎧 پخش صوت',
            default => '📎 دانلود فایل',
        };
        $html .= '<button type="button" class="support-media-load" data-media-id="' . $id . '" data-media-url="' . $url . '" data-media-type="' . $type . '" data-media-name="' . $name . '">' . $label . ($name !== 'فایل پیوست' ? ' · ' . $name : '') . '</button>';
    }
    return $html;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = $_POST['action'] ?? '';
    $tracking = trim($_POST['tracking'] ?? '');
    $postUserId = trim((string) ($_POST['user_id'] ?? ''));
    $ticket = $tracking !== '' ? db_fetch($pdo, 'SELECT * FROM support_message WHERE Tracking = ? ORDER BY id DESC LIMIT 1', [$tracking]) : null;
    if (!$ticket && $postUserId !== '') {
        $ticket = db_fetch($pdo, 'SELECT * FROM support_message WHERE iduser = ? ORDER BY id DESC LIMIT 1', [$postUserId]);
    }

    if ($action === 'set_status') {
        $targetUser = $postUserId !== '' ? $postUserId : (string) ($ticket['iduser'] ?? '');
        $newStatus = trim((string) ($_POST['status'] ?? ''));
        if ($targetUser === '' || !in_array($newStatus, panel_support_conversation_statuses(), true)) {
            flash('error', 'وضعیت گفتگو نامعتبر است.');
        } elseif (support_conversation_set_status($pdo, $targetUser, $newStatus)) {
            flash('success', 'وضعیت گفتگو به‌روزرسانی شد.');
        } else {
            flash('error', 'به‌روزرسانی وضعیت گفتگو ناموفق بود.');
        }
        $redirectUser = $newStatus === 'close' ? null : ($targetUser !== '' ? $targetUser : null);
        header('Location: ' . support_inbox_url(['user_id' => $redirectUser, 'page' => null]));
        exit;
    }

    if (!$ticket) {
        flash('error', 'پیام پشتیبانی یافت نشد.');
    } elseif ($action === 'reply') {
        $reply = trim($_POST['reply'] ?? '');
        $uploadResult = panel_support_prepare_upload($_FILES['attachment'] ?? []);
        $upload = $uploadResult['upload'] ?? null;
        $chat = support_conversation_get($pdo, (string) $ticket['iduser']);
        $chatStatus = (string) ($chat['status'] ?? 'Unseen');
        $canReply = $chatStatus !== 'close';
        if (!$uploadResult['ok']) {
            flash('error', $uploadResult['msg']);
        } elseif ($reply === '' && !$upload) {
            flash('error', 'متن پاسخ یا فایل را وارد کنید.');
        } elseif (mb_strlen($reply, 'UTF-8') > 3500) {
            flash('error', 'متن پاسخ نباید بیشتر از ۳۵۰۰ کاراکتر باشد.');
        } elseif (!$canReply) {
            flash('warning', 'این گفتگو بسته شده و امکان ارسال پاسخ وجود ندارد.');
        } else {
            $sendTicket = $ticket;
            $sendTicket['Tracking'] = bin2hex(random_bytes(4));
            $result = panel_support_send_reply($sendTicket, $reply, $upload);
            if ($result['ok']) {
                $answeredAt = date('Y/m/d H:i:s');
                $adminId = $currentAdmin['id_admin'] ?? '';
                $adminUsername = $currentAdmin['username'] ?? '';
                db_query(
                    $pdo,
                    "INSERT INTO support_message
                     (Tracking, idsupport, iduser, user_name, name_departman, text, time, status, result,
                      answered_by_admin_id, answered_by_admin_username, answered_at)
                     VALUES (?, ?, ?, ?, ?, '', ?, 'Answered', ?, ?, ?, ?)",
                    [
                        $sendTicket['Tracking'],
                        $ticket['idsupport'],
                        $ticket['iduser'],
                        $ticket['user_name'] ?? '',
                        $ticket['name_departman'],
                        $answeredAt,
                        $reply,
                        $adminId,
                        $adminUsername,
                        $answeredAt,
                    ]
                );
                $messageId = (int) $pdo->lastInsertId();
                if ($messageId > 0) {
                    support_store_media($pdo, $messageId, 'out', $result['media'] ?? []);
                }
                support_conversation_touch(
                    $pdo,
                    (string) $ticket['iduser'],
                    [
                        'idsupport' => $ticket['idsupport'] ?? null,
                        'name_departman' => $ticket['name_departman'] ?? null,
                        'user_name' => $ticket['user_name'] ?? null,
                    ],
                    'Answered',
                    $messageId > 0 ? $messageId : null,
                    $answeredAt
                );
                flash('success', $result['msg']);
            } else {
                flash('error', $result['msg']);
            }
        }
    } elseif ($action === 'close') {
        $closeUser = (string) ($ticket['iduser'] ?? $postUserId);
        support_conversation_set_status($pdo, $closeUser, 'close');
        flash('success', 'گفتگو بسته شد.');
        header('Location: ' . support_inbox_url(['user_id' => null, 'page' => null]));
        exit;
    }

    header('Location: ' . support_inbox_url(['user_id' => $ticket['iduser'] ?? null, 'page' => null]));
    exit;
}

$tabStatusMap = [
    'unanswered' => 'Unseen',
    'Answered' => 'Answered',
    'close' => 'close',
    'flagged' => 'flagged',
];
$searchSql = '';
$searchParams = [];
if ($search !== '') {
    $searchSql = " AND (c.iduser LIKE ? OR COALESCE(c.user_name, '') LIKE ? OR COALESCE(u.username, '') LIKE ? OR COALESCE(u.namecustom, '') LIKE ? OR COALESCE(s.Tracking, '') LIKE ?)";
    $searchParams = ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%"];
}
$statusSql = '';
$statusParams = [];
if ($tab !== 'all' && isset($tabStatusMap[$tab])) {
    $statusSql = ' AND c.status = ?';
    $statusParams[] = $tabStatusMap[$tab];
}
$offset = ($page - 1) * $perPage;

try {
    support_ensure_conversation_table($pdo);
    $fromSql = "FROM support_conversation c
         LEFT JOIN support_message s ON s.id = c.last_message_id
         LEFT JOIN user u ON u.id = c.iduser
         WHERE 1=1 $statusSql $searchSql";
    $listParams = array_merge($statusParams, $searchParams);
    $total = db_count($pdo, "SELECT COUNT(*) $fromSql", $listParams);
    $tickets = db_fetchAll(
        $pdo,
        "SELECT c.id AS conversation_id, c.iduser, c.status AS chat_status, c.idsupport AS conversation_idsupport,
                c.name_departman AS conversation_departman, c.user_name AS conversation_user_name,
                c.last_message_at, c.updated_at,
                s.id, s.Tracking, s.text, s.result, s.time, s.status, s.name_departman, s.user_name AS message_user_name,
                s.answered_at, u.username, u.namecustom
         $fromSql
         ORDER BY COALESCE(c.updated_at, c.last_message_at) DESC, c.id DESC
         LIMIT $perPage OFFSET $offset",
        $listParams
    );
} catch (Throwable $e) {
    $total = 0;
    $tickets = [];
    flash('error', 'خواندن پیام‌های پشتیبانی با خطا روبه‌رو شد.');
}

$totalPages = max(1, (int) ceil($total / $perPage));
$unansweredCount = panel_support_unanswered_count($pdo);
$flaggedCount = panel_support_status_count($pdo, 'flagged');
$conversation = $userId !== '' ? db_fetchAll(
    $pdo,
    "SELECT s.*, u.username, u.namecustom
     FROM support_message s
     LEFT JOIN user u ON u.id = s.iduser
     WHERE s.iduser = ?
     ORDER BY s.id ASC",
    [$userId]
) : [];
$chatRow = $userId !== '' ? support_conversation_get($pdo, $userId) : null;
if ($userId !== '' && !$chatRow && $conversation) {
    $latest = $conversation[count($conversation) - 1];
    support_conversation_touch(
        $pdo,
        $userId,
        [
            'idsupport' => $latest['idsupport'] ?? null,
            'name_departman' => $latest['name_departman'] ?? null,
            'user_name' => $latest['user_name'] ?? null,
        ],
        panel_support_chat_status_from_messages($conversation),
        (int) ($latest['id'] ?? 0) ?: null,
        $latest['time'] ?? null
    );
    $chatRow = support_conversation_get($pdo, $userId);
}
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
$replyTicket = $conversation ? $conversation[count($conversation) - 1] : null;
$conversationStatus = (string) ($chatRow['status'] ?? panel_support_chat_status_from_messages($conversation));
$canReply = $conversationStatus !== 'close' && $replyTicket;

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
            <a class="<?= $tab === 'flagged' ? 'active' : '' ?>" href="<?= support_inbox_url(['tab' => 'flagged', 'page' => null, 'user_id' => null]) ?>">نشانه گذاری شده <b><?= $flaggedCount ?></b></a>
            <a class="<?= $tab === 'close' ? 'active' : '' ?>" href="<?= support_inbox_url(['tab' => 'close', 'page' => null, 'user_id' => null]) ?>">بسته‌شده</a>
        </div>

        <div class="support-ticket-list">
            <?php if (!$tickets): ?>
                <div class="empty"><p>پیامی برای نمایش وجود ندارد.</p></div>
            <?php endif; ?>
            <?php foreach ($tickets as $item):
                $chatStatus = (string) ($item['chat_status'] ?? 'Unseen');
                [$tagClass, $statusLabel] = panel_support_status_info($chatStatus);
                $preview = panel_support_preview_message($item);
                $itemUserName = $item['conversation_user_name'] ?? $item['message_user_name'] ?? $item['user_name'] ?? '';
                $displayName = !empty($itemUserName) ? $itemUserName : (($item['namecustom'] && $item['namecustom'] !== 'none') ? $item['namecustom'] : (($item['username'] && $item['username'] !== 'none') ? '@' . $item['username'] : 'کاربر ناشناس'));
                $userHandle = ($item['username'] && $item['username'] !== 'none') ? '@' . $item['username'] : '';
                if ($userHandle === $displayName) {
                    $userHandle = '';
                }
                $previewText = $preview['from'] === 'admin' ? ('شما: ' . $preview['text']) : $preview['text'];
                $dept = $item['conversation_departman'] ?? $item['name_departman'] ?? '';
                $previewTime = $preview['time'] !== '' ? $preview['time'] : (string) ($item['last_message_at'] ?? '');
                ?>
                <a class="support-ticket <?= $userId === (string) $item['iduser'] ? 'selected' : '' ?>" href="<?= support_inbox_url(['user_id' => $item['iduser']]) ?>">
                    <div class="support-ticket-head">
                        <div class="support-contact">
                            <strong><?= htmlspecialchars($displayName) ?></strong>
                            <?php if ($userHandle): ?><small><?= htmlspecialchars($userHandle) ?></small><?php endif; ?>
                        </div>
                        <span class="tag <?= $tagClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                    </div>
                    <p><?= htmlspecialchars(trunc($previewText, 80)) ?></p>
                    <small><?= htmlspecialchars((string) $dept) ?> · <?= htmlspecialchars($previewTime) ?></small>
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
            [$tagClass, $statusLabel] = panel_support_status_info($conversationStatus);
            $displayName = !empty($ticket['user_name']) ? $ticket['user_name'] : (($ticket['namecustom'] && $ticket['namecustom'] !== 'none') ? $ticket['namecustom'] : (($ticket['username'] && $ticket['username'] !== 'none') ? '@' . $ticket['username'] : 'کاربر ناشناس'));
            $adminId = (string) ($replyTicket['idsupport'] ?? $conversation[count($conversation) - 1]['idsupport'] ?? '—');
            ?>
            <div class="support-conversation-head">
                <div>
                    <h2><?= htmlspecialchars($displayName) ?></h2>
                    <a href="user.php?id=<?= urlencode($ticket['iduser']) ?>">مشاهده پروفایل کاربر</a>
                </div>
                <div class="support-head-actions">
                    <div class="support-status-menu">
                        <button type="button" class="tag <?= $tagClass ?> support-status-trigger" aria-haspopup="listbox" aria-expanded="false">
                            <?= htmlspecialchars($statusLabel) ?>
                            <span class="support-status-caret">▾</span>
                        </button>
                        <form method="POST" class="support-status-dropdown" hidden>
                            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="user_id" value="<?= htmlspecialchars($ticket['iduser']) ?>">
                            <?php foreach (panel_support_conversation_statuses() as $statusKey):
                                [$optClass, $optLabel] = panel_support_status_info($statusKey);
                                ?>
                                <button class="support-status-option <?= $conversationStatus === $statusKey ? 'active' : '' ?>" type="submit" name="status" value="<?= htmlspecialchars($statusKey) ?>">
                                    <span class="tag <?= $optClass ?>"><?= htmlspecialchars($optLabel) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </form>
                    </div>
                    <?php if ($canReply): ?>
                        <form method="POST" class="support-close-form support-close-form-head">
                            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="close">
                            <input type="hidden" name="user_id" value="<?= htmlspecialchars($ticket['iduser']) ?>">
                            <input type="hidden" name="tracking" value="<?= htmlspecialchars($replyTicket['Tracking']) ?>">
                            <button class="btn btn-ghost btn-sm" type="submit">بستن گفتگو</button>
                        </form>
                    <?php endif; ?>
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
                <?php foreach ($conversation as $message):
                    $userText = trim((string) $message['text']);
                    $adminText = trim((string) $message['result']);
                    $inMedia = $mediaByMessage[(int) $message['id']]['in'] ?? [];
                    $outMedia = $mediaByMessage[(int) $message['id']]['out'] ?? [];
                    $showUser = $userText !== '' || $inMedia;
                    $showAdmin = $adminText !== '' || $outMedia;
                    ?>
                    <?php if ($showUser): ?>
                        <div class="support-bubble from-user">
                            <small>کاربر · <?= htmlspecialchars($message['time']) ?> · <?= htmlspecialchars($message['name_departman']) ?></small>
                            <?php if ($userText !== ''): ?><div><?= nl2br(htmlspecialchars($userText)) ?></div><?php endif; ?>
                            <?= support_media_markup($inMedia) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($showAdmin): ?>
                        <div class="support-bubble from-admin">
                            <?php
                            $replyAdminName = $message['answered_by_admin_username'] ?? '';
                            $replyAdminId = $message['answered_by_admin_id'] ?? '';
                            ?>
                            <small>
                                <?= $replyAdminName !== '' ? 'ادمین ' . htmlspecialchars($replyAdminName) : 'پاسخ ادمین (قدیمی)' ?>
                                <?= $replyAdminId !== '' ? ' · ' . htmlspecialchars($replyAdminId) : '' ?>
                                <?php if (!empty($message['answered_at'])): ?> · <?= htmlspecialchars($message['answered_at']) ?><?php endif; ?>
                            </small>
                            <?php if ($adminText !== ''): ?><div><?= nl2br(htmlspecialchars($adminText)) ?></div><?php endif; ?>
                            <?= support_media_markup($outMedia) ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php if ($canReply): ?>
                <form method="POST" class="support-reply" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="tracking" value="<?= htmlspecialchars($replyTicket['Tracking']) ?>">
                    <div class="support-reply-box">
                        <button class="support-send-btn" type="submit" title="ارسال پاسخ" aria-label="ارسال پاسخ"><?= icon('send', 18) ?></button>
                        <textarea class="textarea" name="reply" maxlength="3500" rows="1" placeholder="پیام خود را بنویسید..."></textarea>
                        <label class="support-attachment-btn">
                            <input type="file" name="attachment" onchange="var s=this.nextElementSibling.querySelector('em'); this.parentElement.classList.toggle('has-file', !!this.files[0]); s.textContent = this.files[0] ? this.files[0].name : 'افزودن فایل'">
                            <span><?= icon('paperclip', 15) ?> <em>افزودن فایل</em></span>
                        </label>
                    </div>
                </form>
                <form method="POST" class="support-close-form support-close-form-foot">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($ticket['iduser']) ?>">
                    <input type="hidden" name="tracking" value="<?= htmlspecialchars($replyTicket['Tracking']) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">بستن گفتگو</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<div class="support-media-viewer" id="support-media-viewer" hidden>
  <button type="button" class="support-media-viewer-close" id="support-media-viewer-close" aria-label="بستن">×</button>
  <div class="support-media-viewer-body" id="support-media-viewer-body"></div>
</div>

<script>
(function () {
  var menu = document.querySelector('.support-status-menu');
  if (!menu) return;
  var trigger = menu.querySelector('.support-status-trigger');
  var dropdown = menu.querySelector('.support-status-dropdown');
  if (!trigger || !dropdown) return;

  function closeMenu() {
    dropdown.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
  }

  trigger.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();
    var open = dropdown.hidden;
    dropdown.hidden = !open;
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  document.addEventListener('click', function (event) {
    if (!menu.contains(event.target)) closeMenu();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeMenu();
  });
}());
</script>

<script>
(function () {
  var viewer = document.getElementById('support-media-viewer');
  var viewerBody = document.getElementById('support-media-viewer-body');
  var viewerClose = document.getElementById('support-media-viewer-close');

  function closeViewer() {
    if (!viewer) return;
    viewer.hidden = true;
    viewerBody.innerHTML = '';
    document.body.classList.remove('support-media-viewer-open');
  }

  function openViewer(node) {
    if (!viewer || !viewerBody) return;
    viewerBody.innerHTML = '';
    viewerBody.appendChild(node);
    viewer.hidden = false;
    document.body.classList.add('support-media-viewer-open');
  }

  if (viewerClose) viewerClose.addEventListener('click', closeViewer);
  if (viewer) {
    viewer.addEventListener('click', function (event) {
      if (event.target === viewer) closeViewer();
    });
  }
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeViewer();
  });

  document.addEventListener('click', function (event) {
    var btn = event.target.closest('.support-media-load');
    if (!btn || btn.dataset.loading === '1') return;
    event.preventDefault();

    var url = btn.dataset.mediaUrl;
    var type = btn.dataset.mediaType;
    var name = btn.dataset.mediaName || 'attachment';
    btn.dataset.loading = '1';
    btn.disabled = true;
    btn.textContent = 'در حال دریافت...';

    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (!response.ok) throw new Error('http ' + response.status);
        return response.blob();
      })
      .then(function (blob) {
        var objectUrl = URL.createObjectURL(blob);
        var wrap = document.createElement('div');
        wrap.className = 'support-media-ready';

        if (type === 'photo') {
          var thumbLink = document.createElement('button');
          thumbLink.type = 'button';
          thumbLink.className = 'support-media-photo';
          var img = document.createElement('img');
          img.alt = name;
          img.src = objectUrl;
          thumbLink.appendChild(img);
          thumbLink.addEventListener('click', function () {
            var full = document.createElement('img');
            full.alt = name;
            full.src = objectUrl;
            full.className = 'support-media-viewer-img';
            openViewer(full);
          });
          wrap.appendChild(thumbLink);
          btn.replaceWith(wrap);
          return;
        }

        if (type === 'video') {
          var video = document.createElement('video');
          video.className = 'support-media-video';
          video.controls = true;
          video.setAttribute('playsinline', '');
          video.preload = 'metadata';
          video.src = objectUrl;
          wrap.appendChild(video);
          btn.replaceWith(wrap);
          return;
        }

        if (type === 'audio' || type === 'voice') {
          var audio = document.createElement('audio');
          audio.className = 'support-media-audio';
          audio.controls = true;
          audio.preload = 'metadata';
          audio.src = objectUrl;
          wrap.appendChild(audio);
          btn.replaceWith(wrap);
          return;
        }

        var fileLink = document.createElement('a');
        fileLink.className = 'support-media-file';
        fileLink.href = objectUrl;
        fileLink.download = name;
        fileLink.textContent = '📎 ' + name;
        wrap.appendChild(fileLink);
        btn.replaceWith(wrap);
      })
      .catch(function () {
        btn.dataset.loading = '0';
        btn.disabled = false;
        btn.textContent = 'خطا در دریافت فایل · تلاش مجدد';
      });
  });
}());

(function () {
  var shell = document.querySelector('.support-shell.support-chat-open');
  if (!shell) return;

  if ('scrollRestoration' in history) {
    history.scrollRestoration = 'manual';
  }

  var sheet = shell.querySelector('.support-conversation');
  var messages = shell.querySelector('.support-messages');
  var replyInput = shell.querySelector('.support-reply textarea');
  var pinnedToEnd = true;
  var focusPoll = null;
  var mobileMq = window.matchMedia ? window.matchMedia('(max-width: 768px)') : null;

  function isMobileSheet() {
    return document.body.classList.contains('support-sheet-open') && (!mobileMq || mobileMq.matches);
  }

  function scrollMessagesToEnd(force) {
    if (!messages) return;
    if (!force && !pinnedToEnd) return;
    var last = messages.lastElementChild;
    messages.scrollTop = messages.scrollHeight;
    if (last && typeof last.scrollIntoView === 'function') {
      try { last.scrollIntoView({ block: 'end', inline: 'nearest' }); } catch (e) {}
    }
    messages.scrollTop = messages.scrollHeight;
  }

  function scheduleScrollToEnd(force) {
    scrollMessagesToEnd(force);
    requestAnimationFrame(function () {
      scrollMessagesToEnd(force);
      requestAnimationFrame(function () { scrollMessagesToEnd(force); });
    });
    [50, 150, 300, 600].forEach(function (ms) {
      setTimeout(function () { scrollMessagesToEnd(force); }, ms);
    });
  }

  function clearSheetGeometry() {
    if (!sheet) return;
    sheet.style.top = '';
    sheet.style.bottom = '';
    sheet.style.height = '';
    sheet.style.maxHeight = '';
    shell.classList.remove('support-keyboard-open');
  }

  function syncSupportSheetViewport() {
    if (!sheet) return;
    if (!isMobileSheet()) {
      clearSheetGeometry();
      return;
    }

    var vv = window.visualViewport;
    var layoutH = window.innerHeight || document.documentElement.clientHeight || 0;
    var visibleH = vv ? vv.height : layoutH;
    var offsetTop = vv ? vv.offsetTop : 0;
    var kb = Math.max(0, Math.round(layoutH - visibleH - offsetTop));
    var focused = !!(replyInput && document.activeElement === replyInput);
    var keyboardOpen = kb > 24 || focused;

    // Pin the sheet to the visible viewport so the composer stays above the keyboard.
    var top;
    var height;
    if (keyboardOpen) {
      top = Math.round(offsetTop);
      height = Math.max(200, Math.round(visibleH));
      shell.classList.add('support-keyboard-open');
    } else {
      height = Math.max(280, Math.round(Math.min(layoutH * 0.88, visibleH - 8)));
      top = Math.round(offsetTop + Math.max(0, visibleH - height));
      shell.classList.remove('support-keyboard-open');
    }

    sheet.style.top = top + 'px';
    sheet.style.bottom = 'auto';
    sheet.style.height = height + 'px';
    sheet.style.maxHeight = height + 'px';

    if (pinnedToEnd || focused) scrollMessagesToEnd(true);
  }

  function stopFocusPoll() {
    if (focusPoll) {
      clearInterval(focusPoll);
      focusPoll = null;
    }
  }

  function startFocusPoll() {
    stopFocusPoll();
    var ticks = 0;
    focusPoll = setInterval(function () {
      syncSupportSheetViewport();
      if (++ticks >= 60) stopFocusPoll();
    }, 50);
  }

  if (messages) {
    messages.addEventListener('scroll', function () {
      pinnedToEnd = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 80;
    }, { passive: true });
  }

  syncSupportSheetViewport();
  scheduleScrollToEnd(true);
  window.addEventListener('load', function () {
    syncSupportSheetViewport();
    scheduleScrollToEnd(true);
  });

  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', syncSupportSheetViewport);
    window.visualViewport.addEventListener('scroll', syncSupportSheetViewport);
  }
  window.addEventListener('resize', syncSupportSheetViewport);
  window.addEventListener('orientationchange', function () {
    setTimeout(syncSupportSheetViewport, 150);
  });
  if (mobileMq) {
    if (mobileMq.addEventListener) mobileMq.addEventListener('change', syncSupportSheetViewport);
    else if (mobileMq.addListener) mobileMq.addListener(syncSupportSheetViewport);
  }

  if (replyInput) {
    replyInput.addEventListener('focus', function () {
      pinnedToEnd = true;
      shell.classList.add('support-keyboard-open');
      syncSupportSheetViewport();
      startFocusPoll();
      scheduleScrollToEnd(true);
    });
    replyInput.addEventListener('blur', function () {
      stopFocusPoll();
      setTimeout(function () {
        syncSupportSheetViewport();
      }, 120);
    });
  }
}());
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
