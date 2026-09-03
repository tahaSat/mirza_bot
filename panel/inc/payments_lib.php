<?php

/** Payment gateways & PaySetting helpers — mirrors Telegram admin «💎 مالی». */

const PAYMENT_GATEWAYS = [
    'cart' => [
        'label' => 'کارت به کارت',
        'status_key' => 'Cartstatus',
        'on' => 'oncard',
        'off' => 'offcard',
        'textbot_key' => 'carttocart',
        'help_key' => 'helpcart',
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
        'help_key' => 'helpzarinpal',
        'fields' => [
            ['key' => 'merchant_zarinpal', 'label' => 'مرچنت زرین‌پال', 'type' => 'text'],
            ['key' => 'minbalancezarinpal', 'label' => 'حداقل مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'maxbalancezarinpal', 'label' => 'حداکثر مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'chashbackzarinpal', 'label' => 'کش‌بک (درصد)', 'type' => 'number'],
        ],
    ],
    'tetraminator' => [
        'label' => 'Tetraminator',
        'status_key' => 'statustetraminator',
        'on' => 'ontetraminator',
        'off' => 'offtetraminator',
        'textbot_key' => 'tetraminator',
        'fields' => [
            ['key' => 'minbalancetetraminator', 'label' => 'حداقل مبلغ (تومان، حداقل ۵۰۰۰۰)', 'type' => 'number'],
            ['key' => 'maxbalancetetraminator', 'label' => 'حداکثر مبلغ (تومان)', 'type' => 'number'],
            ['key' => 'chashbacktetraminator', 'label' => 'کش‌بک (درصد)', 'type' => 'number'],
        ],
    ],
    'aqayepardakht' => [
        'label' => 'آقای پرداخت',
        'status_key' => 'statusaqayepardakht',
        'on' => 'onaqayepardakht',
        'off' => 'offaqayepardakht',
        'textbot_key' => 'textaqayepardakht',
        'help_key' => 'helpaqayepardakht',
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
        'help_key' => 'helpplisio',
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
        'help_key' => 'helpnowpayment',
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
        'help_key' => 'helpiranpay1',
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
        'help_key' => 'helpiranpay2',
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
        'help_key' => 'helpiranpay3',
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
        'help_key' => 'helpofflinearze',
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
        'help_key' => 'helpstar',
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

/**
 * Pre-payment help message (آموزش) for a gateway.
 * @return array{enabled: bool, type: string, text: string, photoid: string, videoid: string}
 */
function pay_help_get(PDO $pdo, string $key): array
{
    $raw = pay_get($pdo, $key, '2');
    $empty = [
        'enabled' => false,
        'type' => 'text',
        'text' => '',
        'photoid' => '',
        'videoid' => '',
    ];
    if ($raw === '' || $raw === '2' || $raw === '0') {
        return $empty;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['type'])) {
        return $empty;
    }
    $type = in_array($data['type'], ['text', 'photo', 'video'], true) ? $data['type'] : 'text';
    return [
        'enabled' => true,
        'type' => $type,
        'text' => (string) ($data['text'] ?? ''),
        'photoid' => (string) ($data['photoid'] ?? ''),
        'videoid' => (string) ($data['videoid'] ?? ''),
    ];
}

/**
 * @param array{enabled?: bool, type?: string, text?: string, photoid?: string, videoid?: string} $payload
 */
function pay_help_set(PDO $pdo, string $key, array $payload): void
{
    if (empty($payload['enabled'])) {
        pay_set($pdo, $key, '2');
        return;
    }
    $type = in_array($payload['type'] ?? '', ['text', 'photo', 'video'], true)
        ? $payload['type']
        : 'text';
    $data = [
        'type' => $type,
        'text' => trim((string) ($payload['text'] ?? '')),
    ];
    if ($type === 'photo') {
        $data['photoid'] = trim((string) ($payload['photoid'] ?? ''));
    } elseif ($type === 'video') {
        $data['videoid'] = trim((string) ($payload['videoid'] ?? ''));
    }
    pay_set($pdo, $key, json_encode($data, JSON_UNESCAPED_UNICODE));
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
    $prevCwd = getcwd();
    if (is_dir($root)) {
        @chdir($root);
    }

    try {
        // botapi must load even when function.php (select) is already available
        if (!function_exists('sendmessage')) {
            require_once $root . '/botapi.php';
        }
        if (!function_exists('jdate')) {
            require_once $root . '/jdf.php';
        }
        if (!class_exists('ManagePanel', false)) {
            require_once $root . '/panels.php';
        }
        if (!function_exists('languagechange')) {
            require_once $root . '/function.php';
        }

        global $ManagePanel, $textbotlang, $setting, $datatextbot, $keyboard, $pdo;
        global $from_id, $message_id, $Confirm_pay, $keyboardextendfnished;

        if (!isset($ManagePanel)) {
            $ManagePanel = new ManagePanel();
        }
        if (!isset($textbotlang)) {
            $textbotlang = languagechange($root . '/text.json');
        }
        if (!isset($setting) || !is_array($setting)) {
            $setting = select('setting', '*');
        }
        if (!isset($datatextbot) || !is_array($datatextbot)) {
            $datatextbot = $pdo->query('SELECT id_text, text FROM textbot')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        }
        if (!isset($keyboard)) {
            $keyboard = null;
        }
        // Panel has no Telegram callback context — DirectPayment skips Editmessagetext when empty
        if (!isset($from_id)) {
            $from_id = 0;
        }
        if (!isset($message_id)) {
            $message_id = 0;
        }
        if (!isset($Confirm_pay)) {
            $Confirm_pay = null;
        }
        if (!isset($keyboardextendfnished)) {
            $keyboardextendfnished = null;
        }

        $done = true;
    } finally {
        if ($prevCwd !== false) {
            @chdir($prevCwd);
        }
    }
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

    try {
        panel_payment_bootstrap();
        $root = dirname(__DIR__, 2);
        $receiptImage = is_file($root . '/images.jpg') ? $root . '/images.jpg' : 'images.jpg';
        DirectPayment($orderId, $receiptImage);

        $cashKey = ($payment['Payment_Method'] === 'cart to cart') ? 'chashbackcart' : null;
        if ($cashKey) {
            $pct = (int) pay_get($pdo, $cashKey, '0');
            if ($pct > 0) {
                $user = db_fetch($pdo, "SELECT id, Balance FROM user WHERE id = ?", [$payment['id_user']]);
                if ($user) {
                    $bonus = (int) (($payment['price'] * $pct) / 100);
                    if ($bonus > 0) {
                        db_query($pdo, "UPDATE user SET Balance = Balance + ? WHERE id = ?", [$bonus, $user['id']]);
                        if (function_exists('sendmessage')) {
                            @sendmessage($payment['id_user'], "🎁 کاربر عزیز مبلغ {$bonus} تومان به عنوان هدیه واریز به حساب شما واریز گردید.", null, 'HTML');
                        }
                    }
                }
            }
        }

        $fresh = db_fetch($pdo, "SELECT payment_Status FROM Payment_report WHERE id_order = ?", [$orderId]);
        if (($fresh['payment_Status'] ?? '') !== 'paid') {
            db_query($pdo, "UPDATE Payment_report SET payment_Status = 'paid' WHERE id_order = ?", [$orderId]);
        }
        db_query($pdo, "UPDATE user SET Processing_value_one = 'none', Processing_value_tow = 'none', Processing_value_four = 'none' WHERE id = ?", [$payment['id_user']]);
        if (function_exists('markAdminReceiptsAdminConfirmed')) {
            markAdminReceiptsAdminConfirmed($orderId);
        }

        return ['ok' => true, 'msg' => 'پرداخت تأیید شد.'];
    } catch (Throwable $e) {
        error_log('panel_payment_confirm: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return ['ok' => false, 'msg' => 'خطا در تأیید پرداخت: ' . $e->getMessage()];
    }
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

function panel_payment_ensure_note_column(PDO $pdo): void
{
    panel_payment_ensure_schema($pdo);
}

function panel_expense_default_slug(): string
{
    return 'other';
}

function panel_payment_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM Payment_report LIKE 'note'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE Payment_report ADD note TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
        }
    } catch (Throwable $e) {
        error_log('panel_payment_ensure_schema note: ' . $e->getMessage());
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS expense_category (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(64) NOT NULL,
                label VARCHAR(128) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_expense_category_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $count = (int) $pdo->query('SELECT COUNT(*) FROM expense_category')->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO expense_category (slug, label, sort_order) VALUES ('other', 'سایر', 0)");
        } elseif (!db_fetch($pdo, "SELECT id FROM expense_category WHERE slug = 'other'")) {
            $pdo->exec("INSERT INTO expense_category (slug, label, sort_order) VALUES ('other', 'سایر', 0)");
        }
        if (!db_fetch($pdo, "SELECT id FROM expense_category WHERE slug = 'wallet_withdraw'")) {
            $pdo->exec("INSERT INTO expense_category (slug, label, sort_order) VALUES ('wallet_withdraw', 'برداشت از کیف پول', 10)");
        }
        if (!db_fetch($pdo, "SELECT id FROM expense_category WHERE slug = 'ads'")) {
            $pdo->exec("INSERT INTO expense_category (slug, label, sort_order) VALUES ('ads', 'هزینه تبلیغ', 20)");
        }
    } catch (Throwable $e) {
        error_log('panel_payment_ensure_schema expense_category: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM Payment_report LIKE 'tx_type'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE Payment_report ADD tx_type VARCHAR(16) NOT NULL DEFAULT 'income'");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM Payment_report LIKE 'expense_category'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE Payment_report ADD expense_category VARCHAR(64) NULL");
        }
    } catch (Throwable $e) {
        error_log('panel_payment_ensure_schema payment columns: ' . $e->getMessage());
    }

    try {
        if (pay_get($pdo, 'expense_schema_v1', '') !== '1') {
            db_query(
                $pdo,
                "UPDATE Payment_report
                 SET tx_type = 'expense'
                 WHERE (payment_Status = 'cost' OR Payment_Method = 'cost' OR id_invoice = 'cost')
                   AND (tx_type IS NULL OR tx_type = '' OR tx_type = 'income')"
            );
            db_query(
                $pdo,
                "UPDATE Payment_report
                 SET expense_category = 'other'
                 WHERE (tx_type = 'expense' OR payment_Status = 'cost' OR Payment_Method = 'cost' OR id_invoice = 'cost')
                   AND (expense_category IS NULL OR expense_category = '')"
            );
            db_query(
                $pdo,
                "UPDATE Payment_report
                 SET payment_Status = 'cost', id_invoice = 'cost'
                 WHERE tx_type = 'expense'
                   AND (payment_Status <> 'cost' OR id_invoice <> 'cost' OR id_invoice IS NULL)"
            );
            pay_set($pdo, 'expense_schema_v1', '1');
        }
    } catch (Throwable $e) {
        error_log('panel_payment_ensure_schema migrate: ' . $e->getMessage());
    }
}

