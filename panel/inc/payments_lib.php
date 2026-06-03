<?php

/** Payment gateways & PaySetting helpers — mirrors Telegram admin «💎 مالی». */

const PAYMENT_GATEWAYS = [
    'cart' => [
        'label' => 'کارت به کارت',
        'status_key' => 'Cartstatus',
        'on' => 'oncard',
        'off' => 'offcard',
        'textbot_key' => 'carttocart',
        'fields' => [
            ['key' => 'CartDirect', 'label' => 'آیدی تلگرام دریافت کارت (بدون @)', 'type' => 'text'],
            ['key' => 'Cartstatuspv', 'label' => 'درگاه آفلاین در پیوی', 'type' => 'toggle', 'on' => 'oncardpv', 'off' => 'offcardpv'],
            ['key' => 'minbalancecart', 'label' => 'حداقل مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'maxbalancecart', 'label' => 'حداکثر مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'chashbackcart', 'label' => 'کش‌بک (درصد، ۰ = غیرفعال)', 'type' => 'number'],
            ['key' => 'checkpaycartfirst', 'label' => 'نمایش کارت پس از اولین پرداخت', 'type' => 'toggle', 'on' => 'onpayverify', 'off' => 'offpayverify'],
            ['key' => 'autoconfirmcart', 'label' => 'تایید خودکار رسید', 'type' => 'toggle', 'on' => 'onauto', 'off' => 'offauto'],
            ['key' => 'statuscardautoconfirm', 'label' => 'تایید رسید بدون بررسی', 'type' => 'toggle', 'on' => 'onautoconfirm', 'off' => 'offautoconfirm'],
        ],
        'has_cards' => true,
    ],
    'zarinpal' => [
        'label' => 'زرین‌پال',
        'status_key' => 'zarinpalstatus',
        'on' => 'onzarinpal',
        'off' => 'offzarinpal',
        'textbot_key' => 'textzarinpal',
        'fields' => [
            ['key' => 'merchant_zarinpal', 'label' => 'مرچنت زرین‌پال', 'type' => 'text'],
            ['key' => 'minbalancezarinpal', 'label' => 'حداقل مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'maxbalancezarinpal', 'label' => 'حداکثر مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'chashbackzarinpal', 'label' => 'کش‌بک (درصد)', 'type' => 'number'],
        ],
    ],
    'aqayepardakht' => [
        'label' => 'آقای پرداخت',
        'status_key' => 'statusaqayepardakht',
        'on' => 'onaqayepardakht',
        'off' => 'offaqayepardakht',
        'textbot_key' => 'textaqayepardakht',
        'fields' => [
            ['key' => 'merchant_id_aqayepardakht', 'label' => 'مرچنت آقای پرداخت', 'type' => 'text'],
            ['key' => 'minbalanceaqayepardakht', 'label' => 'حداقل مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'maxbalanceaqayepardakht', 'label' => 'حداکثر مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'chashbackaqaypardokht', 'label' => 'کش‌بک (درصد)', 'type' => 'number'],
        ],
    ],
    'plisio' => [
        'label' => 'Plisio',
        'status_key' => 'nowpaymentstatus',
        'on' => 'onnowpayment',
        'off' => 'offnowpayment',
        'textbot_key' => 'textnowpayment',
        'fields' => [
            ['key' => 'apinowpayment', 'label' => 'API Plisio', 'type' => 'text'],
            ['key' => 'minbalanceplisio', 'label' => 'حداقل مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'maxbalanceplisio', 'label' => 'حداکثر مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'chashbackplisio', 'label' => 'کش‌بک (درصد)', 'type' => 'number'],
        ],
    ],
    'nowpayment' => [
        'label' => 'NowPayment',
        'status_key' => 'statusnowpayment',
        'on' => '1',
        'off' => '0',
        'toggle_binary' => true,
        'textbot_key' => 'textsnowpayment',
        'fields' => [
            ['key' => 'cashbacknowpayment', 'label' => 'کش‌بک (درصد)', 'type' => 'number'],
            ['key' => 'minbalancenowpayment', 'label' => 'حداقل مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'maxbalancenowpayment', 'label' => 'حداکثر مبلغ (تومان)', 'type' => 'number'],
        ],
    ],
    'arzireyali1' => [
        'label' => 'ارزی ریالی اول',
        'status_key' => 'statusSwapWallet',
        'on' => 'onSwapinoBot',
        'off' => 'offSwapinoBot',
        'textbot_key' => 'textiranpay1',
        'fields' => [
            ['key' => 'apiiranpay', 'label' => 'API / توکن', 'type' => 'text'],
            ['key' => 'minbalanceiranpay1', 'label' => 'حداقل مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'maxbalanceiranpay1', 'label' => 'حداکثر مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'chashbackiranpay1', 'label' => 'کش‌بک (درصد)', 'type' => 'number'],
        ],
    ],
    'arzireyali2' => [
        'label' => 'ارزی ریالی دوم (Tronado)',
        'status_key' => 'statustarnado',
        'on' => 'onternado',
        'off' => 'offternado',
        'textbot_key' => 'textiranpay2',
        'fields' => [
            ['key' => 'apiternado', 'label' => 'API Tronado', 'type' => 'text'],
            ['key' => 'urlpaymenttron', 'label' => 'آدرس API', 'type' => 'text'],
            ['key' => 'minbalanceiranpay2', 'label' => 'حداقل مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'maxbalanceiranpay2', 'label' => 'حداکثر مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'chashbackiranpay2', 'label' => 'کش‌بک (درصد)', 'type' => 'number'],
        ],
    ],
    'arzireyali3' => [
        'label' => 'ارزی ریالی سوم',
        'status_key' => 'statusiranpay3',
        'on' => 'oniranpay3',
        'off' => 'offiranpay3',
        'textbot_key' => 'textiranpay3',
        'fields' => [
            ['key' => 'marchent_floypay', 'label' => 'API Key', 'type' => 'text'],
            ['key' => 'minbalanceiranpay', 'label' => 'حداقل مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'maxbalanceiranpay', 'label' => 'حداکثر مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'chashbackiranpay3', 'label' => 'کش‌بک (درصد)', 'type' => 'number'],
        ],
    ],
    'affilnecurrency' => [
        'label' => 'ارز دیجیتال آفلاین',
        'status_key' => 'digistatus',
        'on' => 'ondigi',
        'off' => 'offdigi',
        'textbot_key' => 'textperfectmoney',
        'fields' => [
            ['key' => 'marchent_tronseller', 'label' => 'API NowPayment / Tron', 'type' => 'text'],
            ['key' => 'walletaddress', 'label' => 'آدرس ولت', 'type' => 'text'],
            ['key' => 'minbalancedigitaltron', 'label' => 'حداقل مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'maxbalancedigitaltron', 'label' => 'حداکثر مبلغ (تومان)', 'type' => 'number'],
        ],
    ],
    'startelegram' => [
        'label' => 'Star Telegram',
        'status_key' => 'statusstar',
        'on' => '1',
        'off' => '0',
        'toggle_binary' => true,
        'textbot_key' => 'text_star_telegram',
        'fields' => [
            ['key' => 'chashbackstar', 'label' => 'کش‌بک (درصد)', 'type' => 'number'],
            ['key' => 'minbalancestar', 'label' => 'حداقل مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'maxbalancestar', 'label' => 'حداکثر مبلغ (تومان)', 'type' => 'number'],
        ],
    ],
];

function pay_get(PDO $pdo, string $name, string $default = ''): string
{
    $row = db_fetch($pdo, "SELECT ValuePay FROM PaySetting WHERE NamePay = ?", [$name]);
    return $row ? (string) $row['ValuePay'] : $default;
}

function pay_set(PDO $pdo, string $name, string $value): void
{
    $exists = db_fetch($pdo, "SELECT NamePay FROM PaySetting WHERE NamePay = ?", [$name]);
    if ($exists) {
        db_query($pdo, "UPDATE PaySetting SET ValuePay = ? WHERE NamePay = ?", [$value, $name]);
    } else {
        db_query($pdo, "INSERT INTO PaySetting (NamePay, ValuePay) VALUES (?, ?)", [$name, $value]);
    }
}

function pay_gateway_enabled(array $gw): bool
{
    global $pdo;
    $cur = pay_get($pdo, $gw['status_key'], $gw['off']);
    if (!empty($gw['toggle_binary'])) {
        return $cur === $gw['on'];
    }
    return $cur === $gw['on'];
}

function pay_toggle_gateway(PDO $pdo, string $gatewayId): ?array
{
    $gw = PAYMENT_GATEWAYS[$gatewayId] ?? null;
    if (!$gw) {
        return null;
    }
    $cur = pay_get($pdo, $gw['status_key'], $gw['off']);
    $new = ($cur === $gw['on']) ? $gw['off'] : $gw['on'];
    pay_set($pdo, $gw['status_key'], $new);
    return ['enabled' => $new === $gw['on'], 'value' => $new];
}

function pay_textbot_get(PDO $pdo, string $idText, string $default = ''): string
{
    $row = db_fetch($pdo, "SELECT text FROM textbot WHERE id_text = ?", [$idText]);
    return $row ? (string) $row['text'] : $default;
}

function pay_textbot_set(PDO $pdo, string $idText, string $text): void
{
    $exists = db_fetch($pdo, "SELECT id_text FROM textbot WHERE id_text = ?", [$idText]);
    if ($exists) {
        db_query($pdo, "UPDATE textbot SET text = ? WHERE id_text = ?", [$text, $idText]);
    } else {
        db_query($pdo, "INSERT INTO textbot (id_text, text) VALUES (?, ?)", [$idText, $text]);
    }
}

function pay_list_cards(PDO $pdo): array
{
    try {
        return db_fetchAll($pdo, "SELECT cardnumber, namecard FROM card_number ORDER BY cardnumber");
    } catch (Exception $e) {
        return [];
    }
}

function pay_add_card(PDO $pdo, string $number, string $holder): array
{
    $number = preg_replace('/\D/', '', $number);
    if ($number === '') {
        return ['ok' => false, 'msg' => 'شماره کارت باید عدد باشد.'];
    }
    $exists = db_fetch($pdo, "SELECT cardnumber FROM card_number WHERE cardnumber = ?", [$number]);
    if ($exists) {
        return ['ok' => false, 'msg' => 'این شماره کارت قبلاً ثبت شده است.'];
    }
    if (function_exists('ensureCardNumberTableSupportsUnicode')) {
        ensureCardNumberTableSupportsUnicode();
    }
    db_query($pdo, "INSERT INTO card_number (cardnumber, namecard) VALUES (?, ?)", [$number, $holder]);
    return ['ok' => true, 'msg' => 'شماره کارت ثبت شد.'];
}

function pay_delete_card(PDO $pdo, string $number): void
{
    db_query($pdo, "DELETE FROM card_number WHERE cardnumber = ?", [$number]);
}

/** Load bot stack once for payment confirm (DirectPayment, notifications). */
function panel_payment_bootstrap(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $root = dirname(__DIR__, 2);
    if (!function_exists('select')) {
        require_once $root . '/botapi.php';
    }
    if (!class_exists('ManagePanel', false)) {
        require_once $root . '/panels.php';
    }
    if (!function_exists('DirectPayment')) {
        // function.php already loaded via panel config
    }
    if (!function_exists('languagechange')) {
        require_once $root . '/function.php';
    }
    global $ManagePanel, $textbotlang, $setting;
    if (!isset($ManagePanel)) {
        $ManagePanel = new ManagePanel();
    }
    if (!isset($textbotlang)) {
        $textbotlang = languagechange('text.json');
    }
    if (!isset($setting)) {
        $setting = select('setting', '*');
    }
    $done = true;
}

function panel_payment_confirm(PDO $pdo, string $orderId): array
{
    $payment = db_fetch($pdo, "SELECT * FROM Payment_report WHERE id_order = ?", [$orderId]);
    if (!$payment) {
        return ['ok' => false, 'msg' => 'تراکنش یافت نشد.'];
    }
    if (in_array($payment['payment_Status'], ['paid', 'reject'], true)) {
        return ['ok' => false, 'msg' => 'این پرداخت قبلاً بررسی شده است.'];
    }

    $pendingService = db_count(
        $pdo,
        "SELECT COUNT(*) FROM Payment_report WHERE id_user = ? AND payment_Status NOT IN ('paid','Unpaid','expire','reject')
         AND (id_invoice LIKE '%getconfigafterpay%' OR id_invoice LIKE '%getextenduser%'
              OR id_invoice LIKE '%getextravolumeuser%' OR id_invoice LIKE '%getextratimeuser%')",
        [$payment['id_user']]
    );
    $typepay = explode('|', (string) $payment['id_invoice']);
    if ($pendingService > 0 && !in_array($typepay[0] ?? '', ['getconfigafterpay', 'getextenduser', 'getextravolumeuser', 'getextratimeuser'], true)) {
        return ['ok' => false, 'msg' => 'ابتدا رسیدهای خرید/تمدید سرویس این کاربر را تأیید کنید، سپس شارژ کیف پول.'];
    }

    panel_payment_bootstrap();
    DirectPayment($orderId);

    $cashKey = ($payment['Payment_Method'] === 'cart to cart') ? 'chashbackcart' : null;
    if ($cashKey) {
        $pct = (int) pay_get($pdo, $cashKey, '0');
        if ($pct > 0) {
            $user = db_fetch($pdo, "SELECT id, Balance FROM user WHERE id = ?", [$payment['id_user']]);
            if ($user) {
                $bonus = (int) (($payment['price'] * $pct) / 100);
                db_query($pdo, "UPDATE user SET Balance = Balance + ? WHERE id = ?", [$bonus, $user['id']]);
            }
        }
    }

    $fresh = db_fetch($pdo, "SELECT payment_Status FROM Payment_report WHERE id_order = ?", [$orderId]);
    if (($fresh['payment_Status'] ?? '') !== 'paid') {
        db_query($pdo, "UPDATE Payment_report SET payment_Status = 'paid' WHERE id_order = ?", [$orderId]);
    }
    db_query($pdo, "UPDATE user SET Processing_value_one = 'none', Processing_value_tow = 'none', Processing_value_four = 'none' WHERE id = ?", [$payment['id_user']]);

    return ['ok' => true, 'msg' => 'پرداخت تأیید شد.'];
}

function panel_payment_reject(PDO $pdo, string $orderId, string $reason = ''): array
{
    $payment = db_fetch($pdo, "SELECT * FROM Payment_report WHERE id_order = ?", [$orderId]);
    if (!$payment) {
        return ['ok' => false, 'msg' => 'تراکنش یافت نشد.'];
    }
    if (in_array($payment['payment_Status'], ['paid', 'reject'], true)) {
        return ['ok' => false, 'msg' => 'این پرداخت قبلاً بررسی شده است.'];
    }
    $reason = trim($reason) ?: 'رد شده توسط ادمین پنل';
    db_query($pdo, "UPDATE Payment_report SET payment_Status = 'reject', dec_not_confirmed = ? WHERE id_order = ?", [$reason, $orderId]);

    panel_payment_bootstrap();
    if (function_exists('sendmessage')) {
        $text = "❌ کاربر گرامی پرداخت شما رد شد.\n✍️ {$reason}\n🛒 کد پیگیری: {$orderId}";
        @sendmessage($payment['id_user'], $text, null, 'HTML');
    }

    return ['ok' => true, 'msg' => 'پرداخت رد شد.'];
}

function panel_payment_dismiss(PDO $pdo, string $orderId): array
{
    $payment = db_fetch($pdo, "SELECT * FROM Payment_report WHERE id_order = ?", [$orderId]);
    if (!$payment || $payment['payment_Status'] !== 'waiting') {
        return ['ok' => false, 'msg' => 'رسید در انتظار یافت نشد.'];
    }
    db_query($pdo, "UPDATE Payment_report SET payment_Status = 'reject', dec_not_confirmed = 'remove_panel' WHERE id_order = ?", [$orderId]);
    return ['ok' => true, 'msg' => 'رسید حذف شد (بدون اطلاع کاربر).'];
}

function panel_payment_method_label(string $method): string
{
    $map = [
        'cart to cart' => 'کارت به کارت',
        'low balance by admin' => 'کسر موجودی ادمین',
        'add balance by admin' => 'افزایش توسط ادمین',
        'Currency Rial 1' => 'درگاه ریالی ۱',
        'Currency Rial tow' => 'درگاه ریالی ۲',
        'Currency Rial 3' => 'درگاه ریالی ۳',
        'aqayepardakht' => 'آقای پرداخت',
        'zarinpal' => 'زرین‌پال',
        'plisio' => 'Plisio',
        'arze digital offline' => 'ارز دیجیتال آفلاین',
        'Star Telegram' => 'استار تلگرام',
        'nowpayment' => 'NowPayment',
    ];
    return $map[$method] ?? ($method ?: '—');
}
