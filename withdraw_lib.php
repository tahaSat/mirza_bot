<?php

const WITHDRAW_STATUS_PENDING = 'pending';
const WITHDRAW_STATUS_PAID = 'paid';
const WITHDRAW_STATUS_REJECTED = 'rejected';
const WITHDRAW_EXPENSE_SLUG = 'wallet_withdraw';
const WITHDRAW_EXPENSE_LABEL = 'برداشت از کیف پول';
const WITHDRAW_MIN_KEY = 'wallet_withdraw_min';
const WITHDRAW_TEXT_PROMPT = 'text_wallet_withdraw';
const WITHDRAW_TEXT_SUCCESS = 'text_wallet_withdraw_success';

function withdraw_prompt_default(): string
{
    return "💸 لطفاً مبلغ برداشت از کیف پول را به تومان وارد کنید.";
}

function withdraw_success_default(): string
{
    return "✅ درخواست تسویه حساب شما ثبت شد و پس از بررسی واریز خواهد شد.";
}

function withdraw_pdo(?PDO $pdo = null): ?PDO
{
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    global $pdo;
    return $pdo instanceof PDO ? $pdo : null;
}

function withdraw_telegram_ready(): void
{
    $root = __DIR__;
    if (!function_exists('sendmessage') && is_file($root . '/botapi.php')) {
        require_once $root . '/botapi.php';
    }
}

function withdraw_receipt_dir(): string
{
    $dir = __DIR__ . '/storage/withdraw_receipts';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function withdraw_ensure_schema(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO) {
        return;
    }
    $ready = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS wallet_withdraw (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_user VARCHAR(64) NOT NULL,
                amount INT UNSIGNED NOT NULL,
                card_number VARCHAR(32) NOT NULL,
                card_holder VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                reject_reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
                receipt_path VARCHAR(500) NULL,
                receipt_file_id VARCHAR(255) NULL,
                admin_id VARCHAR(64) NULL,
                payment_order_id VARCHAR(64) NULL,
                admin_msgs TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
                created_at INT UNSIGNED NOT NULL,
                updated_at INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_wallet_withdraw_status (status),
                INDEX idx_wallet_withdraw_user (id_user)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('withdraw_ensure_schema table: ' . $e->getMessage());
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS wallet_user_card (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_user VARCHAR(64) NOT NULL,
                card_number VARCHAR(32) NOT NULL,
                card_holder VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                created_at INT UNSIGNED NOT NULL,
                updated_at INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_wallet_user_card (id_user, card_number),
                INDEX idx_wallet_user_card_user (id_user)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('withdraw_ensure_schema user_card: ' . $e->getMessage());
    }

    try {
        $exists = $pdo->prepare('SELECT NamePay FROM PaySetting WHERE NamePay = ?');
        $exists->execute([WITHDRAW_MIN_KEY]);
        if (!$exists->fetch()) {
            $pdo->prepare('INSERT INTO PaySetting (NamePay, ValuePay) VALUES (?, ?)')
                ->execute([WITHDRAW_MIN_KEY, '0']);
        }
    } catch (Throwable $e) {
        error_log('withdraw_ensure_schema min: ' . $e->getMessage());
    }

    $texts = [
        WITHDRAW_TEXT_PROMPT => withdraw_prompt_default(),
        WITHDRAW_TEXT_SUCCESS => withdraw_success_default(),
    ];
    foreach ($texts as $id => $text) {
        try {
            $pdo->prepare('INSERT IGNORE INTO textbot (id_text, text) VALUES (?, ?)')->execute([$id, $text]);
        } catch (Throwable $e) {
            error_log('withdraw_ensure_schema textbot ' . $id . ': ' . $e->getMessage());
        }
    }
    global $datatextbot;
    if (is_array($datatextbot)) {
        foreach ($texts as $id => $text) {
            if (!isset($datatextbot[$id]) || trim((string) $datatextbot[$id]) === '') {
                $datatextbot[$id] = $text;
            }
        }
    }

    withdraw_ensure_expense_category($pdo);
    withdraw_receipt_dir();
}

function withdraw_ensure_expense_category(?PDO $pdo = null): void
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO) {
        return;
    }
    if (function_exists('panel_payment_ensure_schema')) {
        panel_payment_ensure_schema($pdo);
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
        $stmt = $pdo->query("SHOW COLUMNS FROM Payment_report LIKE 'note'");
        if ($stmt && !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE Payment_report ADD note TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM Payment_report LIKE 'tx_type'");
        if ($stmt && !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE Payment_report ADD tx_type VARCHAR(16) NOT NULL DEFAULT 'income'");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM Payment_report LIKE 'expense_category'");
        if ($stmt && !$stmt->fetch()) {
            $pdo->exec("ALTER TABLE Payment_report ADD expense_category VARCHAR(64) NULL");
        }
        $row = $pdo->prepare('SELECT id FROM expense_category WHERE slug = ?');
        $row->execute([WITHDRAW_EXPENSE_SLUG]);
        if (!$row->fetch()) {
            $pdo->prepare('INSERT INTO expense_category (slug, label, sort_order) VALUES (?, ?, ?)')
                ->execute([WITHDRAW_EXPENSE_SLUG, WITHDRAW_EXPENSE_LABEL, 10]);
        }
        if (function_exists('panel_expense_category_map')) {
            panel_expense_category_map($pdo, true);
        }
    } catch (Throwable $e) {
        error_log('withdraw_ensure_expense_category: ' . $e->getMessage());
    }
}

