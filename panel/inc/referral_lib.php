<?php

function referral_lib_products(PDO $pdo): array
{
    return db_fetchAll(
        $pdo,
        "SELECT id, name_product, code_product, Location, price_product, Volume_constraint, Service_time
         FROM product
         WHERE Location IS NOT NULL AND Location != '' AND Location != '/all'
         ORDER BY name_product"
    );
}

function referral_lib_list_campaigns(PDO $pdo): array
{
    referral_ensure_schema();
    $rows = db_fetchAll($pdo, "SELECT * FROM referral_campaign ORDER BY id DESC");
    foreach ($rows as &$row) {
        $row['stats'] = referral_lib_campaign_stats($pdo, (int) $row['id']);
    }
    unset($row);
    return $rows;
}

function referral_lib_campaign_stats(PDO $pdo, int $campaign_id): array
{
    return [
        'invites' => db_count($pdo, "SELECT COUNT(*) FROM referral_invite WHERE campaign_id = ?", [$campaign_id]),
        'referrers' => db_count($pdo, "SELECT COUNT(DISTINCT referrer_id) FROM referral_invite WHERE campaign_id = ?", [$campaign_id]),
        'rewards' => db_count($pdo, "SELECT COUNT(*) FROM referral_reward WHERE campaign_id = ?", [$campaign_id]),
    ];
}

