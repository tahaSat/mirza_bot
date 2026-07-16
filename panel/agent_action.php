<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/users_lib.php';
require_auth();
$pdo = panel_ensure_pdo();
agent_ensure_volume_columns();

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) {
    csrf_check_post();
} else {
    csrf_check_get();
}

$action = $isPost ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');
$id = (int) ($isPost ? ($_POST['id'] ?? 0) : ($_GET['id'] ?? 0));

$allowed_back = ['agents.php', 'agent.php'];
$rawBack = $isPost ? ($_POST['back'] ?? '') : ($_GET['back'] ?? '');
$back = 'agents.php';
foreach ($allowed_back as $allowed) {
    if (strpos($rawBack, $allowed) === 0) {
        $base = explode('?', $rawBack)[0];
        if ($base === 'agents.php') {
            $back = 'agents.php';
        } else {
            $back = 'agent.php' . ($id ? "?id=$id" : '');
        }
        break;
    }
}

$projectRoot = dirname(__DIR__);

function agent_action_redirect(string $back): void
{
    header("Location: $back");
    exit;
}

if ($action === 'promote') {
    $telegramId = trim((string) ($_POST['telegram_id'] ?? ''));
    $newRole = $_POST['new_role'] ?? 'n';
    if (!ctype_digit($telegramId)) {
        flash('error', 'آیدی تلگرام نامعتبر است.');
        agent_action_redirect('agents.php');
    }
    if (!in_array($newRole, ['n', 'n2'], true)) {
        flash('error', 'نقش نامعتبر است.');
        agent_action_redirect('agents.php');
    }
    $user = db_fetch($pdo, 'SELECT * FROM user WHERE id = ?', [(int) $telegramId]);
    if (!$user) {
        flash('error', 'کاربری با این آیدی در ربات یافت نشد.');
        agent_action_redirect('agents.php');
    }
    db_query($pdo, 'UPDATE user SET agent = ?, expire = NULL WHERE id = ?', [$newRole, (int) $telegramId]);
    flash('success', 'کاربر به «' . user_role_label($newRole) . '» تبدیل شد.');
    header('Location: agent.php?id=' . (int) $telegramId);
    exit;
}

if (!$id && $action !== 'promote') {
    flash('error', 'شناسه کاربر نامعتبر است.');
    agent_action_redirect('agents.php');
}

$user = $id ? db_fetch($pdo, 'SELECT * FROM user WHERE id = ?', [$id]) : null;
if ($id && !$user) {
    flash('error', 'کاربر یافت نشد.');
    agent_action_redirect('agents.php');
}

