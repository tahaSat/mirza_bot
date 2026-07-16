<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/panels_lib.php';
require_administrator();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    flash('error', 'پنل مشخص نشده است.');
    header('Location: panels.php');
    exit;
}

$panel = db_fetch($pdo, "SELECT * FROM marzban_panel WHERE id = ?", [$id]);
if (!$panel) {
    flash('error', 'پنل یافت نشد.');
    header('Location: panels.php');
    exit;
}

$ptype = $panel['type'] ?? 'marzban';
$features = panel_features_for_type($ptype);
$isPasarguard = panel_is_pasarguard($panel);
$pasarguardGroupIds = panel_format_pasarguard_group_ids($panel['inbounds'] ?? null);
$tab = $_GET['tab'] ?? 'connection';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    csrf_check_post();
    $newName = trim($_POST['name_panel'] ?? '');
    if ($newName === '') {
        flash('error', 'نام پنل الزامی است.');
        header('Location: panel.php?id=' . $id . '&tab=' . urlencode($tab));
        exit;
    }
    if (panel_name_exists($pdo, $newName, $id)) {
        flash('error', 'نام پنل تکراری است.');
        header('Location: panel.php?id=' . $id);
        exit;
    }
    $url = array_key_exists('url_panel', $_POST) ? trim($_POST['url_panel']) : ($panel['url_panel'] ?? '');
    if (array_key_exists('url_panel', $_POST) && $url !== '' && $url !== 'null' && !filter_var($url, FILTER_VALIDATE_URL)) {
        flash('error', 'آدرس پنل معتبر نیست.');
        header('Location: panel.php?id=' . $id);
        exit;
    }
    $oldName = $panel['name_panel'];
    if ($oldName !== $newName) {
        panel_rename_cascade($pdo, $oldName, $newName);
    }

    $hideJson = array_key_exists('hide_users', $_POST)
        ? panel_format_hide_users(preg_split('/[\s,]+/', trim($_POST['hide_users']), -1, PREG_SPLIT_NO_EMPTY) ?: [])
        : ($panel['hide_user'] ?? '[]');

    $tabSaving = $_POST['tab'] ?? 'connection';
    $toggleInForm = array_flip(panel_toggle_keys_for_tab($tabSaving));
    $toggle = fn(string $postKey, string $dbField, string $onVal, string $offVal) =>
        panel_toggle_field($panel, $postKey, $dbField, $onVal, $offVal, isset($toggleInForm[$postKey]));

    if (array_key_exists('pasarguard_group_ids', $_POST)) {
        $parsedGroups = panel_parse_pasarguard_group_ids((string) $_POST['pasarguard_group_ids']);
        if ($parsedGroups === false) {
            flash('error', 'شناسه گروه پاسارگارد نامعتبر است. فقط اعداد با کاما مجاز است (مثال: 1 یا 1,2).');
            header('Location: panel.php?id=' . $id . '&tab=connection');
            exit;
        }
    } else {
        $parsedGroups = null;
    }

    $data = [
        'name_panel' => $newName,
        'url_panel' => array_key_exists('url_panel', $_POST) ? $url : ($panel['url_panel'] ?? ''),
        'username_panel' => array_key_exists('username_panel', $_POST) ? trim($_POST['username_panel']) : ($panel['username_panel'] ?? ''),
        'password_panel' => array_key_exists('password_panel', $_POST) ? trim($_POST['password_panel']) : ($panel['password_panel'] ?? ''),
        'linksubx' => array_key_exists('linksubx', $_POST) ? trim($_POST['linksubx']) : ($panel['linksubx'] ?? ''),
        'secret_code' => array_key_exists('secret_code', $_POST) ? trim($_POST['secret_code']) : ($panel['secret_code'] ?? ''),
        'inboundid' => array_key_exists('inboundid', $_POST) ? trim($_POST['inboundid']) : ($panel['inboundid'] ?? ''),
        'namecustom' => array_key_exists('namecustom', $_POST) ? trim($_POST['namecustom']) : ($panel['namecustom'] ?? ''),
        'agent' => array_key_exists('agent', $_POST) ? trim($_POST['agent']) : ($panel['agent'] ?? 'all'),
        'limit_panel' => array_key_exists('limit_panel', $_POST) ? trim($_POST['limit_panel']) : ($panel['limit_panel'] ?? 'unlimted'),
        'MethodUsername' => $_POST['MethodUsername'] ?? $panel['MethodUsername'],
        'Methodextend' => $_POST['Methodextend'] ?? $panel['Methodextend'],
        'time_usertest' => array_key_exists('time_usertest', $_POST) ? trim($_POST['time_usertest']) : ($panel['time_usertest'] ?? '1'),
        'val_usertest' => array_key_exists('val_usertest', $_POST) ? trim($_POST['val_usertest']) : ($panel['val_usertest'] ?? '100'),
        'inbound_deactive' => array_key_exists('inbound_deactive', $_POST) ? trim($_POST['inbound_deactive']) : ($panel['inbound_deactive'] ?? '0'),
        'priceChangeloc' => array_key_exists('priceChangeloc', $_POST)
            ? trim($_POST['priceChangeloc'])
            : ($panel['priceChangeloc'] ?? '0'),
        'status' => $toggle('status_active', 'status', 'active', 'disable'),
        'TestAccount' => $toggle('test_on', 'TestAccount', 'ONTestAccount', 'OFFTestAccount'),
        'status_extend' => $toggle('extend_on', 'status_extend', 'on_extend', 'off_extend'),
        'config' => $toggle('config_on', 'config', 'onconfig', 'offconfig'),
        'sublink' => $toggle('sublink_on', 'sublink', 'onsublink', 'offsublink'),
        'conecton' => $toggle('conecton_on', 'conecton', 'onconecton', 'offconecton'),
        'on_hold_test' => $toggle('on_hold_test', 'on_hold_test', '1', '0'),
        'changeloc' => $toggle('changeloc_on', 'changeloc', 'onchangeloc', 'offchangeloc'),
        'subvip' => $toggle('subvip_on', 'subvip', 'onsubvip', 'offsubvip'),
        'inboundstatus' => $toggle('inbound_disable_on', 'inboundstatus', 'oninbounddisable', 'offinbounddisable'),
        'version_panel' => $toggle('version_panel_on', 'version_panel', '1', '0'),
        // Custom volume/time sell moved to categories — keep existing panel values untouched.
        'customvolume' => $panel['customvolume'] ?? panel_default_customvolume(),
        'hide_user' => $hideJson,
        'priceextravolume' => panel_merge_agent_json_field($panel, 'priceextravolume', 'priceextravolume'),
        'priceextratime' => panel_merge_agent_json_field($panel, 'priceextratime', 'priceextratime'),
        'pricecustomvolume' => $panel['pricecustomvolume'] ?? panel_default_price_json(),
        'pricecustomtime' => $panel['pricecustomtime'] ?? panel_default_price_json(),
        'mainvolume' => $panel['mainvolume'] ?? panel_encode_agent_json(['f' => '1', 'n' => '1', 'n2' => '1']),
        'maxvolume' => $panel['maxvolume'] ?? panel_encode_agent_json(['f' => '1000', 'n' => '1000', 'n2' => '1000']),
        'maintime' => $panel['maintime'] ?? panel_encode_agent_json(['f' => '1', 'n' => '1', 'n2' => '1']),
        'maxtime' => $panel['maxtime'] ?? panel_encode_agent_json(['f' => '365', 'n' => '365', 'n2' => '365']),
    ];

    if (array_key_exists('pasarguard_group_ids', $_POST)) {
        $data['inbounds'] = $parsedGroups;
    }

    // Optional bot message after panel/location is chosen (category keyboard).
    // Only include when the DB column exists so saves never 500 on older DBs.
    try {
        $descCol = $pdo->query("SHOW COLUMNS FROM marzban_panel LIKE 'description'");
        if ($descCol && $descCol->fetch(PDO::FETCH_ASSOC)) {
            if (array_key_exists('description', $_POST)) {
                $desc = trim((string) $_POST['description']);
                $data['description'] = $desc !== '' ? $desc : null;
            } else {
                $data['description'] = $panel['description'] ?? null;
            }
        }
    } catch (Throwable $e) {
        // column missing — skip
    }

    $clearLogin = $url !== ($panel['url_panel'] ?? '')
        || $data['username_panel'] !== ($panel['username_panel'] ?? '')
        || $data['password_panel'] !== ($panel['password_panel'] ?? '');

    $sets = [];
    $params = [];
    foreach ($data as $col => $val) {
        $sets[] = "`$col` = ?";
        $params[] = $val;
    }
    if ($clearLogin) {
        $sets[] = 'datelogin = NULL';
    }
    $params[] = $id;
    try {
        db_query($pdo, "UPDATE marzban_panel SET " . implode(', ', $sets) . " WHERE id = ?", $params);
        flash('success', 'تنظیمات پنل ذخیره شد.');
    } catch (Exception $e) {
        flash('error', 'خطا: ' . $e->getMessage());
    }
    header('Location: panel.php?id=' . $id . '&tab=' . urlencode($_POST['tab'] ?? 'connection'));
    exit;
}