function referral_lib_list_invites(PDO $pdo, int $campaign_id, string $search = '', int $limit = 25, int $offset = 0): array
{
    $where = ['ri.campaign_id = ?'];
    $params = [$campaign_id];

    if ($search !== '') {
        $where[] = '(CAST(ri.referrer_id AS CHAR) LIKE ?
                     OR CAST(ri.invited_user_id AS CHAR) LIKE ?
                     OR COALESCE(u1.username, \'\') LIKE ?
                     OR COALESCE(u2.username, \'\') LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);
    $fromSQL = 'FROM referral_invite ri
         LEFT JOIN user u1 ON u1.id = ri.referrer_id
         LEFT JOIN user u2 ON u2.id = ri.invited_user_id';

    $total = db_count($pdo, "SELECT COUNT(*) $fromSQL $whereSQL", $params);
    $rows = db_fetchAll(
        $pdo,
        "SELECT ri.*, u1.username AS referrer_username, u2.username AS invited_username
         $fromSQL
         $whereSQL
         ORDER BY ri.id DESC
         LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
        $params
    );

    return ['rows' => $rows, 'total' => $total];
}

function referral_lib_recent_invites(PDO $pdo, int $campaign_id, int $limit = 20): array
{
    return referral_lib_list_invites($pdo, $campaign_id, '', $limit, 0)['rows'];
}

function referral_lib_get_campaign(PDO $pdo, int $id): ?array
{
    return db_fetch($pdo, "SELECT * FROM referral_campaign WHERE id = ?", [$id]);
}

function referral_lib_validate_code(string $code): bool
{
    return (bool) preg_match('/^[A-Za-z0-9]{2,20}$/', $code);
}

function referral_lib_save_campaign(PDO $pdo, array $data, ?int $id = null): void
{
    referral_ensure_schema();
    $product = db_fetch($pdo, "SELECT * FROM product WHERE code_product = ?", [$data['code_product'] ?? '']);
    if (!$product) {
        throw new InvalidArgumentException('محصول انتخاب‌شده یافت نشد.');
    }
    if (($product['Location'] ?? '') === '' || ($product['Location'] ?? '') === '/all') {
        throw new InvalidArgumentException('محصول باید به یک پنل مشخص متصل باشد (نه /all).');
    }

    $required = max(1, (int) ($data['required_invites'] ?? 1));
    $title = trim($data['title'] ?? '');
    $description = trim($data['description'] ?? '');
    $status = ($data['status'] ?? 'inactive') === 'active' ? 'active' : 'inactive';
    $new_users_only = !empty($data['new_users_only']) ? 1 : 0;
    $panel_name = $product['Location'];

    if ($id) {
        $existing = referral_lib_get_campaign($pdo, $id);
        if (!$existing) {
            throw new InvalidArgumentException('کمپین یافت نشد.');
        }
        $code = $existing['code'] ?? referral_auto_campaign_code($id);
        if ($title === '') {
            $title = $existing['title'] ?? ('کمپین #' . $id);
        }
        db_query(
            $pdo,
            "UPDATE referral_campaign SET title=?, description=?, code_product=?, panel_name=?, required_invites=?, status=?, new_users_only=? WHERE id=?",
            [$title, $description, $product['code_product'], $panel_name, $required, $status, $new_users_only, $id]
        );
        return;
    }

    $placeholder = 'REF' . strtoupper(bin2hex(random_bytes(3)));
    db_query(
        $pdo,
        "INSERT INTO referral_campaign (code, title, description, code_product, panel_name, required_invites, status, new_users_only, created_at)
         VALUES (?,?,?,?,?,?,?,?,?)",
        [$placeholder, $title !== '' ? $title : 'کمپین جدید', $description, $product['code_product'], $panel_name, $required, $status, $new_users_only, date('Y/m/d H:i:s')]
    );
    $new_id = (int) $pdo->lastInsertId();
    $auto_code = referral_auto_campaign_code($new_id);
    if ($title === '') {
        $title = 'کمپین #' . $new_id;
        db_query($pdo, "UPDATE referral_campaign SET code = ?, title = ? WHERE id = ?", [$auto_code, $title, $new_id]);
    } else {
        db_query($pdo, "UPDATE referral_campaign SET code = ? WHERE id = ?", [$auto_code, $new_id]);
    }
}

function referral_lib_toggle_status(PDO $pdo, int $id): void
{
    $row = referral_lib_get_campaign($pdo, $id);
    if (!$row) {
        throw new InvalidArgumentException('کمپین یافت نشد.');
    }
    $new = ($row['status'] ?? '') === 'active' ? 'inactive' : 'active';
    db_query($pdo, "UPDATE referral_campaign SET status = ? WHERE id = ?", [$new, $id]);
}

function referral_lib_toggle_master(PDO $pdo): string
{
    referral_ensure_schema();
    $setting = select('setting', 'referralstatus', null, null, 'select', ['cache' => false]);
    $current = $setting['referralstatus'] ?? 'offreferral';
    $new = $current === 'onreferral' ? 'offreferral' : 'onreferral';
    update('setting', 'referralstatus', $new, null, null);
    clearSelectCache('setting');
    return $new;
}

function referral_lib_master_status(PDO $pdo): string
{
    $setting = select('setting', 'referralstatus', null, null, 'select', ['cache' => false]);
    return $setting['referralstatus'] ?? 'offreferral';
}

/**
 * Referrers who met required_invites but have no referral_reward row.
 *
 * @return list<array{referrer_id:string,invite_count:int,username:?string}>
 */
function referral_lib_pending_rewards(PDO $pdo, int $campaign_id): array
{
    $campaign = referral_lib_get_campaign($pdo, $campaign_id);
    if (!$campaign) {
        return [];
    }

    $required = max(1, (int) ($campaign['required_invites'] ?? 1));

    return db_fetchAll(
        $pdo,
        "SELECT ri.referrer_id,
                COUNT(*) AS invite_count,
                u.username AS username
         FROM referral_invite ri
         LEFT JOIN user u ON u.id = ri.referrer_id
         LEFT JOIN referral_reward rr
           ON rr.campaign_id = ri.campaign_id AND rr.user_id = ri.referrer_id
         WHERE ri.campaign_id = ?
           AND rr.id IS NULL
         GROUP BY ri.referrer_id, u.username
         HAVING COUNT(*) >= ?
         ORDER BY invite_count DESC, ri.referrer_id ASC",
        [$campaign_id, $required]
    );
}

/**
 * Manually provision the campaign prize for a referrer who already qualifies.
 * Uses the panel service path (same as user_services add) instead of bot provision_free_service.
 *
 * @return array{ok:bool,msg:string}
 */
function referral_lib_manual_grant(PDO $pdo, int $campaign_id, $user_id): array
{
    try {
        if (!function_exists('panel_add_user_service')) {
            require_once __DIR__ . '/users_lib.php';
        }
        panel_service_bootstrap();

        global $ManagePanel, $textbotlang, $datatextbot, $setting, $buyreport;

        $campaign = referral_lib_get_campaign($pdo, $campaign_id);
        if (!$campaign) {
            return ['ok' => false, 'msg' => 'کمپین یافت نشد.'];
        }

        $user_id = (string) $user_id;
        if ($user_id === '' || !ctype_digit($user_id)) {
            return ['ok' => false, 'msg' => 'آیدی کاربر نامعتبر است.'];
        }

        if (referral_has_reward($campaign_id, $user_id)) {
            return ['ok' => false, 'msg' => 'این کاربر قبلاً جایزه این کمپین را دریافت کرده است.'];
        }

        $invite_count = referral_count_invites($campaign_id, $user_id);
        $required = max(1, (int) ($campaign['required_invites'] ?? 1));
        if ($invite_count < $required) {
            return ['ok' => false, 'msg' => "تعداد دعوت کافی نیست ({$invite_count} / {$required})."];
        }

        $product = db_fetch($pdo, 'SELECT * FROM product WHERE code_product = ? LIMIT 1', [$campaign['code_product']]);
        $panel = db_fetch($pdo, 'SELECT * FROM marzban_panel WHERE name_panel = ? LIMIT 1', [$campaign['panel_name']]);
        if (!$product || !$panel) {
            return ['ok' => false, 'msg' => 'محصول یا پنل جایزه یافت نشد.'];
        }

        $user = db_fetch($pdo, 'SELECT * FROM user WHERE id = ?', [$user_id]);
        if (!$user) {
            return ['ok' => false, 'msg' => 'کاربر در دیتابیس ربات یافت نشد.'];
        }

        if (!isset($ManagePanel)) {
            $ManagePanel = new ManagePanel();
        }

        $lastError = 'ساخت سرویس ناموفق بود.';
        $created = null;
        $username = '';
        $idInvoice = '';

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $suffix = bin2hex(random_bytes(3));
            $base = substr($user_id, -12);
            $username = strtolower($base . '_' . $suffix);
            if (!preg_match('/^\w{3,32}$/', $username)) {
                $username = strtolower('r' . $suffix . substr(md5($user_id), 0, 8));
            }

            if (db_count($pdo, 'SELECT COUNT(*) FROM invoice WHERE username = ?', [$username]) > 0) {
                $lastError = 'نام کاربری تکراری در فاکتورها.';
                continue;
            }

            $DataUserOut = $ManagePanel->DataUser($panel['name_panel'], $username);
            if (($DataUserOut['status'] ?? '') !== 'Unsuccessful' && !empty($DataUserOut['username'])) {
                $lastError = 'نام کاربری روی پنل از قبل وجود دارد.';
                continue;
            }

            $serviceTime = (int) ($product['Service_time'] ?? 0);
            $datetimestep = $serviceTime === 0 ? 0 : strtotime('+' . $serviceTime . ' days');
            $datac = [
                'expire' => $datetimestep,
                'data_limit' => (int) ($product['Volume_constraint'] ?? 0) * pow(1024, 3),
                'from_id' => $user_id,
                'username' => (string) ($user['username'] ?? ''),
                'type' => 'buy',
            ];

            $DataUserOut = $ManagePanel->createUser(
                $panel['name_panel'],
                $product['code_product'],
                $username,
                $datac
            );

            if (empty($DataUserOut['username'])) {
                $err = $DataUserOut['msg'] ?? 'unknown';
                if (is_array($err) || is_object($err)) {
                    $err = json_encode($err, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $lastError = (string) $err;
                continue;
            }

            $idInvoice = bin2hex(random_bytes(4));
            $notifctions = json_encode(['volume' => false, 'time' => false]);
            $note = 'referral_reward_' . ($campaign['code'] ?? $campaign_id);

            db_query(
                $pdo,
                'INSERT INTO invoice (id_user, id_invoice, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status, notifctions) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $user_id,
                    $idInvoice,
                    $DataUserOut['username'],
                    time(),
                    $panel['name_panel'],
                    $product['name_product'],
                    0,
                    $product['Volume_constraint'],
                    $product['Service_time'],
                    'active',
                    $notifctions,
                ]
            );
            try {
                db_query($pdo, 'UPDATE invoice SET note = ? WHERE id_invoice = ?', [$note, $idInvoice]);
            } catch (Throwable $e) {
                // note column may be absent on some installs
            }

            $created = $DataUserOut;
            $username = (string) $DataUserOut['username'];
            break;
        }

        if ($created === null) {
            return ['ok' => false, 'msg' => 'خطا در ساخت ساب/سرویس: ' . $lastError];
        }

        try {
            db_query(
                $pdo,
                'INSERT INTO referral_reward (campaign_id, user_id, id_invoice, granted_at) VALUES (?, ?, ?, ?)',
                [$campaign_id, $user_id, $idInvoice, date('Y/m/d H:i:s')]
            );
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'msg' => 'سرویس ساخته شد (' . $username . ') ولی ثبت جایزه ناموفق بود: ' . $e->getMessage(),
            ];
        }

        $notifyError = '';
        try {
            $output_config_link = ($panel['sublink'] ?? '') === 'onsublink' ? ($created['subscription_url'] ?? '') : '';
            $config = '';
            $configs = $created['configs'] ?? ($created['links'] ?? []);
            if (($panel['config'] ?? '') === 'onconfig' && is_array($configs)) {
                foreach ($configs as $link) {
                    $config .= "\n" . $link;
                }
            }

            $textTemplate = $datatextbot['textafterpay'] ?? '✅ سرویس {name_service} برای {username} ایجاد شد.';
            if (($panel['type'] ?? '') === 'Manualsale') {
                $textTemplate = $datatextbot['textmanual'] ?? $textTemplate;
            } elseif (in_array($panel['type'] ?? '', ['ibsng', 'mikrotik'], true)) {
                $textTemplate = $datatextbot['textafterpayibsng'] ?? $textTemplate;
            }

            $dayLabel = (int) ($product['Service_time'] ?? 0) === 0
                ? ($textbotlang['users']['stateus']['Unlimited'] ?? 'نامحدود')
                : $product['Service_time'];
            $volumeLabel = (int) ($product['Volume_constraint'] ?? 0) === 0
                ? ($textbotlang['users']['stateus']['Unlimited'] ?? 'نامحدود')
                : $product['Volume_constraint'];

            $textcreatuser = str_replace(
                ['{username}', '{name_service}', '{location}', '{day}', '{volume}', '{config}', '{links}', '{links2}'],
                [
                    '<code>' . $username . '</code>',
                    $product['name_product'],
                    $panel['name_panel'],
                    $dayLabel,
                    $volumeLabel,
                    '<code>' . $output_config_link . '</code>',
                    $config,
                    $output_config_link,
                ],
                $textTemplate
            );

            $reward_text = "<b>🎉 تبریک! هدیه دعوت دریافت شد</b>\n\n";
            $reward_text .= "کمپین: <b>{$campaign['title']}</b>\n";
            $reward_text .= "سرویس: <b>{$product['name_product']}</b>\n";
            $reward_text .= "نام کاربری: <code>{$username}</code>";

            if (function_exists('sendmessage')) {
                sendmessage($user_id, $reward_text, null, 'HTML');
            }

            if (function_exists('sendMessageService')) {
                $Shoppinginfo = json_encode([
                    'inline_keyboard' => [[
                        ['text' => $textbotlang['users']['help']['btninlinebuy'] ?? 'راهنما', 'callback_data' => 'helpbtn'],
                    ]],
                ]);
                sendMessageService(
                    $panel,
                    is_array($configs) ? $configs : [],
                    $output_config_link,
                    $username,
                    $Shoppinginfo,
                    $textcreatuser,
                    $idInvoice,
                    $user_id
                );
            } elseif (function_exists('panel_notify_user')) {
                panel_notify_user($user_id, strip_tags(str_replace(['<code>', '</code>'], '', $textcreatuser)));
            }

            if (strlen($setting['Channel_Report'] ?? '') > 0 && function_exists('telegram')) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $buyreport ?? 0,
                    'text' => "🎁 هدیه دعوت (پنل)\nکمپین: {$campaign['title']}\nکاربر: {$user_id}\nسرویس: {$product['name_product']}\nیوزرنیم: {$username}",
                    'parse_mode' => 'HTML',
                ]);
            }
        } catch (Throwable $e) {
            $notifyError = $e->getMessage();
            error_log('referral_lib_manual_grant notify: ' . $notifyError);
        }

        $msg = 'جایزه برای ' . $user_id . ' ثبت و سرویس «' . $username . '» ساخته شد.';
        if ($notifyError !== '') {
            $msg .= ' (سرویس ساخته شد؛ ارسال تلگرام خطا داد: ' . $notifyError . ')';
        }

        return ['ok' => true, 'msg' => $msg];
    } catch (Throwable $e) {
        error_log('referral_lib_manual_grant: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'خطای سیستمی: ' . $e->getMessage()];
    }
}
