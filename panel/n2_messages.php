<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/support_lib.php';
require_auth();
panel_require_n2();
$pdo = panel_ensure_pdo();
support_ensure_schema($pdo);

$token = panel_n2_bot_token();
$userId = trim((string) ($_GET['user_id'] ?? ''));
$threads = [];
$conversation = [];

if ($token !== '') {
    try {
        $threads = db_fetchAll(
            $pdo,
            "SELECT m.* FROM support_message m
             INNER JOIN (
                SELECT iduser, MAX(id) AS mid
                FROM support_message
                WHERE bottype = ?
                GROUP BY iduser
             ) t ON t.mid = m.id
             ORDER BY m.id DESC
             LIMIT 80",
            [$token]
        );
    } catch (Exception $e) {
        $threads = [];
    }
    if ($userId !== '') {
        try {
            $conversation = db_fetchAll(
                $pdo,
                'SELECT * FROM support_message WHERE iduser = ? AND bottype = ? ORDER BY id ASC',
                [$userId, $token]
            );
        } catch (Exception $e) {
            $conversation = [];
        }
    }
}

$pageTitle = 'پیام‌ها';
$pageLede = 'پیام‌های پشتیبانی ارسال‌شده در ربات فروش شما (فقط مشاهده).';
$activeNav = 'n2_messages';
include __DIR__ . '/inc/layout_head.php';
?>

<?php if ($token === ''): ?>
<div class="notice notice-warn fade-up">ربات فروش یافت نشد.</div>
<?php endif; ?>

<div class="two-col dash-cols">
  <div class="card fade-up d1">
    <div class="card-head">
      <div>
        <div class="card-title">گفتگوها</div>
        <div class="card-subtitle"><?= count($threads) ?> مورد</div>
      </div>
    </div>
    <?php if (empty($threads)): ?>
      <div class="empty" style="padding:32px"><p>پیامی ثبت نشده</p></div>
    <?php else: ?>
      <div class="data-list">
        <?php foreach ($threads as $row):
            $uid = (string) ($row['iduser'] ?? '');
            $preview = panel_support_preview_message($row);
            ?>
          <a href="n2_messages.php?user_id=<?= urlencode($uid) ?>" class="data-row" style="text-decoration:none;color:inherit">
            <div class="data-row-body">
              <div class="data-row-head">
                <div class="data-row-title"><?= htmlspecialchars($uid !== '' ? $uid : '—') ?></div>
                <span class="tag <?= panel_support_status_info((string) ($row['status'] ?? ''))[0] ?>">
                  <?= htmlspecialchars(panel_support_status_info((string) ($row['status'] ?? ''))[1]) ?>
                </span>
              </div>
              <div class="data-row-fields">
                <div class="data-field">
                  <span class="data-field-label">آخرین پیام</span>
                  <span class="data-field-val"><?= htmlspecialchars(trunc($preview['text'], 48)) ?></span>
                </div>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card fade-up d2">
    <div class="card-head">
      <div>
        <div class="card-title"><?= $userId !== '' ? ('گفتگو ' . htmlspecialchars($userId)) : 'جزئیات' ?></div>
        <div class="card-subtitle">فقط مشاهده</div>
      </div>
    </div>
    <?php if ($userId === ''): ?>
      <div class="empty" style="padding:32px"><p>یک گفتگو را انتخاب کنید</p></div>
    <?php elseif (empty($conversation)): ?>
      <div class="empty" style="padding:32px"><p>پیامی برای این کاربر نیست</p></div>
    <?php else: ?>
      <div class="data-list">
        <?php foreach ($conversation as $msg):
            $media = [];
            try {
                $media = db_fetchAll($pdo, 'SELECT * FROM support_media WHERE message_id = ?', [(int) ($msg['id'] ?? 0)]);
            } catch (Exception $e) {
            }
            ?>
          <div class="data-row">
            <div class="data-row-body">
              <div class="data-row-head">
                <div class="data-row-title"><?= htmlspecialchars((string) ($msg['user_name'] ?? $msg['iduser'] ?? 'کاربر')) ?></div>
                <span class="cf"><?= htmlspecialchars((string) ($msg['time'] ?? '')) ?></span>
              </div>
              <p style="margin:8px 0 0;white-space:pre-wrap"><?= htmlspecialchars((string) ($msg['text'] ?? '')) ?></p>
              <?php if (!empty($msg['result'])): ?>
                <p style="margin:8px 0 0;color:var(--mute)">پاسخ: <?= htmlspecialchars((string) $msg['result']) ?></p>
              <?php endif; ?>
              <?php foreach ($media as $item): ?>
                <div style="margin-top:8px">
                  <a class="btn btn-ghost btn-sm" href="support_media.php?id=<?= (int) ($item['id'] ?? 0) ?>">مشاهده پیوست</a>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