function panel_expense_category_map(PDO $pdo, bool $refresh = false): array
{
    static $map = null;
    if ($map !== null && !$refresh) {
        return $map;
    }
    panel_payment_ensure_schema($pdo);
    $map = [];
    try {
        $rows = db_fetchAll($pdo, 'SELECT slug, label FROM expense_category ORDER BY sort_order ASC, id ASC');
        foreach ($rows as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $map[$slug] = (string) ($row['label'] ?? $slug);
        }
    } catch (Throwable $e) {
        error_log('panel_expense_category_map: ' . $e->getMessage());
    }
    if (!isset($map[panel_expense_default_slug()])) {
        $map[panel_expense_default_slug()] = 'سایر';
    }
    return $map;
}

function panel_expense_categories(PDO $pdo): array
{
    panel_payment_ensure_schema($pdo);
    try {
        return db_fetchAll($pdo, 'SELECT * FROM expense_category ORDER BY sort_order ASC, id ASC') ?: [];
    } catch (Throwable $e) {
        error_log('panel_expense_categories: ' . $e->getMessage());
        return [];
    }
}

function panel_expense_category_label(PDO $pdo, string $slug): string
{
    $map = panel_expense_category_map($pdo);
    $slug = trim($slug);
    if ($slug !== '' && isset($map[$slug])) {
        return $map[$slug];
    }
    return $map[panel_expense_default_slug()] ?? 'سایر';
}

