<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/users_lib.php';
require_auth();
$pdo = panel_ensure_pdo();

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) {
    csrf_check_post();
} else {
    csrf_check_get();
}

$action = $isPost ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');
$id = (int) ($isPost ? ($_POST['id'] ?? 0) : ($_GET['id'] ?? 0));

$allowed_back = ['users.php', 'user.php', 'user_services.php'];
$rawBack = $isPost ? ($_POST['back'] ?? '') : ($_GET['back'] ?? '');
$back = 'users.php';
foreach ($allowed_back as $allowed) {
    if (strpos($rawBack, $allowed) === 0) {
        $base = explode('?', $rawBack)[0];
        if ($base === 'users.php') {
            $back = 'users.php';
        } elseif ($base === 'user_services.php') {
            $back = "user_services.php?id=$id";
        } else {
            $back = $base . ($id ? "?id=$id" : '');
        }
        break;
    }
}

if (!$id) {
    flash('error', 'شناسه کاربر نامعتبر است.');
    header('Location: users.php');
    exit;
}

$user = db_fetch($pdo, "SELECT * FROM user WHERE id = ?", [$id]);
if (!$user) {
    flash('error', 'کاربر یافت نشد.');
    header('Location: users.php');
    exit;
}

function panel_user_action_redirect(string $back, int $id): void
{
    header("Location: $back");
    exit;
}

