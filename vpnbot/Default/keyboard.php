<?php

if (!isset($from_id)) {
    $from_id = 0;
}
$from_id = (int) $from_id;

$botinfo = select("botsaz", "*", "bot_token", $ApiToken, "select");
$userbot = select("user", "*", "id", $botinfo['id_user'], "select");
$hide_panel = json_decode($botinfo['hide_panel'], true);
$text_bot_var =  json_decode(file_get_contents('text.json'), true);
// keyboard bot 
$keyboarddate = array(
    'text_sell' => $text_bot_var['btn_keyboard']['buy'],
    'text_usertest' => $text_bot_var['btn_keyboard']['test'],
    'text_Purchased_services' => $text_bot_var['btn_keyboard']['my_service'],
    'accountwallet' => $text_bot_var['btn_keyboard']['wallet'],
    'text_support' => $text_bot_var['btn_keyboard']['support'],
    'text_Admin' => "👨‍💼 پنل مدیریت",
);
$list_admin = select("botsaz", "*", "bot_token", $ApiToken, "select");
$admin_idsmain = select("admin", "id_admin", null, null, "FETCH_COLUMN");
$admin_ids_decoded = json_decode($list_admin['admin_ids'] ?? '[]', true);
if (!is_array($admin_ids_decoded)) {
    $admin_ids_decoded = [];
}

if (!is_array($admin_idsmain)) {
    $admin_idsmain = [];
}

if (!in_array($from_id, $admin_ids_decoded) && !in_array($from_id, $admin_idsmain)) {
    unset($keyboarddate['text_Admin']);
}
$keyboard = ['keyboard' => [], 'resize_keyboard' => true];
$tempArray = [];

foreach ($keyboarddate as $keyboardtext) {
    $tempArray[] = ['text' => $keyboardtext];
    if (count($tempArray) == 2) {
        $keyboard['keyboard'][] = $tempArray;
        $tempArray = [];
    }
}
if (count($tempArray) > 0) {
    $keyboard['keyboard'][] = $tempArray;
}
$keyboard  = json_encode($keyboard);

$backuser = json_encode([
    'keyboard' => [
        [['text' => "🏠 بازگشت به منوی اصلی"]]
    ],
    'resize_keyboard' => true,
    'input_field_placeholder' => "برای بازگشت روی دکمه زیر کلیک کنید"
]);

// keyboard list panel for test 

$stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE TestAccount = 'ONTestAccount' AND (agent = '{$userbot['agent']}' OR agent = 'all')");
$stmt->execute();
$list_marzban_panel_usertest = ['inline_keyboard' => []];
while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($result['hide_user'] != null and in_array($from_id, json_decode($result['hide_user'], true))) continue;
    if (in_array($result['name_panel'], $hide_panel)) continue;
    $list_marzban_panel_usertest['inline_keyboard'][] = [
        ['text' => $result['name_panel'], 'callback_data' => "locationtest_{$result['code_panel']}"]
    ];
}
$list_marzban_panel_usertest['inline_keyboard'][] = [
    ['text' => "🏠 بازگشت به منوی اصلی", 'callback_data' => "backuser"],
];
$list_marzban_usertest = json_encode($list_marzban_panel_usertest);


$keyboardadmin = json_encode([
    'keyboard' => [
        [
            ['text' => "📊 آمار ربات"]
        ],
        [
            ['text' => "💰 تنظیمات فروشگاه"],
            ['text' => "⚙️ وضعیت قابلیت ها"],
        ],
        [
            ['text' => "🔍 جستجو کاربر"],
            ['text' => "👨‍🔧  مدیریت ادمین ها"]
        ],
        [
            ['text' => "📝 تنظیم متون"],
            ['text' => "💳 تنظیمات پرداخت"],
        ],
        [
            ['text' => "📞 تنظیم نام کاربری پشتیبانی"],
            ['text' => "📬 گزارش ربات"],
        ],
        [
            ['text' => "📣 جوین اجباری"]
        ],
        [
            ['text' => "🏠 بازگشت به منوی اصلی"]
        ],
    ],
    'resize_keyboard' =>  true
]);
if (is_array($userbot) && agent_is_n2($userbot['agent'] ?? 'f')) {
    $adminKb = json_decode($keyboardadmin, true);
    if (is_array($adminKb) && isset($adminKb['keyboard'])) {
        array_splice($adminKb['keyboard'], 2, 0, [[['text' => '📦 مدیریت محصولات']]]);
        $keyboardadmin = json_encode($adminKb, JSON_UNESCAPED_UNICODE);
    }
}