function panel_expense_resolve_slug(PDO $pdo, string $slug): string
{
    $map = panel_expense_category_map($pdo);
    $slug = trim($slug);
    if ($slug !== '' && isset($map[$slug])) {
        return $slug;
    }
    return panel_expense_default_slug();
}

function panel_expense_usage_counts(PDO $pdo): array
{
    panel_payment_ensure_schema($pdo);
    $counts = [];
    try {
        $rows = db_fetchAll(
            $pdo,
            "SELECT expense_category AS slug, COUNT(*) AS cnt
             FROM Payment_report
             WHERE (tx_type = 'expense' OR payment_Status = 'cost' OR Payment_Method = 'cost' OR id_invoice = 'cost')
               AND expense_category IS NOT NULL AND expense_category != ''
             GROUP BY expense_category"
        );
        foreach ($rows as $row) {
            $counts[(string) ($row['slug'] ?? '')] = (int) ($row['cnt'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('panel_expense_usage_counts: ' . $e->getMessage());
    }
    return $counts;
}

function panel_expense_make_slug(PDO $pdo, string $label, ?int $excludeId = null): string
{
    $base = strtolower(trim($label));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim($base, '-');
    if ($base === '' || $base === 'cost') {
        $base = 'cat-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
    $slug = $base;
    $n = 2;
    while (true) {
        $row = db_fetch($pdo, 'SELECT id FROM expense_category WHERE slug = ?', [$slug]);
        if (!$row || ($excludeId !== null && (int) $row['id'] === $excludeId)) {
            return $slug;
        }
        $slug = $base . '-' . $n;
        $n++;
        if ($n > 50) {
            return 'cat-' . bin2hex(random_bytes(4));
        }
    }
}

/**
 * @return array{ok:bool,msg:string}
 */
function panel_expense_add(PDO $pdo, string $label, int $sortOrder = 0): array
{
    panel_payment_ensure_schema($pdo);
    $label = trim($label);
    if ($label === '') {
        return ['ok' => false, 'msg' => 'نام دسته الزامی است.'];
    }
    if (mb_strlen($label) > 64) {
        return ['ok' => false, 'msg' => 'نام دسته خیلی طولانی است.'];
    }
    if (db_fetch($pdo, 'SELECT id FROM expense_category WHERE label = ?', [$label])) {
        return ['ok' => false, 'msg' => 'دسته‌ای با این نام قبلاً ثبت شده.'];
    }
    $slug = panel_expense_make_slug($pdo, $label);
    db_query(
        $pdo,
        'INSERT INTO expense_category (slug, label, sort_order) VALUES (?,?,?)',
        [$slug, $label, $sortOrder]
    );
    panel_expense_category_map($pdo, true);
    return ['ok' => true, 'msg' => 'دسته «' . $label . '» اضافه شد.'];
}

/**
 * @return array{ok:bool,msg:string}
 */
function panel_expense_rename(PDO $pdo, int $id, string $label, ?int $sortOrder = null): array
{
    panel_payment_ensure_schema($pdo);
    $label = trim($label);
    if ($id < 1 || $label === '') {
        return ['ok' => false, 'msg' => 'نام دسته الزامی است.'];
    }
    if (mb_strlen($label) > 64) {
        return ['ok' => false, 'msg' => 'نام دسته خیلی طولانی است.'];
    }
    $row = db_fetch($pdo, 'SELECT * FROM expense_category WHERE id = ?', [$id]);
    if (!$row) {
        return ['ok' => false, 'msg' => 'دسته یافت نشد.'];
    }
    $dup = db_fetch($pdo, 'SELECT id FROM expense_category WHERE label = ? AND id != ?', [$label, $id]);
    if ($dup) {
        return ['ok' => false, 'msg' => 'دسته‌ای با این نام قبلاً ثبت شده.'];
    }
    if ($sortOrder === null) {
        db_query($pdo, 'UPDATE expense_category SET label = ? WHERE id = ?', [$label, $id]);
    } else {
        db_query($pdo, 'UPDATE expense_category SET label = ?, sort_order = ? WHERE id = ?', [$label, $sortOrder, $id]);
    }
    panel_expense_category_map($pdo, true);
    return ['ok' => true, 'msg' => 'دسته ویرایش شد.'];
}

/**
 * @return array{ok:bool,msg:string}
 */
function panel_expense_delete(PDO $pdo, int $id): array
{
    panel_payment_ensure_schema($pdo);
    $row = db_fetch($pdo, 'SELECT * FROM expense_category WHERE id = ?', [$id]);
    if (!$row) {
        return ['ok' => false, 'msg' => 'دسته یافت نشد.'];
    }
    $slug = (string) ($row['slug'] ?? '');
    if ($slug === panel_expense_default_slug()) {
        return ['ok' => false, 'msg' => 'دسته پیش‌فرض «سایر» قابل حذف نیست.'];
    }
    $used = (int) db_query(
        $pdo,
        "SELECT COUNT(*) FROM Payment_report
         WHERE expense_category = ?
           AND (tx_type = 'expense' OR payment_Status = 'cost' OR Payment_Method = 'cost' OR id_invoice = 'cost')",
        [$slug]
    )->fetchColumn();
    if ($used > 0) {
        return ['ok' => false, 'msg' => 'این دسته روی ' . number_format($used) . ' هزینه استفاده شده و قابل حذف نیست.'];
    }
    db_query($pdo, 'DELETE FROM expense_category WHERE id = ?', [$id]);
    panel_expense_category_map($pdo, true);
    return ['ok' => true, 'msg' => 'دسته حذف شد.'];
}

function panel_payment_parse_time($raw): int
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return time();
    }
    if (ctype_digit($raw) && strlen($raw) >= 9) {
        return (int) $raw;
    }
    $ts = strtotime(str_replace('T', ' ', $raw));
    return $ts !== false ? $ts : time();
}

function panel_payment_parse_sheet_time($raw): int
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return time();
    }
    if (function_exists('jalali_tehran_parse')) {
        $ts = jalali_tehran_parse($raw, false);
        if ($ts !== null) {
            return $ts;
        }
    }
    return panel_payment_parse_time($raw);
}

