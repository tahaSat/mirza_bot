<?php

function readJsonFileIfExists($path, $default = [])
{
    if (!is_file($path)) {
        return $default;
    }

    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        return $default;
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : $default;
}

function DirectPaymentbot($order_id, $image = 'images.jpg')
{
    global $pdo, $ManagePanel, $textbotlang, $keyboard, $Confirm_pay, $from_id, $message_id, $ApiToken, $Pathfiles, $settingmain, $admin_ids, $buyreport, $errorreport;

    $Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
    if ($Payment_report == false || !is_array($Payment_report)) {
        return;
    }
    $format_price_cart = number_format($Payment_report['price']);
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    if ($Balance_id == false || !is_array($Balance_id)) {
        return;
    }

    $uid = $Payment_report['id_user'];
    $balPath = "data/{$uid}/{$uid}.json";
    $userbalance = readJsonFileIfExists($balPath, ['Balance' => 0]);
    $oldBalance = (int) ($userbalance['Balance'] ?? 0);
    $Balance_id['Balance'] = $oldBalance;

    update("user", "Processing_value", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_one", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_tow", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_four", "0", "id", $Balance_id['id']);
    update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);

    $steppay = explode("|", (string) ($Payment_report['id_invoice'] ?? '0 | 0'));
    $isBuyAfterPay = trim($steppay[0] ?? '') === 'getconfigafterpay' && trim($steppay[1] ?? '') !== '';

    // Credit paid amount into sell-bot wallet JSON
    $credited = $oldBalance + (int) $Payment_report['price'];
    $userbalance['Balance'] = $credited;
    if (!is_dir("data/{$uid}")) {
        @mkdir("data/{$uid}", 0755, true);
    }
    file_put_contents($balPath, json_encode($userbalance));

    if ($isBuyAfterPay) {
        $username_ac = trim($steppay[1]);
        $bottype = $Payment_report['bottype'] ?? $ApiToken;
        $stmt = $pdo->prepare("SELECT * FROM invoice WHERE username = :username AND bottype = :bottype ORDER BY time_sell DESC LIMIT 1");
        $stmt->execute([':username' => $username_ac, ':bottype' => $bottype]);
        $get_invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$get_invoice) {
            $get_invoice = select("invoice", "*", "username", $username_ac, "select");
        }

        if (!$get_invoice) {
            sendmessage($uid, "💎 مبلغ {$format_price_cart} تومان به کیف پول شما واریز شد، اما فاکتور سرویس یافت نشد. با پشتیبانی در ارتباط باشید.\n🛒 کد پیگیری: {$Payment_report['id_order']}", $keyboard, 'HTML');
            if ($Payment_report['Payment_Method'] == "cart to cart" || $Payment_report['Payment_Method'] == "arze digital offline") {
                $textconfrom = "✅ پرداخت تایید شد (افزایش موجودی — فاکتور یافت نشد)
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💸 مبلغ پرداختی: $format_price_cart تومان";
                if (!empty($from_id) && !empty($message_id)) {
                    Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
                }
            }
            return;
        }

        $marzban_list_get = select("marzban_panel", "*", "name_panel", $get_invoice['Service_location'], "select");
        $codeProduct = 'customvolume';
        if ($get_invoice['name_product'] == "🛍 حجم دلخواه" || $get_invoice['name_product'] == "⚙️ سرویس دلخواه" || $get_invoice['name_product'] == ($textbotlang['users']['customsellvolume']['title'] ?? '')) {
            $codeProduct = 'customvolume';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name_product AND (Location = :Service_location OR Location = '/all') LIMIT 1");
            $stmt->execute([
                ':name_product' => $get_invoice['name_product'],
                ':Service_location' => $get_invoice['Service_location'],
            ]);
            $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$info_product) {
                $ownerBot = select('botsaz', '*', 'bot_token', $bottype, 'select');
                if ($ownerBot && !empty($ownerBot['id_user'])) {
                    foreach (agent_own_list_products($ownerBot['id_user'], $get_invoice['Service_location']) as $ownRow) {
                        if (($ownRow['name_product'] ?? '') === ($get_invoice['name_product'] ?? '')) {
                            $info_product = $ownRow;
                            break;
                        }
                    }
                }
            }
            $codeProduct = $info_product['code_product'] ?? 'customvolume';
        }

        $date = strtotime("+" . $get_invoice['Service_time'] . "days");
        $timestamp = intval($get_invoice['Service_time']) == 0 ? 0 : strtotime(date("Y-m-d H:i:s", $date));
        $datac = [
            'expire' => $timestamp,
            'data_limit' => $get_invoice['Volume'] * pow(1024, 3),
            'from_id' => $Balance_id['id'],
            'username' => $Balance_id['username'],
            'type' => 'buy_agent_user_bot',
        ];
        $dataoutput = $ManagePanel->createUser($marzban_list_get['name_panel'], $codeProduct, $username_ac, $datac);

        if (($dataoutput['username'] ?? null) == null) {
            $msgFail = is_string($dataoutput['msg'] ?? null) ? $dataoutput['msg'] : json_encode($dataoutput['msg'] ?? '');
            if (stripos((string) $msgFail, 'already exists') !== false || stripos((string) $msgFail, 'duplicate') !== false) {
                $existing = $ManagePanel->DataUser($marzban_list_get['name_panel'], $username_ac);
                if (($existing['status'] ?? '') !== 'Unsuccessful') {
                    $dataoutput = [
                        'status' => 'successful',
                        'username' => $existing['username'] ?? $username_ac,
                        'subscription_url' => $existing['subscription_url'] ?? '',
                        'configs' => $existing['configs'] ?? ($existing['links'] ?? []),
                    ];
                }
            }
        }

        if (($dataoutput['username'] ?? null) == null) {
            $dataoutput['msg'] = json_encode($dataoutput['msg'] ?? '');
            sendmessage($uid, $textbotlang['users']['sell']['ErrorConfig'], $keyboard, 'HTML');
            sendmessage($uid, "💎 مبلغ {$format_price_cart} تومان به کیف پول شما اضافه شد (ساخت سرویس ناموفق).", $keyboard, 'HTML');
            if (strlen($settingmain['Channel_Report'] ?? '') > 0) {
                telegram('sendmessage', [
                    'chat_id' => $settingmain['Channel_Report'],
                    'message_thread_id' => $errorreport ?? null,
                    'text' => "⭕️ خطا در ساخت کانفیگ ربات نماینده بعد پرداخت\n{$dataoutput['msg']}\nuser: {$Balance_id['id']}\npanel: {$marzban_list_get['name_panel']}",
                    'parse_mode' => "HTML",
                ], $GLOBALS['APIKEY'] ?? null);
            }
            if (!empty($from_id) && !empty($message_id)) {
                Editmessagetext($from_id, $message_id, "✅ پرداخت تایید شد ولی ساخت سرویس ناموفق بود. مبلغ به کیف پول کاربر واریز شد.\n👤 {$Balance_id['id']}\n💸 $format_price_cart", $Confirm_pay);
            }
            return;
        }

        // Deduct product price from credited wallet
        $afterBuy = $credited - (int) $get_invoice['price_product'];
        if ($afterBuy < 0) {
            $afterBuy = 0;
        }
        $userbalance['Balance'] = $afterBuy;
        file_put_contents($balPath, json_encode($userbalance));
        update("invoice", "Status", "active", "id_invoice", $get_invoice['id_invoice']);

        $botbalance = select("botsaz", "*", "bot_token", $bottype, "select");
        $userbotbalance = $botbalance ? select("user", "*", "id", $botbalance['id_user'], "select") : null;
        if ($botbalance) {
            agent_consume_volume($botbalance['id_user'], (int) $get_invoice['Volume']);
            if ($userbotbalance && agent_is_n2($userbotbalance['agent'] ?? 'f')) {
                agent_n2_log_purchase([
                    'agent_id' => $botbalance['id_user'],
                    'code_product' => $codeProduct,
                    'name_product' => $get_invoice['name_product'] ?? '',
                    'volume' => $get_invoice['Volume'] ?? '',
                    'service_time' => $get_invoice['Service_time'] ?? '',
                    'panel' => $marzban_list_get['name_panel'] ?? '',
                    'username_service' => $username_ac,
                    'id_invoice' => $get_invoice['id_invoice'] ?? '',
                    'price_product' => $get_invoice['price_product'] ?? '0',
                    'created_at' => time(),
                ]);
            }
        }

        $output_config_link = "";
        $config = "";
        $configqr = "";
        if (($marzban_list_get['sublink'] ?? '') == "onsublink") {
            $output_config_link = $dataoutput['subscription_url'] ?? '';
        }
        if (($marzban_list_get['config'] ?? '') == "onconfig") {
            if (isset($dataoutput['configs']) && is_array($dataoutput['configs']) && count($dataoutput['configs']) != 0) {
                foreach ($dataoutput['configs'] as $configs) {
                    $config .= "\n" . $configs;
                    $configqr .= $configs;
                }
            }
        }
        $textcreatuser = "✅ سرویس با موفقیت ایجاد شد