$keyboardprice = json_encode([
    'keyboard' => [
        [
            ['text' => "🔋 قیمت حجم"],
            ['text' => "⌛️ قیمت زمان"],
        ],
        [
            ['text' => "💰 تنظیم قیمت محصول"],
            ['text' => "✏️ تنظیم نام محصول"],
        ],
        [
            ['text' => "بازگشت به منوی ادمین"]
        ],
    ],
    'resize_keyboard' =>  true
]);

$keyboard_change_price = json_encode([
    'keyboard' => [
        [
            ['text' => "💎 متن راهنمای کارت"],
            ['text' => "🛍 دکمه خرید"]
        ],
        [
            ['text' => "🔑 دکمه تست"],
            ['text' => "🛒 دکمه سرویس های من"]
        ],
        [
            ['text' => "👤 دکمه حساب کاربری"],
            ['text' => "☎️ متن دکمه پشتیبانی"]
        ],
        [
            ['text' => "💸 متن مرحله افزایش موجودی"]
        ],
        [
            ['text' => "بازگشت به منوی ادمین"]
        ]
    ],
    'resize_keyboard' =>  true
]);

$keyboard_payment = json_encode([
    'keyboard' => [
        [
            ['text' => "💳 شماره کارت"],
            ['text' => "👤 نام صاحب کارت"],
        ],
        [
            ['text' => "📝 متن راهنمای پرداخت"],
            ['text' => "👁 مشاهده تنظیمات پرداخت"],
        ],
        [
            ['text' => "بازگشت به منوی ادمین"]
        ]
    ],
    'resize_keyboard' => true
]);

$backadmin = json_encode([
    'keyboard' => [
        [
            ['text' => "بازگشت به منوی ادمین"]
        ],
    ],
    'resize_keyboard' =>  true
]);

//------------------  [ listpanelusers ]----------------//
$stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE status = 'active' AND (agent = '{$userbot['agent']}' OR agent = 'all')");
$stmt->execute();
$list_marzban_panel_users = ['inline_keyboard' => []];
while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($result['hide_user'] != null and in_array($from_id, json_decode($result['hide_user'], true))) continue;
    if (in_array($result['name_panel'], $hide_panel)) continue;
    $list_marzban_panel_users['inline_keyboard'][] = [
        ['text' => $result['name_panel'], 'callback_data' => "location_{$result['code_panel']}"]
    ];
}
$list_marzban_panel_users['inline_keyboard'][] = [
    ['text' => "🏠 بازگشت به منوی اصلی", 'callback_data' => "backuser"],
];
$list_marzban_panel_user = json_encode($list_marzban_panel_users);

$payment = json_encode([
    'inline_keyboard' => [
        [['text' => "💰 پرداخت و دریافت سرویس", 'callback_data' => "confirmandgetservice"]],
        [['text' => "🏠 بازگشت به منوی اصلی",  'callback_data' => "backuser"]]
    ]
]);
$KeyboardBalance = json_encode([
    'inline_keyboard' => [
        [['text' => "💸 افزایش موجودی", 'callback_data' => "AddBalance"]],
        [['text' => "🏠 بازگشت به منوی اصلی",  'callback_data' => "backuser"]]
    ]
]);

