<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/panels_lib.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_once __DIR__ . '/inc/ads_lib.php';
require_administrator();
$pdo = panel_ensure_pdo();
ads_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    csrf_check_post();
    $editId = (int) ($_POST['edit_id'] ?? 0);
    try {
        $saved = ads_lib_save($pdo, [
            'name' => $_POST['name'] ?? '',
            'join_count' => $_POST['join_count'] ?? '0',
            'amount' => $_POST['amount'] ?? '0',
            'started_at' => $_POST['started_at'] ?? '',
        ], $editId ?: null);
        $link = !empty($saved['code']) ? ads_build_link((string) $saved['code']) : '';
        flash('success', $editId ? 'تبلیغ ویرایش شد.' : ('تبلیغ ثبت شد.' . ($link !== '' ? ' لینک: ' . $link : '')));
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('ads save: ' . $e->getMessage());
        flash('error', 'ذخیره تبلیغ ناموفق بود.');
    }
    header('Location: ads.php');
    exit;
}

if (isset($_GET['delete'])) {
    csrf_check_get();
    try {
        ads_lib_delete($pdo, (int) $_GET['delete']);
        flash('success', 'تبلیغ حذف شد.');
    } catch (Throwable $e) {
        error_log('ads delete: ' . $e->getMessage());
        flash('error', 'حذف تبلیغ ناموفق بود.');
    }
    header('Location: ads.php');
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$list = ['rows' => [], 'total' => 0];
try {
    $list = ads_lib_list($pdo, $search, $perPage, ($page - 1) * $perPage);
} catch (Throwable $e) {
    error_log('ads list: ' . $e->getMessage());
}
$total = (int) $list['total'];
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$pageTitle = 'پنل تبلیغات';
$pageLede = 'ثبت تبلیغ‌کننده، لینک یکتا بدون آیدی تلگرام، و شمارش جوین.';
$activeNav = 'referral';
$referralTab = 'ads';
include __DIR__ . '/inc/layout_head.php';
include __DIR__ . '/inc/referral_nav.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="font-size:.85rem;color:var(--mute)"><?= number_format($total) ?> تبلیغ‌کننده</div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 14) ?> افزودن تبلیغ</button>
</div>

<div class="card fade-up" id="list">
  <div class="toolbar" style="flex-wrap:wrap;gap:10px">
    <div class="toolbar-title">تبلیغ‌کننده‌ها <small>(<?= number_format($total) ?>)</small></div>
    <form method="GET" class="toolbar-end">
      <div class="search-box" style="min-width:240px">
        <?= icon('search', 15) ?>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="نام یا کد لینک..." autocomplete="off">
        <button type="submit" class="search-btn">جستجو</button>
      </div>
      <?php if ($search !== ''): ?>
        <a href="ads.php" class="btn-link" style="font-size:.78rem">پاک کردن</a>
      <?php endif; ?>
    </form>
  </div>
  <?php if (empty($list['rows'])): ?>
    <div class="empty" style="padding:48px 20px">
      <p><?= $search !== '' ? 'نتیجه‌ای یافت نشد.' : 'هنوز تبلیغی ثبت نشده است.' ?></p>
      <?php if ($search === ''): ?>
        <button class="btn btn-primary" style="margin-top:14px" onclick="openModal('addModal')"><?= icon('plus', 14) ?> افزودن اولین تبلیغ</button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl-lg">
        <thead>
          <tr>
            <th>نام</th>
            <th>جوین</th>
            <th>مبلغ تبلیغ</th>
            <th>تاریخ شروع</th>
            <th>لینک</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list['rows'] as $row):
            $link = ads_build_link((string) $row['code']);
            ?>
            <tr>
              <td><?= htmlspecialchars((string) $row['name']) ?></td>
              <td><?= number_format((int) $row['join_count']) ?></td>
              <td class="cn"><?= number_format((int) $row['amount']) ?> <span class="cf">ت</span></td>
              <td class="cf"><?= htmlspecialchars((string) $row['started_at']) ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:6px;max-width:280px">
                  <code class="cm" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;direction:ltr;text-align:left"><?= htmlspecialchars($link) ?></code>
                  <button type="button" class="btn btn-ghost btn-sm" onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode($link), ENT_QUOTES) ?>).then(()=>{this.textContent='کپی شد';setTimeout(()=>this.textContent='کپی',1200)})">کپی</button>
                </div>
              </td>
              <td>
                <button type="button" class="btn btn-ghost btn-sm" onclick='openEditAd(<?= htmlspecialchars(json_encode([
                  'id' => (int) $row['id'],
                  'name' => (string) $row['name'],
                  'join_count' => (int) $row['join_count'],
                  'amount' => (int) $row['amount'],
                  'started_at' => ads_lib_date_input_value((string) $row['started_at']),
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'>ویرایش</button>
                <a href="ads.php?delete=<?= (int) $row['id'] ?>&_csrf=<?= csrf_token() ?>" class="btn btn-no btn-sm" data-confirm="حذف تبلیغ «<?= htmlspecialchars((string) $row['name']) ?>»؟">حذف</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
      <div class="tbl-foot">
        <span><?= number_format($total) ?> مورد · صفحه <?= $page ?> از <?= $totalPages ?></span>
        <div class="pager">
          <?php $qs = fn($p) => 'ads.php?q=' . urlencode($search) . '&page=' . $p; ?>
          <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
          <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
            <a class="<?= $p === $page ? 'cur' : '' ?>" href="<?= $qs($p) ?>"><?= $p ?></a>
          <?php endfor; ?>
          <a class="<?= $page >= $totalPages ? 'dis' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="modal-veil" id="addModal">
  <div class="modal">
    <div class="modal-head">
      <h3>افزودن تبلیغ</h3>
      <button type="button" class="modal-x" onclick="closeModal('addModal')">✕</button>
    </div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save">
      <div class="modal-body">
        <p class="cf" style="margin-bottom:12px">پس از ذخیره، یک لینک تلگرامی یکتا بدون آیدی شخص ساخته می‌شود.</p>
        <label class="lbl">نام تبلیغ‌کننده</label>
        <input class="inp" name="name" required>
        <label class="lbl">تعداد جوین</label>
        <input class="inp" type="number" name="join_count" min="0" value="0" required>
        <label class="lbl">مبلغ تبلیغات (تومان)</label>
        <input class="inp" type="number" name="amount" min="0" value="0" required>
        <label class="lbl">تاریخ شروع تبلیغ</label>
        <input class="inp" type="date" name="started_at" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">انصراف</button>
        <button type="submit" class="btn btn-primary">ذخیره و ساخت لینک</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="editModal">
  <div class="modal">
    <div class="modal-head">
      <h3>ویرایش تبلیغ</h3>
      <button type="button" class="modal-x" onclick="closeModal('editModal')">✕</button>
    </div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="edit_id" id="edit_id">
      <div class="modal-body">
        <label class="lbl">نام تبلیغ‌کننده</label>
        <input class="inp" name="name" id="edit_name" required>
        <label class="lbl">تعداد جوین</label>
        <input class="inp" type="number" name="join_count" id="edit_join_count" min="0" required>
        <label class="lbl">مبلغ تبلیغات (تومان)</label>
        <input class="inp" type="number" name="amount" id="edit_amount" min="0" required>
        <label class="lbl">تاریخ شروع تبلیغ</label>
        <input class="inp" type="date" name="started_at" id="edit_started_at" required>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">انصراف</button>
        <button type="submit" class="btn btn-primary">ذخیره</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditAd(row) {
  document.getElementById('edit_id').value = row.id;
  document.getElementById('edit_name').value = row.name || '';
  document.getElementById('edit_join_count').value = row.join_count || 0;
  document.getElementById('edit_amount').value = row.amount || 0;
  document.getElementById('edit_started_at').value = row.started_at || '';
  openModal('editModal');
}
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
