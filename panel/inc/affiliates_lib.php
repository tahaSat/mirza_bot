<?php

function affiliates_lib_ensure_migration_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $columns = [
        'migrated_to_ads' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'ad_advertiser_id' => 'INT UNSIGNED NULL',
    ];
    foreach ($columns as $name => $definition) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(['reagent_report', $name]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE reagent_report ADD $name $definition");
        }
    }
    $ready = true;
}

function affiliates_lib_settings(PDO $pdo): array
{
    affiliates_lib_ensure_migration_schema($pdo);
    $setting = db_fetch($pdo, 'SELECT affiliatesstatus, affiliatespercentage FROM setting LIMIT 1') ?? [];
    $affiliates = db_fetch($pdo, 'SELECT status_commission, Discount, price_Discount, porsant_one_buy FROM affiliates LIMIT 1') ?? [];
    $percentage = (string) ($setting['affiliatespercentage'] ?? '0');
    $price = (string) ($affiliates['price_Discount'] ?? '0');
    if ($percentage === '') {
        $percentage = '0';
    }
    if ($price === '' || $price === 'none') {
        $price = '0';
    }

    return [
        'status' => (string) ($setting['affiliatesstatus'] ?? 'offaffiliates'),
        'percentage' => $percentage,
        'status_commission' => (string) ($affiliates['status_commission'] ?? 'oncommission'),
        'discount' => (string) ($affiliates['Discount'] ?? 'onDiscountaffiliates'),
        'price_discount' => $price,
        'porsant_one_buy' => (string) ($affiliates['porsant_one_buy'] ?? 'off_buy_porsant'),
    ];
}

function affiliates_lib_save_settings(PDO $pdo, array $data): void
{
    $percentage = trim((string) ($data['percentage'] ?? '0'));
    if ($percentage === '' || !ctype_digit($percentage) || (int) $percentage > 100) {
        throw new InvalidArgumentException('درصد پورسانت باید عددی بین ۰ تا ۱۰۰ باشد.');
    }

    $price = trim((string) ($data['price_discount'] ?? '0'));
    if ($price === '' || !ctype_digit($price)) {
        throw new InvalidArgumentException('مبلغ هدیه استارت باید عدد باشد.');
    }

    $status = !empty($data['status']) ? 'onaffiliates' : 'offaffiliates';
    $commission = !empty($data['status_commission']) ? 'oncommission' : 'offcommission';
    $discount = !empty($data['discount']) ? 'onDiscountaffiliates' : 'offDiscountaffiliates';
    $oneBuy = !empty($data['porsant_one_buy']) ? 'on_buy_porsant' : 'off_buy_porsant';

    db_query($pdo, 'UPDATE setting SET affiliatesstatus = ?, affiliatespercentage = ?', [$status, $percentage]);

    $exists = db_fetch($pdo, 'SELECT status_commission FROM affiliates LIMIT 1');
    if ($exists) {
        db_query(
            $pdo,
            'UPDATE affiliates SET status_commission = ?, Discount = ?, price_Discount = ?, porsant_one_buy = ?',
            [$commission, $discount, $price, $oneBuy]
        );
        return;
    }

    db_query(
        $pdo,
        'INSERT INTO affiliates (description, id_media, status_commission, Discount, price_Discount, porsant_one_buy)
         VALUES (?, ?, ?, ?, ?, ?)',
        ['none', 'none', $commission, $discount, $price, $oneBuy]
    );
}

function affiliates_lib_repair_counts(PDO $pdo): void
{
    affiliates_lib_ensure_migration_schema($pdo);
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $col = $pdo->query("SHOW COLUMNS FROM setting LIKE 'affiliates_counts_repaired'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec("ALTER TABLE setting ADD affiliates_counts_repaired VARCHAR(10) NOT NULL DEFAULT '0'");
        }
        $flagRow = db_fetch($pdo, 'SELECT affiliates_counts_repaired FROM setting LIMIT 1');
        if ((string) ($flagRow['affiliates_counts_repaired'] ?? '0') === '1') {
            return;
        }
    } catch (Throwable $e) {
        error_log('affiliates_lib_repair_counts flag: ' . $e->getMessage());
    }

    try {
        $pdo->exec(
            "UPDATE user u
             INNER JOIN (
                SELECT referrer_id, COUNT(DISTINCT invited_id) AS cnt
                FROM (
                    SELECT CAST(reagent AS CHAR) AS referrer_id, CAST(user_id AS CHAR) AS invited_id
                    FROM reagent_report
                    WHERE COALESCE(migrated_to_ads, 0) = 0
                      AND IFNULL(reagent, '') != '' AND reagent != '0'
                    UNION
                    SELECT CAST(affiliates AS CHAR) AS referrer_id, CAST(id AS CHAR) AS invited_id
                    FROM user
                    WHERE IFNULL(affiliates, '') != '' AND affiliates != '0'
                ) inv
                GROUP BY referrer_id
             ) s ON CAST(u.id AS CHAR) = s.referrer_id
             SET u.affiliatescount = CAST(s.cnt AS CHAR)"
        );
        $pdo->exec("UPDATE setting SET affiliates_counts_repaired = '1'");
    } catch (Throwable $e) {
        error_log('affiliates_lib_repair_counts update: ' . $e->getMessage());
    }
}