function KeyboardProduct($location, $query, $pricediscount, $datakeyboard, $statuscustom = false, $backuser = "backuser", $valuetow = null, $customvolume = "customsellvolume")
{
    global $pdo, $textbotlang, $userbot;
    $product = ['inline_keyboard' => []];
    $shopShow = select("shopSetting", "*", "Namevalue", "statusshowprice", "select");
    $statusshowprice = is_array($shopShow) ? ($shopShow['value'] ?? '') : '';
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $valuetow = $valuetow != null ? "-$valuetow" : "";
    $ownerIsAgentN = is_array($userbot) && (($userbot['agent'] ?? '') === 'n');
    foreach (sortProductsByOrder($stmt->fetchAll(PDO::FETCH_ASSOC)) as $result) {
        $productlist = readJsonFileIfExists('product.json');
        $productlist_name = readJsonFileIfExists('product_name.json');
        $hasRetailOverride = isset($productlist[$result['code_product']]);
        if ($hasRetailOverride) {
            $result['price_product'] = $productlist[$result['code_product']];
        } elseif ($ownerIsAgentN) {
            $result['price_product'] = agent_wholesale_cost($userbot, (int) ($result['Volume_constraint'] ?? 0));
        }
        $result['name_product'] = empty($productlist_name[$result['code_product']]) ? $result['name_product'] : $productlist_name[$result['code_product']];
        $hide_panel = json_decode($result['hide_panel'] ?? '[]', true);
        if (!is_array($hide_panel)) {
            $hide_panel = [];
        }
        if (in_array($location, $hide_panel, true)) {
            continue;
        }
        if (!product_category_is_active($result)) {
            continue;
        }
        if (!$ownerIsAgentN && intval($pricediscount) != 0) {
            $resultper = ($result['price_product'] * $pricediscount) / 100;
            $result['price_product'] = $result['price_product'] - $resultper;
        }
        $namekeyboard = $result['name_product'] . " - " . number_format($result['price_product']) . "تومان";
        if ($statusshowprice == "onshowprice")$result['name_product'] = $namekeyboard;
        $product['inline_keyboard'][] = [
            telegram_button_with_icon(
                ['text' =>  $result['name_product'], 'callback_data' => "{$datakeyboard}{$result['code_product']}{$valuetow}"],
                $result['emoji_id'] ?? ''
            )
        ];
    }
    if ($statuscustom) {
        $panelRow = select("marzban_panel", "*", "name_panel", $location, "select");
        $customBtn = panel_custom_service_inline_button(is_array($panelRow) ? $panelRow : [], $customvolume);
        $product['inline_keyboard'][] = [$customBtn];
    }
    $product['inline_keyboard'][] = [
        ['text' => $textbotlang['users']['stateus']['backinfo'], 'callback_data' => $backuser],
    ];
    return json_encode($product);
}
function KeyboardCategory($location, $agent, $backuser = "backuser", $agentUserId = null)
{
    global $pdo, $textbotlang, $from_id, $botinfo;
    $uid = $agentUserId;
    if ($uid === null && (agent_uses_category_whitelist($agent) || agent_is_n2($agent)) && !empty($botinfo['id_user'])) {
        $uid = $botinfo['id_user'];
    }
    if ($uid === null) {
        $uid = $from_id;
    }
    $accessSql = agent_product_access_sql($agent, $uid);
    $productTable = agent_product_table($agent);
    if (agent_is_n2($agent)) {
        $catRows = agent_own_list_categories($uid, true);
    } else {
        $catStmt = $pdo->prepare("SELECT * FROM category");
        $catStmt->execute();
        $catRows = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $list_category = ['inline_keyboard' => [],];
    foreach ($catRows as $row) {
        if (!category_is_active($row)) {
            continue;
        }
        $stmts = $pdo->prepare("SELECT * FROM {$productTable} WHERE (Location = :location OR Location = '/all') AND category = :category AND {$accessSql}");
        $stmts->bindParam(':location', $location, PDO::PARAM_STR);
        $stmts->bindParam(':category', $row['remark'], PDO::PARAM_STR);
        $stmts->execute();
        $visibleCount = 0;
        foreach ($stmts->fetchAll(PDO::FETCH_ASSOC) as $prodRow) {
            $hide_panel = json_decode($prodRow['hide_panel'] ?? '[]', true);
            if (!is_array($hide_panel)) {
                $hide_panel = [];
            }
            if (in_array($location, $hide_panel, true)) {
                continue;
            }
            $visibleCount++;
            break;
        }
        if ($visibleCount === 0) {
            continue;
        }
        $list_category['inline_keyboard'][] = [telegram_button_with_icon(
            ['text' => $row['remark'], 'callback_data' => "categorynames_" . $row['id']],
            $row['emoji_id'] ?? ''
        )];
    }
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    if (!agent_is_n2($agent) && is_array($panel) && panel_custom_enabled($panel, (string) $agent)) {
        $list_category['inline_keyboard'][] = [
            panel_custom_service_inline_button($panel, 'customvolumebuy'),
        ];
    }
    $list_category['inline_keyboard'][] = [
        ['text' => "▶️ بازگشت به منوی قبل", "callback_data" => $backuser],
    ];
    return json_encode($list_category);
}
