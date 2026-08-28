<?php

function affiliates_lib_settings(PDO $pdo): array
{
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

function affiliates_lib_list_referrers(PDO $pdo, string $search = '', int $limit = 25, int $offset = 0): array
{
    $where = ["IFNULL(u.affiliatescount, '') != ''", "u.affiliatescount != '0'"];
    $params = [];

    if (function_exists('ads_ensure_schema')) {
        ads_ensure_schema();
        $where[] = "CAST(u.id AS CHAR) NOT IN (
            SELECT a.source_user_id FROM ad_advertiser a
            WHERE IFNULL(a.source_user_id, '') != ''
        )";
    }

    if ($search !== '') {
        $where[] = '(CAST(u.id AS CHAR) LIKE ?
                     OR COALESCE(u.username, \'\') LIKE ?
                     OR COALESCE(u.namecustom, \'\') LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);
    $fromSQL = 'FROM user u
         LEFT JOIN (
            SELECT u2.affiliates AS referrer_id, COUNT(DISTINCT u2.id) AS buyer_count
            FROM user u2
            INNER JOIN invoice i ON i.id_user = u2.id
            WHERE i.name_product != \'سرویس تست\'
              AND i.Status != \'Unpaid\'
              AND IFNULL(u2.affiliates, \'\') != \'\'
              AND u2.affiliates != \'0\'
            GROUP BY u2.affiliates
         ) b ON CAST(b.referrer_id AS CHAR) = CAST(u.id AS CHAR)';

    $total = db_count($pdo, "SELECT COUNT(*) FROM user u $whereSQL", $params);
    $rows = db_fetchAll(
        $pdo,
        "SELECT u.id, u.username, u.namecustom, u.affiliatescount, u.Balance,
                COALESCE(b.buyer_count, 0) AS buyer_count
         $fromSQL
         $whereSQL
         ORDER BY CAST(u.affiliatescount AS UNSIGNED) DESC, u.id DESC
         LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
        $params
    );

    return ['rows' => $rows, 'total' => $total];
}

function affiliates_lib_list_history(PDO $pdo, string $search = '', int $limit = 25, int $offset = 0): array
{
    $where = [];
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
    $fromSQL = 'FROM reagent_report r
         LEFT JOIN user invited ON invited.id = r.user_id
         LEFT JOIN user referrer ON CAST(referrer.id AS CHAR) = CAST(r.reagent AS CHAR)';

    $total = db_count($pdo, "SELECT COUNT(*) $fromSQL $whereSQL", $params);
    $rows = db_fetchAll(
        $pdo,
        "SELECT r.id, r.user_id, r.reagent, r.time, r.get_gift,
                invited.username AS invited_username,
                referrer.username AS referrer_username
         $fromSQL
         $whereSQL
         ORDER BY r.id DESC
         LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
        $params
    );

    return ['rows' => $rows, 'total' => $total];
}