function panel_payment_time_to_jalali($raw, string $fmt = 'Y/m/d H:i'): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    if (ctype_digit($raw) && strlen($raw) >= 9) {
        $ts = (int) $raw;
    } else {
        $normalized = str_replace(['T', '.'], [' ', '/'], $raw);
        $ts = strtotime(str_replace('/', '-', $normalized));
        if ($ts === false) {
            $ts = panel_payment_parse_time($raw);
        }
    }
    return function_exists('jalali_tehran_format')
        ? jalali_tehran_format($ts, $fmt, 'en')
        : date($fmt, $ts);
}

function panel_payment_new_order_id(PDO $pdo): string
{
    for ($i = 0; $i < 8; $i++) {
        $id = bin2hex(random_bytes(5));
        if (!db_fetch($pdo, 'SELECT id FROM Payment_report WHERE id_order = ?', [$id])) {
            return $id;
        }
    }
    return bin2hex(random_bytes(8));
}

function panel_payment_status_meta(): array
{
    return [
        'paid' => ['tag-ok', 'پرداخت شده'],
        'Unpaid' => ['tag-no', 'پرداخت نشده'],
        'expire' => ['tag-plain', 'منقضی'],
        'reject' => ['tag-no', 'رد شده'],
        'waiting' => ['tag-warn', 'در انتظار'],
        'cost' => ['tag-plain', 'هزینه شده'],
    ];
}

function panel_payment_method_map(): array
{
    return [
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
        'tetraminator' => 'Tetraminator',
        'manual invoice' => 'فاکتور دستی',
        'capital_injection' => 'ورود سرمایه',
        'add order by admin' => 'سفارش توسط ادمین',
        'extend by admin' => 'تمدید توسط ادمین',
        'refund to wallet' => 'مرجوعی به کیف پول',
    ];
}

