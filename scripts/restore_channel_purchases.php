<?php
/**
 * Rebuild bot users + invoices from post-backup Telegram report logs.
 * VPN accounts are looked up on the live panel (Pasarguard). If they still
 * exist, only DB rows are inserted. If not, a matching panel user is created.
 *
 * On the server:
 *   php scripts/restore_channel_purchases.php
 *   php scripts/restore_channel_purchases.php --apply
 *   php scripts/restore_channel_purchases.php --apply --notify
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/jdf.php';
require_once dirname(__DIR__) . '/function.php';
require_once dirname(__DIR__) . '/botapi.php';
require_once dirname(__DIR__) . '/panels.php';

$apply = in_array('--apply', $argv, true);
$notify = in_array('--notify', $argv, true);

$purchases = [
    [
        'user_id' => '7460303384',
        'tg_username' => 'NOT_USERNAME',
        'config' => 'pichanet_f032',
        'location' => 'اینترنت معمولی (شرایط عادی)',
        'days' => 30,
        'product' => '25 گیگ — 120,000 تومان',
        'volume' => '25',
        'price' => '120000',
        'final_price' => '120774',
        'id_invoice' => '6b9089cf',
        'balance_after' => '0',
        'jalali' => '1405/05/24 20:14:54',
    ],
    [
        'user_id' => '7828346302',
        'tg_username' => 'meri_tii',
        'config' => 'pichanet_408f',
        'location' => 'اینترنت معمولی (شرایط عادی)',
        'days' => 30,
        'product' => '10 گیگ — 50,000 تومان',
        'volume' => '10',
        'price' => '50000',
        'final_price' => '51326',
        'id_invoice' => '77b4d56c',
        'balance_after' => '0',
        'jalali' => '1405/05/24 21:22:36',
    ],
    [
        'user_id' => '8868876312',
        'tg_username' => 'CBlueSky0',
        'config' => 'pichanet_4735',
        'location' => 'اینترنت معمولی (شرایط عادی)',
        'days' => 30,
        'product' => '25 گیگ — 120,000 تومان',
        'volume' => '25',
        'price' => '120000',
        'final_price' => '121529',
        'id_invoice' => '74292336',
        'balance_after' => '0',
        'jalali' => '1405/05/25 00:16:46',
    ],
    [
        'user_id' => '5746883132',
        'tg_username' => 'mari_22106',
        'config' => 'pichanet_78a7',
        'location' => 'اینترنت معمولی (شرایط عادی)',
        'days' => 30,
        'product' => '25 گیگ — 120,000 تومان',
        'volume' => '25',
        'price' => '120000',
        'final_price' => '120434',
        'id_invoice' => '209a41a9',
        'balance_after' => '0',
        'jalali' => '1405/05/25 00:30:00',
    ],
    [
        'user_id' => '978386454',
        'tg_username' => 'sh_hdpr',
        'config' => 'pichanet_db51',
        'location' => 'اینترنت معمولی (شرایط عادی)',
        'days' => 30,
        'product' => '40 گیگ — 170,000 تومان',
        'volume' => '40',
        'price' => '170000',
        'final_price' => '171109',
        'id_invoice' => 'c3c1a18d',
        'balance_after' => '0',
        'jalali' => '1405/05/25 00:30:05',
    ],
    [
        'user_id' => '1234756205',
        'tg_username' => 'YOmamad',
        'config' => 'pichanet_2cd4',
        'location' => 'اینترنت معمولی (شرایط عادی)',
        'days' => 30,
        'product' => '80 گیگ — 310,000 تومان',
        'volume' => '80',
        'price' => '310000',
        'final_price' => '310861',
        'id_invoice' => '1959f8e3',
        'balance_after' => '0',
        'jalali' => '1405/05/25 00:32:15',
    ],
    [
        'user_id' => '906027978',
        'tg_username' => 'mhywwr',
        'config' => 'pichanet_b99e',
        'location' => 'اینترنت معمولی (شرایط عادی)',
        'days' => 30,
        'product' => '10 گیگ — 50,000 تومان',
        'volume' => '10',
        'price' => '50000',
        'final_price' => '50000',
        'id_invoice' => '264d621e',
        'balance_after' => '3299',
        'jalali' => '1405/05/25 00:44:14',
        'first_name' => 'Mahyar',
        'category' => '🚀 حجمی ماهانه (نت معمولی)',
        'wallet' => true,
    ],
];

function restore_jalali_to_unix(string $jalali): int
{
    $jalali = tr_num($jalali, 'en');
    if (!preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})\s+(\d{1,2}):(\d{1,2}):(\d{1,2})$#', $jalali, $m)) {
        return time();
    }
    [$gy, $gm, $gd] = jalali_to_gregorian((int) $m[1], (int) $m[2], (int) $m[3]);
    $tz = new DateTimeZone('Asia/Tehran');
    $dt = new DateTime(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $gy, $gm, $gd, $m[4], $m[5], $m[6]), $tz);
    return $dt->getTimestamp();
}

function restore_ensure_user(PDO $pdo, array $row, array $setting): void
{
    $existing = select('user', 'id', 'id', $row['user_id'], 'select');
    if ($existing) {
        if ($row['tg_username'] !== 'NOT_USERNAME') {
            update('user', 'username', $row['tg_username'], 'id', $row['user_id']);
        }
        update('user', 'Balance', $row['balance_after'], 'id', $row['user_id']);
        return;
    }
    $verify = ($setting['verifystart'] ?? '') !== 'onverify' ? '1' : '0';
    $invite = bin2hex(random_bytes(6));
    $stmt = $pdo->prepare("INSERT IGNORE INTO user (id, step, limit_usertest, User_Status, number, Balance, pagenumber, username, agent, message_count, last_message_time, affiliates, affiliatescount, cardpayment, number_username, namecustom, register, verify, codeInvitation, pricediscount, maxbuyagent, joinchannel, score, status_cron) VALUES (:id, 'none', :limit_usertest, 'Active', 'none', :balance, '1', :username, 'f', '0', '0', '0', '0', :showcard, '100', 'none', :register, :verify, :invite, '0', '0', '0', '0', '1')");
    $stmt->execute([
        ':id' => $row['user_id'],
        ':limit_usertest' => $setting['limit_usertest_all'] ?? '1',
        ':balance' => $row['balance_after'],
        ':username' => $row['tg_username'],
        ':showcard' => $setting['showcard'] ?? '1',
        ':register' => (string) $row['time_sell'],
        ':verify' => $verify,
        ':invite' => $invite,
    ]);
}

function restore_panel_user(ManagePanel $panel, array $row): array
{
    $names = array_unique([$row['config'], '@' . ltrim($row['config'], '@')]);
    $last = null;
    foreach ($names as $name) {
        $data = $panel->DataUser($row['location'], $name);
        $last = $data;
        if (is_array($data) && ($data['status'] ?? '') !== 'Unsuccessful' && (!empty($data['subscription_url']) || !empty($data['links']))) {
            return ['ok' => true, 'created' => false, 'data' => $data, 'config' => $name];
        }
    }
    $expire = $row['time_sell'] + ((int) $row['days'] * 86400);
    $limit = (int) $row['volume'] * 1024 * 1024 * 1024;
    $created = $panel->createUser($row['location'], 'customvolume', $row['config'], [
        'expire' => $expire,
        'data_limit' => $limit,
        'from_id' => $row['user_id'],
        'username' => $row['tg_username'],
        'type' => 'channel-log-restore',
    ]);
    if (!is_array($created) || ($created['status'] ?? '') === 'Unsuccessful') {
        $msg = $created['msg'] ?? json_encode($created, JSON_UNESCAPED_UNICODE);
        if (is_array($last) && !empty($last['msg'])) {
            $msg .= ' | lookup: ' . (is_string($last['msg']) ? $last['msg'] : json_encode($last['msg'], JSON_UNESCAPED_UNICODE));
        }
        return ['ok' => false, 'created' => false, 'msg' => $msg, 'data' => $last];
    }
    return ['ok' => true, 'created' => true, 'data' => $created, 'config' => $row['config']];
}

function restore_ensure_payment(PDO $pdo, array $row): void
{
    $exists = select('Payment_report', 'id', 'id_invoice', $row['id_invoice'], 'select');
    if ($exists) {
        echo "payment already exists, skip\n";
        return;
    }
    $order = 'restore-' . $row['id_invoice'];
    $existsOrder = select('Payment_report', 'id', 'id_order', $order, 'select');
    if ($existsOrder) {
        echo "payment order already exists, skip\n";
        return;
    }
    $method = !empty($row['wallet']) ? 'wallet' : 'after pay';
    $time = date('Y/m/d H:i:s', (int) $row['time_sell']);
    $stmt = $pdo->prepare('INSERT INTO Payment_report (id_user, id_order, time, at_updated, price, Payment_Method, payment_Status, bottype, id_invoice) VALUES (:id_user, :id_order, :time, :at_updated, :price, :method, :status, :bottype, :id_invoice)');
    $stmt->execute([
        ':id_user' => $row['user_id'],
        ':id_order' => $order,
        ':time' => $time,
        ':at_updated' => $time,
        ':price' => $row['final_price'],
        ':method' => $method,
        ':status' => 'paid',
        ':bottype' => 'main',
        ':id_invoice' => $row['id_invoice'],
    ]);
    echo "payment inserted ({$method} {$row['final_price']})\n";
}

$setting = select('setting', '*') ?: [];
$ManagePanel = new ManagePanel();
$notif = json_encode(['volume' => false, 'time' => false]);

echo $apply ? "APPLY mode\n" : "DRY RUN (pass --apply to write)\n";

foreach ($purchases as $row) {
    $row['time_sell'] = restore_jalali_to_unix($row['jalali']);
    $sub = 'https://' . $domainhosts . '/sub/' . $row['id_invoice'];
    echo "\n--- {$row['id_invoice']} {$row['config']} user {$row['user_id']} ---\n";

    $existingInvoice = select('invoice', '*', 'id_invoice', $row['id_invoice'], 'select');
    if ($existingInvoice) {
        echo "invoice already exists\n";
        echo "sub: {$sub}\n";
        if ($apply) {
            restore_ensure_payment($pdo, $row);
        }
        continue;
    }
    $byUser = select('invoice', '*', 'username', $row['config'], 'select');
    if ($byUser) {
        echo "invoice already exists for config username ({$byUser['id_invoice']})\n";
        echo "sub: https://{$domainhosts}/sub/{$byUser['id_invoice']}\n";
        if ($apply) {
            $row['id_invoice'] = $byUser['id_invoice'];
            restore_ensure_payment($pdo, $row);
        }
        continue;
    }

    $panel = restore_panel_user($ManagePanel, $row);
    if (!$panel['ok']) {
        echo "PANEL FAIL: {$panel['msg']}\n";
        continue;
    }
    echo $panel['created'] ? "panel user CREATED\n" : "panel user FOUND\n";
    if (!empty($panel['config'])) {
        $row['config'] = $panel['config'];
    }
    $subUrl = $panel['data']['subscription_url'] ?? $sub;
    $uuid = $panel['data']['username'] ?? $row['config'];
    if (!empty($panel['data']['uuid'])) {
        $uuid = is_string($panel['data']['uuid']) ? $panel['data']['uuid'] : json_encode($panel['data']['uuid']);
    }

    echo "user_info/sub: {$subUrl}\n";
    echo "bot sub: {$sub}\n";
    echo "payment: " . (!empty($row['wallet']) ? 'wallet' : 'after pay') . " {$row['final_price']}\n";

    if (!$apply) {
        continue;
    }

    restore_ensure_user($pdo, $row, $setting);

    $stmt = $pdo->prepare('INSERT INTO invoice (id_invoice, id_user, username, Service_location, time_sell, name_product, price_product, Volume, Service_time, uuid, note, user_info, bottype, refral, time_cron, notifctions, Status) VALUES (:id_invoice, :id_user, :username, :loc, :time_sell, :name_product, :price, :volume, :days, :uuid, :note, :user_info, :bottype, :refral, :time_cron, :notif, :status)');
    $stmt->execute([
        ':id_invoice' => $row['id_invoice'],
        ':id_user' => $row['user_id'],
        ':username' => ltrim($row['config'], '@'),
        ':loc' => $row['location'],
        ':time_sell' => (string) $row['time_sell'],
        ':name_product' => $row['product'],
        ':price' => $row['price'],
        ':volume' => $row['volume'],
        ':days' => (string) $row['days'],
        ':uuid' => (string) $uuid,
        ':note' => 'restored-from-channel-log',
        ':user_info' => $sub,
        ':bottype' => 'main',
        ':refral' => '0',
        ':time_cron' => (string) time(),
        ':notif' => $notif,
        ':status' => 'active',
    ]);
    echo "invoice inserted\n";
    restore_ensure_payment($pdo, $row);

    if ($notify) {
        $text = "✅ سرویس شما بازیابی شد\n\n"
            . "📌 نام کانفیگ: <code>{$row['config']}</code>\n"
            . "🔗 لینک اشتراک:\n<code>{$sub}</code>";
        sendmessage($row['user_id'], $text, null, 'HTML');
        echo "notified {$row['user_id']}\n";
    }
}

echo "\nDone.\n";