👤 نام کاربری سرویس : {username}
🌿 نام سرویس:  {name_service}
‏🇺🇳 لوکیشن: {location}
⏳ مدت زمان: {day}  روز
🗜 حجم سرویس:  {volume} گیگابایت

لینک اتصال:
{config}
{links}
";
        $textcreatuser = str_replace('{username}', $dataoutput['username'], $textcreatuser);
        $textcreatuser = str_replace('{name_service}', $get_invoice['name_product'], $textcreatuser);
        $textcreatuser = str_replace('{location}', $marzban_list_get['name_panel'], $textcreatuser);
        $textcreatuser = str_replace('{day}', $get_invoice['Service_time'], $textcreatuser);
        $textcreatuser = str_replace('{volume}', $get_invoice['Volume'], $textcreatuser);
        $textcreatuser = str_replace('{config}', "<code>{$output_config_link}</code>", $textcreatuser);
        $textcreatuser = str_replace('{links}', "<code>{$config}</code>", $textcreatuser);

        if (($marzban_list_get['type'] ?? '') == "Manualsale" || ($marzban_list_get['type'] ?? '') == "ibsng") {
            sendmessage($uid, $textcreatuser, null, 'HTML');
        } else {
            $configsArr = $dataoutput['configs'] ?? [];
            if (!is_array($configsArr)) {
                $configsArr = [];
            }
            if (count($configsArr) != 1 && ($marzban_list_get['config'] ?? '') == "onconfig") {
                sendmessage($uid, $textcreatuser, null, 'HTML');
            } else {
                if (($marzban_list_get['sublink'] ?? '') == "offsublink") {
                    $output_config_link = $configqr;
                }
                if (($marzban_list_get['type'] ?? '') == "WGDashboard") {
                    $urlimage = "{$marzban_list_get['inboundid']}_{$dataoutput['username']}.conf";
                    file_put_contents($urlimage, $output_config_link);
                    telegram('senddocument', [
                        'chat_id' => $uid,
                        'document' => new CURLFile($urlimage),
                        'caption' => $textcreatuser,
                        'parse_mode' => "HTML",
                    ]);
                    @unlink($urlimage);
                } else {
                    $urlimage = "{$uid}{$get_invoice['id_invoice']}.png";
                    if (function_exists('createqrcode') && $output_config_link !== '') {
                        $qrCode = createqrcode($output_config_link);
                        file_put_contents($urlimage, $qrCode->getString());
                        if (function_exists('addBackgroundImage') && isset($Pathfiles)) {
                            addBackgroundImage($urlimage, $qrCode, $Pathfiles . 'images.jpg');
                        }
                        telegram('sendphoto', [
                            'chat_id' => $uid,
                            'photo' => new CURLFile($urlimage),
                            'caption' => $textcreatuser,
                            'parse_mode' => "HTML",
                        ]);
                        @unlink($urlimage);
                    } else {
                        sendmessage($uid, $textcreatuser, null, 'HTML');
                    }
                }
            }
        }
        sendmessage($uid, $textbotlang['users']['selectoption'] ?? '✅', $keyboard, 'HTML');

        if ($Payment_report['Payment_Method'] == "cart to cart" || $Payment_report['Payment_Method'] == "arze digital offline") {
            $textconfrom = "✅ پرداخت تایید شد
🛍 خرید سرویس
▫️نام کاربری کانفیگ : {$dataoutput['username']}
▫️لوکیشن : {$marzban_list_get['name_panel']}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💸 مبلغ پرداختی: $format_price_cart تومان";
            if (!empty($from_id) && !empty($message_id)) {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
        return;
    }

    // Default: wallet top-up only
    $Payment_report_price_fmt = number_format($Payment_report['price'], 0);
    if ($Payment_report['Payment_Method'] == "cart to cart" || $Payment_report['Payment_Method'] == "arze digital offline") {
        $textconfrom = "⭕️ یک پرداخت جدید انجام شده است
افزایش موجودی.
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💸 مبلغ پرداختی: $format_price_cart تومان
✍️ توضیحات : {$Payment_report['dec_not_confirmed']}";
        if (!empty($from_id) && !empty($message_id)) {
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
    }
    sendmessage($Payment_report['id_user'], "💎 کاربر گرامی مبلغ {$Payment_report_price_fmt} تومان به کیف پول شما واریز گردید با تشکراز پرداخت شما.

🛒 کد پیگیری شما: {$Payment_report['id_order']}", null, 'HTML');
}

function channel_check($id_channel)
{
    global $from_id;
    $channel_link = array();
    $response = telegram('getChatMember', [
        'chat_id' => $id_channel,
        'user_id' => $from_id
    ]);
    if ($response['ok']) {
        if (!in_array($response['result']['status'], ['member', 'creator', 'administrator'])) {
            $channel_link[] = $id_channel;
        }
    }

    if (count($channel_link) == 0) {
        return [];
    } else {
        return $channel_link;
    }
}