function panel_payment_row_has_product(array $payment): bool
{
    return strncmp((string) ($payment['id_invoice'] ?? ''), 'getconfigafterpay|', 18) === 0;
}

function panel_payment_serialize_sheet_row(array $p, array $knownUsers = []): array
{
    $status = (string) ($p['payment_Status'] ?? '');
    $meta = panel_payment_status_meta();
    [$cls, $lbl] = $meta[$status] ?? ['tag-plain', $status !== '' ? $status : '—'];
    $method = (string) ($p['Payment_Method'] ?? '');
    $uid = trim((string) ($p['id_user'] ?? ''));
    $note = trim((string) ($p['note'] ?? ''));
    $oid = (string) ($p['id_order'] ?? '');
    $price = (int) ($p['price'] ?? 0);
    $isCost = panel_payment_is_cost($p);
    $category = trim((string) ($p['expense_category'] ?? ''));
    $categoryLabel = '';
    if ($isCost) {
        global $pdo;
        if ($pdo instanceof PDO) {
            $category = panel_expense_resolve_slug($pdo, $category);
            $categoryLabel = panel_expense_category_label($pdo, $category);
        } else {
            $categoryLabel = $category !== '' ? $category : 'سایر';
        }
    }
    return [
        'id_order' => $oid,
        'id_user' => $uid,
        'user_known' => $uid !== '' && $uid !== '0' && !empty($knownUsers[$uid]),
        'price' => $price,
        'price_fmt' => number_format($price),
        'method' => $method,
        'method_label' => panel_payment_method_label($method),
        'expense_category' => $category,
        'category_label' => $categoryLabel,
        'note' => $note,
        'time' => panel_payment_time_to_jalali($p['time'] ?? ''),
        'status' => $status,
        'status_label' => $lbl,
        'status_class' => $cls,
        'has_product' => panel_payment_row_has_product($p),
        'is_cost' => $isCost,
    ];
}

function panel_payment_format_time(int $ts): string
{
    return (new DateTime('@' . $ts))
        ->setTimezone(new DateTimeZone('Asia/Tehran'))
        ->format('Y/m/d H:i:s');
}

function panel_payment_time_sort_sql(string $column = 'time'): string
{
    return "CASE
        WHEN $column REGEXP '^[0-9]{9,}$' THEN CAST($column AS UNSIGNED)
        ELSE COALESCE(
            UNIX_TIMESTAMP(STR_TO_DATE($column, '%Y-%m-%d %H:%i:%s')),
            UNIX_TIMESTAMP(STR_TO_DATE($column, '%Y/%m/%d %H:%i:%s'))
        )
    END";
}

/**
 * Parse a payment date filter as Jalali Tehran time (with Gregorian datetime-local fallback).
 *
 * @return array{ts:int,sql:string,input:string}|null
 */
function panel_payment_parse_filter_datetime(string $raw, bool $endOfRange = false): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $ts = function_exists('jalali_tehran_parse') ? jalali_tehran_parse($raw, $endOfRange) : null;
    if ($ts === null) {
        $normalized = trim(str_replace(' ', 'T', $raw));
        $tz = new DateTimeZone('Asia/Tehran');
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $normalized, $tz)
            ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $normalized, $tz)
            ?: DateTime::createFromFormat('Y-m-d', substr($normalized, 0, 10), $tz);
        if (!$dt) {
            return null;
        }
        if (!str_contains($normalized, 'T')) {
            $dt->setTime($endOfRange ? 23 : 0, $endOfRange ? 59 : 0, $endOfRange ? 59 : 0);
        }
        $ts = $dt->getTimestamp();
    }

    $sqlDt = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Asia/Tehran'));
    return [
        'ts' => $ts,
        'sql' => $sqlDt->format('Y/m/d H:i:s'),
        'input' => function_exists('jalali_tehran_format')
            ? jalali_tehran_format($ts, 'Y/m/d H:i', 'en')
            : $sqlDt->format('Y-m-d\TH:i'),
    ];
}

function panel_payment_append_time_range(array &$where, array &$params, ?array $from, ?array $to): void
{
    if ($from === null && $to === null) {
        return;
    }
    $startTs = $from['ts'] ?? 0;
    $endTs = $to['ts'] ?? 2147483647;
    $startDt = $from['sql'] ?? '1970/01/01 00:00:00';
    $endDt = $to['sql'] ?? '2038/01/19 03:14:07';
    $where[] = "(
        (time REGEXP '^[0-9]{9,}$' AND CAST(time AS UNSIGNED) BETWEEN ? AND ?)
        OR (time NOT REGEXP '^[0-9]{9,}$' AND COALESCE(
              STR_TO_DATE(time, '%Y-%m-%d %H:%i:%s'),
              STR_TO_DATE(time, '%Y/%m/%d %H:%i:%s')
            ) BETWEEN ? AND ?)
    )";
    $params[] = $startTs;
    $params[] = $endTs;
    $params[] = $startDt;
    $params[] = $endDt;
}

function panel_payment_is_cost(array $payment): bool
{
    return ($payment['tx_type'] ?? '') === 'expense'
        || ($payment['payment_Status'] ?? '') === 'cost'
        || ($payment['Payment_Method'] ?? '') === 'cost'
        || ($payment['id_invoice'] ?? '') === 'cost';
}

