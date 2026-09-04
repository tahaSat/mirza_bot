<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_auth();
$pdo = panel_ensure_pdo();

$bot_button_labels = get_main_keyboard_button_fallback_labels();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_bot_button') {
    csrf_check_post();
    $button_id = $_POST['button_id'] ?? '';
    $setting_row = select('setting', '*', null, null, 'select');
    $textbot_rows = db_fetchAll($pdo, "SELECT id_text, text FROM textbot WHERE id_text IN ('" . implode("','", array_keys($bot_button_labels)) . "')");
    $toggle_datatextbot = $bot_button_labels;
    foreach ($textbot_rows as $row) {
        if (!empty($row['text'])) {
            $toggle_datatextbot[$row['id_text']] = $row['text'];
        }
    }
    $new_keyboard = toggle_main_keyboard_button($setting_row['keyboardmain'], $button_id, $toggle_datatextbot);
    update('setting', 'keyboardmain', $new_keyboard, null, null);
    clearSelectCache('setting');
    flash('success', 'وضعیت دکمه به‌روز شد.');
    header('Location: settings.php?tab=bot');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_bot_buttons') {
    csrf_check_post();
    $default_keyboard = get_default_main_keyboard_json();
    update('setting', 'keyboardmain', $default_keyboard, null, null);
    reset_main_keyboard_button_styles();
    reset_main_keyboard_button_icons();
    clearSelectCache('setting');
    flash('success', 'دکمه‌های منو به حالت پیش‌فرض بازگردانده شد.');
    header('Location: settings.php?tab=bot');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_bot_button_title') {
    csrf_check_post();
    $button_id = trim((string) ($_POST['button_id'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $emoji_input = trim((string) ($_POST['emoji'] ?? ''));
    $allowed_ids = get_main_keyboard_button_ids();
    $custom_emoji_id = normalize_main_keyboard_custom_emoji_id($emoji_input);

    $flash_error = null;
    $stored_title = $title;
    $stored_icon = '';

    if (!in_array($button_id, $allowed_ids, true)) {
        $flash_error = 'دکمه نامعتبر است.';
    } elseif ($title === '') {
        $flash_error = 'عنوان دکمه نمی‌تواند خالی باشد.';
    } elseif ($emoji_input !== '' && $custom_emoji_id === null && mb_strlen($emoji_input) > 8) {
        $flash_error = 'شناسه ایموجی پرمیوم باید فقط عدد باشد (مثلاً 5368324170671202286).';
    } elseif ($custom_emoji_id !== null && $custom_emoji_id !== '') {
        $stored_title = $title;
        $stored_icon = $custom_emoji_id;
    } elseif ($emoji_input !== '') {
        $stored_title = trim($emoji_input . ' ' . $title);
        $stored_icon = '';
    }

    if ($flash_error === null) {
        if (str_contains($stored_title, "\n") || mb_strlen($stored_title) > 32) {
            $flash_error = 'عنوان دکمه باید حداکثر ۳۲ کاراکتر و بدون خط جدید باشد.';
        } elseif (is_main_keyboard_internal_id($stored_title)) {
            $flash_error = 'این عنوان مجاز نیست.';
        }
    }

    if ($flash_error !== null) {
        flash('error', $flash_error);
    } else {
        $exists = db_fetch($pdo, "SELECT id_text FROM textbot WHERE id_text = ?", [$button_id]);
        if ($exists) {
            db_query($pdo, "UPDATE textbot SET text = ? WHERE id_text = ?", [$stored_title, $button_id]);
        } else {
            db_query($pdo, "INSERT INTO textbot (id_text, text) VALUES (?, ?)", [$button_id, $stored_title]);
        }
        set_main_keyboard_button_icon($button_id, $stored_icon);
        clearSelectCache('textbot');
        flash('success', $stored_icon !== '' ? 'عنوان و ایموجی پرمیوم دکمه ذخیره شد.' : 'عنوان دکمه ذخیره شد.');
    }
    header('Location: settings.php?tab=bot');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move_bot_button') {
    csrf_check_post();
    $button_id = trim((string) ($_POST['button_id'] ?? ''));
    $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
    $setting_row = select('setting', '*', null, null, 'select');
    $textbot_rows = db_fetchAll($pdo, "SELECT id_text, text FROM textbot WHERE id_text IN ('" . implode("','", array_keys($bot_button_labels)) . "')");
    $move_datatextbot = $bot_button_labels;
    foreach ($textbot_rows as $row) {
        if (!empty($row['text'])) {
            $move_datatextbot[$row['id_text']] = $row['text'];
        }
    }
    $new_keyboard = move_main_keyboard_button($setting_row['keyboardmain'], $button_id, $direction, $move_datatextbot);
    update('setting', 'keyboardmain', $new_keyboard, null, null);
    clearSelectCache('setting');
    flash('success', 'ترتیب دکمه‌ها به‌روز شد.');
    header('Location: settings.php?tab=bot');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_bot_button_width') {
    csrf_check_post();
    $button_id = trim((string) ($_POST['button_id'] ?? ''));
    $width = ($_POST['width'] ?? '') === 'full' ? 'full' : 'half';
    $setting_row = select('setting', '*', null, null, 'select');
    $textbot_rows = db_fetchAll($pdo, "SELECT id_text, text FROM textbot WHERE id_text IN ('" . implode("','", array_keys($bot_button_labels)) . "')");
    $width_datatextbot = $bot_button_labels;
    foreach ($textbot_rows as $row) {
        if (!empty($row['text'])) {
            $width_datatextbot[$row['id_text']] = $row['text'];
        }
    }
    $new_keyboard = set_main_keyboard_button_width($setting_row['keyboardmain'], $button_id, $width, $width_datatextbot);
    update('setting', 'keyboardmain', $new_keyboard, null, null);
    clearSelectCache('setting');
    flash('success', $width === 'full' ? 'دکمه در سطر کامل قرار گرفت.' : 'دکمه به حالت دو ستونه درآمد.');
    header('Location: settings.php?tab=bot');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_bot_button_style') {
    csrf_check_post();
    $button_id = trim((string) ($_POST['button_id'] ?? ''));
    $style = trim((string) ($_POST['style'] ?? ''));
    if (set_main_keyboard_button_style($button_id, $style)) {
        flash('success', 'رنگ دکمه ذخیره شد.');
    } else {
        flash('error', 'رنگ دکمه نامعتبر است.');
    }
    header('Location: settings.php?tab=bot');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    csrf_check_post();
    $cur = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $admin = db_fetch($pdo, "SELECT * FROM admin WHERE username = ?", [$_SESSION['admin_user']]);
    $valid = password_verify($cur, $admin['password']) || $cur === $admin['password'];

    if (!$valid) {
        flash('error', 'رمز عبور فعلی اشتباه است.');
    } elseif ($new !== $confirm) {
        flash('error', 'تأیید رمز جدید مطابقت ندارد.');
    } elseif (strlen($new) < 6) {
        flash('error', 'رمز عبور باید حداقل ۶ کاراکتر باشد.');
    } else {
        db_query(
            $pdo,
            "UPDATE admin SET password = ? WHERE username = ?",
            [password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $_SESSION['admin_user']]
        );
        flash('success', 'رمز عبور تغییر کرد.');
    }
    header('Location: settings.php?tab=security');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_channel_post') {
    csrf_check_post();
    ensure_channel_post_setting_column();
    $channel = normalize_channel_post_input($_POST['channel_post'] ?? '');
    update('setting', 'Channel_Post', $channel, null, null);
    clearSelectCache('setting');
    flash('success', $channel === '' ? 'کانال پیش‌فرض پاک شد.' : 'کانال پیش‌فرض ذخیره شد.');
    header('Location: settings.php?tab=system');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_forced_join') {
    csrf_check_post();
    $saved = save_forced_join_channel(
        $_POST['channel_id'] ?? '',
        $_POST['remark'] ?? '',
        $_POST['linkjoin'] ?? ''
    );
    flash(!empty($saved['ok']) ? 'success' : 'error', $saved['msg'] ?? '');
    header('Location: settings.php?tab=system');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_forced_join') {
    csrf_check_post();
    $removed = delete_forced_join_channel($_POST['channel_link'] ?? '');
    flash(!empty($removed['ok']) ? 'success' : 'error', $removed['msg'] ?? '');
    header('Location: settings.php?tab=system');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['expense_add', 'expense_edit', 'expense_delete'], true)) {
    csrf_check_post();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'expense_add') {
        $r = panel_expense_add($pdo, (string) ($_POST['label'] ?? ''), (int) ($_POST['sort_order'] ?? 0));
    } elseif ($action === 'expense_edit') {
        $sortRaw = trim((string) ($_POST['sort_order'] ?? ''));
        $r = panel_expense_rename(
            $pdo,
            (int) ($_POST['edit_id'] ?? 0),
            (string) ($_POST['label'] ?? ''),
            $sortRaw === '' ? null : (int) $sortRaw
        );
    } else {
        $r = panel_expense_delete($pdo, (int) ($_POST['delete_id'] ?? 0));
    }
    flash(!empty($r['ok']) ? 'success' : 'error', $r['msg'] ?? '');
    header('Location: settings.php?tab=finance');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['income_add', 'income_edit', 'income_delete'], true)) {
    csrf_check_post();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'income_add') {
        $r = panel_income_add($pdo, (string) ($_POST['label'] ?? ''), (int) ($_POST['sort_order'] ?? 0));
    } elseif ($action === 'income_edit') {
        $sortRaw = trim((string) ($_POST['sort_order'] ?? ''));
        $r = panel_income_rename(
            $pdo,
            (int) ($_POST['edit_id'] ?? 0),
            (string) ($_POST['label'] ?? ''),
            $sortRaw === '' ? null : (int) $sortRaw
        );
    } else {
        $r = panel_income_delete($pdo, (int) ($_POST['delete_id'] ?? 0));
    }
    flash(!empty($r['ok']) ? 'success' : 'error', $r['msg'] ?? '');
    header('Location: settings.php?tab=finance');
    exit;
}