$panel = db_fetch($pdo, "SELECT * FROM marzban_panel WHERE id = ?", [$id]);
$stats = panel_invoice_stats($pdo, $panel['name_panel']);
$priceExtra = panel_decode_agent_json($panel['priceextravolume'] ?? '');
$priceExtraTime = panel_decode_agent_json($panel['priceextratime'] ?? '');
$hideUsers = panel_parse_hide_users($panel['hide_user'] ?? null);

$tabs = [
    'connection' => 'اتصال',
    'features' => 'قابلیت‌ها',
    'account' => 'اکانت و تست',
    'pricing' => 'قیمت‌گذاری',
    'advanced' => 'پیشرفته',
    'reports' => 'گزارشات',
];
if (!isset($tabs[$tab])) {
    $tab = 'connection';
}

if (isset($_GET['probe']) && $_GET['probe'] === '1') {
    csrf_check_get();
}
$connectionProbe = ($tab === 'connection') ? panel_probe_connection($panel) : null;
$reportLogs = [];
$reportSelectedKey = '';
$reportSelectedInfo = null;
$reportTail = null;
if ($tab === 'reports') {
    $reportLogs = panel_report_log_files();
    $reportSelectedKey = (string) ($_GET['log'] ?? '');
    if ($reportSelectedKey === '' || !isset($reportLogs[$reportSelectedKey])) {
        $keys = array_keys($reportLogs);
        $reportSelectedKey = $keys[0] ?? '';
    }
    if ($reportSelectedKey !== '' && isset($reportLogs[$reportSelectedKey])) {
        $reportSelectedInfo = $reportLogs[$reportSelectedKey];
        $reportTail = panel_read_log_tail($reportSelectedInfo['path']);
    }
}