/**
 * @return array{ok:bool,msg:string}
 */
function panel_payment_add_manual(PDO $pdo, array $input): array
{
    panel_payment_ensure_note_column($pdo);
    $amount = (int) ($input['amount'] ?? 0);
    if ($amount < 1) {
        return ['ok' => false, 'msg' => 'مبلغ باید عدد مثبت باشد.'];
    }

    $userId = trim((string) ($input['id_user'] ?? ''));
    $note = trim((string) ($input['note'] ?? ''));
    $orderId = trim((string) ($input['id_order'] ?? ''));
    if ($orderId === '' || db_fetch($pdo, 'SELECT id FROM Payment_report WHERE id_order = ?', [$orderId])) {
        $orderId = panel_payment_new_order_id($pdo);
    }

    $method = trim((string) ($input['method'] ?? 'manual invoice'));
    if ($method === '' || $method === 'cost') {
        $method = 'manual invoice';
    }
    $isCapital = $method === 'capital_injection';

    $creditWallet = !$isCapital && !empty($input['credit_wallet']);
    $realUser = $userId !== ''
        ? db_fetch($pdo, 'SELECT id FROM user WHERE id = ?', [$userId])
        : null;
    if ($creditWallet && !$realUser) {
        return ['ok' => false, 'msg' => 'برای افزودن به کیف پول باید آیدی کاربر معتبر وارد شود.'];
    }
    $status = trim((string) ($input['status'] ?? 'paid'));
    if (!in_array($status, panel_payment_status_values(), true)) {
        $status = 'paid';
    }

    $time = panel_payment_format_time(panel_payment_parse_sheet_time($input['time'] ?? ''));
    $idInvoice = $isCapital ? 'capital' : ($creditWallet ? 'manual|wallet' : 'manual');

    db_query(
        $pdo,
        'INSERT INTO Payment_report (id_user, id_order, time, price, payment_Status, Payment_Method, id_invoice, note, tx_type, expense_category) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$userId, $orderId, $time, (string) $amount, $status, $method, $idInvoice, $note !== '' ? $note : null, 'income', null]
    );

    if ($creditWallet && $realUser) {
        db_query($pdo, 'UPDATE user SET Balance = Balance + ? WHERE id = ?', [$amount, $realUser['id']]);
        require_once __DIR__ . '/users_lib.php';
        panel_notify_user(
            $realUser['id'],
            '💎 کاربر عزیز مبلغ ' . number_format($amount) . ' تومان به موجودی کیف پول تان اضافه گردید.'
        );
    }

    return [
        'ok' => true,
        'msg' => $isCapital ? 'ورود سرمایه ثبت شد.' : 'فاکتور دستی ثبت شد.',
        'id_order' => $orderId,
    ];
}

/**
 * @return array{ok:bool,msg:string}
 */
function panel_payment_add_cost(PDO $pdo, array $input): array
{
    panel_payment_ensure_schema($pdo);
    $amount = (int) ($input['amount'] ?? 0);
    if ($amount < 1) {
        return ['ok' => false, 'msg' => 'مبلغ باید عدد مثبت باشد.'];
    }

    $note = trim((string) ($input['note'] ?? ''));
    $userId = trim((string) ($input['id_user'] ?? ''));
    $orderId = trim((string) ($input['id_order'] ?? ''));
    if ($orderId === '' || db_fetch($pdo, 'SELECT id FROM Payment_report WHERE id_order = ?', [$orderId])) {
        $orderId = panel_payment_new_order_id($pdo);
    }
    $category = panel_expense_resolve_slug(
        $pdo,
        (string) ($input['expense_category'] ?? $input['category'] ?? '')
    );

    $time = panel_payment_format_time(panel_payment_parse_sheet_time($input['time'] ?? ''));
    db_query(
        $pdo,
        'INSERT INTO Payment_report (id_user, id_order, time, price, payment_Status, Payment_Method, id_invoice, note, tx_type, expense_category) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$userId, $orderId, $time, (string) $amount, 'cost', 'cost', 'cost', $note !== '' ? $note : null, 'expense', $category]
    );

    return ['ok' => true, 'msg' => 'هزینه ثبت شد.', 'id_order' => $orderId];
}

/**
 * @return array{ok:bool,msg:string}
 */
function panel_payment_delete_cost(PDO $pdo, string $orderId): array
{
    $row = db_fetch($pdo, "SELECT id FROM Payment_report WHERE id_order = ? AND payment_Status = 'cost'", [$orderId]);
    if (!$row) {
        return ['ok' => false, 'msg' => 'هزینه یافت نشد.'];
    }
    db_query($pdo, "DELETE FROM Payment_report WHERE id_order = ? AND payment_Status = 'cost'", [$orderId]);
    return ['ok' => true, 'msg' => 'هزینه حذف شد.'];
}

/** Allowed payment_Status values for admin updates. */
function panel_payment_status_values(): array
{
    return ['paid', 'Unpaid', 'waiting', 'reject', 'expire'];
}

/**
 * Invoice created by a purchase payment (getconfigafterpay|username), if any.
 *
 * @return array|null
 */