switch ($action) {
    case 'block':
        if (panel_user_is_blocked($user)) {
            flash('warning', 'کاربر از قبل مسدود بود.');
        } else {
            $reason = trim($isPost ? ($_POST['reason'] ?? '') : ($_GET['reason'] ?? ''));
            db_query($pdo, "UPDATE user SET User_Status = 'block', description_blocking = ? WHERE id = ?", [$reason ?: 'مسدود توسط ادمین', $id]);
            flash('success', "کاربر $id مسدود شد.");
            error_log("Admin {$_SESSION['admin_user']} blocked user $id");
        }
        break;

    case 'unblock':
        if (!panel_user_is_blocked($user)) {
            flash('warning', 'کاربر در وضعیت فعال است.');
        } else {
            db_query($pdo, "UPDATE user SET User_Status = 'active', description_blocking = ' ' WHERE id = ?", [$id]);
            panel_notify_user($id, "✳️ حساب کاربری شما از مسدودی خارج شد ✳️\nاکنون می‌توانید از ربات استفاده کنید ✔️");
            flash('success', "مسدودیت کاربر $id برداشته شد.");
            error_log("Admin {$_SESSION['admin_user']} unblocked user $id");
        }
        break;

    case 'add_balance':
        $amount = (int) ($_POST['amount'] ?? 0);
        if ($amount < 1000 || $amount > 100000000) {
            flash('error', 'مبلغ باید بین ۱٬۰۰۰ تا ۱۰۰٬۰۰۰٬۰۰۰ تومان باشد.');
            break;
        }
        db_query($pdo, "UPDATE user SET Balance = Balance + ? WHERE id = ?", [$amount, $id]);
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
        db_query($pdo, "UPDATE user SET Balance = GREATEST(0, Balance - ?) WHERE id = ?", [$amount, $id]);
        panel_record_admin_balance_change($pdo, $id, $amount, 'low balance by admin');
        panel_notify_user($id, '❌ کاربر عزیز مبلغ ' . number_format($amount) . ' تومان از موجودی کیف پول تان کسر گردید.');
        flash('success', number_format($amount) . ' تومان از موجودی کسر شد.');
        break;

    case 'zero_balance':
        $prev = (int) ($user['Balance'] ?? 0);
        db_query($pdo, "UPDATE user SET Balance = 0 WHERE id = ?", [$id]);
        flash('success', 'موجودی کاربر (' . number_format($prev) . ' تومان) صفر شد.');
        break;

    case 'set_role':
        $newRole = $_POST['new_role'] ?? 'f';
        if (!in_array($newRole, ['f', 'n', 'n2', 'all'], true)) {
            flash('error', 'گروه نامعتبر است.');
            break;
        }
        db_query($pdo, "UPDATE user SET agent = ? WHERE id = ?", [$newRole, $id]);
        if ($newRole === 'f') {
            db_query($pdo, "UPDATE user SET pricediscount = 0, expire = NULL WHERE id = ?", [$id]);
        }
        flash('success', 'گروه کاربری به «' . user_role_label($newRole) . '» تغییر کرد.');
        break;

    case 'remove_agent':
        db_query($pdo, "UPDATE user SET agent = 'f', pricediscount = 0, expire = NULL WHERE id = ?", [$id]);
        try {
            db_query($pdo, "DELETE FROM Requestagent WHERE id = ?", [$id]);
        } catch (Throwable $e) {
        }
        flash('success', 'نمایندگی کاربر حذف شد.');
        break;

    case 'set_discount':
        $percent = (int) ($_POST['percent'] ?? -1);
        if ($percent < 0 || $percent > 100) {
            flash('error', 'درصد تخفیف باید بین ۰ تا ۱۰۰ باشد.');
            break;
        }
        db_query($pdo, "UPDATE user SET pricediscount = ? WHERE id = ?", [$percent, $id]);
        flash('success', 'درصد تخفیف کاربر به ' . $percent . '٪ تنظیم شد.');
        break;

    case 'confirm_number':
        db_query($pdo, "UPDATE user SET number = 'confrim number by admin' WHERE id = ?", [$id]);
        flash('success', 'شماره تماس کاربر توسط ادمین تایید شد.');
        break;

    case 'verify':
        db_query($pdo, "UPDATE user SET verify = '1' WHERE id = ?", [$id]);
        panel_notify_user($id, '💎 کاربر گرامی حساب کاربری شما توسط ادمین با موفقیت احراز هویت گردید.');
        flash('success', 'کاربر احراز هویت شد.');
        break;

    case 'unverify':
        db_query($pdo, "UPDATE user SET verify = '0' WHERE id = ?", [$id]);
        flash('success', 'احراز هویت کاربر لغو شد.');
        break;

    case 'show_card':
        db_query($pdo, "UPDATE user SET cardpayment = '1' WHERE id = ?", [$id]);
        panel_notify_user($id, '💳 کاربر عزیز شماره کارت برای شما فعال شد هم‌اکنون می‌توانید خرید خود را انجام دهید.');
        flash('success', 'نمایش شماره کارت برای کاربر فعال شد.');
        break;

    case 'hide_card':
        db_query($pdo, "UPDATE user SET cardpayment = '0' WHERE id = ?", [$id]);
        flash('success', 'نمایش شماره کارت برای کاربر غیرفعال شد.');
        break;

    case 'confirm_channel':
        db_query($pdo, "UPDATE user SET joinchannel = 'active' WHERE id = ?", [$id]);
        flash('success', 'عضویت کانال برای کاربر تایید شد.');
        break;

    case 'toggle_cron':
        $newVal = (int) ($user['status_cron'] ?? 0) === 1 ? '0' : '1';
        db_query($pdo, "UPDATE user SET status_cron = ? WHERE id = ?", [$newVal, $id]);
        flash('success', $newVal === '1' ? 'اعلان‌های کرون فعال شد.' : 'اعلان‌های کرون غیرفعال شد.');
        break;

    case 'remove_affiliate':
        if (!empty($user['affiliates']) && $user['affiliates'] !== '0') {
            $parent = db_fetch($pdo, "SELECT id, affiliatescount FROM user WHERE id = ?", [$user['affiliates']]);
            if ($parent) {
                $count = max(0, (int) ($parent['affiliatescount'] ?? 0) - 1);
                db_query($pdo, "UPDATE user SET affiliatescount = ? WHERE id = ?", [$count, $parent['id']]);
            }
        }
        db_query($pdo, "UPDATE user SET affiliates = '0' WHERE id = ?", [$id]);
        flash('success', 'کاربر از زیرمجموعه خارج شد.');
        break;

    case 'clear_affiliates':
        db_query($pdo, "UPDATE user SET affiliatescount = 0 WHERE id = ?", [$id]);
        db_query($pdo, "UPDATE user SET affiliates = '0' WHERE affiliates = ?", [(string) $id]);
        flash('success', 'زیرمجموعه‌های کاربر حذف شدند.');
        break;

    case 'set_test_limit':
        $limit = trim($_POST['limit'] ?? '');
        if ($limit === '' || !ctype_digit($limit)) {
            flash('error', 'محدودیت اکانت تست باید عدد باشد.');
            break;
        }
        db_query($pdo, "UPDATE user SET limit_usertest = ? WHERE id = ?", [$limit, $id]);
        flash('success', 'محدودیت اکانت تست به ' . number_format((int) $limit) . ' تنظیم شد.');
        break;

    case 'set_max_buy_agent':
        $max = trim($_POST['max'] ?? '');
        if ($max === '' || !ctype_digit($max)) {
            flash('error', 'سقف خرید باید عدد باشد (۰ = نامحدود).');
            break;
        }
        db_query($pdo, "UPDATE user SET maxbuyagent = ? WHERE id = ?", [$max, $id]);
        flash('success', 'سقف خرید نماینده تنظیم شد.');
        break;

    case 'set_agent_expire':
        $days = (int) ($_POST['days'] ?? 0);
        if ($days < 1) {
            flash('error', 'تعداد روز باید حداقل ۱ باشد.');
            break;
        }
        $expire = time() + ($days * 86400);
        db_query($pdo, "UPDATE user SET expire = ? WHERE id = ?", [$expire, $id]);
        flash('success', 'انقضای نمایندگی ' . $days . ' روز دیگر تنظیم شد.');
        break;

    case 'send_message':
        $text = trim($_POST['message'] ?? '');
        if ($text === '') {
            flash('error', 'متن پیام خالی است.');
            break;
        }
        $prefix = "👤 یک پیام از طرف ادمین ارسال شده است\n\nمتن پیام:\n\n";
        panel_notify_user($id, $prefix . htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        flash('success', 'پیام برای کاربر ارسال شد.');
        break;

    default:
        flash('error', 'عملیات نامعتبر است.');
}

panel_user_action_redirect($back, $id);
