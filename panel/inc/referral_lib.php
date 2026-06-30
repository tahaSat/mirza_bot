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

function referral_lib_recent_invites(PDO $pdo, int $campaign_id, int $limit = 20): array
{
    return db_fetchAll(
        $pdo,
        "SELECT ri.*, u1.username AS referrer_username, u2.username AS invited_username
         FROM referral_invite ri
         LEFT JOIN user u1 ON u1.id = ri.referrer_id
         LEFT JOIN user u2 ON u2.id = ri.invited_user_id
         WHERE ri.campaign_id = ?
         ORDER BY ri.id DESC
         LIMIT " . (int) $limit,
        [$campaign_id]
    );
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