function panel_payment_linked_invoice(PDO $pdo, array $payment): ?array
{
    $parts = explode('|', (string) ($payment['id_invoice'] ?? ''), 2);
    if (($parts[0] ?? '') !== 'getconfigafterpay' || ($parts[1] ?? '') === '') {
        return null;
    }
    $invoice = db_fetch($pdo, 'SELECT * FROM invoice WHERE username = ? LIMIT 1', [$parts[1]]);
    return $invoice ?: null;
}

function panel_payment_is_wallet(array $payment): bool
{
    $prefix = explode('|', (string) ($payment['id_invoice'] ?? ''), 2)[0] ?? '';
    return !in_array($prefix, ['getconfigafterpay', 'getextenduser', 'getextravolumeuser', 'getextratimeuser'], true);
}

/**
 * Manually change a payment status (e.g. paid → reject).
 * When leaving paid for a purchase payment, optionally remove the created service
 * and/or mark the linked invoice/order as rejected (so Telegram سفارشات stats exclude it).
 *
 * @return array{ok:bool,msg:string}
 */
function panel_payment_set_status(
    PDO $pdo,
    string $orderId,
    string $newStatus,
    bool $removeProduct = false,
    bool $rejectInvoice = false
): array {
    $newStatus = trim($newStatus);
    if (!in_array($newStatus, panel_payment_status_values(), true)) {
        return ['ok' => false, 'msg' => 'وضعیت نامعتبر است.'];
    }

    $payment = db_fetch($pdo, 'SELECT * FROM Payment_report WHERE id_order = ?', [$orderId]);
    if (!$payment) {
        return ['ok' => false, 'msg' => 'تراکنش یافت نشد.'];
    }
    if (panel_payment_is_cost($payment)) {
        return ['ok' => false, 'msg' => 'تغییر وضعیت برای هزینه مجاز نیست.'];
    }

    $oldStatus = (string) ($payment['payment_Status'] ?? '');
    if ($oldStatus === $newStatus) {
        return ['ok' => true, 'msg' => 'وضعیت تغییری نکرد.'];
    }

    db_query($pdo, 'UPDATE Payment_report SET payment_Status = ? WHERE id_order = ?', [$newStatus, $orderId]);

    $notes = [];
    $wasPaid = $oldStatus === 'paid';
    $leavingPaid = $wasPaid && $newStatus !== 'paid';
    $method = (string) ($payment['Payment_Method'] ?? '');
    $idInvoice = (string) ($payment['id_invoice'] ?? '');
    $skipWalletClawback = in_array($method, ['add balance by admin', 'low balance by admin', 'add order by admin', 'extend by admin', 'refund to wallet', 'cost', 'capital_injection'], true)
        || $idInvoice === 'manual'
        || $idInvoice === 'capital'
        || $idInvoice === 'cost';

    if ($leavingPaid && panel_payment_is_wallet($payment) && !$skipWalletClawback) {
        $price = (int) ($payment['price'] ?? 0);
        if ($price > 0) {
            db_query($pdo, 'UPDATE user SET Balance = GREATEST(0, CAST(Balance AS SIGNED) - ?) WHERE id = ?', [$price, $payment['id_user']]);
            $notes[] = 'مبلغ از کیف پول کاربر کسر شد.';
        }
    }

    if ($leavingPaid && $removeProduct) {
        $invoice = panel_payment_linked_invoice($pdo, $payment);
        if ($invoice) {
            require_once __DIR__ . '/users_lib.php';
            $removed = panel_remove_user_service($pdo, (string) $invoice['id_invoice'], $invoice['id_user'], false);
            $notes[] = $removed['ok']
                ? 'سرویس مرتبط حذف شد.'
                : ('حذف سرویس: ' . $removed['msg']);
        } else {
            $notes[] = 'سرویس مرتبطی برای حذف یافت نشد.';
        }
    } elseif ($leavingPaid && $rejectInvoice) {
        require_once __DIR__ . '/users_lib.php';
        $notes[] = panel_payment_reject_linked_order($pdo, $payment);
    }

    $statusLabels = [
        'paid' => 'پرداخت شده',
        'Unpaid' => 'پرداخت نشده',
        'waiting' => 'در انتظار تأیید',
        'reject' => 'رد شده',
        'expire' => 'منقضی',
    ];
    $msg = 'وضعیت پرداخت به «' . ($statusLabels[$newStatus] ?? $newStatus) . '» تغییر کرد.';
    if ($notes) {
        $msg .= ' ' . implode(' ', $notes);
    }
    return ['ok' => true, 'msg' => $msg];
}

/**
 * Mark the invoice / service_other linked to a payment as rejected.
 */