if (isset($_GET['delete_expense'])) {
    csrf_check_get();
    $r = panel_expense_delete($pdo, (int) ($_GET['delete_expense'] ?? 0));
    flash(!empty($r['ok']) ? 'success' : 'error', $r['msg'] ?? '');
    header('Location: settings.php?tab=finance');
    exit;
}

if (isset($_GET['delete_income'])) {
    csrf_check_get();
    $r = panel_income_delete($pdo, (int) ($_GET['delete_income'] ?? 0));
    flash(!empty($r['ok']) ? 'success' : 'error', $r['msg'] ?? '');
    header('Location: settings.php?tab=finance');
    exit;
}

$tab = $_GET['tab'] ?? 'appearance';

ensure_channel_post_setting_column();
$channel_post_setting = select('setting', 'Channel_Post', null, null, 'select', ['cache' => false]);
$channel_post_value = normalize_channel_post_input(is_array($channel_post_setting) ? ($channel_post_setting['Channel_Post'] ?? '') : '');

$forced_join_channels = [];
try {
    $forced_join_channels = db_fetchAll($pdo, 'SELECT remark, link, linkjoin FROM channels ORDER BY remark');
} catch (Throwable $e) {
    $forced_join_channels = [];
}

$bot_setting = select('setting', 'keyboardmain', null, null, 'select', ['cache' => false]);
$bot_keyboardmain = $bot_setting['keyboardmain'] ?? get_default_main_keyboard_json();
$textbot_rows = db_fetchAll($pdo, "SELECT id_text, text FROM textbot WHERE id_text IN ('" . implode("','", array_keys($bot_button_labels)) . "')");
$bot_text_labels = $bot_button_labels;
$bot_datatextbot = [];
foreach ($textbot_rows as $row) {
    if (!empty($row['text'])) {
        $bot_text_labels[$row['id_text']] = $row['text'];
    }
    $bot_datatextbot[$row['id_text']] = $row['text'];
}
foreach (array_keys($bot_button_labels) as $btn_id) {
    if (!isset($bot_datatextbot[$btn_id])) {
        $bot_datatextbot[$btn_id] = $bot_text_labels[$btn_id] ?? $btn_id;
    }
}
$normalized_bot_keyboard = normalize_keyboardmain_to_ids($bot_keyboardmain, $bot_datatextbot);
if ($normalized_bot_keyboard !== $bot_keyboardmain) {
    update('setting', 'keyboardmain', $normalized_bot_keyboard, null, null);
    $bot_keyboardmain = $normalized_bot_keyboard;
}
$bot_active_ids = get_active_main_keyboard_buttons($bot_keyboardmain, $bot_datatextbot);
$bot_solo_ids = get_main_keyboard_solo_button_ids($bot_keyboardmain, $bot_datatextbot);
$bot_button_styles = get_main_keyboard_button_styles();
$bot_button_icons = get_main_keyboard_button_icons();
$bot_style_options = get_main_keyboard_allowed_styles();
$bot_all_ids = get_main_keyboard_button_ids();
$bot_ordered_ids = array_values(array_unique(array_merge(
    $bot_active_ids,
    array_values(array_diff($bot_all_ids, $bot_active_ids))
)));
$bot_menu_buttons = [];
$active_count = count($bot_active_ids);
foreach ($bot_ordered_ids as $index => $btn_id) {
    $is_active = in_array($btn_id, $bot_active_ids, true);
    $active_index = $is_active ? array_search($btn_id, $bot_active_ids, true) : false;
    $is_full = $is_active && in_array($btn_id, $bot_solo_ids, true);
    $full_label = get_main_keyboard_button_label($btn_id, $bot_datatextbot);
    $label_parts = split_main_keyboard_button_label($full_label);
    $icon_id = $bot_button_icons[$btn_id] ?? '';
    $bot_menu_buttons[] = [
        'id' => $btn_id,
        'label' => $full_label,
        'emoji' => $icon_id !== '' ? $icon_id : $label_parts['emoji'],
        'title' => $label_parts['title'] !== '' ? $label_parts['title'] : $full_label,
        'has_premium_emoji' => $icon_id !== '',
        'active' => $is_active,
        'full_width' => $is_full,
        'style' => $bot_button_styles[$btn_id] ?? '',
        'can_move_up' => $is_active && $active_index !== false && $active_index > 0,
        'can_move_down' => $is_active && $active_index !== false && $active_index < ($active_count - 1),
        'position' => $is_active && $active_index !== false ? ($active_index + 1) : null,
    ];
}
$bot_style_preview_colors = [
    '' => ['bg' => 'var(--sf3)', 'fg' => 'var(--tx)', 'bd' => 'var(--bd)'],
    'primary' => ['bg' => '#1B6AC9', 'fg' => '#fff', 'bd' => '#1B6AC9'],
    'success' => ['bg' => '#31B545', 'fg' => '#fff', 'bd' => '#31B545'],
    'danger' => ['bg' => '#E44C4C', 'fg' => '#fff', 'bd' => '#E44C4C'],
];
$bot_preview_rows = [];
$layout_preview = json_decode($bot_keyboardmain, true);
if (is_array($layout_preview) && !empty($layout_preview['keyboard'])) {
    foreach ($layout_preview['keyboard'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $preview_row = [];
        foreach ($row as $btn) {
            $id = $btn['text'] ?? '';
            if ($id === '') {
                continue;
            }
            $style = $bot_button_styles[$id] ?? '';
            $full_label = get_main_keyboard_button_label($id, $bot_datatextbot);
            $label_parts = split_main_keyboard_button_label($full_label);
            $icon_id = $bot_button_icons[$id] ?? '';
            $preview_row[] = [
                'label' => $full_label,
                'title' => $label_parts['title'] !== '' ? $label_parts['title'] : $full_label,
                'emoji' => $icon_id !== '' ? '' : $label_parts['emoji'],
                'has_premium_emoji' => $icon_id !== '',
                'style' => $style,
                'colors' => $bot_style_preview_colors[$style] ?? $bot_style_preview_colors[''],
            ];
        }
        if ($preview_row !== []) {
            $bot_preview_rows[] = $preview_row;
        }
    }
}

$themes = [
    'navy' => ['name' => 'دریای آبی', 'desc' => 'پیش‌فرض · فیروزه‌ای', 'c' => ['#0F172A', '#1E293B', '#06B6D4', '#22C55E'], 'dark' => true],
    'purple' => ['name' => 'بنفش رویا', 'desc' => 'تیره · مدرن', 'c' => ['#180D2E', '#231545', '#A855F7', '#F43F5E'], 'dark' => true],
    'emerald' => ['name' => 'زمرد سبز', 'desc' => 'طبیعی · آرام', 'c' => ['#0A1F1C', '#132E2A', '#10B981', '#84CC16'], 'dark' => true],
    'sunset' => ['name' => 'غروب گرم', 'desc' => 'گرم · پرانرژی', 'c' => ['#1A0D0D', '#2A1615', '#F97316', '#FBBF24'], 'dark' => true],
    'slate' => ['name' => 'مشکی', 'desc' => 'بی‌رنگ · مینیمال', 'c' => ['#080808', '#141414', '#E2E8F0', '#22C55E'], 'dark' => true],
    'light' => ['name' => 'روشن سفید', 'desc' => 'روشن · حرفه‌ای', 'c' => ['#F1F5F9', '#FFFFFF', '#0891B2', '#16A34A'], 'dark' => false],
    'linen' => ['name' => 'کاغذ کرم', 'desc' => 'گرم · ادیتوریال', 'c' => ['#FAF7F2', '#FFFFFF', '#B87333', '#5D7C4A'], 'dark' => false],
    'mint' => ['name' => 'نعناع سبز', 'desc' => 'تازه · طبیعی', 'c' => ['#F0FDF4', '#FFFFFF', '#166534', '#1D4ED8'], 'dark' => false],
    'lavender' => ['name' => 'اسطوخودوس', 'desc' => 'ملایم · آرامش‌بخش', 'c' => ['#FAF5FF', '#FFFFFF', '#6D28D9', '#15803D'], 'dark' => false],
];

$tabs = [
    'appearance' => ['icon' => 'settings', 'label' => 'ظاهر'],
    'bot' => ['icon' => 'menu', 'label' => 'منوی ربات'],
    'finance' => ['icon' => 'wallet', 'label' => 'مالی'],
    'security' => ['icon' => 'block', 'label' => 'امنیت'],
    'system' => ['icon' => 'dashboard', 'label' => 'سیستم'],
];

$expenseCategories = [];
$expenseUsage = [];
$incomeCategories = [];
$incomeUsage = [];
if ($tab === 'finance') {
    panel_payment_ensure_schema($pdo);
    $expenseCategories = panel_expense_categories($pdo);
    $expenseUsage = panel_expense_usage_counts($pdo);
    $incomeCategories = panel_income_categories($pdo);
    $incomeUsage = panel_income_usage_counts($pdo);
}

$pageTitle = $tab === 'bot' ? 'منوی ربات' : 'تنظیمات';
$activeNav = $tab === 'bot' ? 'bot_menu' : 'settings';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;gap:4px;margin-bottom:18px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:5px;overflow-x:auto"
    class="fade-up">
    <?php foreach ($tabs as $key => $tab_data): ?>
        <a href="?tab=<?= $key ?>"
            style="display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:7px;font-size:.82rem;font-weight:600;white-space:nowrap;flex-shrink:0;transition:all .15s;text-decoration:none;
                  <?= $tab === $key ? 'background:var(--ac);color:#fff;box-shadow:0 0 14px var(--acg)' : 'color:var(--mute)' ?>">
            <?= icon($tab_data['icon'], 15) ?>     <?= $tab_data['label'] ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'appearance'): ?>

    <div class="card fade-up">
        <div class="card-head">
            <div>
                <div class="card-title">رنگ‌بندی پنل</div>
                <div class="card-subtitle">تغییر فوری · ذخیره در مرورگر</div>
            </div>
        </div>
        <div class="card-body">
            <div
                style="font-size:.75rem;font-weight:700;color:var(--mute);letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px">
                تیره</div>
            <div class="theme-grid" style="margin-bottom:20px">
                <?php foreach ($themes as $key => $theme):
                    if (!$theme['dark'])
                        continue; ?>
                    <div class="theme-card" data-tk="<?= $key ?>" onclick="pickTheme('<?= $key ?>')">
                        <div class="theme-preview">
                            <?php foreach ($theme['c'] as $color): ?>
                                <div style="background:<?= $color ?>"></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="theme-name"><?= htmlspecialchars($theme['name']) ?></div>
                        <div class="theme-desc"><?= htmlspecialchars($theme['desc']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div
                style="font-size:.75rem;font-weight:700;color:var(--mute);letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px">
                روشن</div>
            <div class="theme-grid">
                <?php foreach ($themes as $key => $theme):
                    if ($theme['dark'])
                        continue; ?>
                    <div class="theme-card" data-tk="<?= $key ?>" onclick="pickTheme('<?= $key ?>')">
                        <div class="theme-preview" style="border:1px solid var(--bd)">
                            <?php foreach ($theme['c'] as $color): ?>
                                <div style="background:<?= $color ?>"></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="theme-name"><?= htmlspecialchars($theme['name']) ?></div>
                        <div class="theme-desc"><?= htmlspecialchars($theme['desc']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card fade-up d1" style="margin-top:14px">
        <div class="card-head">
            <div>
                <div class="card-title">نمای سایدبار</div>
            </div>
        </div>
        <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap">
            <button onclick="setSidebarMode(false)" class="btn btn-ghost" id="modeExpanded"
                style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 20px;flex:1;min-width:120px">
                <svg width="44" height="32" viewBox="0 0 44 32" fill="none">
                    <rect x="0" y="0" width="13" height="32" rx="3" fill="var(--sf3)" />
                    <rect x="2" y="5" width="9" height="2" rx="1" fill="var(--ac)" />
                    <rect x="2" y="10" width="9" height="2" rx="1" fill="var(--bd)" />
                    <rect x="15" y="0" width="29" height="32" rx="3" fill="var(--sf3)" />
                </svg>
                <span style="font-size:.78rem;font-weight:600">باز</span>
            </button>
            <button onclick="setSidebarMode(true)" class="btn btn-ghost" id="modeCollapsed"
                style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 20px;flex:1;min-width:120px">
                <svg width="44" height="32" viewBox="0 0 44 32" fill="none">
                    <rect x="0" y="0" width="7" height="32" rx="3" fill="var(--sf3)" />
                    <rect x="2" y="5" width="3" height="2" rx="1" fill="var(--ac)" />
                    <rect x="2" y="10" width="3" height="2" rx="1" fill="var(--bd)" />
                    <rect x="9" y="0" width="35" height="32" rx="3" fill="var(--sf3)" />
                </svg>
                <span style="font-size:.78rem;font-weight:600">جمع‌شده</span>
            </button>
        </div>
    </div>

<?php elseif ($tab === 'bot'): ?>

    <div class="card fade-up">
        <div class="card-head">
            <div>
                <div class="card-title">دکمه‌های منوی اصلی ربات</div>
                <div class="card-subtitle">عنوان، ترتیب و نمایش دکمه‌هایی که کاربران در تلگرام می‌بینند</div>
            </div>
            <form method="POST">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reset_bot_buttons">
                <button type="submit" class="btn btn-ghost btn-sm"><?= icon('settings', 14) ?> بازنشانی پیش‌فرض</button>
            </form>
        </div>
        <div class="tbl-wrap">
            <table class="tbl-md">
                <thead>
                    <tr>
                        <th style="width:56px">ترتیب</th>
                        <th colspan="2">ایموجی و عنوان</th>
                        <th>عرض</th>
                        <th>رنگ</th>
                        <th>وضعیت</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bot_menu_buttons as $btn): ?>
                        <tr style="<?= $btn['active'] ? '' : 'opacity:.65' ?>">
                            <td>
                                <?php if ($btn['active']): ?>
                                    <div style="display:flex;flex-direction:column;gap:4px;align-items:center">
                                        <form method="POST" style="margin:0">
                                            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="move_bot_button">
                                            <input type="hidden" name="button_id" value="<?= htmlspecialchars($btn['id']) ?>">
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="btn btn-ghost btn-sm" title="بالاتر"
                                                <?= $btn['can_move_up'] ? '' : 'disabled style="opacity:.35;pointer-events:none"' ?>>
                                                <?= icon('arrow-up', 14) ?>
                                            </button>
                                        </form>
                                        <span style="font-size:.72rem;color:var(--mute);font-weight:700"><?= (int) $btn['position'] ?></span>
                                        <form method="POST" style="margin:0">
                                            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="move_bot_button">
                                            <input type="hidden" name="button_id" value="<?= htmlspecialchars($btn['id']) ?>">
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="btn btn-ghost btn-sm" title="پایین‌تر"
                                                <?= $btn['can_move_down'] ? '' : 'disabled style="opacity:.35;pointer-events:none"' ?>>
                                                <?= icon('arrow-down', 14) ?>
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span style="font-size:.72rem;color:var(--mute)">—</span>
                                <?php endif; ?>
                            </td>
                            <td colspan="2">
                                <form method="POST" style="display:flex;gap:8px;align-items:center;min-width:280px">
                                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="save_bot_button_title">
                                    <input type="hidden" name="button_id" value="<?= htmlspecialchars($btn['id']) ?>">
                                    <input type="text" name="emoji" class="input" maxlength="64"
                                        value="<?= htmlspecialchars($btn['emoji']) ?>"
                                        placeholder="<?= $btn['has_premium_emoji'] ? 'شناسه پرمیوم' : '🔐 یا ID' ?>"
                                        title="ایموجی معمولی یا شناسه عددی ایموجی پرمیوم تلگرام"
                                        style="width:110px;flex:0 0 110px;padding:7px 8px;font-size:.8rem;text-align:center<?= $btn['has_premium_emoji'] ? ';font-family:ui-monospace,monospace;font-size:.72rem' : '' ?>">
                                    <input type="text" name="title" class="input" maxlength="32" required
                                        value="<?= htmlspecialchars($btn['title']) ?>"
                                        placeholder="عنوان بدون ایموجی"
                                        style="flex:1;min-width:0;padding:7px 10px;font-size:.85rem">
                                    <button type="submit" class="btn btn-primary btn-sm" title="ذخیره">
                                        <?= icon('check', 14) ?>
                                    </button>
                                </form>
                                <?php if ($btn['has_premium_emoji']): ?>
                                    <div style="margin-top:4px;font-size:.68rem;color:var(--mute)">ایموجی پرمیوم فعال</div>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <?php if ($btn['active']): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="set_bot_button_width">
                                        <input type="hidden" name="button_id" value="<?= htmlspecialchars($btn['id']) ?>">
                                        <input type="hidden" name="width" value="<?= $btn['full_width'] ? 'half' : 'full' ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm"
                                            title="<?= $btn['full_width'] ? 'تبدیل به دو ستونه (نیمه‌عرض)' : 'سطر کامل (تمام‌عرض)' ?>">
                                            <span class="tag <?= $btn['full_width'] ? 'tag-ok' : 'tag-plain' ?>">
                                                <?= $btn['full_width'] ? 'کامل' : 'نیمه' ?>
                                            </span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size:.72rem;color:var(--mute)">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" style="display:flex;align-items:center;gap:6px;min-width:120px">
                                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="set_bot_button_style">
                                    <input type="hidden" name="button_id" value="<?= htmlspecialchars($btn['id']) ?>">
                                    <select name="style" class="input" style="padding:6px 8px;font-size:.8rem;min-width:100px"
                                        onchange="this.form.submit()">
                                        <option value="default" <?= $btn['style'] === '' ? 'selected' : '' ?>>پیش‌فرض</option>
                                        <?php foreach ($bot_style_options as $style_key => $style_label): ?>
                                            <option value="<?= htmlspecialchars($style_key) ?>" <?= $btn['style'] === $style_key ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($style_label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <span class="tag <?= $btn['active'] ? 'tag-ok' : 'tag-plain' ?>">
                                    <?= $btn['active'] ? 'نمایش' : 'مخفی' ?>
                                </span>
                            </td>
                            <td style="white-space:nowrap">
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="toggle_bot_button">
                                    <input type="hidden" name="button_id" value="<?= htmlspecialchars($btn['id']) ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm">
                                        <?= $btn['active'] ? 'مخفی کردن' : 'نمایش دادن' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($bot_preview_rows !== []): ?>
        <div class="card fade-up d1" style="margin-top:14px">
            <div class="card-head">
                <div>
                    <div class="card-title">پیش‌نمایش منو</div>
                    <div class="card-subtitle">چیدمان دکمه‌های فعال به همان شکلی که در تلگرام دیده می‌شود</div>
                </div>
            </div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:8px;max-width:420px">
                <?php foreach ($bot_preview_rows as $preview_row): ?>
                    <div style="display:grid;grid-template-columns:repeat(<?= count($preview_row) ?>,minmax(0,1fr));gap:8px">
                        <?php foreach ($preview_row as $preview_btn): ?>
                            <div style="background:<?= htmlspecialchars($preview_btn['colors']['bg']) ?>;color:<?= htmlspecialchars($preview_btn['colors']['fg']) ?>;border:1px solid <?= htmlspecialchars($preview_btn['colors']['bd']) ?>;border-radius:8px;padding:10px 12px;text-align:center;font-size:.82rem;font-weight:600">
                                <?php if ($preview_btn['has_premium_emoji']): ?>
                                    <span style="opacity:.85;margin-inline-end:4px" title="ایموجی پرمیوم">✦</span>
                                <?php elseif ($preview_btn['emoji'] !== ''): ?>
                                    <span style="margin-inline-end:4px"><?= htmlspecialchars($preview_btn['emoji']) ?></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($preview_btn['title']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card fade-up d2" style="margin-top:14px">
        <div class="card-body" style="font-size:.82rem;color:var(--mute);line-height:1.7">
            با دکمه‌های بالا/پایین ترتیب را عوض کنید.
            ستون <strong>ایموجی</strong>: ایموجی معمولی (مثل 🔐) را اینجا می‌توانید بگذارید.
            برای <strong>ایموجی پرمیوم</strong> از داخل ربات بروید: تنظیمات عمومی ← تنظیم دکمه‌های منو ← دکمه موردنظر ← تنظیم ایموجی پرمیوم (با اکانت پرمیوم مالک ربات یک ایموجی سفارشی بفرستید).
            ستون <strong>عرض</strong>: <strong>کامل</strong> یعنی دکمه تنها در یک سطر (تمام‌عرض در تلگرام)، <strong>نیمه</strong> یعنی دو دکمه در یک سطر.
            ستون <strong>رنگ</strong>: آبی، سبز یا قرمز (قابلیت رسمی تلگرام؛ در نسخه‌های قدیمی اپ ممکن است دیده نشود).
            عنوان حداکثر ۳۲ کاراکتر است. کاربران فعلی پس از دریافت مجدد منو تغییرات را می‌بینند.
        </div>
    </div>

<?php elseif ($tab === 'finance'): ?>

    <div class="card fade-up" style="margin-bottom:16px">
        <div class="card-head">
            <div>
                <div class="card-title">دسته‌های درآمد</div>
                <div class="card-subtitle">متدها و درگاه‌های پرداخت ثابت‌اند؛ دسته‌های دستی درآمد را اینجا اضافه کنید</div>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="openModal('incomeAddModal')"><?= icon('plus', 14) ?> افزودن دسته</button>
        </div>
        <?php if (empty($incomeCategories)): ?>
            <div class="empty" style="padding:48px 20px">
                <p>هنوز دسته‌ای ثبت نشده</p>
                <button type="button" class="btn btn-primary" style="margin-top:14px" onclick="openModal('incomeAddModal')"><?= icon('plus', 14) ?> افزودن دسته</button>
            </div>
        <?php else: ?>
            <div class="tbl-wrap">
                <table class="tbl-lg">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>نام دسته</th>
                            <th>تعداد درآمد</th>
                            <th>ترتیب</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($incomeCategories as $cat):
                            $slug = (string) ($cat['slug'] ?? '');
                            $isProtected = in_array($slug, panel_income_protected_slugs(), true);
                            $used = (int) ($incomeUsage[$slug] ?? 0);
                            $editPayload = [
                                'id' => (int) ($cat['id'] ?? 0),
                                'label' => (string) ($cat['label'] ?? ''),
                                'sort_order' => (int) ($cat['sort_order'] ?? 0),
                            ];
                        ?>
                        <tr>
                            <td class="cf"><?= $i++ ?></td>
                            <td>
                                <?= htmlspecialchars((string) ($cat['label'] ?? '')) ?>
                                <?php if ($isProtected): ?>
                                    <span class="tag tag-plain" style="margin-right:6px">سیستمی</span>
                                <?php endif; ?>
                            </td>
                            <td class="cn"><?= number_format($used) ?></td>
                            <td class="cf"><?= (int) ($cat['sort_order'] ?? 0) ?></td>
                            <td>
                                <div style="display:flex;gap:5px;flex-wrap:wrap">
                                    <?php if (!$isProtected): ?>
                                    <button type="button" class="btn btn-ghost btn-sm btn-icon" title="ویرایش"
                                        onclick="openIncomeEditModal(<?= htmlspecialchars(json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
                                        <?= icon('edit', 13) ?>
                                    </button>
                                    <?php if ($used > 0): ?>
                                    <button type="button" class="btn btn-no btn-sm btn-icon" title="این دسته روی <?= number_format($used) ?> درآمد استفاده شده" disabled>
                                        <?= icon('trash', 13) ?>
                                    </button>
                                    <?php else: ?>
                                    <a href="settings.php?tab=finance&delete_income=<?= (int) ($cat['id'] ?? 0) ?>&_csrf=<?= csrf_token() ?>"
                                        class="btn btn-no btn-sm btn-icon" title="حذف"
                                        data-confirm="حذف دسته «<?= htmlspecialchars((string) ($cat['label'] ?? '')) ?>»؟">
                                        <?= icon('trash', 13) ?>
                                    </a>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="cf" style="font-size:.75rem">غیرقابل تغییر</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card fade-up">
        <div class="card-head">
            <div>
                <div class="card-title">دسته‌های هزینه</div>
                <div class="card-subtitle">برای ثبت هزینه در بخش مالی، به‌جای روش پرداخت از این دسته‌ها استفاده می‌شود</div>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="openModal('expenseAddModal')"><?= icon('plus', 14) ?> افزودن دسته</button>
        </div>
        <?php if (empty($expenseCategories)): ?>
            <div class="empty" style="padding:48px 20px">
                <p>هنوز دسته‌ای ثبت نشده</p>
                <button type="button" class="btn btn-primary" style="margin-top:14px" onclick="openModal('expenseAddModal')"><?= icon('plus', 14) ?> افزودن دسته</button>
            </div>
        <?php else: ?>
            <div class="tbl-wrap">
                <table class="tbl-lg">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>نام دسته</th>
                            <th>تعداد هزینه</th>
                            <th>ترتیب</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($expenseCategories as $cat):
                            $slug = (string) ($cat['slug'] ?? '');
                            $isDefault = $slug === panel_expense_default_slug();
                            $used = (int) ($expenseUsage[$slug] ?? 0);
                            $editPayload = [
                                'id' => (int) ($cat['id'] ?? 0),
                                'label' => (string) ($cat['label'] ?? ''),
                                'sort_order' => (int) ($cat['sort_order'] ?? 0),
                            ];
                        ?>
                        <tr>
                            <td class="cf"><?= $i++ ?></td>
                            <td>
                                <?= htmlspecialchars((string) ($cat['label'] ?? '')) ?>
                                <?php if ($isDefault): ?>
                                    <span class="tag tag-plain" style="margin-right:6px">پیش‌فرض</span>
                                <?php endif; ?>
                            </td>
                            <td class="cn"><?= number_format($used) ?></td>
                            <td class="cf"><?= (int) ($cat['sort_order'] ?? 0) ?></td>
                            <td>
                                <div style="display:flex;gap:5px;flex-wrap:wrap">
                                    <button type="button" class="btn btn-ghost btn-sm btn-icon" title="ویرایش"
                                        onclick="openExpenseEditModal(<?= htmlspecialchars(json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
                                        <?= icon('edit', 13) ?>
                                    </button>
                                    <?php if (!$isDefault): ?>
                                    <?php if ($used > 0): ?>
                                    <button type="button" class="btn btn-no btn-sm btn-icon" title="این دسته روی <?= number_format($used) ?> هزینه استفاده شده" disabled>
                                        <?= icon('trash', 13) ?>
                                    </button>
                                    <?php else: ?>
                                    <a href="settings.php?tab=finance&delete_expense=<?= (int) ($cat['id'] ?? 0) ?>&_csrf=<?= csrf_token() ?>"
                                        class="btn btn-no btn-sm btn-icon" title="حذف"
                                        data-confirm="حذف دسته «<?= htmlspecialchars((string) ($cat['label'] ?? '')) ?>»؟">
                                        <?= icon('trash', 13) ?>
                                    </a>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal-veil" id="expenseAddModal">
        <div class="modal" style="max-width:480px">
            <div class="modal-head">
                <h3>افزودن دسته هزینه</h3>
                <button type="button" class="modal-x" onclick="closeModal('expenseAddModal')"><?= icon('close', 14) ?></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="expense_add">
                    <div class="field">
                        <label>نام دسته *</label>
                        <input type="text" name="label" class="input" placeholder="مثلاً اجاره سرور، تبلیغات، ..." required maxlength="64">
                    </div>
                    <div class="field">
                        <label>ترتیب نمایش</label>
                        <input type="number" name="sort_order" class="input" value="0" step="1">
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> ذخیره</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('expenseAddModal')">انصراف</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-veil" id="expenseEditModal">
        <div class="modal" style="max-width:480px">
            <div class="modal-head">
                <h3>ویرایش دسته هزینه</h3>
                <button type="button" class="modal-x" onclick="closeModal('expenseEditModal')"><?= icon('close', 14) ?></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="expense_edit">
                    <input type="hidden" name="edit_id" id="expense_edit_id">
                    <div class="field">
                        <label>نام دسته *</label>
                        <input type="text" name="label" id="expense_edit_label" class="input" required maxlength="64">
                    </div>
                    <div class="field">
                        <label>ترتیب نمایش</label>
                        <input type="number" name="sort_order" id="expense_edit_sort" class="input" step="1">
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> ذخیره تغییرات</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('expenseEditModal')">انصراف</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    window.openExpenseEditModal = function (c) {
        document.getElementById('expense_edit_id').value = c.id || '';
        document.getElementById('expense_edit_label').value = c.label || '';
        document.getElementById('expense_edit_sort').value = c.sort_order != null ? c.sort_order : 0;
        openModal('expenseEditModal');
    };
    window.openIncomeEditModal = function (c) {
        document.getElementById('income_edit_id').value = c.id || '';
        document.getElementById('income_edit_label').value = c.label || '';
        document.getElementById('income_edit_sort').value = c.sort_order != null ? c.sort_order : 0;
        openModal('incomeEditModal');
    };
    </script>

    <div class="modal-veil" id="incomeAddModal">
        <div class="modal" style="max-width:480px">
            <div class="modal-head">
                <h3>افزودن دسته درآمد</h3>
                <button type="button" class="modal-x" onclick="closeModal('incomeAddModal')"><?= icon('close', 14) ?></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="income_add">
                    <div class="field">
                        <label>نام دسته *</label>
                        <input type="text" name="label" class="input" placeholder="مثلاً فروش سخت‌افزار، اجاره، ..." required maxlength="64">
                    </div>
                    <div class="field">
                        <label>ترتیب نمایش</label>
                        <input type="number" name="sort_order" class="input" value="0" step="1">
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> ذخیره</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('incomeAddModal')">انصراف</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-veil" id="incomeEditModal">
        <div class="modal" style="max-width:480px">
            <div class="modal-head">
                <h3>ویرایش دسته درآمد</h3>
                <button type="button" class="modal-x" onclick="closeModal('incomeEditModal')"><?= icon('close', 14) ?></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="income_edit">
                    <input type="hidden" name="edit_id" id="income_edit_id">
                    <div class="field">
                        <label>نام دسته *</label>
                        <input type="text" name="label" id="income_edit_label" class="input" required maxlength="64">
                    </div>
                    <div class="field">
                        <label>ترتیب نمایش</label>
                        <input type="number" name="sort_order" id="income_edit_sort" class="input" step="1">
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> ذخیره تغییرات</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('incomeEditModal')">انصراف</button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($tab === 'security'): ?>

    <div class="two-col">
        <div class="card fade-up">
            <div class="card-head">
                <div>
                    <div class="card-title">تغییر رمز عبور</div>
                    <div class="card-subtitle">برای ورود به پنل</div>
                </div>
            </div>
            <form method="POST" class="card-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="change_password">
                <div style="display:flex;flex-direction:column;gap:14px">
                    <div class="field">
                        <label>رمز فعلی</label>
                        <div style="position:relative">
                            <input type="password" name="current_password" id="pw1" class="input" required
                                autocomplete="current-password" style="padding-left:40px">
                            <button type="button" onclick="togglePw('pw1', this)"
                                style="position:absolute;left:10px;top:50%;transform:translateY(-50%);border:none;background:none;color:var(--dim);cursor:pointer">
                                <?= icon('eye', 16) ?>
                            </button>
                        </div>
                    </div>
                    <div class="field">
                        <label>رمز جدید</label>
                        <div style="position:relative">
                            <input type="password" name="new_password" id="pw2" class="input" minlength="6" required
                                autocomplete="new-password" style="padding-left:40px" oninput="checkPwStr(this.value)">
                            <button type="button" onclick="togglePw('pw2', this)"
                                style="position:absolute;left:10px;top:50%;transform:translateY(-50%);border:none;background:none;color:var(--dim);cursor:pointer">
                                <?= icon('eye', 16) ?>
                            </button>
                        </div>
                        <div style="height:4px;background:var(--sf3);border-radius:99px;margin-top:5px">
                            <div id="pwBar"
                                style="height:100%;width:0;border-radius:99px;transition:all .3s;background:var(--no)">
                            </div>
                        </div>
                        <span id="pwHint" class="field-hint">حداقل ۶ کاراکتر</span>
                    </div>
                    <div class="field">
                        <label>تکرار رمز جدید</label>
                        <input type="password" name="confirm_password" class="input" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary"><?= icon('check', 14) ?> تغییر رمز</button>
                </div>
            </form>
        </div>

        <div class="card fade-up d1" style="height:fit-content">
            <div class="card-head">
                <div>
                    <div class="card-title">نشست فعلی</div>
                </div>
                <a href="logout.php" class="btn btn-no btn-sm"><?= icon('logout', 13) ?> خروج</a>
            </div>
            <div class="kv-list">
                <div class="kv">
                    <span class="kv-key">مدیر</span>
                    <span class="kv-val"><?= htmlspecialchars($_SESSION['admin_user']) ?></span>
                </div>
                <div class="kv">
                    <span class="kv-key">زمان ورود</span>
                    <span class="kv-val">
                        <?= isset($_SESSION['login_time']) ? date('Y/m/d H:i:s', $_SESSION['login_time']) : '—' ?>
                    </span>
                </div>
                <div class="kv">
                    <span class="kv-key">آی‌پی</span>
                    <span class="kv-val cm"><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '—') ?></span>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'system'): ?>

    <div class="card fade-up">
        <div class="card-head">
            <div>
                <div class="card-title">جوین اجباری کانال</div>
                <div class="card-subtitle">کاربر تا وقتی عضو این کانال‌ها نباشد نمی‌تواند از ربات استفاده کند. ربات باید ادمین کانال باشد.</div>
            </div>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:18px">
            <?php if (empty($forced_join_channels)): ?>
                <p style="margin:0;color:var(--mute);font-size:.9rem">هنوز کانالی برای جوین اجباری ثبت نشده است.</p>
            <?php else: ?>
                <div class="tbl-wrap">
                    <table class="tbl-lg">
                        <thead>
                            <tr>
                                <th>نام دکمه</th>
                                <th>شناسه کانال</th>
                                <th>لینک عضویت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forced_join_channels as $ch): ?>
                                <tr>
                                    <td class="cs"><?= htmlspecialchars($ch['remark'] ?? '') ?></td>
                                    <td class="cm" dir="ltr"><?= htmlspecialchars($ch['link'] ?? '') ?></td>
                                    <td class="cn" dir="ltr" style="max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                        <?php if (!empty($ch['linkjoin'])): ?>
                                            <a href="<?= htmlspecialchars($ch['linkjoin']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($ch['linkjoin']) ?></a>
                                        <?php else: ?>
                                            <span style="color:var(--mute)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" onsubmit="return confirm('این کانال از جوین اجباری حذف شود؟')">
                                            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete_forced_join">
                                            <input type="hidden" name="channel_link" value="<?= htmlspecialchars($ch['link'] ?? '') ?>">
                                            <button type="submit" class="btn btn-no btn-sm btn-icon" title="حذف"><?= icon('trash', 13) ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <form method="POST" style="display:flex;flex-direction:column;gap:14px">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="save_forced_join">
                <div class="field">
                    <label>یوزرنیم یا آیدی عددی کانال</label>
                    <input type="text" name="channel_id" class="input" dir="ltr"
                        placeholder="@mychannel یا -1001234567890"
                        autocomplete="off" required>
                    <span class="field-hint">همان شناسه‌ای که ربات با آن عضویت کاربر را از تلگرام چک می‌کند.</span>
                </div>
                <div class="field">
                    <label>نام دکمه عضویت</label>
                    <input type="text" name="remark" class="input" placeholder="مثلاً عضویت در کانال اخبار" maxlength="64" required>
                </div>
                <div class="field">
                    <label>لینک عضویت</label>
                    <input type="text" name="linkjoin" class="input" dir="ltr"
                        placeholder="https://t.me/mychannel یا لینک دعوت"
                        autocomplete="off">
                    <span class="field-hint">برای کانال عمومی اگر خالی بگذارید از روی یوزرنیم ساخته می‌شود. کانال خصوصی حتماً لینک دعوت نیاز دارد.</span>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary"><?= icon('plus', 14) ?> افزودن کانال</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card fade-up d1" style="margin-top:14px">
        <div class="card-head">
            <div>
                <div class="card-title">کانال ارسال پست</div>
                <div class="card-subtitle">آیدی یا یوزرنیم پیش‌فرض برای «پست در کانال» در ربات</div>
            </div>
        </div>
        <form method="POST" class="card-body">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save_channel_post">
            <div style="display:flex;flex-direction:column;gap:14px">
                <div class="field">
                    <label>آیدی عددی یا یوزرنیم کانال</label>
                    <input type="text" name="channel_post" class="input" dir="ltr"
                        value="<?= htmlspecialchars($channel_post_value) ?>"
                        placeholder="@mychannel یا -1001234567890"
                        autocomplete="off">
                    <span class="field-hint">ربات باید ادمین کانال با دسترسی ارسال پیام باشد. خالی بگذارید تا هر بار در ربات پرسیده شود.</span>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="submit" class="btn btn-primary"><?= icon('check', 14) ?> ذخیره</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card fade-up d1" style="margin-top:14px">
        <div class="card-head">
            <div>
                <div class="card-title">اطلاعات محیط</div>
            </div>
        </div>
        <div class="kv-list">
            <?php
            $dbVer = '—';
            try {
                $dbVer = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            } catch (Exception $e) {
            }
            $sysInfo = [
                ['نسخه پنل', 1.0],
                ['PHP', phpversion()],
                ['MySQL', $dbVer],
                ['سرور وب', $_SERVER['SERVER_SOFTWARE'] ?? '—'],
                ['مدیر فعلی', $_SESSION['admin_user']],
                ['زمان سرور', date('Y/m/d H:i:s')],
                ['حافظه PHP', ini_get('memory_limit')],
            ];
            foreach ($sysInfo as [$key, $value]):
                ?>
                <div class="kv">
                    <span class="kv-key"><?= $key ?></span>
                    <span class="kv-val cm" style="font-size:.78rem"><?= htmlspecialchars($value) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

<?php endif; ?>

<script src="<?= htmlspecialchars(panel_asset('js/settings.js')) ?>"></script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>