function affiliates_lib_list_referrers(PDO $pdo, string $search = '', int $limit = 25, int $offset = 0, string $sort = 'affiliatescount', string $dir = 'desc'): array
{
    affiliates_lib_ensure_migration_schema($pdo);
    affiliates_lib_repair_counts($pdo);

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(CAST(r.referrer_id AS CHAR) LIKE ?
                     OR COALESCE(u.username, \'\') LIKE ?
                     OR COALESCE(u.namecustom, \'\') LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $fromSQL = 'FROM (
            SELECT referrer_id, COUNT(DISTINCT invited_id) AS invite_count
            FROM (
                SELECT CAST(reagent AS CHAR) AS referrer_id, CAST(user_id AS CHAR) AS invited_id
                FROM reagent_report
                WHERE COALESCE(migrated_to_ads, 0) = 0
                  AND IFNULL(reagent, \'\') != \'\' AND reagent != \'0\'
                UNION
                SELECT CAST(affiliates AS CHAR) AS referrer_id, CAST(id AS CHAR) AS invited_id
                FROM user
                WHERE IFNULL(affiliates, \'\') != \'\' AND affiliates != \'0\'
            ) inv
            GROUP BY referrer_id
         ) r
         LEFT JOIN user u ON CAST(u.id AS CHAR) = r.referrer_id
         LEFT JOIN (
            SELECT u2.affiliates AS referrer_id, COUNT(DISTINCT u2.id) AS buyer_count
            FROM user u2
            INNER JOIN invoice i ON CAST(i.id_user AS CHAR) = CAST(u2.id AS CHAR)
            WHERE i.name_product != \'سرویس تست\'
              AND i.Status != \'Unpaid\'
              AND IFNULL(u2.affiliates, \'\') != \'\'
              AND u2.affiliates != \'0\'
            GROUP BY u2.affiliates
         ) b ON CAST(b.referrer_id AS CHAR) = r.referrer_id';

    $dirSql = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
    $orderMap = [
        'balance' => 'CAST(u.Balance AS SIGNED) ' . $dirSql . ', r.invite_count DESC, r.referrer_id DESC',
        'affiliatescount' => 'r.invite_count ' . $dirSql . ', r.referrer_id DESC',
        'buyer_count' => 'COALESCE(b.buyer_count, 0) ' . $dirSql . ', r.invite_count DESC, r.referrer_id DESC',
    ];
    $orderSql = $orderMap[$sort] ?? $orderMap['affiliatescount'];

    $total = db_count($pdo, "SELECT COUNT(*) $fromSQL $whereSQL", $params);
    $rows = db_fetchAll(
        $pdo,
        "SELECT COALESCE(u.id, r.referrer_id) AS id,
                u.username, u.namecustom, u.Balance,
                r.invite_count AS affiliatescount,
                COALESCE(b.buyer_count, 0) AS buyer_count
         $fromSQL
         $whereSQL
         ORDER BY $orderSql
         LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
        $params
    );

    return ['rows' => $rows, 'total' => $total];
}

function affiliates_lib_list_history(PDO $pdo, string $search = '', int $limit = 25, int $offset = 0): array
{
    affiliates_lib_ensure_migration_schema($pdo);
    $where = ['COALESCE(r.migrated_to_ads, 0) = 0'];
    $params = [];

    if ($search !== '') {
        $where[] = '(CAST(r.user_id AS CHAR) LIKE ?
                     OR CAST(r.reagent AS CHAR) LIKE ?
                     OR COALESCE(invited.username, \'\') LIKE ?
                     OR COALESCE(referrer.username, \'\') LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $paidSql = function_exists('invoice_paid_status_sql')
        ? invoice_paid_status_sql('i.Status')
        : "i.Status != 'Unpaid'";
    $fromSQL = 'FROM reagent_report r
         LEFT JOIN user invited ON invited.id = r.user_id
         LEFT JOIN user referrer ON CAST(referrer.id AS CHAR) = CAST(r.reagent AS CHAR)
         LEFT JOIN (
            SELECT CAST(i.id_user AS CHAR) AS invited_id, COUNT(*) AS paid_orders
            FROM invoice i
            WHERE i.name_product != \'سرویس تست\'
              AND (' . $paidSql . ')
            GROUP BY CAST(i.id_user AS CHAR)
         ) p ON p.invited_id = CAST(r.user_id AS CHAR)';

    $total = db_count($pdo, "SELECT COUNT(*) $fromSQL $whereSQL", $params);
    $rows = db_fetchAll(
        $pdo,
        "SELECT r.id, r.user_id, r.reagent, r.time,
                invited.username AS invited_username,
                referrer.username AS referrer_username,
                COALESCE(p.paid_orders, 0) AS paid_orders
         $fromSQL
         $whereSQL
         ORDER BY r.id DESC
         LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
        $params
    );

    return ['rows' => $rows, 'total' => $total];
}