function panel_payment_reject_linked_order(PDO $pdo, array $payment): string
{
    $parts = explode('|', (string) ($payment['id_invoice'] ?? ''), 2);
    $type = $parts[0] ?? '';
    $payload = $parts[1] ?? '';

    if ($type === 'getconfigafterpay' && $payload !== '') {
        $before = db_fetch($pdo, 'SELECT id_invoice, Status FROM invoice WHERE username = ? LIMIT 1', [$payload]);
        if (!$before) {
            return 'فاکتور مرتبط یافت نشد.';
        }
        db_query($pdo, "UPDATE invoice SET Status = 'reject' WHERE username = ?", [$payload]);
        return 'وضعیت فاکتور به رد شده تغییر کرد.';
    }

    $map = [
        'getextenduser' => 'extend_user',
        'getextravolumeuser' => 'extra_user',
        'getextratimeuser' => 'extra_time_user',
    ];
    if (isset($map[$type]) && $payload !== '') {
        $username = explode('%', $payload, 2)[0];
        $serviceType = $map[$type];
        if ($type === 'getextenduser' && ($payment['Payment_Method'] ?? '') === 'extend by admin') {
            $serviceType = 'extend_user_by_admin';
        }
        $row = db_fetch(
            $pdo,
            "SELECT id FROM service_other
             WHERE id_user = ? AND username = ? AND type = ?
             ORDER BY id DESC LIMIT 1",
            [$payment['id_user'], $username, $serviceType]
        );
        if ($row) {
            db_query($pdo, "UPDATE service_other SET status = 'reject' WHERE id = ?", [$row['id']]);
            return 'وضعیت سفارش مرتبط به رد شده تغییر کرد.';
        }
        return 'سفارش مرتبطی یافت نشد.';
    }

    return 'سفارش/فاکتور مرتبطی برای رد وجود ندارد.';
}

/**
 * @return array{ok:bool,msg:string,id_order?:string}
 */
function panel_payment_update_row(PDO $pdo, string $orderId, array $input): array
{
    panel_payment_ensure_note_column($pdo);
    $payment = db_fetch($pdo, 'SELECT * FROM Payment_report WHERE id_order = ?', [$orderId]);
    if (!$payment) {
        return ['ok' => false, 'msg' => 'تراکنش یافت نشد.'];
    }

    $amount = (int) ($input['amount'] ?? 0);
    if ($amount < 1) {
        return ['ok' => false, 'msg' => 'مبلغ باید عدد مثبت باشد.'];
    }

    $userId = trim((string) ($input['id_user'] ?? ''));
    $note = trim((string) ($input['note'] ?? ''));
    $method = trim((string) ($input['method'] ?? ''));
    $isCost = panel_payment_is_cost($payment);
    $category = null;
    if ($isCost) {
        $method = 'cost';
        $category = panel_expense_resolve_slug(
            $pdo,
            (string) ($input['expense_category'] ?? $input['category'] ?? $payment['expense_category'] ?? '')
        );
    } elseif ($method === '' || $method === 'cost') {
        $method = (string) ($payment['Payment_Method'] ?? '');
    }

    $timeRaw = trim((string) ($input['time'] ?? ''));
    if ($timeRaw === '') {
        $time = (string) ($payment['time'] ?? panel_payment_format_time(time()));
    } else {
        $time = panel_payment_format_time(panel_payment_parse_sheet_time($timeRaw));
    }
    if ($isCost) {
        db_query(
            $pdo,
            'UPDATE Payment_report SET id_user = ?, price = ?, Payment_Method = ?, note = ?, time = ?, tx_type = ?, expense_category = ? WHERE id_order = ?',
            [$userId, (string) $amount, $method, $note !== '' ? $note : null, $time, 'expense', $category, $orderId]
        );
    } else {
        db_query(
            $pdo,
            'UPDATE Payment_report SET id_user = ?, price = ?, Payment_Method = ?, note = ?, time = ? WHERE id_order = ?',
            [$userId, (string) $amount, $method, $note !== '' ? $note : null, $time, $orderId]
        );
    }

    $statusResult = ['ok' => true, 'msg' => ''];
    $newStatus = trim((string) ($input['status'] ?? ''));
    if (!$isCost && $newStatus !== '' && $newStatus !== (string) ($payment['payment_Status'] ?? '')) {
        $statusResult = panel_payment_set_status(
            $pdo,
            $orderId,
            $newStatus,
            !empty($input['remove_product']),
            !empty($input['reject_invoice'])
        );
        if (!$statusResult['ok']) {
            return $statusResult;
        }
    }

    $msg = 'تراکنش ذخیره شد.';
    if (($statusResult['msg'] ?? '') !== '' && ($statusResult['msg'] ?? '') !== 'وضعیت تغییری نکرد.') {
        $msg .= ' ' . $statusResult['msg'];
    }
    return ['ok' => true, 'msg' => $msg, 'id_order' => $orderId];
}

/**
 * @return array{ok:bool,msg:string}
 */
function panel_payment_delete_row(PDO $pdo, string $orderId): array
{
    $row = db_fetch($pdo, 'SELECT id FROM Payment_report WHERE id_order = ?', [$orderId]);
    if (!$row) {
        return ['ok' => false, 'msg' => 'تراکنش یافت نشد.'];
    }
    db_query($pdo, 'DELETE FROM Payment_report WHERE id_order = ?', [$orderId]);
    return ['ok' => true, 'msg' => 'تراکنش حذف شد.'];
}

function panel_payment_method_label(string $method): string
{
    $map = panel_payment_method_map();
    return $map[$method] ?? ($method !== '' ? $method : '—');
}