function withdraw_min_amount(?PDO $pdo = null): int
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare('SELECT ValuePay FROM PaySetting WHERE NamePay = ?');
        $stmt->execute([WITHDRAW_MIN_KEY]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return max(0, (int) ($row['ValuePay'] ?? 0));
    } catch (Throwable $e) {
        return 0;
    }
}

function withdraw_set_min(int $amount, ?PDO $pdo = null): void
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO) {
        return;
    }
    $amount = max(0, $amount);
    $exists = $pdo->prepare('SELECT NamePay FROM PaySetting WHERE NamePay = ?');
    $exists->execute([WITHDRAW_MIN_KEY]);
    if ($exists->fetch()) {
        $pdo->prepare('UPDATE PaySetting SET ValuePay = ? WHERE NamePay = ?')->execute([(string) $amount, WITHDRAW_MIN_KEY]);
    } else {
        $pdo->prepare('INSERT INTO PaySetting (NamePay, ValuePay) VALUES (?, ?)')->execute([WITHDRAW_MIN_KEY, (string) $amount]);
    }
}

function withdraw_prompt_text(): string
{
    if (function_exists('textbot_get')) {
        return textbot_get(WITHDRAW_TEXT_PROMPT, withdraw_prompt_default());
    }
    return withdraw_prompt_default();
}

function withdraw_success_text(): string
{
    if (function_exists('textbot_get')) {
        return textbot_get(WITHDRAW_TEXT_SUCCESS, withdraw_success_default());
    }
    return withdraw_success_default();
}

function withdraw_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function withdraw_parse_int(string $text): ?int
{
    if (function_exists('convertPersianNumbersToEnglish')) {
        $text = convertPersianNumbersToEnglish($text);
    }
    $text = str_replace(['٬', ',', ' ', '،'], '', trim($text));
    if ($text === '' || !preg_match('/^\d+$/', $text)) {
        return null;
    }
    return (int) $text;
}

function withdraw_normalize_card(string $text): ?string
{
    if (function_exists('convertPersianNumbersToEnglish')) {
        $text = convertPersianNumbersToEnglish($text);
    }
    $digits = preg_replace('/\D+/', '', $text) ?? '';
    if (strlen($digits) !== 16) {
        return null;
    }
    return $digits;
}

function withdraw_validate_amount(int $amount, int $balance, ?PDO $pdo = null): array
{
    if ($amount < 1) {
        return ['ok' => false, 'error' => '❌ مبلغ نامعتبر است. مبلغ را به تومان و به‌صورت عدد وارد کنید.'];
    }
    $min = withdraw_min_amount($pdo);
    if ($amount < $min) {
        return ['ok' => false, 'error' => 'مبلغ ورودی از حداقل برداشت کمتر است'];
    }
    if ($amount > $balance) {
        return ['ok' => false, 'error' => 'مبلغ ورودی از موجودی کیف پول شما بیشتر است'];
    }
    return ['ok' => true, 'error' => ''];
}

function withdraw_format_card(string $card): string
{
    $card = preg_replace('/\D+/', '', $card) ?? $card;
    if (strlen($card) === 16) {
        return trim(chunk_split($card, 4, ' '));
    }
    return $card;
}

function withdraw_card_mask(string $card): string
{
    $card = preg_replace('/\D+/', '', $card) ?? $card;
    if (strlen($card) === 16) {
        return substr($card, 0, 4) . ' **** ' . substr($card, -4);
    }
    return $card;
}

function withdraw_user_cards(string $userId, ?PDO $pdo = null): array
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO || $userId === '') {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM wallet_user_card WHERE id_user = ? ORDER BY updated_at DESC, id DESC LIMIT 20'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            withdraw_import_cards_from_history($userId, $pdo);
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return $rows;
    } catch (Throwable $e) {
        error_log('withdraw_user_cards: ' . $e->getMessage());
        return [];
    }
}

