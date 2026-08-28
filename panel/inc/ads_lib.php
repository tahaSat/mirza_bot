<?php

function ads_lib_normalize_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return date('Y/m/d');
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
        return $m[1] . '/' . $m[2] . '/' . $m[3];
    }
    if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})/', $raw, $m)) {
        return $m[1] . '/' . $m[2] . '/' . $m[3];
    }
    return $raw;
}

function ads_lib_date_input_value(string $stored): string
{
    $stored = trim($stored);
    if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})/', $stored, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $stored, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    return date('Y-m-d');
}

function ads_lib_list(PDO $pdo, string $search = '', int $limit = 25, int $offset = 0): array
{
    ads_ensure_schema();
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(name LIKE ? OR code LIKE ? OR CAST(id AS CHAR) LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }
    $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $total = db_count($pdo, "SELECT COUNT(*) FROM ad_advertiser $whereSQL", $params);
    $rows = db_fetchAll(
        $pdo,
        "SELECT * FROM ad_advertiser $whereSQL ORDER BY id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
        $params
    );
    return ['rows' => $rows, 'total' => $total];
}

function ads_lib_get(PDO $pdo, int $id): ?array
{
    ads_ensure_schema();
    return db_fetch($pdo, 'SELECT * FROM ad_advertiser WHERE id = ?', [$id]);
}

function ads_lib_sync_payment(PDO $pdo, array $advertiser): ?string
{
    require_once __DIR__ . '/payments_lib.php';
    panel_payment_ensure_schema($pdo);

    $amount = (int) ($advertiser['amount'] ?? 0);
    $orderId = trim((string) ($advertiser['payment_order_id'] ?? ''));
    $note = 'هزینه تبلیغ — ' . (string) ($advertiser['name'] ?? '');
    $time = ads_lib_date_input_value((string) ($advertiser['started_at'] ?? '')) . 'T00:00';

    if ($amount < 1) {
        if ($orderId !== '') {
            panel_payment_delete_cost($pdo, $orderId);
            db_query($pdo, 'UPDATE ad_advertiser SET payment_order_id = NULL WHERE id = ?', [(int) $advertiser['id']]);
        }
        return null;
    }

    if ($orderId !== '') {
        $existing = db_fetch($pdo, "SELECT id FROM Payment_report WHERE id_order = ? AND payment_Status = 'cost'", [$orderId]);
        if ($existing) {
            panel_payment_update_row($pdo, $orderId, [
                'amount' => $amount,
                'id_user' => '',
                'note' => $note,
                'expense_category' => 'ads',
                'time' => $time,
            ]);
            return $orderId;
        }
    }

    $result = panel_payment_add_cost($pdo, [
        'amount' => $amount,
        'id_user' => '',
        'note' => $note,
        'expense_category' => 'ads',
        'time' => $time,
    ]);
    if (empty($result['ok'])) {
        throw new RuntimeException($result['msg'] ?? 'ثبت هزینه تبلیغ ناموفق بود.');
    }
    $newOrder = (string) ($result['id_order'] ?? '');
    db_query($pdo, 'UPDATE ad_advertiser SET payment_order_id = ? WHERE id = ?', [$newOrder, (int) $advertiser['id']]);
    return $newOrder;
}

function ads_lib_save(PDO $pdo, array $data, ?int $id = null): array
{
    ads_ensure_schema();
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('نام تبلیغ‌کننده الزامی است.');
    }

    $joinCount = trim((string) ($data['join_count'] ?? '0'));
    if ($joinCount === '' || !ctype_digit($joinCount)) {
        throw new InvalidArgumentException('تعداد جوین باید عدد باشد.');
    }

    $amount = trim((string) ($data['amount'] ?? '0'));
    if ($amount === '' || !ctype_digit($amount)) {
        throw new InvalidArgumentException('مبلغ تبلیغ باید عدد باشد.');
    }

    $startedAt = ads_lib_normalize_date((string) ($data['started_at'] ?? ''));
    $now = date('Y/m/d H:i:s');

    if ($id) {
        $row = ads_lib_get($pdo, $id);
        if (!$row) {
            throw new InvalidArgumentException('تبلیغ یافت نشد.');
        }
        db_query(
            $pdo,
            'UPDATE ad_advertiser SET name = ?, join_count = ?, amount = ?, started_at = ? WHERE id = ?',
            [$name, (int) $joinCount, (int) $amount, $startedAt, $id]
        );
        $saved = ads_lib_get($pdo, $id);
        ads_lib_sync_payment($pdo, $saved ?? $row);
        return ads_lib_get($pdo, $id) ?? $saved;
    }

    $code = ads_generate_code();
    db_query(
        $pdo,
        'INSERT INTO ad_advertiser (name, code, join_count, amount, started_at, payment_order_id, source_user_id, created_at)
         VALUES (?, ?, ?, ?, ?, NULL, NULL, ?)',
        [$name, $code, (int) $joinCount, (int) $amount, $startedAt, $now]
    );
    $newId = (int) $pdo->lastInsertId();
    $saved = ads_lib_get($pdo, $newId);
    if ($saved) {
        ads_lib_sync_payment($pdo, $saved);
        $saved = ads_lib_get($pdo, $newId) ?? $saved;
    }
    return $saved ?? [];
}

function ads_lib_delete(PDO $pdo, int $id): void
{
    ads_ensure_schema();
    $row = ads_lib_get($pdo, $id);
    if (!$row) {
        return;
    }
    $orderId = trim((string) ($row['payment_order_id'] ?? ''));
    if ($orderId !== '') {
        require_once __DIR__ . '/payments_lib.php';
        panel_payment_delete_cost($pdo, $orderId);
    }
    db_query($pdo, 'DELETE FROM ad_join WHERE advertiser_id = ?', [$id]);
    db_query($pdo, 'DELETE FROM ad_advertiser WHERE id = ?', [$id]);
}