switch ($action) {
    case 'set_role':
        $newRole = $_POST['new_role'] ?? 'f';
        if (!in_array($newRole, ['f', 'n', 'n2'], true)) {
            flash('error', 'نقش نامعتبر است.');
            break;
        }
        if ($newRole === 'f') {
            db_query($pdo, "UPDATE user SET agent = 'f', pricediscount = 0, expire = NULL WHERE id = ?", [$id]);
            try {
                db_query($pdo, 'DELETE FROM Requestagent WHERE id = ?', [$id]);
            } catch (Throwable $e) {
            }
            flash('success', 'نمایندگی حذف شد.');
            $back = 'agents.php';
        } else {
            db_query($pdo, 'UPDATE user SET agent = ? WHERE id = ?', [$newRole, $id]);
            flash('success', 'نقش به «' . user_role_label($newRole) . '» تغییر کرد.');
        }
        break;

    case 'set_volume_remaining':
        $volume = (int) ($_POST['volume'] ?? -1);
        if ($volume < 0) {
            flash('error', 'حجم نامعتبر است.');
            break;
        }
        db_query($pdo, 'UPDATE user SET agent_volume_remaining = ? WHERE id = ?', [(string) $volume, $id]);
        flash('success', 'حجم باقیمانده به ' . number_format($volume) . ' گیگ تنظیم شد.');
        break;

    case 'add_volume':
        $volume = (int) ($_POST['volume'] ?? 0);
        if ($volume < 1) {
            flash('error', 'مقدار افزودن باید حداقل ۱ گیگ باشد.');
            break;
        }
        $current = (int) ($user['agent_volume_remaining'] ?? 0);
        db_query($pdo, 'UPDATE user SET agent_volume_remaining = ? WHERE id = ?', [(string) ($current + $volume), $id]);
        flash('success', number_format($volume) . ' گیگ به سهمیه افزوده شد.');
        break;

    case 'set_price_per_gb':
        $price = (int) ($_POST['price'] ?? -1);
        if ($price < 0) {
            flash('error', 'قیمت نامعتبر است.');
            break;
        }
        db_query($pdo, 'UPDATE user SET agent_price_per_gb = ? WHERE id = ?', [(string) $price, $id]);
        flash('success', 'قیمت هر گیگ به ' . number_format($price) . ' تومان تنظیم شد.');
        break;

    case 'set_max_buy':
        $max = (int) ($_POST['max'] ?? -1);
        if ($max < 0) {
            flash('error', 'سقف نامعتبر است.');
            break;
        }
        db_query($pdo, 'UPDATE user SET maxbuyagent = ? WHERE id = ?', [(string) $max, $id]);
        flash('success', 'سقف خرید نماینده ذخیره شد.');
        break;

    case 'set_expire':
        $days = (int) ($_POST['days'] ?? -1);
        if ($days < 0) {
            flash('error', 'تعداد روز نامعتبر است.');
            break;
        }
        if ($days === 0) {
            db_query($pdo, 'UPDATE user SET expire = NULL WHERE id = ?', [$id]);
            flash('success', 'انقضای نمایندگی حذف شد.');
        } else {
            $ts = time() + ($days * 86400);
            db_query($pdo, 'UPDATE user SET expire = ? WHERE id = ?', [(string) $ts, $id]);
            flash('success', "انقضای نمایندگی $days روز دیگر تنظیم شد.");
        }
        break;

    case 'add_balance':
        $amount = (int) ($_POST['amount'] ?? 0);
        if ($amount < 1000 || $amount > 100000000) {
            flash('error', 'مبلغ باید بین ۱٬۰۰۰ تا ۱۰۰٬۰۰۰٬۰۰۰ تومان باشد.');
            break;
        }
        db_query($pdo, 'UPDATE user SET Balance = Balance + ? WHERE id = ?', [$amount, $id]);
        panel_record_admin_balance_change($pdo, $id, $amount, 'add balance by admin');
        panel_notify_user($id, '💎 کاربر عزیز مبلغ ' . number_format($amount) . ' تومان به موجودی کیف پول تان اضافه گردید.');
        flash('success', number_format($amount) . ' تومان به موجودی افزوده شد.');
        break;

    case 'low_balance':
        $amount = (int) ($_POST['amount'] ?? 0);
        if ($amount < 1 || $amount > 100000000) {
            flash('error', 'مبلغ نامعتبر است.');
            break;
        }
        db_query($pdo, 'UPDATE user SET Balance = GREATEST(0, Balance - ?) WHERE id = ?', [$amount, $id]);
        panel_record_admin_balance_change($pdo, $id, $amount, 'low balance by admin');
        panel_notify_user($id, '❌ کاربر عزیز مبلغ ' . number_format($amount) . ' تومان از موجودی کیف پول تان کسر گردید.');
        flash('success', number_format($amount) . ' تومان از موجودی کسر شد.');
        break;

    case 'create_bot':
        if (!agent_is_reseller($user['agent'] ?? 'f')) {
            flash('error', 'ابتدا نقش نمایندگی را تنظیم کنید.');
            break;
        }
        $token = trim((string) ($_POST['token'] ?? ''));
        $result = agent_create_sell_bot($id, $token, $projectRoot);
        flash($result['ok'] ? 'success' : 'error', $result['msg'] . (!empty($result['username']) ? ' (@' . $result['username'] . ')' : ''));
        break;

    case 'remove_bot':
        $result = agent_remove_sell_bot($id, $projectRoot);
        flash($result['ok'] ? 'success' : 'error', $result['msg']);
        break;

    case 'set_bot_min_volume':
        $amount = (int) ($_POST['amount'] ?? -1);
        if ($amount < 0) {
            flash('error', 'مبلغ نامعتبر است.');
            break;
        }
        $row = db_fetch($pdo, 'SELECT setting FROM botsaz WHERE id_user = ?', [(string) $id]);
        if (!$row) {
            flash('error', 'ربات فروش یافت نشد.');
            break;
        }
        $setting = json_decode($row['setting'] ?? '{}', true) ?: [];
        $setting['minpricevolume'] = $amount;
        db_query($pdo, 'UPDATE botsaz SET setting = ? WHERE id_user = ?', [json_encode($setting), (string) $id]);
        flash('success', 'حداقل قیمت حجم ذخیره شد.');
        break;

    case 'set_bot_min_time':
        $amount = (int) ($_POST['amount'] ?? -1);
        if ($amount < 0) {
            flash('error', 'مبلغ نامعتبر است.');
            break;
        }
        $row = db_fetch($pdo, 'SELECT setting FROM botsaz WHERE id_user = ?', [(string) $id]);
        if (!$row) {
            flash('error', 'ربات فروش یافت نشد.');
            break;
        }
        $setting = json_decode($row['setting'] ?? '{}', true) ?: [];
        $setting['minpricetime'] = $amount;
        db_query($pdo, 'UPDATE botsaz SET setting = ? WHERE id_user = ?', [json_encode($setting), (string) $id]);
        flash('success', 'حداقل قیمت زمان ذخیره شد.');
        break;

    case 'set_hide_panels':
        $panels = $_POST['panels'] ?? [];
        if (!is_array($panels)) {
            $panels = [];
        }
        $panels = array_values(array_filter(array_map('strval', $panels)));
        $exists = db_fetch($pdo, 'SELECT id FROM botsaz WHERE id_user = ?', [(string) $id]);
        if (!$exists) {
            flash('error', 'ربات فروش یافت نشد.');
            break;
        }
        db_query($pdo, 'UPDATE botsaz SET hide_panel = ? WHERE id_user = ?', [json_encode($panels, JSON_UNESCAPED_UNICODE), (string) $id]);
        flash('success', 'پنل‌های مخفی ذخیره شدند.');
        break;

    default:
        flash('error', 'عملیات نامعتبر است.');
        break;
}

agent_action_redirect($back);