function withdraw_import_cards_from_history(string $userId, PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare(
            'SELECT card_number, card_holder FROM wallet_withdraw
             WHERE id_user = ? AND card_number != \'\' AND card_holder != \'\'
             ORDER BY id ASC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $card = withdraw_normalize_card((string) ($row['card_number'] ?? ''));
            $holder = trim((string) ($row['card_holder'] ?? ''));
            if ($card && $holder !== '') {
                withdraw_save_user_card($userId, $card, $holder, $pdo);
            }
        }
    } catch (Throwable $e) {
        error_log('withdraw_import_cards_from_history: ' . $e->getMessage());
    }
}

function withdraw_get_user_card(int $id, string $userId, ?PDO $pdo = null): ?array
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO || $id < 1 || $userId === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM wallet_user_card WHERE id = ? AND id_user = ?');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function withdraw_save_user_card(string $userId, string $card, string $holder, ?PDO $pdo = null): int
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO) {
        return 0;
    }
    $now = time();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO wallet_user_card (id_user, card_number, card_holder, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE card_holder = VALUES(card_holder), updated_at = VALUES(updated_at), id = LAST_INSERT_ID(id)'
        );
        $stmt->execute([$userId, $card, $holder, $now, $now]);
        $id = (int) $pdo->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $existing = $pdo->prepare('SELECT id FROM wallet_user_card WHERE id_user = ? AND card_number = ?');
        $existing->execute([$userId, $card]);
        return (int) ($existing->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('withdraw_save_user_card: ' . $e->getMessage());
        return 0;
    }
}

function withdraw_card_button_label(string $card, string $holder): string
{
    $label = withdraw_card_mask($card) . ' | ' . $holder;
    if (function_exists('mb_strlen') && mb_strlen($label, 'UTF-8') > 60) {
        $label = mb_substr($label, 0, 59, 'UTF-8') . '…';
    } elseif (strlen($label) > 64) {
        $label = substr($label, 0, 61) . '...';
    }
    return $label;
}

function withdraw_card_picker_text(): string
{
    return '💳 یک کارت را انتخاب کنید';
}

