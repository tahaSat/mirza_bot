<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_once dirname(__DIR__) . '/withdraw_lib.php';
require_auth();

$pdo = panel_ensure_pdo();
panel_payment_ensure_schema($pdo);
withdraw_ensure_schema($pdo);

$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, ['settings', 'pending', 'history'], true)) {
    $tab = 'pending';
}

function wallet_withdraw_redirect(string $tab): string
{
    return 'wallet_withdraw.php?tab=' . urlencode($tab);
}

function wallet_withdraw_admin_id(): string
{
    return 'panel:' . (string) ($_SESSION['admin_user'] ?? 'admin');
}

function wallet_withdraw_when(int $ts): string
{
    if ($ts < 1) {
        return '—';
    }
    return function_exists('jalali_tehran_format')
        ? jalali_tehran_format($ts, 'Y/m/d H:i')
        : date('Y/m/d H:i', $ts);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = (string) ($_POST['action'] ?? '');
    $postTab = (string) ($_POST['tab'] ?? $tab);
    if (!in_array($postTab, ['settings', 'pending', 'history'], true)) {
        $postTab = $tab;
    }

    if ($action === 'save_settings') {
        $min = withdraw_parse_int((string) ($_POST['min_amount'] ?? '0'));
        if ($min === null) {
            flash('error', 'حداقل برداشت باید عدد باشد.');
            header('Location: ' . wallet_withdraw_redirect('settings'));
            exit;
        }
        withdraw_set_min($min, $pdo);
        $prompt = trim((string) ($_POST['prompt_text'] ?? ''));
        $success = trim((string) ($_POST['success_text'] ?? ''));
        if ($prompt === '') {
            $prompt = withdraw_prompt_default();
        }
        if ($success === '') {
            $success = withdraw_success_default();
        }
        pay_textbot_set($pdo, WITHDRAW_TEXT_PROMPT, $prompt);
        pay_textbot_set($pdo, WITHDRAW_TEXT_SUCCESS, $success);
        if (function_exists('clearSelectCache')) {
            clearSelectCache('textbot');
        }
        flash('success', 'تنظیمات برداشت ذخیره شد.');
        header('Location: ' . wallet_withdraw_redirect('settings'));
        exit;
    }

    if ($action === 'approve') {
        $id = (int) ($_POST['id'] ?? 0);
        $upload = withdraw_save_receipt_from_upload($id, $_FILES['receipt'] ?? []);
        if (empty($upload['ok'])) {
            flash('error', $upload['msg'] ?? 'آپلود رسید ناموفق بود.');
            header('Location: ' . wallet_withdraw_redirect('pending'));
            exit;
        }
        $result = withdraw_approve($id, wallet_withdraw_admin_id(), [
            'path' => $upload['path'],
            'abs_path' => withdraw_absolute_receipt_path($upload['path']),
            'mime' => $upload['mime'] ?? 'image/jpeg',
        ], $pdo);
        flash(!empty($result['ok']) ? 'success' : 'error', $result['msg'] ?? 'خطا');
        header('Location: ' . wallet_withdraw_redirect(!empty($result['ok']) ? 'history' : 'pending'));
        exit;
    }

    if ($action === 'reject') {
        $id = (int) ($_POST['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $result = withdraw_reject($id, wallet_withdraw_admin_id(), $reason, $pdo);
        flash(!empty($result['ok']) ? 'success' : 'error', $result['msg'] ?? 'خطا');
        header('Location: ' . wallet_withdraw_redirect('pending'));
        exit;
    }

    header('Location: ' . wallet_withdraw_redirect($postTab));
    exit;
}

$pendingCount = withdraw_pending_count($pdo);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$list = [];
$users = [];
$total = 0;
if ($tab === 'pending' || $tab === 'history') {
    $status = $tab === 'pending' ? WITHDRAW_STATUS_PENDING : WITHDRAW_STATUS_PAID;
    $total = withdraw_count_status($status, $pdo);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $rows = withdraw_list($status, $perPage, ($page - 1) * $perPage, $pdo);
    $userIds = [];
    foreach ($rows as $row) {
        $uid = (string) ($row['id_user'] ?? '');
        if ($uid !== '') {
            $userIds[$uid] = $uid;
        }
    }
    $users = [];
    if ($userIds !== []) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $userRows = db_fetchAll($pdo, "SELECT id, username, namecustom, Balance FROM user WHERE id IN ($placeholders)", array_values($userIds));
        foreach ($userRows as $u) {
            $users[(string) $u['id']] = $u;
        }
    }
    $list = $rows;
} else {
    $totalPages = 1;
}

$minAmount = withdraw_min_amount($pdo);
$promptText = pay_textbot_get($pdo, WITHDRAW_TEXT_PROMPT, withdraw_prompt_default());
$successText = pay_textbot_get($pdo, WITHDRAW_TEXT_SUCCESS, withdraw_success_default());

$pageTitle = 'برداشت از کیف پول';
$pageLede = 'تنظیم حداقل برداشت، بررسی درخواست‌ها و تاریخچه تسویه حساب.';
$activeNav = 'wallet_withdraw';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;gap:4px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:4px;flex-wrap:wrap;margin-bottom:18px" class="fade-up">
  <a href="wallet_withdraw.php?tab=settings" class="btn btn-sm <?= $tab === 'settings' ? 'btn-primary' : 'btn-ghost' ?>">تنظیمات</a>
  <a href="wallet_withdraw.php?tab=pending" class="btn btn-sm <?= $tab === 'pending' ? 'btn-primary' : 'btn-ghost' ?>">
    درخواست‌ها
    <?php if ($pendingCount > 0): ?>
      <span class="tag tag-warn" style="margin-right:6px;font-size:.7rem"><?= number_format($pendingCount) ?></span>
    <?php endif; ?>
  </a>
  <a href="wallet_withdraw.php?tab=history" class="btn btn-sm <?= $tab === 'history' ? 'btn-primary' : 'btn-ghost' ?>">تاریخچه</a>
</div>

<?php if ($tab === 'settings'): ?>
<div class="card fade-up">
  <div class="card-head">
    <div>
      <div class="card-title">تنظیمات برداشت</div>
      <div class="card-subtitle">حداقل مبلغ و متن‌های ربات</div>
    </div>
  </div>
  <form method="POST" class="card-body" style="display:flex;flex-direction:column;gap:16px;max-width:720px">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="tab" value="settings">
    <div class="field">
      <label>حداقل برداشت (تومان)</label>
      <input type="number" name="min_amount" class="input" min="0" step="1" required value="<?= (int) $minAmount ?>">
    </div>
    <div class="field">
      <label>متن پیام دکمه تسویه حساب</label>
      <textarea name="prompt_text" class="input" rows="4" required><?= htmlspecialchars($promptText) ?></textarea>
      <div style="font-size:.75rem;color:var(--mute);margin-top:6px">زمانی که کاربر روی درخواست برداشت کلیک می‌کند این متن ارسال می‌شود.</div>
    </div>
    <div class="field">
      <label>متن پیام بعد از ثبت موفق درخواست</label>
      <textarea name="success_text" class="input" rows="3" required><?= htmlspecialchars($successText) ?></textarea>
    </div>
    <div>
      <button type="submit" class="btn btn-primary"><?= icon('check', 14) ?> ذخیره تنظیمات</button>
    </div>
  </form>
</div>

<?php elseif ($tab === 'pending'): ?>
<div class="card fade-up">
  <div class="card-head">
    <div>
      <div class="card-title">درخواست‌های در انتظار</div>
      <div class="card-subtitle"><?= number_format($total) ?> مورد</div>
    </div>
  </div>
  <div class="tbl-wrap">
    <table class="tbl-lg">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>مبلغ</th>
          <th>شماره کارت</th>
          <th>صاحب حساب</th>
          <th>موجودی فعلی</th>
          <th>زمان</th>
          <th>عملیات</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($list === []): ?>
          <tr>
            <td colspan="8">
              <div class="empty">
                <div class="empty-mark">—</div>
                <p>درخواست در انتظاری نیست</p>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($list as $row):
              $id = (int) $row['id'];
              $uid = (string) $row['id_user'];
              $u = $users[$uid] ?? [];
              $uname = trim((string) ($u['username'] ?? ''));
              if ($uname === 'none') {
                  $uname = '';
              }
              $balanceNow = isset($u['Balance']) ? (int) $u['Balance'] : null;
              $over = $balanceNow !== null && (int) $row['amount'] > $balanceNow;
              ?>
            <tr>
              <td style="color:var(--text-dim)"><?= $id ?></td>
              <td>
                <a href="user.php?id=<?= htmlspecialchars($uid) ?>" class="cell-mono" style="color:var(--accent)"><?= htmlspecialchars($uid) ?></a>
                <?php if ($uname !== ''): ?><div style="font-size:.75rem;color:var(--mute)">@<?= htmlspecialchars($uname) ?></div><?php endif; ?>
              </td>
              <td class="cell-strong cell-num"><?= number_format((int) $row['amount']) ?> <span style="color:var(--text-dim);font-weight:400;font-size:.72rem">ت</span></td>
              <td class="cell-mono" dir="ltr"><?= htmlspecialchars(withdraw_format_card((string) $row['card_number'])) ?></td>
              <td><?= htmlspecialchars((string) $row['card_holder']) ?></td>
              <td class="cell-num" style="<?= $over ? 'color:var(--danger,#ef4444)' : '' ?>">
                <?= $balanceNow === null ? '—' : number_format($balanceNow) ?>
              </td>
              <td style="font-size:.78rem;color:var(--text-dim);white-space:nowrap"><?= htmlspecialchars(wallet_withdraw_when((int) $row['created_at'])) ?></td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                  <button type="button" class="btn btn-primary btn-sm" onclick="openApproveModal(<?= $id ?>)">تأیید</button>
                  <button type="button" class="btn btn-no btn-sm" onclick="openRejectModal(<?= $id ?>)">رد</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-veil" id="wdApproveModal">
  <div class="modal" style="max-width:460px">
    <div class="modal-head">
      <h3>تأیید برداشت</h3>
      <button type="button" class="modal-x" onclick="closeModal('wdApproveModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="id" id="wdApproveId" value="">
        <p style="font-size:.85rem;color:var(--mute);margin:0 0 12px">عکس رسید پرداخت الزامی است. پس از تأیید، مبلغ از کیف پول کاربر کسر می‌شود.</p>
        <div class="field">
          <label>عکس رسید *</label>
          <input type="file" name="receipt" class="input" accept="image/*" required>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary">تأیید و ارسال رسید</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('wdApproveModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="wdRejectModal">
  <div class="modal" style="max-width:460px">
    <div class="modal-head">
      <h3>رد درخواست</h3>
      <button type="button" class="modal-x" onclick="closeModal('wdRejectModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="id" id="wdRejectId" value="">
        <div class="field">
          <label>دلیل رد (برای کاربر ارسال می‌شود) *</label>
          <textarea name="reason" class="input" rows="3" required placeholder="دلیل رد را بنویسید"></textarea>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-no">رد کردن</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('wdRejectModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>
<script>
function openApproveModal(id) {
  document.getElementById('wdApproveId').value = id;
  openModal('wdApproveModal');
}
function openRejectModal(id) {
  document.getElementById('wdRejectId').value = id;
  openModal('wdRejectModal');
}
</script>

<?php else: ?>
<div class="card fade-up">
  <div class="card-head">
    <div>
      <div class="card-title">تاریخچه برداشت‌های پرداخت‌شده</div>
      <div class="card-subtitle"><?= number_format($total) ?> مورد</div>
    </div>
  </div>
  <div class="tbl-wrap">
    <table class="tbl-lg">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>مبلغ</th>
          <th>شماره کارت</th>
          <th>صاحب حساب</th>
          <th>رسید</th>
          <th>هزینه مالی</th>
          <th>زمان</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($list === []): ?>
          <tr>
            <td colspan="8">
              <div class="empty">
                <div class="empty-mark">—</div>
                <p>تاریخچه‌ای ثبت نشده</p>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($list as $row):
              $id = (int) $row['id'];
              $uid = (string) $row['id_user'];
              $u = $users[$uid] ?? [];
              $uname = trim((string) ($u['username'] ?? ''));
              if ($uname === 'none') {
                  $uname = '';
              }
              $oid = trim((string) ($row['payment_order_id'] ?? ''));
              $hasReceipt = trim((string) ($row['receipt_path'] ?? '')) !== '' || trim((string) ($row['receipt_file_id'] ?? '')) !== '';
              ?>
            <tr>
              <td style="color:var(--text-dim)"><?= $id ?></td>
              <td>
                <a href="user.php?id=<?= htmlspecialchars($uid) ?>" class="cell-mono" style="color:var(--accent)"><?= htmlspecialchars($uid) ?></a>
                <?php if ($uname !== ''): ?><div style="font-size:.75rem;color:var(--mute)">@<?= htmlspecialchars($uname) ?></div><?php endif; ?>
              </td>
              <td class="cell-strong cell-num"><?= number_format((int) $row['amount']) ?> <span style="color:var(--text-dim);font-weight:400;font-size:.72rem">ت</span></td>
              <td class="cell-mono" dir="ltr"><?= htmlspecialchars(withdraw_format_card((string) $row['card_number'])) ?></td>
              <td><?= htmlspecialchars((string) $row['card_holder']) ?></td>
              <td>
                <?php if ($hasReceipt): ?>
                  <a href="withdraw_receipt.php?id=<?= $id ?>" target="_blank" class="btn btn-ghost btn-sm">مشاهده رسید</a>
                <?php else: ?>
                  <span style="color:var(--text-dim)">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($oid !== ''): ?>
                  <a href="payment.php?tab=costs&q=<?= urlencode($oid) ?>" class="cell-mono" style="color:var(--accent);font-size:.78rem"><?= htmlspecialchars($oid) ?></a>
                <?php else: ?>
                  <span style="color:var(--text-dim)">—</span>
                <?php endif; ?>
              </td>
              <td style="font-size:.78rem;color:var(--text-dim);white-space:nowrap"><?= htmlspecialchars(wallet_withdraw_when((int) ($row['updated_at'] ?: $row['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (($tab === 'pending' || $tab === 'history') && $totalPages > 1): ?>
<div class="tbl-foot fade-up">
  <span><?= number_format($total) ?> رکورد · صفحه <?= $page ?> از <?= $totalPages ?></span>
  <div class="pager">
    <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="wallet_withdraw.php?tab=<?= htmlspecialchars($tab) ?>&page=<?= max(1, $page - 1) ?>">‹</a>
    <?php for ($p2 = max(1, $page - 2); $p2 <= min($totalPages, $page + 2); $p2++): ?>
      <a class="<?= $p2 === $page ? 'cur' : '' ?>" href="wallet_withdraw.php?tab=<?= htmlspecialchars($tab) ?>&page=<?= $p2 ?>"><?= $p2 ?></a>
    <?php endfor; ?>
    <a class="<?= $page >= $totalPages ? 'dis' : '' ?>" href="wallet_withdraw.php?tab=<?= htmlspecialchars($tab) ?>&page=<?= min($totalPages, $page + 1) ?>">›</a>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
