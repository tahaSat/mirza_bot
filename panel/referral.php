<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/referral_lib.php';
require_once __DIR__ . '/inc/panels_lib.php';
require_auth();
require_administrator();
$pdo = panel_ensure_pdo();
referral_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_master') {
    csrf_check_post();
    $new = referral_lib_toggle_master($pdo);
    flash('success', $new === 'onreferral' ? 'سیستم دعوت فعال شد.' : 'سیستم دعوت غیرفعال شد.');
    header('Location: referral.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    csrf_check_post();
    try {
        $id = (int) ($_POST['edit_id'] ?? 0);
        referral_lib_save_campaign($pdo, [
            'code' => $_POST['code'] ?? '',
            'title' => $_POST['title'] ?? '',
            'description' => $_POST['description'] ?? '',
            'code_product' => $_POST['code_product'] ?? '',
            'required_invites' => $_POST['required_invites'] ?? 1,
            'status' => $_POST['status'] ?? 'inactive',
            'new_users_only' => isset($_POST['new_users_only']) ? 1 : 0,
        ], $id ?: null);
        flash('success', $id ? 'کمپین ویرایش شد.' : 'کمپین ایجاد شد.');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header('Location: referral.php');
    exit;
}

if (isset($_GET['toggle'])) {
    csrf_check_get();
    try {
        referral_lib_toggle_status($pdo, (int) $_GET['toggle']);
        flash('success', 'وضعیت کمپین تغییر کرد.');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header('Location: referral.php');
    exit;
}

if (isset($_GET['delete'])) {
    csrf_check_get();
    db_query($pdo, "DELETE FROM referral_campaign WHERE id = ?", [(int) $_GET['delete']]);
    flash('success', 'کمپین حذف شد.');
    header('Location: referral.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grant_reward') {
    csrf_check_post();
    $grantCampaignId = (int) ($_POST['campaign_id'] ?? 0);
    $grantUserId = trim((string) ($_POST['user_id'] ?? ''));
    try {
        $result = referral_lib_manual_grant($pdo, $grantCampaignId, $grantUserId);
    } catch (Throwable $e) {
        error_log('grant_reward: ' . $e->getMessage());
        $result = ['ok' => false, 'msg' => 'خطای سیستمی: ' . $e->getMessage()];
    }
    flash($result['ok'] ? 'success' : 'error', $result['msg']);
    header('Location: referral.php?view=' . $grantCampaignId . '&scan=1#pending');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grant_last_product') {
    csrf_check_post();
    $grantCampaignId = (int) ($_POST['campaign_id'] ?? 0);
    $grantUserId = trim((string) ($_POST['user_id'] ?? ''));
    try {
        $result = referral_lib_grant_last_product($pdo, $grantCampaignId, $grantUserId);
    } catch (Throwable $e) {
        error_log('grant_last_product: ' . $e->getMessage());
        $result = ['ok' => false, 'msg' => 'خطای سیستمی: ' . $e->getMessage()];
    }
    flash($result['ok'] ? 'success' : 'error', $result['msg']);
    header('Location: referral.php?view=' . $grantCampaignId . '&scan=1#pending');
    exit;
}

$campaigns = referral_lib_list_campaigns($pdo);
$products = referral_lib_products($pdo);
$master_status = referral_lib_master_status($pdo);
$view_id = (int) ($_GET['view'] ?? 0);
$invite_search = trim($_GET['q'] ?? '');
$invite_page = max(1, (int) ($_GET['page'] ?? 1));
$invite_per_page = 25;
$do_scan = isset($_GET['scan']);
$view_campaign = $view_id ? referral_lib_get_campaign($pdo, $view_id) : null;
$recent_invites = [];
$invite_total = 0;
$invite_total_pages = 1;
$view_stats = ['invites' => 0, 'referrers' => 0, 'rewards' => 0];
$pending_rewards = [];
if ($view_campaign) {
    $view_stats = referral_lib_campaign_stats($pdo, $view_id);
    $invite_result = referral_lib_list_invites(
        $pdo,
        $view_id,
        $invite_search,
        $invite_per_page,
        ($invite_page - 1) * $invite_per_page
    );
    $recent_invites = $invite_result['rows'];
    $invite_total = (int) $invite_result['total'];
    $invite_total_pages = max(1, (int) ceil($invite_total / $invite_per_page));
    if ($do_scan) {
        $pending_rewards = referral_lib_pending_rewards($pdo, $view_id);
    }
}

$pageTitle = 'کمپین‌های دعوت';
$pageLede = 'مدیریت لینک دعوت، تعداد دعوت موردنیاز و جایزه سرویس.';
$activeNav = 'referral';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px" class="fade-up">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span class="tag <?= $master_status === 'onreferral' ? 'tag-ok' : 'tag-no' ?>">
      <?= $master_status === 'onreferral' ? 'سیستم فعال' : 'سیستم غیرفعال' ?>
    </span>
    <form method="post" style="margin:0">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="toggle_master">
      <button type="submit" class="btn btn-ghost btn-sm">
        <?= $master_status === 'onreferral' ? 'غیرفعال کردن' : 'فعال کردن' ?> سیستم
      </button>
    </form>
  </div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 14) ?> کمپین جدید</button>
</div>

<div class="card fade-up d1">
  <?php if (empty($campaigns)): ?>
    <div class="empty" style="padding:60px 20px">
      <p>هنوز کمپین دعوتی ثبت نشده است.</p>
      <button class="btn btn-primary" style="margin-top:14px" onclick="openModal('addModal')"><?= icon('plus', 14) ?> ایجاد اولین کمپین</button>
    </div>
  <?php else: ?>
    <div class="toolbar">
      <div class="toolbar-title">کمپین‌ها <small>(<?= count($campaigns) ?>)</small></div>
    </div>
    <div class="tbl-wrap">
      <table class="tbl-lg">
        <thead>
          <tr>
            <th>#</th>
            <th>عنوان</th>
            <th>محصول</th>
            <th>دعوت</th>
            <th>آمار</th>
            <th>وضعیت</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($campaigns as $c):
            $product = db_fetch($pdo, "SELECT name_product FROM product WHERE code_product = ?", [$c['code_product']]);
            ?>
            <tr>
              <td class="cn"><?= (int) $c['id'] ?></td>
              <td><?= htmlspecialchars($c['title']) ?></td>
              <td class="cf"><?= htmlspecialchars($product['name_product'] ?? $c['code_product']) ?></td>
              <td class="cn"><?= (int) $c['required_invites'] ?></td>
              <td class="cf">
                <?= (int) ($c['stats']['invites'] ?? 0) ?> دعوت ·
                <?= (int) ($c['stats']['rewards'] ?? 0) ?> جایزه
              </td>
              <td>
                <span class="tag <?= ($c['status'] ?? '') === 'active' ? 'tag-ok' : 'tag-warn' ?>">
                  <?= ($c['status'] ?? '') === 'active' ? 'فعال' : 'غیرفعال' ?>
                </span>
              </td>
              <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                  <a href="referral.php?view=<?= (int) $c['id'] ?>#invites" class="btn btn-ghost btn-sm">جزئیات</a>
                  <button class="btn btn-ghost btn-sm" onclick="openEditModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)">ویرایش</button>
                  <a href="referral.php?toggle=<?= (int) $c['id'] ?>&_csrf=<?= csrf_token() ?>" class="btn btn-ghost btn-sm">تغییر وضعیت</a>
                  <a href="referral.php?delete=<?= (int) $c['id'] ?>&_csrf=<?= csrf_token() ?>" class="btn btn-no btn-sm" data-confirm="حذف کمپین «<?= htmlspecialchars($c['title']) ?>»؟">حذف</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($view_campaign): ?>
  <div class="card fade-up d2" style="margin-top:18px" id="invites">
    <div class="toolbar" style="flex-wrap:wrap;gap:10px">
      <div class="toolbar-title">دعوت‌ها — <?= htmlspecialchars($view_campaign['title']) ?></div>
      <div class="toolbar-end" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span class="tag"><?= number_format((int) $view_stats['invites']) ?> جوین</span>
        <span class="tag"><?= number_format((int) $view_stats['referrers']) ?> معرف</span>
        <span class="tag tag-ok"><?= number_format((int) $view_stats['rewards']) ?> جایزه</span>
        <span class="tag tag-warn"><?= (int) $view_campaign['required_invites'] ?> دعوت لازم</span>
        <a href="referral.php?view=<?= (int) $view_id ?>&scan=1#pending" class="btn btn-primary btn-sm">
          <?= icon('search', 14) ?> اسکن واجدین بدون جایزه
        </a>
      </div>
    </div>
    <div class="toolbar" style="border-top:1px solid var(--bd,rgba(0,0,0,.06));padding-top:12px">
      <div class="toolbar-title" style="font-size:.9rem">لیست دعوت‌ها <small>(<?= number_format($invite_total) ?>)</small></div>
      <form method="GET" class="toolbar-end">
        <input type="hidden" name="view" value="<?= (int) $view_id ?>">
        <?php if ($do_scan): ?><input type="hidden" name="scan" value="1"><?php endif; ?>
        <div class="search-box" style="min-width:240px">
          <?= icon('search', 15) ?>
          <input type="text" name="q" value="<?= htmlspecialchars($invite_search) ?>" placeholder="آیدی یا یوزرنیم..." autocomplete="off">
          <button type="submit" class="search-btn">جستجو</button>
        </div>
        <?php if ($invite_search !== ''): ?>
          <a href="referral.php?view=<?= (int) $view_id ?><?= $do_scan ? '&scan=1' : '' ?>#invites" class="btn-link" style="font-size:.78rem">پاک کردن</a>
        <?php endif; ?>
      </form>
    </div>
    <?php if (empty($recent_invites)): ?>
      <div class="empty" style="padding:36px">
        <p><?= $invite_search !== '' ? 'نتیجه‌ای یافت نشد.' : 'هنوز دعوتی ثبت نشده.' ?></p>
      </div>
    <?php else: ?>
      <div class="tbl-wrap">
        <table class="tbl-lg">
          <thead>
            <tr>
              <th>معرف</th>
              <th>آیدی تلگرام معرف</th>
              <th>دعوت‌شده</th>
              <th>آیدی تلگرام دعوت‌شده</th>
              <th>زمان</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_invites as $inv): ?>
              <tr>
                <td><?= !empty($inv['referrer_username']) ? '@' . htmlspecialchars($inv['referrer_username']) : '—' ?></td>
                <td class="cm"><?= htmlspecialchars((string) $inv['referrer_id']) ?></td>
                <td><?= !empty($inv['invited_username']) ? '@' . htmlspecialchars($inv['invited_username']) : '—' ?></td>
                <td class="cm"><?= htmlspecialchars((string) $inv['invited_user_id']) ?></td>
                <td class="cf"><?= htmlspecialchars($inv['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($invite_total_pages > 1): ?>
        <div class="tbl-foot">
          <span><?= number_format($invite_total) ?> دعوت · صفحه <?= $invite_page ?> از <?= $invite_total_pages ?></span>
          <div class="pager">
            <?php
            $invite_qs = fn($p) => 'referral.php?view=' . (int) $view_id
                . '&q=' . urlencode($invite_search)
                . ($do_scan ? '&scan=1' : '')
                . '&page=' . $p
                . '#invites';
            ?>
            <a class="<?= $invite_page <= 1 ? 'dis' : '' ?>" href="<?= $invite_qs(max(1, $invite_page - 1)) ?>">‹</a>
            <?php for ($p = max(1, $invite_page - 2); $p <= min($invite_total_pages, $invite_page + 2); $p++): ?>
              <a class="<?= $p === $invite_page ? 'cur' : '' ?>" href="<?= $invite_qs($p) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="<?= $invite_page >= $invite_total_pages ? 'dis' : '' ?>" href="<?= $invite_qs(min($invite_total_pages, $invite_page + 1)) ?>">›</a>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($do_scan): ?>
    <div class="card fade-up d3" style="margin-top:18px" id="pending">
      <div class="toolbar">
        <div class="toolbar-title">
          واجدین بدون جایزه
          <small>(<?= count($pending_rewards) ?> نفر · حداقل <?= (int) $view_campaign['required_invites'] ?> دعوت)</small>
        </div>
        <a href="referral.php?view=<?= (int) $view_id ?>#invites" class="btn btn-ghost btn-sm">بستن اسکن</a>
      </div>
      <?php if (empty($pending_rewards)): ?>
        <div class="empty" style="padding:36px">
          <p>کسی با دعوت کافی و بدون جایزه پیدا نشد.</p>
        </div>
      <?php else: ?>
        <p class="cf" style="padding:0 16px 12px;margin:0">
          این کاربران به حد نصاب رسیده‌اند ولی رکورد جایزه ندارند (احتمالاً ساخت ساب ناموفق بوده). با تأیید، سرویس ساخته و در تلگرام ارسال می‌شود.
        </p>
        <div class="tbl-wrap">
          <table class="tbl-lg">
            <thead>
              <tr>
                <th>معرف</th>
                <th>آیدی تلگرام</th>
                <th>تعداد دعوت</th>
                <th>لازم</th>
                <th>عملیات</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pending_rewards as $row): ?>
                <tr>
                  <td><?= !empty($row['username']) ? '@' . htmlspecialchars((string) $row['username']) : '—' ?></td>
                  <td class="cm"><?= htmlspecialchars((string) $row['referrer_id']) ?></td>
                  <td class="cn"><?= (int) $row['invite_count'] ?></td>
                  <td class="cn"><?= (int) $view_campaign['required_invites'] ?></td>
                  <td>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                      <form method="post" style="margin:0">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="grant_reward">
                        <input type="hidden" name="campaign_id" value="<?= (int) $view_id ?>">
                        <input type="hidden" name="user_id" value="<?= htmlspecialchars((string) $row['referrer_id']) ?>">
                        <button
                          type="submit"
                          class="btn btn-primary btn-sm"
                          onclick="return confirm('ارسال جایزه برای این کاربر؟');"
                        ><?= icon('check', 13) ?> تأیید و ارسال جایزه</button>
                      </form>
                      <form method="post" style="margin:0">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="grant_last_product">
                        <input type="hidden" name="campaign_id" value="<?= (int) $view_id ?>">
                        <input type="hidden" name="user_id" value="<?= htmlspecialchars((string) $row['referrer_id']) ?>">
                        <button
                          type="submit"
                          class="btn btn-ghost btn-sm"
                          onclick="return confirm('آخرین محصول این کاربر به‌عنوان جایزه ثبت شود و از لیست حذف گردد؟');"
                        >انتخاب آخرین محصول به‌عنوان جایزه</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="modal-veil" id="addModal">
  <div class="modal">
    <div class="modal-head">
      <h3>کمپین دعوت جدید</h3>
      <button type="button" class="modal-x" onclick="closeModal('addModal')">✕</button>
    </div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save">
      <div class="modal-body">
        <p class="cf" style="margin-bottom:12px">لینک هر کاربر با <b>آیدی عددی تلگرام</b> او ساخته می‌شود (خودکار).</p>
        <label class="lbl">عنوان</label>
        <input class="inp" name="title" placeholder="کمپین تابستان">
        <label class="lbl">توضیحات</label>
        <textarea class="inp" name="description" rows="3" placeholder="متن نمایش به کاربر"></textarea>
        <label class="lbl">محصول جایزه</label>
        <select class="inp" name="code_product" required>
          <option value="">انتخاب محصول</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= htmlspecialchars($p['code_product']) ?>"><?= htmlspecialchars($p['name_product']) ?> (<?= htmlspecialchars($p['Location']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <label class="lbl">تعداد دعوت موردنیاز</label>
        <input class="inp" type="number" name="required_invites" min="1" value="3" required>
        <label class="lbl"><input type="checkbox" name="new_users_only" value="1" checked> فقط کاربران جدید</label>
        <label class="lbl">وضعیت</label>
        <select class="inp" name="status">
          <option value="active">فعال</option>
          <option value="inactive">غیرفعال</option>
        </select>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">انصراف</button>
        <button type="submit" class="btn btn-primary">ذخیره</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="editModal">
  <div class="modal">
    <div class="modal-head">
      <h3>ویرایش کمپین</h3>
      <button type="button" class="modal-x" onclick="closeModal('editModal')">✕</button>
    </div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="edit_id" id="edit_id">
      <div class="modal-body">
        <label class="lbl">شناسه کمپین</label>
        <input class="inp" id="edit_id_display" readonly disabled>
        <label class="lbl">عنوان</label>
        <input class="inp" name="title" id="edit_title">
        <label class="lbl">توضیحات</label>
        <textarea class="inp" name="description" id="edit_description" rows="3"></textarea>
        <label class="lbl">محصول جایزه</label>
        <select class="inp" name="code_product" id="edit_code_product" required>
          <?php foreach ($products as $p): ?>
            <option value="<?= htmlspecialchars($p['code_product']) ?>"><?= htmlspecialchars($p['name_product']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="lbl">تعداد دعوت</label>
        <input class="inp" type="number" name="required_invites" id="edit_required_invites" min="1" required>
        <label class="lbl"><input type="checkbox" name="new_users_only" id="edit_new_users_only" value="1"> فقط کاربران جدید</label>
        <label class="lbl">وضعیت</label>
        <select class="inp" name="status" id="edit_status">
          <option value="active">فعال</option>
          <option value="inactive">غیرفعال</option>
        </select>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">انصراف</button>
        <button type="submit" class="btn btn-primary">ذخیره</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditModal(c) {
  document.getElementById('edit_id').value = c.id;
  document.getElementById('edit_id_display').value = c.id;
  document.getElementById('edit_title').value = c.title || '';
  document.getElementById('edit_description').value = c.description || '';
  document.getElementById('edit_code_product').value = c.code_product || '';
  document.getElementById('edit_required_invites').value = c.required_invites || 1;
  document.getElementById('edit_new_users_only').checked = String(c.new_users_only) === '1';
  document.getElementById('edit_status').value = c.status || 'inactive';
  openModal('editModal');
}
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