function withdraw_card_picker_keyboard(string $userId, bool $canBackToReview = false): string
{
    $rows = [];
    foreach (withdraw_user_cards($userId) as $card) {
        $id = (int) ($card['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $rows[] = [[
            'text' => withdraw_card_button_label((string) ($card['card_number'] ?? ''), (string) ($card['card_holder'] ?? '')),
            'callback_data' => 'wd_use_' . $id,
        ]];
    }
    $rows[] = [['text' => '➕ افزودن کارت جدید', 'callback_data' => 'wd_add_card']];
    if ($canBackToReview) {
        $rows[] = [['text' => '🔙 بازگشت به بررسی', 'callback_data' => 'wd_edit_back']];
    } else {
        global $textbotlang;
        $back = is_array($textbotlang) ? ($textbotlang['users']['stateus']['backinfo'] ?? '🔙 بازگشت') : '🔙 بازگشت';
        $rows[] = [['text' => $back, 'callback_data' => 'account']];
    }
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function withdraw_user_review_text(int $amount, string $card, string $holder): string
{
    return "📋 لطفاً اطلاعات درخواست برداشت را بررسی کنید:\n\n"
        . '💰 مبلغ: <code>' . number_format($amount) . "</code> تومان\n"
        . '💳 شماره کارت: <code>' . withdraw_esc(withdraw_format_card($card)) . "</code>\n"
        . '👤 نام صاحب حساب: <code>' . withdraw_esc($holder) . "</code>\n\n"
        . 'در صورت صحت اطلاعات تأیید کنید.';
}

function withdraw_user_confirm_keyboard(): string
{
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => '✅ تأیید', 'callback_data' => 'wd_confirm_yes'],
                ['text' => '❌ لغو', 'callback_data' => 'wd_confirm_no'],
            ],
            [
                ['text' => '✏️ ویرایش', 'callback_data' => 'wd_confirm_edit'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function withdraw_user_edit_keyboard(): string
{
    return json_encode([
        'inline_keyboard' => [
            [['text' => '💰 مبلغ', 'callback_data' => 'wd_edit_amount']],
            [['text' => '💳 کارت', 'callback_data' => 'wd_edit_card']],
            [['text' => '🔙 بازگشت به بررسی', 'callback_data' => 'wd_edit_back']],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function withdraw_user_back_keyboard(): string
{
    global $textbotlang;
    $back = is_array($textbotlang) ? ($textbotlang['users']['stateus']['backinfo'] ?? '🔙 بازگشت') : '🔙 بازگشت';
    return json_encode([
        'inline_keyboard' => [
            [['text' => $back, 'callback_data' => 'account']],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function withdraw_draft_from_user(array $user): array
{
    $data = json_decode((string) ($user['Processing_value'] ?? ''), true);
    return is_array($data) ? $data : [];
}

function withdraw_new_order_id(PDO $pdo): string
{
    for ($i = 0; $i < 8; $i++) {
        $id = bin2hex(random_bytes(5));
        $stmt = $pdo->prepare('SELECT id FROM Payment_report WHERE id_order = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            return $id;
        }
    }
    return bin2hex(random_bytes(8));
}

function withdraw_get(int $id, ?PDO $pdo = null): ?array
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO || $id < 1) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM wallet_withdraw WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function withdraw_pending_count(?PDO $pdo = null): int
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO) {
        return 0;
    }
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM wallet_withdraw WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function withdraw_list(string $status, int $limit = 20, int $offset = 0, ?PDO $pdo = null): array
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM wallet_withdraw WHERE status = ? ORDER BY id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
    );
    $stmt->execute([$status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function withdraw_count_status(string $status, ?PDO $pdo = null): int
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM wallet_withdraw WHERE status = ?');
    $stmt->execute([$status]);
    return (int) $stmt->fetchColumn();
}

function withdraw_admin_request_keyboard(int $id): string
{
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => '✅ تأیید', 'callback_data' => 'wd_ok_' . $id],
                ['text' => '❌ رد', 'callback_data' => 'wd_no_' . $id],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function withdraw_admin_menu_keyboard(string $tab = 'pending'): string
{
    $pending = withdraw_pending_count();
    $pendingLabel = 'درخواست‌ها';
    if ($pending > 0) {
        $pendingLabel .= ' (' . $pending . ')';
    }
    $mark = static function (string $label, string $key) use ($tab): string {
        return $tab === $key ? '• ' . $label : $label;
    };
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => $mark('تنظیمات', 'settings'), 'callback_data' => 'wd_tab_settings'],
                ['text' => $mark($pendingLabel, 'pending'), 'callback_data' => 'wd_tab_pending'],
                ['text' => $mark('تاریخچه', 'history'), 'callback_data' => 'wd_tab_history'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function withdraw_user_account_line(array $userRow, string $fallbackName = '', string $fallbackUsername = ''): string
{
    $id = (string) ($userRow['id'] ?? '');
    $username = trim((string) ($userRow['username'] ?? ''));
    if ($username === '' || $username === 'none') {
        $username = $fallbackUsername;
    }
    $name = trim((string) ($userRow['namecustom'] ?? ''));
    if ($name === '' || $name === 'none') {
        $name = $fallbackName;
    }
    $line = '🪪 آیدی: <code>' . withdraw_esc($id) . '</code>';
    if ($username !== '' && $username !== 'NOT_USERNAME') {
        $line .= "\n👤 یوزرنیم: @" . withdraw_esc(ltrim($username, '@'));
    }
    if ($name !== '') {
        $line .= "\n📛 نام: " . withdraw_esc($name);
    }
    return $line;
}

function withdraw_admin_detail_text(array $row, array $userRow = [], string $fallbackName = '', string $fallbackUsername = ''): string
{
    $id = (int) ($row['id'] ?? 0);
    $amount = (int) ($row['amount'] ?? 0);
    $status = (string) ($row['status'] ?? '');
    $statusLabel = [
        WITHDRAW_STATUS_PENDING => 'در انتظار',
        WITHDRAW_STATUS_PAID => 'پرداخت شده',
        WITHDRAW_STATUS_REJECTED => 'رد شده',
    ][$status] ?? $status;
    $created = (int) ($row['created_at'] ?? 0);
    $when = $created > 0 && function_exists('jalali_tehran_format')
        ? jalali_tehran_format($created, 'Y/m/d H:i')
        : date('Y/m/d H:i', $created);
    $text = "💸 درخواست برداشت #$id\n";
    $text .= "📌 وضعیت: $statusLabel\n";
    $text .= "📅 زمان: $when\n\n";
    $text .= withdraw_user_account_line($userRow, $fallbackName, $fallbackUsername) . "\n\n";
    $text .= '💰 مبلغ: <code>' . number_format($amount) . "</code> تومان\n";
    $text .= '💳 شماره کارت: <code>' . withdraw_esc(withdraw_format_card((string) ($row['card_number'] ?? ''))) . "</code>\n";
    $text .= '👤 صاحب حساب: <code>' . withdraw_esc((string) ($row['card_holder'] ?? '')) . '</code>';
    if ($status === WITHDRAW_STATUS_REJECTED && trim((string) ($row['reject_reason'] ?? '')) !== '') {
        $text .= "\n\n📝 دلیل رد: " . withdraw_esc((string) $row['reject_reason']);
    }
    return $text;
}

function withdraw_fetch_user(string $userId, ?PDO $pdo = null): array
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO || $userId === '') {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM user WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function withdraw_create_request(string $userId, int $amount, string $card, string $holder, string $fallbackName = '', string $fallbackUsername = '', ?PDO $pdo = null): array
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'msg' => 'خطای پایگاه داده'];
    }
    $userRow = withdraw_fetch_user($userId, $pdo);
    $balance = (int) ($userRow['Balance'] ?? 0);
    $check = withdraw_validate_amount($amount, $balance, $pdo);
    if (!$check['ok']) {
        return ['ok' => false, 'msg' => $check['error']];
    }
    $now = time();
    $stmt = $pdo->prepare(
        'INSERT INTO wallet_withdraw (id_user, amount, card_number, card_holder, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $amount, $card, $holder, WITHDRAW_STATUS_PENDING, $now, $now]);
    $id = (int) $pdo->lastInsertId();
    $row = withdraw_get($id, $pdo);
    if (!$row) {
        return ['ok' => false, 'msg' => 'ثبت درخواست ناموفق بود.'];
    }
    withdraw_notify_admins($row, $userRow, $fallbackName, $fallbackUsername, $pdo);
    return ['ok' => true, 'id' => $id, 'row' => $row];
}

function withdraw_notify_admins(array $row, array $userRow, string $fallbackName = '', string $fallbackUsername = '', ?PDO $pdo = null): void
{
    withdraw_telegram_ready();
    if (!function_exists('sendmessage') || !function_exists('select')) {
        return;
    }
    $pdo = withdraw_pdo($pdo);
    $text = "🔔 درخواست برداشت از کیف پول\n\n" . withdraw_admin_detail_text($row, $userRow, $fallbackName, $fallbackUsername);
    $keyboard = withdraw_admin_request_keyboard((int) $row['id']);
    $adminIds = select('admin', 'id_admin', null, null, 'FETCH_COLUMN') ?: [];
    $stored = [];
    foreach ($adminIds as $adminId) {
        $admin = select('admin', '*', 'id_admin', $adminId, 'select');
        if (!is_array($admin) || ($admin['rule'] ?? '') === 'support') {
            continue;
        }
        $sent = sendmessage($adminId, $text, $keyboard, 'HTML');
        $mid = function_exists('telegramSentMessageId') ? telegramSentMessageId($sent) : (int) ($sent['result']['message_id'] ?? 0);
        if ($mid > 0) {
            $stored[] = ['chat_id' => (int) $adminId, 'message_id' => $mid];
        }
    }
    if ($pdo instanceof PDO && $stored !== []) {
        $pdo->prepare('UPDATE wallet_withdraw SET admin_msgs = ? WHERE id = ?')->execute([
            json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (int) $row['id'],
        ]);
    }
}

function withdraw_decode_admin_msgs($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : [];
}

function withdraw_update_admin_messages(array $row, string $suffix): void
{
    withdraw_telegram_ready();
    $messages = withdraw_decode_admin_msgs($row['admin_msgs'] ?? '');
    if ($messages === []) {
        return;
    }
    $userRow = withdraw_fetch_user((string) ($row['id_user'] ?? ''));
    $text = withdraw_admin_detail_text($row, $userRow) . "\n\n" . $suffix;
    foreach ($messages as $item) {
        $chatId = (int) ($item['chat_id'] ?? 0);
        $messageId = (int) ($item['message_id'] ?? 0);
        if ($chatId < 1 || $messageId < 1) {
            continue;
        }
        if (function_exists('Editmessagetext')) {
            Editmessagetext($chatId, $messageId, $text, json_encode(['inline_keyboard' => []]), 'HTML');
        }
    }
}

function withdraw_http_get(string $url): ?string
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 12);
    if (function_exists('apply_telegram_proxy')) {
        apply_telegram_proxy($ch, $url);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400 || $body === '') {
        return null;
    }
    return $body;
}

function withdraw_save_bytes(int $id, string $bytes, string $ext = 'jpg'): ?string
{
    $ext = strtolower(preg_replace('/[^a-z0-9]+/i', '', $ext) ?: 'jpg');
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        $ext = 'jpg';
    }
    $dir = withdraw_receipt_dir();
    $name = $id . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $path = $dir . '/' . $name;
    if (file_put_contents($path, $bytes) === false) {
        return null;
    }
    return 'storage/withdraw_receipts/' . $name;
}

function withdraw_download_telegram_file(string $fileId): ?array
{
    withdraw_telegram_ready();
    global $APIKEY;
    if ($fileId === '' || !function_exists('getFileddire')) {
        return null;
    }
    $response = getFileddire($fileId);
    $filePath = (string) ($response['result']['file_path'] ?? '');
    if (empty($response['ok']) || $filePath === '' || empty($APIKEY)) {
        return null;
    }
    $url = 'https://api.telegram.org/file/bot' . $APIKEY . '/' . $filePath;
    $bytes = withdraw_http_get($url);
    if ($bytes === null) {
        return null;
    }
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg');
    return ['bytes' => $bytes, 'ext' => $ext];
}

function withdraw_save_receipt_from_telegram(int $id, string $fileId): array
{
    $downloaded = withdraw_download_telegram_file($fileId);
    $path = null;
    if ($downloaded) {
        $path = withdraw_save_bytes($id, $downloaded['bytes'], $downloaded['ext']);
    }
    return ['path' => $path, 'file_id' => $fileId];
}

function withdraw_save_receipt_from_upload(int $id, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['ok' => false, 'msg' => 'بارگذاری تصویر رسید الزامی است.'];
    }
    if (($file['size'] ?? 0) < 1 || $file['size'] > 20 * 1024 * 1024) {
        return ['ok' => false, 'msg' => 'حجم تصویر باید حداکثر ۲۰ مگابایت باشد.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!str_starts_with($mime, 'image/')) {
        return ['ok' => false, 'msg' => 'فقط تصویر رسید قابل قبول است.'];
    }
    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $ext = $extMap[$mime] ?? 'jpg';
    $bytes = file_get_contents($file['tmp_name']);
    if ($bytes === false) {
        return ['ok' => false, 'msg' => 'خواندن فایل ناموفق بود.'];
    }
    $path = withdraw_save_bytes($id, $bytes, $ext);
    if ($path === null) {
        return ['ok' => false, 'msg' => 'ذخیره رسید ناموفق بود.'];
    }
    return ['ok' => true, 'path' => $path, 'mime' => $mime, 'name' => basename($path)];
}

function withdraw_absolute_receipt_path(string $relative): string
{
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    return __DIR__ . '/' . $relative;
}

function withdraw_send_paid_notice(array $row, ?string $uploadAbsPath = null, ?string $uploadMime = null): ?string
{
    withdraw_telegram_ready();
    $userId = (string) ($row['id_user'] ?? '');
    if ($userId === '' || !function_exists('telegram')) {
        return null;
    }
    $caption = '✅ درخواست تسویه حساب شما با موفقیت پردازش شد.';
    $fileId = trim((string) ($row['receipt_file_id'] ?? ''));
    $payload = [
        'chat_id' => $userId,
        'caption' => $caption,
        'parse_mode' => 'HTML',
    ];
    if ($fileId !== '') {
        $payload['photo'] = $fileId;
    } elseif ($uploadAbsPath && is_file($uploadAbsPath)) {
        $payload['photo'] = new CURLFile($uploadAbsPath, $uploadMime ?: 'image/jpeg', basename($uploadAbsPath));
    } else {
        $rel = (string) ($row['receipt_path'] ?? '');
        $abs = $rel !== '' ? withdraw_absolute_receipt_path($rel) : '';
        if ($abs !== '' && is_file($abs)) {
            $payload['photo'] = new CURLFile($abs, 'image/jpeg', basename($abs));
        } else {
            if (function_exists('sendmessage')) {
                sendmessage($userId, $caption, null, 'HTML');
            }
            return null;
        }
    }
    $response = telegram('sendPhoto', $payload);
    if (empty($response['ok'])) {
        if (function_exists('sendmessage')) {
            sendmessage($userId, $caption, null, 'HTML');
        }
        return null;
    }
    $photos = $response['result']['photo'] ?? [];
    $last = is_array($photos) && $photos !== [] ? end($photos) : [];
    return (string) ($last['file_id'] ?? '');
}

function withdraw_approve(int $id, string $adminId, array $receipt, ?PDO $pdo = null): array
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'msg' => 'خطای پایگاه داده'];
    }
    withdraw_ensure_schema($pdo);
    $receiptPath = (string) ($receipt['path'] ?? '');
    $receiptFileId = (string) ($receipt['file_id'] ?? '');
    if ($receiptPath === '' && $receiptFileId === '') {
        return ['ok' => false, 'msg' => 'ارسال عکس رسید الزامی است.'];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM wallet_withdraw WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => 'درخواست یافت نشد.'];
        }
        if (($row['status'] ?? '') !== WITHDRAW_STATUS_PENDING) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => 'این درخواست قبلاً بررسی شده است.', 'already' => true];
        }
        $userId = (string) $row['id_user'];
        $amount = (int) $row['amount'];
        $userStmt = $pdo->prepare('SELECT * FROM user WHERE id = ? FOR UPDATE');
        $userStmt->execute([$userId]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => 'کاربر یافت نشد.'];
        }
        $balance = (int) ($userRow['Balance'] ?? 0);
        if ($amount > $balance) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => 'مبلغ برداشت از موجودی کیف پول کاربر بیشتر است.'];
        }
        $pdo->prepare('UPDATE user SET Balance = Balance - ? WHERE id = ?')->execute([$amount, $userId]);
        $orderId = withdraw_new_order_id($pdo);
        $time = function_exists('tehran_datetime_string')
            ? tehran_datetime_string(time())
            : date('Y/m/d H:i:s');
        $note = 'برداشت از کیف پول | کارت: ' . withdraw_format_card((string) $row['card_number'])
            . ' | صاحب: ' . (string) $row['card_holder']
            . ' | درخواست #' . $id;
        $pdo->prepare(
            'INSERT INTO Payment_report (id_user, id_order, time, price, payment_Status, Payment_Method, id_invoice, note, tx_type, expense_category)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $orderId,
            $time,
            (string) $amount,
            'cost',
            'cost',
            'cost',
            $note,
            'expense',
            WITHDRAW_EXPENSE_SLUG,
        ]);
        $now = time();
        $pdo->prepare(
            'UPDATE wallet_withdraw
             SET status = ?, receipt_path = ?, receipt_file_id = ?, admin_id = ?, payment_order_id = ?, updated_at = ?
             WHERE id = ?'
        )->execute([
            WITHDRAW_STATUS_PAID,
            $receiptPath !== '' ? $receiptPath : ($row['receipt_path'] ?? null),
            $receiptFileId !== '' ? $receiptFileId : ($row['receipt_file_id'] ?? null),
            $adminId,
            $orderId,
            $now,
            $id,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('withdraw_approve: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'ثبت تأیید ناموفق بود.'];
    }

    $row = withdraw_get($id, $pdo);
    $newFileId = withdraw_send_paid_notice(
        $row ?: [],
        isset($receipt['abs_path']) ? (string) $receipt['abs_path'] : null,
        isset($receipt['mime']) ? (string) $receipt['mime'] : null
    );
    if ($newFileId && $pdo instanceof PDO && empty($row['receipt_file_id'])) {
        $pdo->prepare('UPDATE wallet_withdraw SET receipt_file_id = ? WHERE id = ?')->execute([$newFileId, $id]);
        if (is_array($row)) {
            $row['receipt_file_id'] = $newFileId;
        }
    }
    if (is_array($row)) {
        $row['status'] = WITHDRAW_STATUS_PAID;
        withdraw_update_admin_messages($row, '✅ پرداخت شده');
    }
    if (function_exists('clearSelectCache')) {
        clearSelectCache('user');
    }
    return ['ok' => true, 'msg' => 'درخواست پرداخت شد.', 'row' => $row, 'order_id' => $orderId ?? ''];
}

function withdraw_reject(int $id, string $adminId, string $reason, ?PDO $pdo = null): array
{
    $pdo = withdraw_pdo($pdo);
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'msg' => 'خطای پایگاه داده'];
    }
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'msg' => 'دلیل رد را وارد کنید.'];
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM wallet_withdraw WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => 'درخواست یافت نشد.'];
        }
        if (($row['status'] ?? '') !== WITHDRAW_STATUS_PENDING) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => 'این درخواست قبلاً بررسی شده است.', 'already' => true];
        }
        $now = time();
        $pdo->prepare(
            'UPDATE wallet_withdraw SET status = ?, reject_reason = ?, admin_id = ?, updated_at = ? WHERE id = ?'
        )->execute([WITHDRAW_STATUS_REJECTED, $reason, $adminId, $now, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('withdraw_reject: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'رد درخواست ناموفق بود.'];
    }
    $row = withdraw_get($id, $pdo);
    withdraw_telegram_ready();
    if (is_array($row) && function_exists('sendmessage')) {
        $text = "❌ درخواست برداشت شما رد شد.\n\n✍️ " . withdraw_esc($reason);
        sendmessage($row['id_user'], $text, null, 'HTML');
        $row['status'] = WITHDRAW_STATUS_REJECTED;
        $row['reject_reason'] = $reason;
        withdraw_update_admin_messages($row, '❌ رد شده');
    }
    return ['ok' => true, 'msg' => 'درخواست رد شد.', 'row' => $row];
}

function withdraw_admin_settings_text(): string
{
    $min = number_format(withdraw_min_amount());
    return "⚙️ تنظیمات برداشت از کیف پول\n\n"
        . "⬇️ حداقل برداشت: <code>$min</code> تومان\n\n"
        . "📝 متن دکمه تسویه حساب:\n" . withdraw_prompt_text() . "\n\n"
        . "✅ متن پس از ثبت موفق:\n" . withdraw_success_text();
}

function withdraw_admin_settings_keyboard(): string
{
    $base = json_decode(withdraw_admin_menu_keyboard('settings'), true);
    $rows = $base['inline_keyboard'] ?? [];
    $rows[] = [['text' => '⬇️ تغییر حداقل برداشت', 'callback_data' => 'wd_set_min']];
    $rows[] = [['text' => '📝 ویرایش متن دکمه', 'callback_data' => 'wd_set_prompt']];
    $rows[] = [['text' => '✅ ویرایش متن موفقیت', 'callback_data' => 'wd_set_success']];
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function withdraw_admin_list_keyboard(string $tab, int $page, int $totalPages, array $extraRows = []): string
{
    $base = json_decode(withdraw_admin_menu_keyboard($tab), true);
    $rows = $base['inline_keyboard'] ?? [];
    foreach ($extraRows as $row) {
        $rows[] = $row;
    }
    if ($totalPages > 1) {
        $nav = [];
        if ($page > 1) {
            $nav[] = ['text' => '◀️', 'callback_data' => 'wd_tab_' . $tab . '_' . ($page - 1)];
        }
        $nav[] = ['text' => $page . '/' . $totalPages, 'callback_data' => 'wd_none'];
        if ($page < $totalPages) {
            $nav[] = ['text' => '▶️', 'callback_data' => 'wd_tab_' . $tab . '_' . ($page + 1)];
        }
        $rows[] = $nav;
    }
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function withdraw_admin_pending_view(int $page = 1): array
{
    $perPage = 5;
    $total = withdraw_count_status(WITHDRAW_STATUS_PENDING);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $rows = withdraw_list(WITHDRAW_STATUS_PENDING, $perPage, ($page - 1) * $perPage);
    if ($rows === []) {
        return [
            'text' => "📭 درخواست در انتظاری وجود ندارد.",
            'keyboard' => withdraw_admin_menu_keyboard('pending'),
        ];
    }
    $text = "📥 درخواست‌های در انتظار برداشت\n\n";
    $extra = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $user = withdraw_fetch_user((string) $row['id_user']);
        $uname = trim((string) ($user['username'] ?? ''));
                $who = $uname !== '' && $uname !== 'none'
                    ? '@' . withdraw_esc(ltrim($uname, '@'))
                    : withdraw_esc((string) $row['id_user']);
                $text .= "▪️ #$id — " . number_format((int) $row['amount']) . " ت — $who\n";
                $text .= '💳 ' . withdraw_esc(withdraw_format_card((string) $row['card_number']))
                    . ' | ' . withdraw_esc((string) $row['card_holder']) . "\n\n";
        $extra[] = [
            ['text' => "✅ #$id", 'callback_data' => 'wd_ok_' . $id],
            ['text' => "❌ #$id", 'callback_data' => 'wd_no_' . $id],
        ];
    }
    return [
        'text' => $text,
        'keyboard' => withdraw_admin_list_keyboard('pending', $page, $totalPages, $extra),
    ];
}

function withdraw_admin_history_view(int $page = 1): array
{
    $perPage = 8;
    $total = withdraw_count_status(WITHDRAW_STATUS_PAID);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $rows = withdraw_list(WITHDRAW_STATUS_PAID, $perPage, ($page - 1) * $perPage);
    if ($rows === []) {
        return [
            'text' => "📭 تاریخچه برداشتی وجود ندارد.",
            'keyboard' => withdraw_admin_menu_keyboard('history'),
        ];
    }
    $text = "✅ برداشت‌های پرداخت‌شده\n\n";
    foreach ($rows as $row) {
        $when = (int) ($row['updated_at'] ?? $row['created_at'] ?? 0);
        $whenTxt = $when > 0 && function_exists('jalali_tehran_format')
            ? jalali_tehran_format($when, 'Y/m/d H:i')
            : date('Y/m/d H:i', $when);
        $text .= '▪️ #' . (int) $row['id'] . ' — ' . number_format((int) $row['amount']) . ' ت — '
            . (string) $row['id_user'] . "\n📅 $whenTxt\n\n";
    }
    return [
        'text' => $text,
        'keyboard' => withdraw_admin_list_keyboard('history', $page, $totalPages),
    ];
}