$pageTitle = 'مدیریت پنل: ' . ($panel['name_panel'] ?? '');
$activeNav = 'panels';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="margin-bottom:14px" class="fade-up">
  <a href="panels.php" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 14) ?> بازگشت به فهرست</a>
</div>

<div class="stats fade-up" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr))">
  <div class="stat">
    <div class="stat-label">نوع پنل</div>
    <div class="stat-num" style="font-size:1rem"><?= htmlspecialchars(panel_type_label($ptype)) ?></div>
  </div>
  <div class="stat ok">
    <div class="stat-label">فروش در این پنل</div>
    <div class="stat-num"><?= number_format($stats['count']) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">جمع فروش</div>
    <div class="stat-num" style="font-size:.95rem"><?= number_format($stats['sum']) ?> <small>ت</small></div>
  </div>
  <div class="stat <?= ($panel['status'] ?? '') === 'active' ? 'ok' : 'warn' ?>">
    <div class="stat-label">نمایش پنل</div>
    <div class="stat-num" style="font-size:.9rem"><?= panel_status_label($panel['status'] ?? '') ?></div>
  </div>
</div>

<div style="display:flex;gap:4px;margin-bottom:18px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:5px;overflow-x:auto" class="fade-up">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="panel.php?id=<?= $id ?>&tab=<?= $key ?>"
      style="padding:8px 14px;border-radius:7px;font-size:.82rem;font-weight:600;white-space:nowrap;text-decoration:none;<?= $tab === $key ? 'background:var(--ac);color:#fff' : 'color:var(--mute)' ?>">
      <?= htmlspecialchars($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<form method="POST" class="fade-up">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
  <?php if ($tab !== 'connection'): ?>
    <input type="hidden" name="name_panel" value="<?= htmlspecialchars($panel['name_panel'] ?? '') ?>">
    <input type="hidden" name="url_panel" value="<?= htmlspecialchars(($panel['url_panel'] ?? '') !== 'null' ? ($panel['url_panel'] ?? '') : '') ?>">
    <input type="hidden" name="username_panel" value="<?= htmlspecialchars(($panel['username_panel'] ?? '') !== 'null' ? ($panel['username_panel'] ?? '') : '') ?>">
    <input type="hidden" name="password_panel" value="<?= htmlspecialchars(($panel['password_panel'] ?? '') !== 'null' ? ($panel['password_panel'] ?? '') : '') ?>">
    <input type="hidden" name="linksubx" value="<?= htmlspecialchars($panel['linksubx'] ?? '') ?>">
    <input type="hidden" name="secret_code" value="<?= htmlspecialchars($panel['secret_code'] ?? '') ?>">
    <input type="hidden" name="inboundid" value="<?= htmlspecialchars($panel['inboundid'] ?? '') ?>">
    <input type="hidden" name="namecustom" value="<?= htmlspecialchars($panel['namecustom'] ?? '') ?>">
    <input type="hidden" name="agent" value="<?= htmlspecialchars($panel['agent'] ?? 'all') ?>">
    <input type="hidden" name="limit_panel" value="<?= htmlspecialchars($panel['limit_panel'] ?? '') ?>">
    <input type="hidden" name="description" value="<?= htmlspecialchars($panel['description'] ?? '') ?>">
    <?php if ($isPasarguard): ?>
    <input type="hidden" name="pasarguard_group_ids" value="<?= htmlspecialchars($pasarguardGroupIds) ?>">
    <?php endif; ?>
    <?php if (($panel['status'] ?? '') === 'active'): ?><input type="hidden" name="status_active" value="1"><?php endif; ?>
    <?php if (($panel['TestAccount'] ?? '') === 'ONTestAccount'): ?><input type="hidden" name="test_on" value="1"><?php endif; ?>
  <?php endif; ?>
  <?php if ($tab !== 'features'): ?>
    <?php
    $featHidden = [
        'extend_on' => ['status_extend', 'on_extend'],
        'config_on' => ['config', 'onconfig'],
        'sublink_on' => ['sublink', 'onsublink'],
        'conecton_on' => ['conecton', 'onconecton'],
        'on_hold_test' => ['on_hold_test', '1'],
        'changeloc_on' => ['changeloc', 'onchangeloc'],
        'subvip_on' => ['subvip', 'onsubvip'],
        'inbound_disable_on' => ['inboundstatus', 'oninbounddisable'],
        'version_panel_on' => ['version_panel', '1'],
    ];
    foreach ($featHidden as $postKey => [$field, $onVal]) {
        if (($panel[$field] ?? '') === $onVal) {
            echo '<input type="hidden" name="' . $postKey . '" value="1">';
        }
    }
    ?>
  <?php endif; ?>

  <?php if ($tab === 'connection'): ?>
    <div class="card" style="margin-bottom:16px">
      <div class="card-head" style="flex-wrap:wrap;gap:10px">
        <div>
          <div class="card-title">اتصال و فعال بودن پنل</div>
          <div class="card-subtitle">مثل بخش مدیریت پنل در ربات تلگرام</div>
        </div>
        <a href="panel.php?id=<?= $id ?>&tab=connection&probe=1&amp;_csrf=<?= urlencode(csrf_token()) ?>"
          class="btn btn-ghost btn-sm">بررسی مجدد اتصال</a>
      </div>
      <div class="card-body">
        <?php if ($connectionProbe): ?>
          <div class="kv-list" style="margin-bottom:16px">
            <div class="kv">
              <span class="kv-key">وضعیت اتصال API</span>
              <span class="tag <?= $connectionProbe['ok'] ? 'tag-ok' : 'tag-no' ?>">
                <?= $connectionProbe['ok'] ? 'متصل' : 'قطع / خطا' ?>
              </span>
            </div>
            <div class="kv">
              <span class="kv-key">پیام</span>
              <span class="kv-val" style="font-size:.85rem"><?= htmlspecialchars($connectionProbe['title']) ?></span>
            </div>
            <?php foreach ($connectionProbe['lines'] as $line): ?>
              <div class="kv">
                <span class="kv-key">جزئیات</span>
                <span class="kv-val cm" style="font-size:.8rem"><?= htmlspecialchars($line) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <label style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--sf2);border:1px solid var(--bd);border-radius:10px;cursor:pointer;margin-bottom:8px">
          <input type="checkbox" name="status_active" value="1" <?= ($panel['status'] ?? '') === 'active' ? 'checked' : '' ?>
            style="width:20px;height:20px;accent-color:var(--ac)">
          <span>
            <strong style="display:block;font-size:.9rem">فعال بودن پنل در ربات</strong>
            <span style="font-size:.78rem;color:var(--mute)">اگر خاموش باشد، این لوکیشن در فروشگاه و خرید به کاربر نشان داده نمی‌شود (معادل «نمایش پنل» در ربات)</span>
          </span>
        </label>
        <?php if (in_array('test', $features, true)): ?>
        <label style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--sf2);border:1px solid var(--bd);border-radius:10px;cursor:pointer">
          <input type="checkbox" name="test_on" value="1" <?= ($panel['TestAccount'] ?? '') === 'ONTestAccount' ? 'checked' : '' ?>
            style="width:20px;height:20px;accent-color:var(--ac)">
          <span>
            <strong style="display:block;font-size:.9rem">نمایش اکانت تست</strong>
            <span style="font-size:.78rem;color:var(--mute)">امکان ساخت سرویس تست روی این پنل</span>
          </span>
        </label>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><div class="card-title">تنظیمات اتصال</div></div>
      <div class="card-body">
        <div class="form-grid">
          <div class="field">
            <label>کد پنل</label>
            <input type="text" class="input" value="<?= htmlspecialchars($panel['code_panel'] ?? '') ?>" readonly>
          </div>
          <div class="field">
            <label>نوع (ثابت)</label>
            <input type="text" class="input" value="<?= htmlspecialchars(panel_type_label($ptype)) ?>" readonly>
          </div>
          <div class="field full">
            <label>نام پنل *</label>
            <input type="text" name="name_panel" class="input" value="<?= htmlspecialchars($panel['name_panel'] ?? '') ?>" required>
          </div>
          <div class="field full">
            <label>توضیحات ربات (اختیاری)</label>
            <textarea name="description" class="input" rows="4" placeholder="اگر پر شود، پس از انتخاب این پنل به‌جای «دسته بندی خود را انتخاب نمایید» نمایش داده می‌شود"><?= htmlspecialchars($panel['description'] ?? '') ?></textarea>
          </div>
          <div class="field full">
            <label>آدرس پنل (URL)</label>
            <input type="url" name="url_panel" class="input" value="<?= htmlspecialchars(($panel['url_panel'] ?? '') !== 'null' ? ($panel['url_panel'] ?? '') : '') ?>">
          </div>
          <?php if (!in_array($ptype, ['s_ui', 'WGDashboard'], true)): ?>
          <div class="field">
            <label>نام کاربری پنل</label>
            <input type="text" name="username_panel" class="input" value="<?= htmlspecialchars(($panel['username_panel'] ?? '') !== 'null' ? ($panel['username_panel'] ?? '') : '') ?>" autocomplete="off">
          </div>
          <?php endif; ?>
          <div class="field">
            <label><?= in_array($ptype, ['s_ui', 'WGDashboard'], true) ? 'توکن API' : 'رمز عبور' ?></label>
            <input type="text" name="password_panel" class="input" value="<?= htmlspecialchars(($panel['password_panel'] ?? '') !== 'null' ? ($panel['password_panel'] ?? '') : '') ?>" autocomplete="off">
          </div>
          <div class="field full">
            <label>دامنه لینک ساب</label>
            <input type="url" name="linksubx" class="input" value="<?= htmlspecialchars($panel['linksubx'] ?? '') ?>">
          </div>
          <?php if ($ptype === 'hiddify'): ?>
          <div class="field full">
            <label>UUID ادمین (هیدیفای)</label>
            <input type="text" name="secret_code" class="input" value="<?= htmlspecialchars($panel['secret_code'] ?? '') ?>">
          </div>
          <?php endif; ?>
          <?php if (in_array($ptype, ['x-ui_single', 'alireza_single', 'WGDashboard', 's_ui'], true)): ?>
          <div class="field">
            <label>شناسه اینباند</label>
            <input type="text" name="inboundid" class="input" value="<?= htmlspecialchars($panel['inboundid'] ?? '') ?>">
          </div>
          <?php endif; ?>
          <?php if ($ptype === 'ibsng'): ?>
          <div class="field full">
            <label>نام گروه پیش‌فرض (IBSng)</label>
            <input type="text" name="namecustom" class="input" value="<?= htmlspecialchars($panel['namecustom'] ?? '') ?>">
          </div>
          <?php endif; ?>
          <?php if ($isPasarguard): ?>
          <div class="field full">
            <label>شناسه گروه پاسارگارد (group_ids)</label>
            <input type="text" name="pasarguard_group_ids" class="input"
              value="<?= htmlspecialchars($pasarguardGroupIds) ?>"
              placeholder="مثال: 1 یا 1, 2"
              pattern="\d+(\s*,\s*\d+)*"
              inputmode="numeric"
              autocomplete="off">
            <small class="cf" style="display:block;margin-top:6px">
              از پنل PasarGuard → <strong>Groups</strong> شناسه گروه را بگیرید. هر گروه اینباندهای مشخصی دارد.
              برای فعال‌سازی حالت پاسارگارد به تب <strong>قابلیت‌ها</strong> بروید و «پنل پاسارگارد» را روشن کنید.
            </small>
          </div>
          <?php elseif ($ptype === 'marzban'): ?>
          <div class="field full">
            <div class="notice notice-warn" style="margin:0">
              برای پاسارگارد، در تب <strong>قابلیت‌ها</strong> گزینه «پنل پاسارگارد» را فعال کنید تا فیلد شناسه گروه نمایش داده شود.
            </div>
          </div>
          <?php endif; ?>
          <div class="field">
            <label>گروه کاربری</label>
            <select name="agent" class="select">
              <option value="all" <?= ($panel['agent'] ?? '') === 'all' ? 'selected' : '' ?>>همه (all)</option>
              <option value="f" <?= ($panel['agent'] ?? '') === 'f' ? 'selected' : '' ?>>کاربر عادی (f)</option>
              <option value="n" <?= ($panel['agent'] ?? '') === 'n' ? 'selected' : '' ?>>نماینده (n)</option>
              <option value="n2" <?= ($panel['agent'] ?? '') === 'n2' ? 'selected' : '' ?>>نماینده پیشرفته (n2)</option>
            </select>
          </div>
          <div class="field">
            <label>محدودیت ساخت اکانت</label>
            <input type="text" name="limit_panel" class="input" value="<?= htmlspecialchars($panel['limit_panel'] ?? '') ?>">
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'features'): ?>
    <div class="card">
      <div class="card-head"><div class="card-title">وضعیت قابلیت‌های پنل</div></div>
      <div class="card-body">
        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(240px,1fr))">
          <?php
          $toggles = [
              'extend_on' => ['label' => 'وضعیت تمدید', 'on' => ($panel['status_extend'] ?? '') === 'on_extend', 'feat' => 'extend'],
              'config_on' => ['label' => 'ارسال کانفیگ', 'on' => ($panel['config'] ?? '') === 'onconfig', 'feat' => 'config'],
              'sublink_on' => ['label' => 'ارسال لینک اشتراک', 'on' => ($panel['sublink'] ?? '') === 'onsublink', 'feat' => 'sublink'],
              'conecton_on' => ['label' => 'اولین اتصال', 'on' => ($panel['conecton'] ?? '') === 'onconecton', 'feat' => 'conecton'],
              'on_hold_test' => ['label' => 'اولین اتصال — اکانت تست', 'on' => ($panel['on_hold_test'] ?? '0') === '1', 'feat' => 'on_hold_test'],
              'changeloc_on' => ['label' => 'تغییر لوکیشن', 'on' => ($panel['changeloc'] ?? '') === 'onchangeloc', 'feat' => 'changeloc'],
              'subvip_on' => ['label' => 'لینک ساب اختصاصی', 'on' => ($panel['subvip'] ?? '') === 'onsubvip', 'feat' => 'subvip'],
              'inbound_disable_on' => ['label' => 'اینباند اکانت غیرفعال', 'on' => ($panel['inboundstatus'] ?? '') === 'oninbounddisable', 'feat' => 'inbound_disable'],
              'version_panel_on' => ['label' => 'پنل پاسارگارد', 'on' => ($panel['version_panel'] ?? '0') === '1', 'feat' => 'version_panel'],
          ];
          foreach ($toggles as $name => $t):
              if (!in_array($t['feat'], $features, true)) continue;
          ?>
            <label style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;cursor:pointer">
              <input type="checkbox" name="<?= $name ?>" value="1" <?= $t['on'] ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--ac)">
              <span style="font-size:.85rem"><?= htmlspecialchars($t['label']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'account'): ?>
    <div class="card">
      <div class="card-head"><div class="card-title">روش ساخت نام کاربری و تمدید</div></div>
      <div class="card-body">
        <div class="form-grid">
          <div class="field full">
            <label>روش ساخت نام کاربری</label>
            <select name="MethodUsername" class="select">
              <?php foreach (METHOD_USERNAME_OPTIONS as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= ($panel['MethodUsername'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field full">
            <label>روش تمدید سرویس</label>
            <select name="Methodextend" class="select">
              <?php foreach (METHOD_EXTEND_OPTIONS as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= ($panel['Methodextend'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>زمان سرویس تست (ساعت)</label>
            <input type="number" name="time_usertest" class="input" min="0" value="<?= htmlspecialchars($panel['time_usertest'] ?? '1') ?>">
          </div>
          <div class="field">
            <label>حجم اکانت تست (مگابایت)</label>
            <input type="number" name="val_usertest" class="input" min="0" value="<?= htmlspecialchars($panel['val_usertest'] ?? '100') ?>">
          </div>
          <div class="field full">
            <label>اینباند اکانت غیرفعال</label>
            <input type="text" name="inbound_deactive" class="input" value="<?= htmlspecialchars($panel['inbound_deactive'] ?? '0') ?>">
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'pricing'): ?>
    <div class="card">
      <div class="card-head"><div class="card-title">قیمت‌گذاری (تومان / واحد)</div><div class="card-subtitle">مقادیر جدا برای گروه‌های f، n، n2</div></div>
      <div class="card-body">
        <?php
        $priceRows = [
            ['قیمت حجم اضافه', 'priceextravolume', $priceExtra],
            ['قیمت زمان اضافه', 'priceextratime', $priceExtraTime],
        ];
        ?>
        <div class="notice" style="margin-bottom:12px;font-size:.85rem;color:var(--mute)">قیمت و فعال‌سازی «سرویس دلخواه» از بخش <a href="categories.php">دسته‌بندی‌ها</a> تنظیم می‌شود.</div>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr><th>عنوان</th><th>f</th><th>n</th><th>n2</th></tr></thead>
            <tbody>
              <?php foreach ($priceRows as [$title, $prefix, $vals]): ?>
                <tr>
                  <td class="cs"><?= htmlspecialchars($title) ?></td>
                  <td><input type="number" name="<?= $prefix ?>_f" class="input" style="min-width:90px" value="<?= htmlspecialchars($vals['f']) ?>"></td>
                  <td><input type="number" name="<?= $prefix ?>_n" class="input" style="min-width:90px" value="<?= htmlspecialchars($vals['n']) ?>"></td>
                  <td><input type="number" name="<?= $prefix ?>_n2" class="input" style="min-width:90px" value="<?= htmlspecialchars($vals['n2']) ?>"></td>
                </tr>
              <?php endforeach; ?>
              <tr>
                <td class="cs">قیمت تغییر لوکیشن</td>
                <td colspan="3"><input type="number" name="priceChangeloc" class="input" value="<?= htmlspecialchars($panel['priceChangeloc'] ?? '0') ?>"></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'advanced'): ?>
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><div class="card-title">کاربران مخفی از این پنل</div></div>
      <div class="card-body">
        <div class="field full">
          <label>آیدی عددی کاربران (با کاما یا خط جدید جدا کنید)</label>
          <textarea name="hide_users" class="input" rows="4" placeholder="123456789, 987654321"><?= htmlspecialchars(implode(', ', $hideUsers)) ?></textarea>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><div class="card-title">داده‌های فنی (فقط خواندنی)</div></div>
      <div class="card-body">
        <div class="field full">
          <label><?= $isPasarguard ? 'group_ids (inbounds)' : 'inbounds' ?></label>
          <textarea class="input" rows="3" readonly><?= htmlspecialchars($panel['inbounds'] ?? '') ?></textarea>
          <small class="cf">
            <?php if ($isPasarguard): ?>
              ویرایش از تب <strong>اتصال</strong> → فیلد «شناسه گروه پاسارگارد»، یا از ربات تلگرام → تنظیم پروتکل و اینباند
            <?php else: ?>
              از ربات: ⚙️ تنظیم پروتکل و اینباند — با ارسال نام کاربری کانفیگ
            <?php endif; ?>
          </small>
        </div>
        <div class="field full">
          <label>proxies</label>
          <textarea class="input" rows="3" readonly><?= htmlspecialchars($panel['proxies'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'reports'): ?>
    <div class="card" style="margin-bottom:16px">
      <div class="card-head" style="flex-wrap:wrap;gap:10px">
        <div>
          <div class="card-title">گزارشات سرور</div>
          <div class="card-subtitle">نمایش آخرین خطوط لاگ برای عیب‌یابی ربات و پنل</div>
        </div>
        <a href="panel.php?id=<?= $id ?>&tab=reports&log=<?= urlencode($reportSelectedKey) ?>"
          class="btn btn-ghost btn-sm">بروزرسانی</a>
      </div>
      <div class="card-body">
        <?php if (empty($reportLogs)): ?>
          <div class="notice notice-warn">هیچ فایل لاگی برای نمایش پیدا نشد (error_log / polling.log).</div>
        <?php else: ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <?php foreach ($reportLogs as $key => $info): ?>
              <a href="panel.php?id=<?= $id ?>&tab=reports&log=<?= urlencode($key) ?>"
                class="btn <?= $reportSelectedKey === $key ? 'btn-primary' : 'btn-ghost' ?> btn-sm">
                <?= htmlspecialchars($info['label']) ?>
              </a>
            <?php endforeach; ?>
          </div>
          <?php if ($reportSelectedInfo && is_array($reportTail)): ?>
            <div class="kv-list" style="margin-bottom:12px">
              <div class="kv">
                <span class="kv-key">فایل</span>
                <span class="kv-val cm" style="font-size:.75rem"><?= htmlspecialchars($reportSelectedInfo['path']) ?></span>
              </div>
              <div class="kv">
                <span class="kv-key">حجم</span>
                <span class="kv-val"><?= number_format((int) ($reportTail['size'] ?? 0)) ?> بایت</span>
              </div>
              <div class="kv">
                <span class="kv-key">آخرین بروزرسانی</span>
                <span class="kv-val"><?= htmlspecialchars(($reportTail['mtime'] ?? null) ? date('Y/m/d H:i:s', (int) $reportTail['mtime']) : '—') ?></span>
              </div>
            </div>
            <div style="background:var(--sf2);border:1px solid var(--bd);border-radius:10px;padding:12px;max-height:420px;overflow:auto">
              <pre style="margin:0;white-space:pre-wrap;word-break:break-word;direction:ltr;text-align:left;font-size:.78rem;line-height:1.6"><?= htmlspecialchars(!empty($reportTail['lines']) ? implode("\n", $reportTail['lines']) : 'لاگ خالی است.') ?></pre>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap">
    <button type="submit" class="btn btn-primary"><?= icon('check', 14) ?> ذخیره تغییرات</button>
    <a href="panels.php" class="btn btn-ghost">انصراف</a>
  </div>
</form>

<div class="card fade-up" style="margin-top:24px;border-color:var(--no)">
  <div class="card-head">
    <div class="card-title" style="color:var(--no)">حذف پنل</div>
  </div>
  <div class="card-body">
    <form method="POST" onsubmit="return confirm('پنل و تمام تنظیمات آن حذف می‌شود. ادامه می‌دهید؟')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= $id ?>">
      <p style="font-size:.85rem;color:var(--mute);margin-bottom:12px">برای تأیید کلمه <strong>تایید</strong> را در کادر زیر بنویسید (مثل ربات).</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div class="field" style="flex:1;min-width:200px;margin:0">
          <input type="text" name="confirm" class="input" placeholder="تایید" autocomplete="off">
        </div>
        <button type="submit" class="btn btn-no"><?= icon('trash', 14) ?> حذف پنل</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
