<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/request.php';
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

/**
 * Telegram inline button labels are limited to 64 UTF-8 code units.
 */
function mirza_inline_service_button_text(string $username, string $noteSuffix = ''): string
{
    $label = '✨' . $username . $noteSuffix . '✨';
    if (mb_strlen($label, 'UTF-8') > 64) {
        return mb_substr($label, 0, 61, 'UTF-8') . '…';
    }
    return $label;
}

/**
 * Telegram callback_data is limited to 64 bytes.
 */
function mirza_inline_callback_data(string $prefix, $id): string
{
    $data = $prefix . $id;
    return strlen($data) > 64 ? substr($data, 0, 64) : $data;
}

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

#-----------shell helper utilities------------#
function isShellExecAvailable()
{
    static $isAvailable;

    if ($isAvailable !== null) {
        return $isAvailable;
    }

    if (!function_exists('shell_exec')) {
        $isAvailable = false;
        return $isAvailable;
    }

    $disabledFunctions = ini_get('disable_functions');
    if (!empty($disabledFunctions) && stripos($disabledFunctions, 'shell_exec') !== false) {
        $isAvailable = false;
        return $isAvailable;
    }

    $isAvailable = true;
    return $isAvailable;
}

function getCrontabBinary()
{
    static $resolvedPath;

    if ($resolvedPath !== null) {
        return $resolvedPath ?: null;
    }

    $candidateDirectories = [
        '/usr/local/bin',
        '/usr/bin',
        '/bin',
        '/usr/sbin',
        '/sbin',
    ];

    $environmentPath = getenv('PATH');
    if ($environmentPath !== false && $environmentPath !== '') {
        foreach (explode(PATH_SEPARATOR, $environmentPath) as $pathDirectory) {
            $pathDirectory = trim($pathDirectory);
            if ($pathDirectory !== '' && !in_array($pathDirectory, $candidateDirectories, true)) {
                $candidateDirectories[] = $pathDirectory;
            }
        }
    }

    foreach ($candidateDirectories as $directory) {
        $executablePath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'crontab';
        if (@is_file($executablePath) && @is_executable($executablePath)) {
            $resolvedPath = $executablePath;
            return $resolvedPath;
        }
    }

    if (isShellExecAvailable()) {
        $whichOutput = @shell_exec('command -v crontab 2>/dev/null');
        if (is_string($whichOutput)) {
            $whichOutput = trim($whichOutput);
            if ($whichOutput !== '' && @is_executable($whichOutput)) {
                $resolvedPath = $whichOutput;
                return $resolvedPath;
            }
        }
    }

    $resolvedPath = '';
    error_log('Unable to locate the crontab executable on this system.');

    return null;
}

function runShellCommand($command)
{
    if (!isShellExecAvailable()) {
        error_log('shell_exec is not available; unable to run command: ' . $command);
        return null;
    }

    if (getenv('PATH') === false || trim((string) getenv('PATH')) === '') {
        putenv('PATH=/usr/local/bin:/usr/bin:/bin');
    }

    return shell_exec($command);
}

function deleteDirectory($directory)
{
    if (!file_exists($directory)) {
        return true;
    }

    if (!is_dir($directory)) {
        return @unlink($directory);
    }

    $items = scandir($directory);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            if (!deleteDirectory($path)) {
                return false;
            }
        } else {
            if (!@unlink($path)) {
                return false;
            }
        }
    }

    return @rmdir($directory);
}

function ensureTableUtf8mb4($table)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $currentCollation = $stmt->fetchColumn();

        if ($currentCollation === false) {
            error_log("Failed to detect current collation for table {$table}");
            return false;
        }

        if (stripos((string) $currentCollation, 'utf8mb4') === 0) {
            return true;
        }

        $pdo->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return true;
    } catch (PDOException $e) {
        error_log('Failed to convert table to utf8mb4: ' . $e->getMessage());
        return false;
    }
}

function ensureCardNumberTableSupportsUnicode()
{
    global $connect;

    if (!isset($connect) || !($connect instanceof mysqli)) {
        return;
    }

    try {
        if (method_exists($connect, 'character_set_name') && $connect->character_set_name() !== 'utf8mb4') {
            if (!$connect->set_charset('utf8mb4')) {
                error_log('Failed to enforce utf8mb4 charset on mysqli connection: ' . $connect->error);
            }
        }

        if (!$connect->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'")) {
            error_log('Failed to execute SET NAMES utf8mb4 for card_number table: ' . $connect->error);
        }

        $createQuery = "CREATE TABLE IF NOT EXISTS card_number (" .
            "cardnumber varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY," .
            "namecard varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$connect->query($createQuery)) {
            error_log('Failed to create card_number table with utf8mb4 charset: ' . $connect->error);
        }

        ensureTableUtf8mb4('card_number');

        $columnInfo = $connect->query("SHOW FULL COLUMNS FROM card_number WHERE Field IN ('cardnumber', 'namecard')");
        if ($columnInfo instanceof mysqli_result) {
            while ($column = $columnInfo->fetch_assoc()) {
                $collation = $column['Collation'] ?? '';
                if (!is_string($collation) || stripos($collation, 'utf8mb4') === false) {
                    $field = $column['Field'];
                    $type = $field === 'cardnumber' ? 'varchar(500)' : 'varchar(1000)';
                    $alter = sprintf(
                        "ALTER TABLE card_number MODIFY %s %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci%s",
                        $field,
                        $type,
                        $field === 'cardnumber' ? ' PRIMARY KEY' : ' NOT NULL'
                    );
                    if (!$connect->query($alter)) {
                        error_log('Failed to update card_number column collation: ' . $connect->error);
                    }
                }
            }
            $columnInfo->free();
        } else {
            error_log('Unable to inspect card_number column collations: ' . $connect->error);
        }
    } catch (\Throwable $e) {
        error_log('Unexpected error while ensuring card_number utf8mb4 compatibility: ' . $e->getMessage());
    }
}

function normaliseUpdateValue($value)
{
    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return $value;
}

function copyDirectoryContents($source, $destination)
{
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        return false;
    }

    $items = scandir($source);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;

        if (is_dir($sourcePath)) {
            if (!copyDirectoryContents($sourcePath, $destinationPath)) {
                return false;
            }
        } else {
            if (!@copy($sourcePath, $destinationPath)) {
                return false;
            }
        }
    }

    return true;
}

#-----------function------------#
function step($step, $from_id)
{
    userUpdate($from_id, ['step' => $step]);
}

/**
 * Direct user-row write. Skips INFORMATION_SCHEMA and SELECT FOR UPDATE.
 */
function userUpdate($from_id, array $fields): void
{
    global $pdo;
    if ($from_id === null || $from_id === '' || $fields === []) {
        return;
    }
    $sets = [];
    $params = [];
    foreach ($fields as $col => $val) {
        $col = preg_replace('/[^A-Za-z0-9_]/', '', (string) $col);
        if ($col === '') {
            continue;
        }
        $sets[] = "`$col` = ?";
        $params[] = $val;
    }
    if ($sets === []) {
        return;
    }
    $params[] = $from_id;
    $stmt = $pdo->prepare('UPDATE user SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);
    clearSelectCache('user');
}

function count_products(): int
{
    global $pdo;
    return (int) $pdo->query('SELECT COUNT(*) FROM product')->fetchColumn();
}

function count_active_invoices_for_panel($name_panel): int
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE Service_location = :loc AND Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold')");
    $stmt->execute([':loc' => $name_panel]);
    return (int) $stmt->fetchColumn();
}

function count_user_non_unpaid_invoices($user_id): int
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE id_user = :id AND Status != 'Unpaid'");
    $stmt->execute([':id' => $user_id]);
    return (int) $stmt->fetchColumn();
}

function invoice_unpaid_statuses(): array
{
    return [
        'Unpaid', 'unpaid', 'unpiad', 'Unpiad',
        'reject', 'waiting', 'expire',
        'removebyadmin', 'removedbyadmin',
    ];
}

function invoice_paid_status_sql(string $statusCol = 'Status'): string
{
    $quoted = [];
    foreach (invoice_unpaid_statuses() as $st) {
        $quoted[] = "'" . str_replace("'", "''", $st) . "'";
    }
    return "$statusCol NOT IN (" . implode(',', $quoted) . ") AND $statusCol IS NOT NULL AND $statusCol != ''";
}

function paid_real_income_sql(): string
{
    return "payment_Status = 'paid'
        AND COALESCE(tx_type,'') NOT IN ('expense','investment')
        AND COALESCE(id_invoice,'') <> 'capital'
        AND COALESCE(Payment_Method,'') NOT IN ('add balance by admin','low balance by admin','capital_injection')";
}

/**
 * Record an admin wallet credit/debit so it appears in Payment_report.
 */
function record_admin_balance_payment(PDO $pdo, $userId, int $amount, string $method): ?string
{
    if ($amount <= 0 || $userId === null || $userId === '') {
        return null;
    }
    $dateacc = date('Y/m/d H:i:s');
    $orderId = bin2hex(random_bytes(5));
    $isExpense = $method === 'low balance by admin';
    try {
        if ($isExpense) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO Payment_report
                        (id_user, id_order, time, price, payment_Status, Payment_Method, id_invoice, note, tx_type, expense_category)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    (string) $userId,
                    $orderId,
                    $dateacc,
                    (string) $amount,
                    'cost',
                    'cost',
                    'cost',
                    'کسر موجودی کاربر توسط ادمین',
                    'expense',
                    'admin_balance_deduction',
                ]);
                return $orderId;
            } catch (Throwable $e) {
                // Compatibility with installations whose expense columns are not migrated yet.
                $stmt = $pdo->prepare(
                    'INSERT INTO Payment_report (id_user, id_order, time, price, payment_Status, Payment_Method, id_invoice, note)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    (string) $userId,
                    $orderId,
                    $dateacc,
                    (string) $amount,
                    'cost',
                    'cost',
                    'cost',
                    'کسر موجودی کاربر توسط ادمین',
                ]);
                return $orderId;
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO Payment_report (id_user, id_order, time, price, payment_Status, Payment_Method, id_invoice)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (string) $userId,
            $orderId,
            $dateacc,
            (string) $amount,
            'paid',
            $method,
            null,
        ]);
        return $orderId;
    } catch (Throwable $e) {
        error_log('record_admin_balance_payment: ' . $e->getMessage());
        return null;
    }
}

/**
 * Record a paid purchase payment for a service created by admin (panel or Telegram).
 * Linked as getconfigafterpay|{username} so it shows as «خرید سرویس» in reports.
 */
function record_admin_order_payment(PDO $pdo, $userId, $price, string $username, string $idInvoice = '', string $productName = ''): ?string
{
    if ($userId === null || $userId === '' || trim($username) === '') {
        return null;
    }
    $dateacc = date('Y/m/d H:i:s');
    $orderId = bin2hex(random_bytes(5));
    $invoiceRef = 'getconfigafterpay|' . $username;
    $noteParts = ['سفارش ساخته‌شده توسط ادمین'];
    if ($productName !== '') {
        $noteParts[] = $productName;
    }
    if ($idInvoice !== '') {
        $noteParts[] = 'فاکتور ' . $idInvoice;
    }
    $note = implode(' | ', $noteParts);
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO Payment_report (id_user, id_order, time, price, payment_Status, Payment_Method, id_invoice, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (string) $userId,
            $orderId,
            $dateacc,
            (string) max(0, (int) $price),
            'paid',
            'add order by admin',
            $invoiceRef,
            $note,
        ]);
        return $orderId;
    } catch (Throwable $e) {
        error_log('record_admin_order_payment: ' . $e->getMessage());
        return null;
    }
}

/**
 * Record a paid extend payment created by admin (web panel).
 * Linked as getextenduser|{username}%{orderId} so reports treat it as an extend.
 */
function record_admin_extend_payment(PDO $pdo, $userId, $price, string $username, string $productName = '', string $orderId = ''): ?string
{
    if ($userId === null || $userId === '' || trim($username) === '') {
        return null;
    }
    $dateacc = date('Y/m/d H:i:s');
    if ($orderId === '' || strlen($orderId) < 4) {
        $orderId = bin2hex(random_bytes(5));
    }
    $invoiceRef = 'getextenduser|' . $username . '%' . $orderId;
    $noteParts = ['تمدید توسط ادمین'];
    if ($productName !== '') {
        $noteParts[] = $productName;
    }
    $note = implode(' | ', $noteParts);
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO Payment_report (id_user, id_order, time, price, payment_Status, Payment_Method, id_invoice, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (string) $userId,
            $orderId,
            $dateacc,
            (string) max(0, (int) $price),
            'paid',
            'extend by admin',
            $invoiceRef,
            $note,
        ]);
        return $orderId;
    } catch (Throwable $e) {
        error_log('record_admin_extend_payment: ' . $e->getMessage());
        return null;
    }
}

function unix_column_epoch_sql(string $column): string
{
    return "CASE
        WHEN $column REGEXP '^[0-9]{9,}$' THEN CAST($column AS UNSIGNED)
        ELSE COALESCE(
            UNIX_TIMESTAMP(STR_TO_DATE($column, '%Y-%m-%d %H:%i:%s')),
            UNIX_TIMESTAMP(STR_TO_DATE($column, '%Y/%m/%d %H:%i:%s'))
        )
    END";
}

function ensure_jdf_loaded(): void
{
    if (!function_exists('jdate')) {
        require_once __DIR__ . '/jdf.php';
    }
}

function tehran_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Tehran');
}

function tehran_datetime_string(int $ts, string $fmt = 'Y/m/d H:i:s'): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(tehran_timezone())->format($fmt);
}

function jalali_tehran_format(int $ts, string $fmt = 'Y/m/d H:i:s', string $trNum = 'en'): string
{
    ensure_jdf_loaded();
    return jdate($fmt, $ts, '', 'Asia/Tehran', $trNum);
}

/**
 * Convert a Jalali date entered in Tehran local time to a Unix timestamp.
 */
function jalali_tehran_timestamp(string $date, string $time = '', bool $endOfDay = false): ?int
{
    ensure_jdf_loaded();
    $date = trim((string) tr_num($date, 'en'));
    $time = trim((string) tr_num($time, 'en'));

    if (!preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $date, $dateParts)) {
        return null;
    }

    if ($time === '') {
        $time = $endOfDay ? '23:59:59' : '00:00:00';
    } elseif (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        $time .= ':00';
    }

    if (!preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $time, $timeParts)) {
        return null;
    }

    [$jy, $jm, $jd] = [(int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[3]];
    [$hour, $minute, $second] = [(int) $timeParts[1], (int) $timeParts[2], (int) $timeParts[3]];
    if ($jy < 1200 || $jy > 1600 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 || $hour > 23 || $minute > 59 || $second > 59) {
        return null;
    }

    [$gy, $gm, $gd] = jalali_to_gregorian($jy, $jm, $jd);
    if (!checkdate($gm, $gd, $gy) || gregorian_to_jalali($gy, $gm, $gd) !== [$jy, $jm, $jd]) {
        return null;
    }

    $dateTime = DateTimeImmutable::createFromFormat(
        '!Y-n-j H:i:s',
        "$gy-$gm-$gd " . sprintf('%02d:%02d:%02d', $hour, $minute, $second),
        tehran_timezone()
    );

    return $dateTime instanceof DateTimeImmutable ? $dateTime->getTimestamp() : null;
}

function jalali_tehran_parse(string $value, bool $endOfDay = false): ?int
{
    ensure_jdf_loaded();
    $value = trim((string) tr_num($value, 'en'));
    if (!preg_match('/^(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2})(?:\s+(\d{1,2}:\d{2}(?::\d{2})?))?$/', $value, $parts)) {
        return null;
    }

    return jalali_tehran_timestamp($parts[1], $parts[2] ?? '', $endOfDay);
}

function isValidJalaliDate($date): bool
{
    return jalali_tehran_parse((string) $date) !== null;
}

function jalali_days_in_month(int $jy, int $jm): int
{
    if ($jm < 1 || $jm > 12) {
        return 0;
    }
    if ($jm <= 6) {
        return 31;
    }
    if ($jm <= 11) {
        return 30;
    }
    return ((((($jy + 12) % 33) % 4) === 1) ? 1 : 0) + 29;
}

/**
 * @return array{jy:int,jm:int,jd:int,ts:int}
 */
function jalali_tehran_now_parts(): array
{
    ensure_jdf_loaded();
    $ts = time();
    return [
        'jy' => (int) jdate('Y', $ts, '', 'Asia/Tehran', 'en'),
        'jm' => (int) jdate('n', $ts, '', 'Asia/Tehran', 'en'),
        'jd' => (int) jdate('j', $ts, '', 'Asia/Tehran', 'en'),
        'ts' => $ts,
    ];
}

/**
 * @return array{0:int,1:int}
 */
function jalali_add_months(int $jy, int $jm, int $delta): array
{
    $monthIndex = ($jy * 12 + ($jm - 1)) + $delta;
    $year = intdiv($monthIndex, 12);
    $month = $monthIndex - ($year * 12) + 1;
    if ($month < 1) {
        $month += 12;
        $year--;
    }
    return [$year, $month];
}

function gregorian_ymd_to_jalali_key(string $ymd): ?string
{
    ensure_jdf_loaded();
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $ymd, $m)) {
        return null;
    }
    [$jy, $jm, $jd] = gregorian_to_jalali((int) $m[1], (int) $m[2], (int) $m[3]);
    return sprintf('%04d-%02d-%02d', $jy, $jm, $jd);
}

/**
 * Inclusive Unix range for a Jalali month in Tehran.
 *
 * @return array{start:int,end:int,jy:int,jm:int,days:int,start_dt:string,end_dt:string,label:string,param:string}|null
 */
function jalali_month_range(int $jy, int $jm): ?array
{
    $days = jalali_days_in_month($jy, $jm);
    $start = jalali_tehran_timestamp(sprintf('%04d/%02d/01', $jy, $jm), '00:00:00');
    $end = jalali_tehran_timestamp(sprintf('%04d/%02d/%02d', $jy, $jm, $days), '', true);
    if ($start === null || $end === null || $days < 1) {
        return null;
    }
    return [
        'start' => $start,
        'end' => $end,
        'jy' => $jy,
        'jm' => $jm,
        'days' => $days,
        'start_dt' => tehran_datetime_string($start),
        'end_dt' => tehran_datetime_string($end),
        'label' => jalali_tehran_format($start, 'F Y', 'fa'),
        'param' => sprintf('%04d-%02d', $jy, $jm),
    ];
}

/**
 * Named stats windows in Tehran / Jalali calendar.
 *
 * @return array{start:int,end:int,start_label:string,end_label:string,start_dt:string,end_dt:string,label:string}
 */
function stats_tehran_named_range(string $name): array
{
    $now = time();
    $parts = jalali_tehran_now_parts();
    if ($name === 'last_hour') {
        $start = $now - 3600;
        $end = $now;
    } elseif ($name === 'yesterday') {
        $startDay = (new DateTimeImmutable('yesterday', tehran_timezone()));
        $endDay = $startDay->setTime(23, 59, 59);
        $start = $startDay->setTime(0, 0, 0)->getTimestamp();
        $end = $endDay->getTimestamp();
    } elseif ($name === 'this_month') {
        $range = jalali_month_range($parts['jy'], $parts['jm']);
        $start = $range['start'];
        $end = $range['end'];
    } elseif ($name === 'last_month') {
        [$jy, $jm] = jalali_add_months($parts['jy'], $parts['jm'], -1);
        $range = jalali_month_range($jy, $jm);
        $start = $range['start'];
        $end = $range['end'];
    } else {
        $startDay = new DateTimeImmutable('today', tehran_timezone());
        $start = $startDay->getTimestamp();
        $end = $now;
    }

    return [
        'start' => $start,
        'end' => $end,
        'start_label' => jalali_tehran_format($start, 'Y/m/d H:i:s'),
        'end_label' => jalali_tehran_format($end, 'Y/m/d H:i:s'),
        'start_dt' => tehran_datetime_string($start),
        'end_dt' => tehran_datetime_string($end),
        'label' => jalali_tehran_format($start, 'Y/m/d H:i:s') . ' تا ' . jalali_tehran_format($end, 'Y/m/d H:i:s'),
    ];
}

function sql_unix_or_datetime_between(string $column): string
{
    return "(
        ($column REGEXP '^[0-9]{9,}$' AND CAST($column AS UNSIGNED) BETWEEN ? AND ?)
        OR (
            $column NOT REGEXP '^[0-9]{9,}$'
            AND COALESCE(
                STR_TO_DATE($column, '%Y-%m-%d %H:%i:%s'),
                STR_TO_DATE($column, '%Y/%m/%d %H:%i:%s')
            ) BETWEEN ? AND ?
        )
    )";
}

function sql_tehran_day_from_unix(string $unixExpr): string
{
    return "DATE_FORMAT(COALESCE(
        CONVERT_TZ(FROM_UNIXTIME(($unixExpr)), @@session.time_zone, '+03:30'),
        FROM_UNIXTIME(($unixExpr))
    ), '%Y-%m-%d')";
}

function bot_payment_id_invoice_prefix_sql(string $column = 'id_invoice'): string
{
    return "TRIM(SUBSTRING_INDEX(COALESCE($column,''), '|', 1))";
}

function bot_payment_paid_income_sql(): string
{
    return paid_real_income_sql() . "
        AND COALESCE(Payment_Method,'') != 'cost'
        AND COALESCE(id_invoice,'') != 'cost'";
}

function bot_payment_wallet_recharge_sql(): string
{
    $prefix = bot_payment_id_invoice_prefix_sql();
    return bot_payment_paid_income_sql() . "
        AND $prefix NOT IN (
            'getconfigafterpay','getextenduser','getextravolumeuser','getextratimeuser'
        )";
}

function bot_payment_purpose_case_sql(string $column = 'id_invoice'): string
{
    $prefix = bot_payment_id_invoice_prefix_sql($column);
    return "CASE
        WHEN $prefix = 'getconfigafterpay' THEN 'buy'
        WHEN $prefix = 'getextenduser' THEN 'extend'
        WHEN $prefix IN ('getextravolumeuser','getextratimeuser') THEN 'extra'
        ELSE 'wallet'
    END";
}

function bot_payment_purpose_filter_sql(string $purpose, string $column = 'id_invoice'): string
{
    $prefix = bot_payment_id_invoice_prefix_sql($column);
    if ($purpose === 'buy') {
        return "$prefix = 'getconfigafterpay'";
    }
    if ($purpose === 'extend') {
        return "$prefix = 'getextenduser'";
    }
    if ($purpose === 'extra') {
        return "$prefix IN ('getextravolumeuser','getextratimeuser')";
    }
    if ($purpose === 'wallet') {
        return "$prefix NOT IN ('getconfigafterpay','getextenduser','getextravolumeuser','getextratimeuser')";
    }
    return '1=1';
}

/**
 * Successful customer payments in a window, split by checkout purpose.
 *
 * @return array{purchase_count:int,purchase_sum:float,extend_count:int,extend_sum:float,extra_volume_count:int,extra_volume_sum:float,extra_time_count:int,extra_time_sum:float,wallet_count:int,wallet_sum:float}
 */
function bot_period_payment_purpose_stats(PDO $pdo, int $startTs, int $endTs): array
{
    $empty = [
        'purchase_count' => 0,
        'purchase_sum' => 0.0,
        'extend_count' => 0,
        'extend_sum' => 0.0,
        'extra_volume_count' => 0,
        'extra_volume_sum' => 0.0,
        'extra_time_count' => 0,
        'extra_time_sum' => 0.0,
        'wallet_count' => 0,
        'wallet_sum' => 0.0,
    ];
    $mixedTime = sql_unix_or_datetime_between('time');
    $prefix = bot_payment_id_invoice_prefix_sql();
    $incomeSql = bot_payment_paid_income_sql();
    $sql = "SELECT
            COALESCE(SUM(CASE WHEN $prefix = 'getconfigafterpay' THEN 1 ELSE 0 END), 0) AS purchase_count,
            COALESCE(SUM(CASE WHEN $prefix = 'getconfigafterpay' THEN CAST(price AS DECIMAL(20,0)) ELSE 0 END), 0) AS purchase_sum,
            COALESCE(SUM(CASE WHEN $prefix = 'getextenduser' THEN 1 ELSE 0 END), 0) AS extend_count,
            COALESCE(SUM(CASE WHEN $prefix = 'getextenduser' THEN CAST(price AS DECIMAL(20,0)) ELSE 0 END), 0) AS extend_sum,
            COALESCE(SUM(CASE WHEN $prefix = 'getextravolumeuser' THEN 1 ELSE 0 END), 0) AS extra_volume_count,
            COALESCE(SUM(CASE WHEN $prefix = 'getextravolumeuser' THEN CAST(price AS DECIMAL(20,0)) ELSE 0 END), 0) AS extra_volume_sum,
            COALESCE(SUM(CASE WHEN $prefix = 'getextratimeuser' THEN 1 ELSE 0 END), 0) AS extra_time_count,
            COALESCE(SUM(CASE WHEN $prefix = 'getextratimeuser' THEN CAST(price AS DECIMAL(20,0)) ELSE 0 END), 0) AS extra_time_sum,
            COALESCE(SUM(CASE WHEN $prefix NOT IN (
                'getconfigafterpay','getextenduser','getextravolumeuser','getextratimeuser'
            ) THEN 1 ELSE 0 END), 0) AS wallet_count,
            COALESCE(SUM(CASE WHEN $prefix NOT IN (
                'getconfigafterpay','getextenduser','getextravolumeuser','getextratimeuser'
            ) THEN CAST(price AS DECIMAL(20,0)) ELSE 0 END), 0) AS wallet_sum
        FROM Payment_report
        WHERE $mixedTime
          AND $incomeSql";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $startTs,
            $endTs,
            tehran_datetime_string($startTs, 'Y-m-d H:i:s'),
            tehran_datetime_string($endTs, 'Y-m-d H:i:s'),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'purchase_count' => (int) ($row['purchase_count'] ?? 0),
            'purchase_sum' => (float) ($row['purchase_sum'] ?? 0),
            'extend_count' => (int) ($row['extend_count'] ?? 0),
            'extend_sum' => (float) ($row['extend_sum'] ?? 0),
            'extra_volume_count' => (int) ($row['extra_volume_count'] ?? 0),
            'extra_volume_sum' => (float) ($row['extra_volume_sum'] ?? 0),
            'extra_time_count' => (int) ($row['extra_time_count'] ?? 0),
            'extra_time_sum' => (float) ($row['extra_time_sum'] ?? 0),
            'wallet_count' => (int) ($row['wallet_count'] ?? 0),
            'wallet_sum' => (float) ($row['wallet_sum'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('bot_period_payment_purpose_stats: ' . $e->getMessage());
        return $empty;
    }
}

/**
 * Paid wallet withdrawals in a window (approval time). Omit timestamps for all-time.
 *
 * @return array{count:int,sum:float}
 */
function bot_wallet_withdraw_stats(PDO $pdo, ?int $startTs = null, ?int $endTs = null): array
{
    $empty = ['count' => 0, 'sum' => 0.0];
    try {
        $sql = "SELECT COUNT(*) AS count, COALESCE(SUM(amount),0) AS sum
                FROM wallet_withdraw
                WHERE status = 'paid'";
        $params = [];
        if ($startTs !== null && $endTs !== null) {
            $sql .= ' AND updated_at BETWEEN :start AND :end';
            $params = [':start' => $startTs, ':end' => $endTs];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'count' => (int) ($row['count'] ?? 0),
            'sum' => (float) ($row['sum'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('bot_wallet_withdraw_stats: ' . $e->getMessage());
        return $empty;
    }
}

/**
 * Same ledger as the payments page cards:
 * income = Payment_report.paid (not investment), expenses = cost, investment = capital inflow, net = income - expenses.
 *
 * @return array{income_count:int,income_sum:float,expenses_count:int,expenses_sum:float,investment_count:int,investment_sum:float,net_sum:float}
 */
function bot_payment_ledger_stats(PDO $pdo, ?int $startTs = null, ?int $endTs = null): array
{
    $empty = [
        'income_count' => 0,
        'income_sum' => 0.0,
        'expenses_count' => 0,
        'expenses_sum' => 0.0,
        'investment_count' => 0,
        'investment_sum' => 0.0,
        'net_sum' => 0.0,
    ];
    try {
        $timeSql = '';
        $params = [];
        if ($startTs !== null && $endTs !== null) {
            $timeSql = ' AND ' . sql_unix_or_datetime_between('time');
            $params = [
                $startTs,
                $endTs,
                tehran_datetime_string($startTs),
                tehran_datetime_string($endTs),
            ];
        }
        $investmentSql = "(tx_type = 'investment' OR payment_Status = 'investment' OR Payment_Method = 'capital_injection' OR id_invoice = 'capital')";
        $incomeSql = "payment_Status = 'paid' AND NOT $investmentSql";
        $sql = "SELECT
                COALESCE(SUM(CASE WHEN $incomeSql THEN 1 ELSE 0 END), 0) AS income_count,
                COALESCE(SUM(CASE WHEN $incomeSql THEN CAST(price AS DECIMAL(20,0)) ELSE 0 END), 0) AS income_sum,
                COALESCE(SUM(CASE WHEN payment_Status = 'cost' THEN 1 ELSE 0 END), 0) AS expenses_count,
                COALESCE(SUM(CASE WHEN payment_Status = 'cost' THEN CAST(price AS DECIMAL(20,0)) ELSE 0 END), 0) AS expenses_sum,
                COALESCE(SUM(CASE WHEN $investmentSql THEN 1 ELSE 0 END), 0) AS investment_count,
                COALESCE(SUM(CASE WHEN $investmentSql THEN CAST(price AS DECIMAL(20,0)) ELSE 0 END), 0) AS investment_sum
            FROM Payment_report
            WHERE (
                payment_Status IN ('paid', 'cost', 'investment')
                OR tx_type IN ('expense', 'investment')
                OR Payment_Method = 'capital_injection'
                OR id_invoice = 'capital'
            )
              $timeSql";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $income = (float) ($row['income_sum'] ?? 0);
        $expenses = (float) ($row['expenses_sum'] ?? 0);
        return [
            'income_count' => (int) ($row['income_count'] ?? 0),
            'income_sum' => $income,
            'expenses_count' => (int) ($row['expenses_count'] ?? 0),
            'expenses_sum' => $expenses,
            'investment_count' => (int) ($row['investment_count'] ?? 0),
            'investment_sum' => (float) ($row['investment_sum'] ?? 0),
            'net_sum' => $income - $expenses,
        ];
    } catch (Throwable $e) {
        error_log('bot_payment_ledger_stats: ' . $e->getMessage());
        return $empty;
    }
}

/**
 * Recorded costs other than wallet withdrawals (withdrawals are counted from
 * wallet_withdraw so old requests and their Payment_report mirror are not double-counted).
 *
 * @return array{count:int,sum:float}
 */
function bot_payment_expense_stats(PDO $pdo, ?int $startTs = null, ?int $endTs = null): array
{
    $empty = ['count' => 0, 'sum' => 0.0];
    try {
        $sql = "SELECT COUNT(*) AS count, COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) AS sum
                FROM Payment_report
                WHERE (payment_Status = 'cost' OR Payment_Method = 'cost' OR id_invoice = 'cost')
                  AND COALESCE(expense_category,'') != 'wallet_withdraw'";
        $params = [];
        if ($startTs !== null && $endTs !== null) {
            $sql .= ' AND ' . sql_unix_or_datetime_between('time');
            $params = [
                $startTs,
                $endTs,
                tehran_datetime_string($startTs, 'Y-m-d H:i:s'),
                tehran_datetime_string($endTs, 'Y-m-d H:i:s'),
            ];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'count' => (int) ($row['count'] ?? 0),
            'sum' => (float) ($row['sum'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('bot_payment_expense_stats: ' . $e->getMessage());
        return $empty;
    }
}

function bot_format_gb($gb): string
{
    $gb = (float) $gb;
    if (abs($gb - round($gb)) < 0.005) {
        return number_format($gb, 0);
    }
    return number_format($gb, 2);
}

function bot_sql_extra_volume_gb(string $column = 'value'): string
{
    return "CASE
        WHEN $column LIKE '%\"volume_value\"%' THEN COALESCE(CAST(TRIM(BOTH '\"' FROM TRIM(TRAILING '}' FROM SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT($column, ','), '\"volume_value\":', -1), ',', 1))) AS DECIMAL(20,2)), 0)
        ELSE 0
    END";
}

/**
 * Sold volume (GB) from paid non-test invoices plus extra-volume purchases.
 *
 * @return array{invoice:float, extra:float, total:float, panels: list<array{name:string, invoice:float, extra:float, total:float}>}
 */
function bot_sold_volume_stats(PDO $pdo, ?int $startTs = null, ?int $endTs = null): array
{
    $paidSql = invoice_paid_status_sql('Status');
    $panelSql = "COALESCE(NULLIF(TRIM(Service_location), ''), 'نامشخص')";
    $hasRange = $startTs !== null && $endTs !== null;

    $invoiceTime = $hasRange ? 'AND time_sell BETWEEN :start AND :end' : '';
    $invoiceParams = $hasRange ? [':start' => $startTs, ':end' => $endTs] : [];
    $stmt = $pdo->prepare("SELECT $panelSql AS panel,
            COALESCE(SUM(CAST(Volume AS DECIMAL(20,2))), 0) AS volume
        FROM invoice
        WHERE $paidSql
          AND name_product != 'سرویس تست'
          $invoiceTime
        GROUP BY COALESCE(NULLIF(TRIM(Service_location), ''), 'نامشخص')");
    $stmt->execute($invoiceParams);
    $invoiceByPanel = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $invoiceByPanel[(string) $row['panel']] = (float) $row['volume'];
    }

    $extraByPanel = [];
    try {
        $extraGbSql = bot_sql_extra_volume_gb('so.value');
        $extraTimeSql = '';
        $extraParams = [];
        if ($hasRange) {
            $extraTimeSql = 'AND ' . sql_unix_or_datetime_between('so.time');
            $extraParams = [
                $startTs,
                $endTs,
                tehran_datetime_string($startTs, 'Y-m-d H:i:s'),
                tehran_datetime_string($endTs, 'Y-m-d H:i:s'),
            ];
        }
        $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(i.Service_location), ''), 'نامشخص') AS panel,
                COALESCE(SUM($extraGbSql), 0) AS volume
            FROM service_other so
            LEFT JOIN (
                SELECT username, MIN(Service_location) AS Service_location
                FROM invoice
                GROUP BY username
            ) i ON i.username = so.username
            WHERE so.type = 'extra_user'
              AND COALESCE(so.status,'') NOT IN ('unpaid','Unpaid','reject')
              $extraTimeSql
            GROUP BY COALESCE(NULLIF(TRIM(i.Service_location), ''), 'نامشخص')");
        $stmt->execute($extraParams);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $extraByPanel[(string) $row['panel']] = (float) $row['volume'];
        }
    } catch (Throwable $e) {
        error_log('bot_sold_volume extra: ' . $e->getMessage());
    }

    $panelNames = [];
    try {
        $panelNames = $pdo->query("SELECT name_panel FROM marzban_panel ORDER BY name_panel")
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        error_log('bot_sold_volume panels: ' . $e->getMessage());
    }

    $allNames = [];
    foreach ($panelNames as $name) {
        $name = (string) $name;
        if ($name !== '') {
            $allNames[$name] = true;
        }
    }
    foreach (array_keys($invoiceByPanel + $extraByPanel) as $name) {
        $allNames[(string) $name] = true;
    }

    $panels = [];
    $invoiceTotal = 0.0;
    $extraTotal = 0.0;
    foreach (array_keys($allNames) as $name) {
        $invoiceVol = (float) ($invoiceByPanel[$name] ?? 0);
        $extraVol = (float) ($extraByPanel[$name] ?? 0);
        $invoiceTotal += $invoiceVol;
        $extraTotal += $extraVol;
        $panels[] = [
            'name' => $name,
            'invoice' => $invoiceVol,
            'extra' => $extraVol,
            'total' => $invoiceVol + $extraVol,
        ];
    }
    usort($panels, static function (array $a, array $b): int {
        $cmp = $b['total'] <=> $a['total'];
        return $cmp !== 0 ? $cmp : strcasecmp($a['name'], $b['name']);
    });

    return [
        'invoice' => $invoiceTotal,
        'extra' => $extraTotal,
        'total' => $invoiceTotal + $extraTotal,
        'panels' => $panels,
    ];
}

function bot_format_sold_volume_block(array $vol, bool $html = false): string
{
    $fmt = static fn($n) => bot_format_gb($n);
    $total = $fmt($vol['total'] ?? 0);
    $invoice = $fmt($vol['invoice'] ?? 0);
    $extra = $fmt($vol['extra'] ?? 0);
    if ($html) {
        $lines = "🔋 <b>حجم فروخته‌شده:</b> <code>$total</code> گیگابایت"
            . "\n• از خرید سرویس: <code>$invoice</code> گیگابایت"
            . "\n• از حجم اضافه: <code>$extra</code> گیگابایت";
    } else {
        $lines = "🔋 حجم فروخته‌شده : $total گیگابایت"
            . "\n• از خرید سرویس : $invoice گیگابایت"
            . "\n• از حجم اضافه : $extra گیگابایت";
    }

    $panels = $vol['panels'] ?? [];
    $shown = 0;
    $totalPanels = count($panels);
    foreach ($panels as $panel) {
        $name = htmlspecialchars((string) ($panel['name'] ?? 'نامشخص'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $gb = $fmt($panel['total'] ?? 0);
        $line = $html
            ? "\n• {$name}: <code>$gb</code> گیگابایت"
            : "\n• {$name} : $gb گیگابایت";
        if ($shown > 0 && mb_strlen($lines . $line) > 1800) {
            $remaining = $totalPanels - $shown;
            $lines .= $html
                ? "\n• … و <code>$remaining</code> پنل دیگر"
                : "\n• … و $remaining پنل دیگر";
            break;
        }
        $lines .= $line;
        $shown++;
    }
    return $lines;
}

/**
 * Lifetime first paid non-test product purchase per user.
 * Optional window keeps only first purchases whose time_sell falls in the range.
 *
 * @return array{count:int, sum:float}
 */
function bot_first_purchase_stats(PDO $pdo, ?int $startTs = null, ?int $endTs = null): array
{
    $empty = ['count' => 0, 'sum' => 0.0];
    $paidSql = invoice_paid_status_sql('i.Status');
    $sellEpoch = unix_column_epoch_sql('i.time_sell');
    $hasRange = $startTs !== null && $endTs !== null;
    $timeFilter = $hasRange ? 'AND t.time_sell BETWEEN :start AND :end' : '';
    $params = $hasRange ? [':start' => $startTs, ':end' => $endTs] : [];

    $sqlWindow = "SELECT COUNT(*) AS count, COALESCE(SUM(t.price_product), 0) AS sum
        FROM (
            SELECT i.price_product, i.time_sell,
                   ROW_NUMBER() OVER (
                       PARTITION BY i.id_user
                       ORDER BY ($sellEpoch) ASC, i.id_invoice ASC
                   ) AS rn
            FROM invoice i
            WHERE i.name_product != 'سرویس تست'
              AND $paidSql
              AND ($sellEpoch) IS NOT NULL
              AND ($sellEpoch) > 0
        ) t
        WHERE t.rn = 1
          $timeFilter";

    try {
        $stmt = $pdo->prepare($sqlWindow);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'count' => (int) ($row['count'] ?? 0),
            'sum' => (float) ($row['sum'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('bot_first_purchase_stats window: ' . $e->getMessage());
    }

    $paidNoAlias = invoice_paid_status_sql('Status');
    $timeFilterI = $hasRange ? 'AND i.time_sell BETWEEN :start AND :end' : '';
    $sqlFallback = "SELECT COUNT(*) AS count, COALESCE(SUM(i.price_product), 0) AS sum
        FROM invoice i
        INNER JOIN (
            SELECT id_user, MIN(CAST(time_sell AS UNSIGNED)) AS first_ts
            FROM invoice
            WHERE name_product != 'سرویس تست'
              AND $paidNoAlias
            GROUP BY id_user
        ) fp ON fp.id_user = i.id_user
           AND CAST(i.time_sell AS UNSIGNED) = fp.first_ts
        WHERE i.name_product != 'سرویس تست'
          AND $paidSql
          $timeFilterI";
    try {
        $stmt = $pdo->prepare($sqlFallback);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'count' => (int) ($row['count'] ?? 0),
            'sum' => (float) ($row['sum'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('bot_first_purchase_stats fallback: ' . $e->getMessage());
        return $empty;
    }
}

function bot_format_first_purchase_block(array $fp, int $ordersCount, float $ordersSum, bool $html = false): string
{
    $count = number_format((int) ($fp['count'] ?? 0));
    $sum = number_format((float) ($fp['sum'] ?? 0), 0);
    $pct = $ordersCount > 0 ? round(((int) ($fp['count'] ?? 0) / $ordersCount) * 100, 2) : 0;
    $pctMoney = $ordersSum > 0 ? round(((float) ($fp['sum'] ?? 0) / $ordersSum) * 100, 2) : 0;
    $rlm = "\u{200F}";
    if ($html) {
        return "{$rlm}🆕 <b>خرید اول:</b> <code>$count</code> عدد — <code>$pct</code>٪ از فروش\n"
            . "{$rlm}💰 <b>مبلغ خرید اول:</b> <code>$sum</code> تومان — <code>$pctMoney</code>٪ از مبلغ فروش";
    }
    return "{$rlm}🆕 خرید اول : $count عدد — $pct٪ از سفارشات\n"
        . "{$rlm}💰 مبلغ خرید اول : $sum تومان — $pctMoney٪ از مبلغ سفارشات";
}

/**
 * Paid non-test product invoices for a user.
 * Optional invoice id is included even if still unpaid (in-progress purchase).
 */
function bot_non_test_purchase_count(PDO $pdo, $userId, $includeInvoiceId = null): int
{
    $paidSql = invoice_paid_status_sql('Status');
    $sql = "SELECT COUNT(*) FROM invoice
            WHERE id_user = :id
              AND name_product != 'سرویس تست'
              AND (($paidSql)";
    $params = [':id' => (string) $userId];
    if ($includeInvoiceId !== null && $includeInvoiceId !== '') {
        $sql .= " OR id_invoice = :current";
        $params[':current'] = (string) $includeInvoiceId;
    }
    $sql .= ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function bot_is_first_product_purchase(PDO $pdo, $userId, $includeInvoiceId = null): bool
{
    return bot_non_test_purchase_count($pdo, $userId, $includeInvoiceId) === 1;
}

function affiliates_ads_cutoff(): string
{
    return '2026-08-21 00:00:00';
}

function affiliates_report_time_sql(string $alias = 'r'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        return 'NULL';
    }
    return "COALESCE(
        STR_TO_DATE(REPLACE(LEFT(TRIM({$alias}.time), 19), '-', '/'), '%Y/%m/%d %H:%i:%s'),
        STR_TO_DATE(REPLACE(LEFT(TRIM({$alias}.time), 10), '-', '/'), '%Y/%m/%d')
    )";
}

function affiliates_register_time_sql(string $alias = 'u'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        return 'NULL';
    }
    return "CASE
        WHEN TRIM({$alias}.register) REGEXP '^[0-9]{10}$'
            THEN FROM_UNIXTIME(CAST({$alias}.register AS UNSIGNED))
        WHEN TRIM({$alias}.register) REGEXP '^[0-9]{13}$'
            THEN FROM_UNIXTIME(FLOOR(CAST({$alias}.register AS UNSIGNED) / 1000))
        ELSE NULL
    END";
}

function affiliates_report_is_active_sql(string $alias = 'r'): string
{
    $timeSql = affiliates_report_time_sql($alias);
    $cutoff = affiliates_ads_cutoff();
    return "COALESCE({$alias}.migrated_to_ads, 0) = 0
      AND IFNULL({$alias}.reagent, '') != ''
      AND {$alias}.reagent != '0'
      AND ({$timeSql} IS NULL OR {$timeSql} >= '{$cutoff}')";
}

function affiliates_relationship_is_migrated($buyerId, $affiliateId = null): bool
{
    global $pdo;
    if (!($pdo instanceof PDO) || $buyerId === null || $buyerId === '') {
        return false;
    }

    $sql = 'SELECT migrated_to_ads, time FROM reagent_report WHERE user_id = ?';
    $params = [(string) $buyerId];
    if ($affiliateId !== null && $affiliateId !== '') {
        $sql .= ' AND CAST(reagent AS CHAR) = CAST(? AS CHAR)';
        $params[] = (string) $affiliateId;
    }
    $sql .= ' LIMIT 1';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        if ((int) ($row['migrated_to_ads'] ?? 0) === 1) {
            return true;
        }
        $ts = affiliates_parse_timestamp($row['time'] ?? null);
        return $ts !== null && $ts < strtotime(affiliates_ads_cutoff());
    } catch (Throwable $e) {
        error_log('affiliates_relationship_is_migrated: ' . $e->getMessage());
        return false;
    }
}

/**
 * Pay affiliate commission for a buy or extend.
 * When first-purchase-only is on, extend never pays and later buys are skipped.
 *
 * @param array $buyer user row of the invited customer
 * @param string $reason buy|extend
 */
function pay_affiliate_commission(array $buyer, $amount, string $reason = 'buy', $currentInvoiceId = null): bool
{
    global $pdo, $setting, $admin_ids, $porsantreport;

    $amount = (float) $amount;
    if ($amount <= 0) {
        return false;
    }
    $affiliateId = (string) ($buyer['affiliates'] ?? '0');
    if ($affiliateId === '' || $affiliateId === '0' || intval($affiliateId) === 0) {
        return false;
    }
    if (affiliates_relationship_is_migrated($buyer['id'] ?? null, $affiliateId)) {
        return false;
    }
    $affSettings = select('affiliates', '*', null, null, 'select');
    if (!is_array($affSettings) || ($affSettings['status_commission'] ?? '') !== 'oncommission') {
        return false;
    }

    $firstBuyOnly = ($affSettings['porsant_one_buy'] ?? 'off_buy_porsant') === 'on_buy_porsant';
    if ($firstBuyOnly) {
        if ($reason === 'extend') {
            return false;
        }
        if (!($pdo instanceof PDO)) {
            return false;
        }
        if (bot_non_test_purchase_count($pdo, $buyer['id'] ?? '', $currentInvoiceId) > 1) {
            return false;
        }
    }

    $percent = (float) ($setting['affiliatespercentage'] ?? 0);
    if ($percent <= 0) {
        return false;
    }
    $commission = ($amount * $percent) / 100;
    if ($commission <= 0) {
        return false;
    }

    $referrer = select('user', '*', 'id', $affiliateId, 'select');
    if (!is_array($referrer)) {
        return false;
    }

    $adminList = (isset($admin_ids) && is_array($admin_ids)) ? $admin_ids : [];
    if (intval($setting['scorestatus'] ?? 0) === 1 && !in_array($affiliateId, $adminList, false) && !in_array((int) $affiliateId, $adminList, false)) {
        sendmessage($affiliateId, '📌شما 2 امتیاز جدید کسب کردید.', null, 'html');
        update('user', 'score', intval($referrer['score'] ?? 0) + 2, 'id', $affiliateId);
    }

    update('user', 'Balance', ($referrer['Balance'] ?? 0) + $commission, 'id', $affiliateId);

    $commissionFmt = number_format($commission);
    $dateacc = date('Y/m/d H:i:s');
    $buyerId = $buyer['id'] ?? '';
    sendmessage($affiliateId, "🎁  پرداخت پورسانت 

        مبلغ $commissionFmt تومان به حساب شما از طرف  زیر مجموعه تان به کیف پول شما واریز گردید", null, 'HTML');

    $label = $reason === 'extend' ? 'پورسانت تمدید' : 'پورسانت';
    $textreportport = "مبلغ $commissionFmt به کاربر {$affiliateId} برای {$label} از کاربر {$buyerId} واریز گردید 
تایم : $dateacc";

    $threadId = $porsantreport ?? null;
    if ($threadId === null || $threadId === '') {
        $topic = select('topicid', 'idreport', 'report', 'porsantreport', 'select');
        $threadId = is_array($topic) ? ($topic['idreport'] ?? null) : null;
    }
    if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $threadId,
            'text' => $textreportport,
            'parse_mode' => 'HTML',
        ]);
    }
    return true;
}

/**
 * @return array{orders:int,orders_sum:float,orders_invoice_sum:float,tests:int,extends:int,extends_sum:float,extra_volume:int,extra_volume_sum:float,extra_time:int,extra_time_sum:float,change_location:int,change_location_sum:float,wallet:int,wallet_sum:float,wallet_withdraw:int,wallet_withdraw_sum:float,expenses:int,expenses_sum:float,users:int,avg_join:string,total_count:int,period_income_sum:float,period_net_sum:float,period_investment_sum:float,income_sum:float,total_sum:float,investment_sum:float,sold_volume:array,first_purchase:array,forecast_sold_volume:?float}
 */
function bot_period_stats(PDO $pdo, int $startTs, int $endTs): array
{
    $startDt = tehran_datetime_string($startTs, 'Y-m-d H:i:s');
    $endDt = tehran_datetime_string($endTs, 'Y-m-d H:i:s');
    $mixedTime = sql_unix_or_datetime_between('time');
    $mixedParams = [$startTs, $endTs, $startDt, $endDt];
    $paidSql = invoice_paid_status_sql('Status');
    $payments = bot_period_payment_purpose_stats($pdo, $startTs, $endTs);

    $stmt = $pdo->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(price_product),0) AS sum FROM invoice WHERE (time_sell BETWEEN :start AND :end) AND $paidSql AND name_product != 'سرویس تست'");
    $stmt->execute([':start' => $startTs, ':end' => $endTs]);
    $orders = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['count' => 0, 'sum' => 0];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM invoice WHERE (time_sell BETWEEN :start AND :end) AND name_product = 'سرویس تست'");
    $stmt->execute([':start' => $startTs, ':end' => $endTs]);
    $tests = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);

    $stmt = $pdo->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) AS sum FROM service_other WHERE $mixedTime AND type IN ('extend_user','extends_not_user','extend_user_by_admin') AND status = 'paid'");
    $stmt->execute($mixedParams);
    $extends = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['count' => 0, 'sum' => 0];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(price),0) AS sum FROM service_other WHERE $mixedTime AND type = 'change_location' AND COALESCE(status,'') NOT IN ('unpaid','Unpaid','reject')");
    $stmt->execute($mixedParams);
    $changeLocation = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['count' => 0, 'sum' => 0];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM user WHERE register != 'none' AND (register BETWEEN :start AND :end)");
    $stmt->execute([':start' => $startTs, ':end' => $endTs]);
    $users = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);

    $orderInvoiceSum = (float) ($orders['sum'] ?? 0);
    $orderSum = (float) ($payments['purchase_sum'] ?? 0);
    $extendSum = (float) ($payments['extend_sum'] ?? 0);
    $extraVolumeCount = (int) ($payments['extra_volume_count'] ?? 0);
    $extraVolumeSum = (float) ($payments['extra_volume_sum'] ?? 0);
    $extraTimeCount = (int) ($payments['extra_time_count'] ?? 0);
    $extraTimeSum = (float) ($payments['extra_time_sum'] ?? 0);
    $changeLocationSum = (float) ($changeLocation['sum'] ?? 0);
    $walletSum = (float) ($payments['wallet_sum'] ?? 0);
    $walletCount = (int) ($payments['wallet_count'] ?? 0);
    $withdraw = bot_wallet_withdraw_stats($pdo, $startTs, $endTs);
    $withdrawCount = (int) ($withdraw['count'] ?? 0);
    $withdrawSum = (float) ($withdraw['sum'] ?? 0);
    $ledger = bot_payment_ledger_stats($pdo, $startTs, $endTs);
    $ledgerAll = bot_payment_ledger_stats($pdo);
    $expenseCount = (int) ($ledger['expenses_count'] ?? 0);
    $expenseSum = (float) ($ledger['expenses_sum'] ?? 0);

    return [
        'orders' => (int) ($orders['count'] ?? 0),
        'orders_sum' => $orderSum,
        'orders_invoice_sum' => $orderInvoiceSum,
        'tests' => $tests,
        'extends' => (int) ($extends['count'] ?? 0),
        'extends_sum' => $extendSum,
        'extra_volume' => $extraVolumeCount,
        'extra_volume_sum' => $extraVolumeSum,
        'extra_time' => $extraTimeCount,
        'extra_time_sum' => $extraTimeSum,
        'change_location' => (int) ($changeLocation['count'] ?? 0),
        'change_location_sum' => $changeLocationSum,
        'wallet' => $walletCount,
        'wallet_sum' => $walletSum,
        'wallet_withdraw' => $withdrawCount,
        'wallet_withdraw_sum' => $withdrawSum,
        'expenses' => $expenseCount,
        'expenses_sum' => $expenseSum,
        'users' => $users,
        'avg_join' => avg_join_to_first_purchase_label($pdo, $startTs, $endTs),
        'total_count' => (int) ($payments['purchase_count'] ?? 0)
            + (int) ($payments['extend_count'] ?? 0)
            + $extraVolumeCount
            + $extraTimeCount
            + $walletCount,
        'period_income_sum' => (float) ($ledger['income_sum'] ?? 0),
        'period_net_sum' => (float) ($ledger['net_sum'] ?? 0),
        'period_investment_sum' => (float) ($ledger['investment_sum'] ?? 0),
        'income_sum' => (float) ($ledgerAll['income_sum'] ?? 0),
        'total_sum' => (float) ($ledgerAll['net_sum'] ?? 0),
        'investment_sum' => (float) ($ledgerAll['investment_sum'] ?? 0),
        'sold_volume' => bot_sold_volume_stats($pdo, $startTs, $endTs),
        'first_purchase' => bot_first_purchase_stats($pdo, $startTs, $endTs),
        'forecast_sold_volume' => ($endTs - $startTs) >= (7 * 86400)
            ? forecast_monthly_sold_volume($pdo, $startTs, $endTs)
            : null,
    ];
}

function bot_format_period_stats(array $s, string $title, ?string $rangeLabel = null): string
{
    $rangeLine = $rangeLabel !== null && $rangeLabel !== ''
        ? "\n⏳ بازه تایم : $rangeLabel\n"
        : "\n";
    $sumOrder = number_format($s['orders_sum'], 0);
    $sumExtend = number_format($s['extends_sum'], 0);
    $sumExtraVolume = number_format($s['extra_volume_sum'], 0);
    $walletCount = (int) ($s['wallet'] ?? 0);
    $sumWallet = number_format((float) ($s['wallet_sum'] ?? 0), 0);
    $withdrawCount = (int) ($s['wallet_withdraw'] ?? 0);
    $sumWithdraw = number_format((float) ($s['wallet_withdraw_sum'] ?? 0), 0);
    $expenseCount = (int) ($s['expenses'] ?? 0);
    $sumExpenses = number_format((float) ($s['expenses_sum'] ?? 0), 0);
    $sumPeriodIncome = number_format((float) ($s['period_income_sum'] ?? 0), 0);
    $sumPeriodNet = number_format((float) ($s['period_net_sum'] ?? 0), 0);
    $sumPeriodInvestment = number_format((float) ($s['period_investment_sum'] ?? 0), 0);
    $sumIncome = number_format((float) ($s['income_sum'] ?? 0), 0);
    $sumTotal = number_format($s['total_sum'], 0);
    $sumInvestment = number_format((float) ($s['investment_sum'] ?? 0), 0);
    $soldVolumeBlock = bot_format_sold_volume_block($s['sold_volume'] ?? []);
    $forecastVolume = $s['forecast_sold_volume'] ?? null;
    if ($forecastVolume !== null) {
        $soldVolumeBlock .= "\n📅 حجم فروخته‌شده پیش‌بینی‌شده ماهانه : " . bot_format_gb($forecastVolume) . " گیگابایت";
    }
    $firstPurchaseBlock = bot_format_first_purchase_block(
        $s['first_purchase'] ?? [],
        (int) ($s['orders'] ?? 0),
        (float) ($s['orders_invoice_sum'] ?? $s['orders_sum'] ?? 0)
    );

    return "
🕐 <b>$title</b>
$rangeLine
🛍 تعداد سفارشات : {$s['orders']} عدد
💸 جمع مبلغ سفارشات  : $sumOrder تومان
$firstPurchaseBlock

🧲 تعداد تمدید  : {$s['extends']} عدد
💰 جمع مبلغ تمدید: $sumExtend تومان

📦 حجم‌های اضافه  :{$s['extra_volume']} عدد
💰 مبلغ حجم‌های اضافه : $sumExtraVolume تومان

💳 شارژ کیف پول : $walletCount عدد
💰 مبلغ شارژ کیف پول : $sumWallet تومان

💸 تعداد برداشت از کیف پول : $withdrawCount عدد
💰 مبلغ برداشت از کیف پول : $sumWithdraw تومان

🧾 تعداد هزینه‌ها : $expenseCount عدد
💸 مجموع هزینه‌ها : $sumExpenses تومان

📊 تعداد کل : {$s['total_count']} عدد

$soldVolumeBlock

🔑 اکانت‌های تست  : {$s['tests']} عدد
👤 تعداد کاربران  : {$s['users']} نفر
⏱ میانگین زمان عضویت تا اولین خرید : {$s['avg_join']}

💰 درآمد کل (این بازه) : $sumPeriodIncome تومان
💵 درآمد خالص (این بازه) : $sumPeriodNet تومان
🏦 ورود سرمایه (این بازه) : $sumPeriodInvestment تومان
💰 درآمد کل (از ابتدا) : $sumIncome تومان
💵 درآمد خالص (از ابتدا) : $sumTotal تومان
🏦 ورود سرمایه (از ابتدا) : $sumInvestment تومان
";
}

function format_duration_fa(?float $seconds): string
{
    if ($seconds === null || $seconds < 0) {
        return '—';
    }
    $seconds = (int) round($seconds);
    if ($seconds < 60) {
        return 'کمتر از ۱ دقیقه';
    }
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $parts = [];
    if ($days > 0) {
        $parts[] = $days . ' روز';
    }
    if ($hours > 0) {
        $parts[] = $hours . ' ساعت';
    }
    if ($minutes > 0 && $days === 0) {
        $parts[] = $minutes . ' دقیقه';
    }
    return $parts === [] ? 'کمتر از ۱ دقیقه' : implode(' و ', $parts);
}

/**
 * Average seconds from join (user.register) to first paid non-test purchase.
 * Optional window filters users by join date; first purchase may be later.
 *
 * @return array{avg_seconds: ?float, buyers: int, formatted: string}
 */
function avg_join_to_first_purchase(PDO $pdo, ?int $joinStart = null, ?int $joinEnd = null): array
{
    $empty = ['avg_seconds' => null, 'buyers' => 0, 'formatted' => 'داده کافی نیست'];
    $registerEpoch = unix_column_epoch_sql('u.register');
    $sellEpoch = unix_column_epoch_sql('i.time_sell');
    $paidSql = invoice_paid_status_sql('i.Status');
    $joinFilter = '';
    $params = [];
    if ($joinStart !== null && $joinEnd !== null) {
        $joinFilter = "AND ($registerEpoch) BETWEEN :join_start AND :join_end";
        $params[':join_start'] = $joinStart;
        $params[':join_end'] = $joinEnd;
    }
    $sql = "SELECT AVG(fp.first_ts - joins.join_ts) AS avg_seconds,
                   COUNT(*) AS buyers
            FROM (
                SELECT u.id AS user_id, ($registerEpoch) AS join_ts
                FROM user u
                WHERE u.register IS NOT NULL
                  AND u.register != ''
                  AND u.register != 'none'
                  AND ($registerEpoch) IS NOT NULL
                  AND ($registerEpoch) > 0
                  $joinFilter
            ) joins
            INNER JOIN (
                SELECT i.id_user, MIN($sellEpoch) AS first_ts
                FROM invoice i
                WHERE i.name_product != 'سرویس تست'
                  AND $paidSql
                  AND ($sellEpoch) IS NOT NULL
                  AND ($sellEpoch) > 0
                GROUP BY i.id_user
            ) fp ON fp.id_user = joins.user_id
            WHERE fp.first_ts >= joins.join_ts";
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $buyers = (int) ($row['buyers'] ?? 0);
        $avg = isset($row['avg_seconds']) && $row['avg_seconds'] !== null ? (float) $row['avg_seconds'] : null;
        if ($buyers <= 0 || $avg === null) {
            return $empty;
        }
        return [
            'avg_seconds' => $avg,
            'buyers' => $buyers,
            'formatted' => format_duration_fa($avg) . ' (' . number_format($buyers) . ' کاربر)',
        ];
    } catch (Exception $e) {
        error_log('avg_join_to_first_purchase: ' . $e->getMessage());
        return $empty;
    }
}

function avg_join_to_first_purchase_label(PDO $pdo, ?int $joinStart = null, ?int $joinEnd = null): string
{
    return avg_join_to_first_purchase($pdo, $joinStart, $joinEnd)['formatted'];
}

/**
 * Complete-day window used by monthly forecasts.
 * Default is the last 28 days ending yesterday (same as paid-income forecast).
 * Optional range clamps the window to a period (e.g. a Jalali month) and still uses at most $days days.
 *
 * @return array{start:int,end:int,days:int}|null
 */
function forecast_monthly_complete_day_window(?int $rangeStart = null, ?int $rangeEnd = null, int $days = 28): ?array
{
    if ($days < 1) {
        return null;
    }
    $todayStart = strtotime('today');
    if ($todayStart === false) {
        return null;
    }
    $latestEnd = $todayStart - 1;

    if ($rangeStart === null || $rangeEnd === null) {
        $windowStart = strtotime('today -' . $days . ' days');
        if ($windowStart === false) {
            return null;
        }
        return [
            'start' => (int) $windowStart,
            'end' => $latestEnd,
            'days' => $days,
        ];
    }

    $windowEnd = min($rangeEnd, $latestEnd);
    if ($windowEnd < $rangeStart) {
        return null;
    }

    $endDayStart = strtotime('today', $windowEnd);
    if ($endDayStart === false) {
        return null;
    }
    $windowStart = strtotime('-' . ($days - 1) . ' days', $endDayStart);
    if ($windowStart === false) {
        return null;
    }
    $windowStart = max((int) $rangeStart, (int) $windowStart);
    $span = (int) round(($windowEnd + 1 - $windowStart) / 86400);
    if ($span < 1) {
        return null;
    }

    return [
        'start' => $windowStart,
        'end' => $windowEnd,
        'days' => $span,
    ];
}

/**
 * 50% last-7-day run-rate + 25% mean + 25% median, scaled to an average month.
 *
 * @param list<float> $daily
 */
function forecast_monthly_from_daily(array $daily): float
{
    $days = count($daily);
    if ($days < 1) {
        return 0.0;
    }
    $sum = array_sum($daily);
    if ($sum <= 0) {
        return 0.0;
    }

    $monthLength = 365.25 / 12;
    $sorted = $daily;
    sort($sorted, SORT_NUMERIC);
    $mid = intdiv($days, 2);
    $median = ($days % 2 === 1)
        ? (float) $sorted[$mid]
        : ((float) $sorted[$mid - 1] + (float) $sorted[$mid]) / 2.0;

    $runDays = min(7, $days);
    $runrate = (array_sum(array_slice($daily, -$runDays)) / $runDays) * $monthLength;
    $meanMonth = ($sum / $days) * $monthLength;
    $medianMonth = $median * $monthLength;

    return (0.5 * $runrate) + (0.25 * $meanMonth) + (0.25 * $medianMonth);
}

/**
 * Daily sold volume (GB) from paid non-test invoices plus extra-volume purchases.
 *
 * @return list<float>
 */
function bot_daily_sold_volume(PDO $pdo, int $windowStart, int $windowEnd, int $days): array
{
    $daily = array_fill(0, $days, 0.0);
    if ($days < 1 || $windowEnd < $windowStart) {
        return $daily;
    }

    $addRows = static function (array &$daily, array $rows, int $days): void {
        foreach ($rows as $row) {
            $idx = (int) ($row['day_index'] ?? -1);
            if ($idx < 0 || $idx >= $days) {
                continue;
            }
            $daily[$idx] += (float) ($row['volume'] ?? 0);
        }
    };

    $invoiceUnix = unix_column_epoch_sql('time_sell');
    $paidSql = invoice_paid_status_sql('Status');
    try {
        $stmt = $pdo->prepare("SELECT FLOOR(($invoiceUnix - :window_start) / 86400) AS day_index,
                COALESCE(SUM(CAST(Volume AS DECIMAL(20,2))), 0) AS volume
            FROM invoice
            WHERE $paidSql
              AND name_product != 'سرویس تست'
              AND ($invoiceUnix) BETWEEN :start AND :end
            GROUP BY day_index
            HAVING day_index IS NOT NULL AND day_index >= 0 AND day_index < :days");
        $stmt->bindValue(':window_start', $windowStart, PDO::PARAM_INT);
        $stmt->bindValue(':start', $windowStart, PDO::PARAM_INT);
        $stmt->bindValue(':end', $windowEnd, PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $addRows($daily, $stmt->fetchAll(PDO::FETCH_ASSOC), $days);
    } catch (Throwable $e) {
        error_log('bot_daily_sold_volume invoice: ' . $e->getMessage());
    }

    try {
        $extraUnix = unix_column_epoch_sql('so.time');
        $extraGbSql = bot_sql_extra_volume_gb('so.value');
        $stmt = $pdo->prepare("SELECT FLOOR(($extraUnix - :window_start) / 86400) AS day_index,
                COALESCE(SUM($extraGbSql), 0) AS volume
            FROM service_other so
            WHERE so.type = 'extra_user'
              AND COALESCE(so.status,'') NOT IN ('unpaid','Unpaid','reject')
              AND ($extraUnix) BETWEEN :start AND :end
            GROUP BY day_index
            HAVING day_index IS NOT NULL AND day_index >= 0 AND day_index < :days");
        $stmt->bindValue(':window_start', $windowStart, PDO::PARAM_INT);
        $stmt->bindValue(':start', $windowStart, PDO::PARAM_INT);
        $stmt->bindValue(':end', $windowEnd, PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $addRows($daily, $stmt->fetchAll(PDO::FETCH_ASSOC), $days);
    } catch (Throwable $e) {
        error_log('bot_daily_sold_volume extra: ' . $e->getMessage());
    }

    return $daily;
}

function forecast_monthly_sold_volume(PDO $pdo, ?int $startTs = null, ?int $endTs = null): ?float
{
    $window = forecast_monthly_complete_day_window($startTs, $endTs);
    if ($window === null) {
        return null;
    }
    return forecast_monthly_from_daily(
        bot_daily_sold_volume($pdo, $window['start'], $window['end'], $window['days'])
    );
}

function forecast_monthly_paid_income(PDO $pdo): float
{
    $window = forecast_monthly_complete_day_window();
    if ($window === null) {
        return 0.0;
    }
    $windowStart = $window['start'];
    $windowEnd = $window['end'];
    $days = $window['days'];

    $unixExpr = unix_column_epoch_sql('time');
    $sql = "SELECT FLOOR(($unixExpr - :window_start) / 86400) AS day_index,
                   COALESCE(SUM(CAST(price AS DECIMAL(20,0))), 0) AS total
            FROM Payment_report
            WHERE " . paid_real_income_sql() . "
              AND ($unixExpr) BETWEEN :start AND :end
            GROUP BY day_index
            HAVING day_index IS NOT NULL AND day_index >= 0 AND day_index < :days";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':window_start', $windowStart, PDO::PARAM_INT);
    $stmt->bindValue(':start', $windowStart, PDO::PARAM_INT);
    $stmt->bindValue(':end', $windowEnd, PDO::PARAM_INT);
    $stmt->bindValue(':days', $days, PDO::PARAM_INT);
    $stmt->execute();

    $daily = array_fill(0, $days, 0.0);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $idx = (int) $row['day_index'];
        if ($idx < 0 || $idx >= $days) {
            continue;
        }
        $daily[$idx] = (float) $row['total'];
    }

    return forecast_monthly_from_daily($daily);
}

function panel_is_hidden_from_user($panel, $user_id): bool
{
    if (!is_array($panel) || empty($panel['hide_user'])) {
        return false;
    }
    $list = json_decode($panel['hide_user'], true);
    return is_array($list) && in_array($user_id, $list);
}

function panel_manualsale_in_stock($code_panel): bool
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT 1 FROM manualsell WHERE codepanel = :codepanel AND status = 'active' LIMIT 1");
    $stmt->execute([':codepanel' => $code_panel]);
    return (bool) $stmt->fetchColumn();
}

function determineColumnTypeFromValue($value)
{
    if (is_bool($value)) {
        return 'TINYINT(1)';
    }

    if (is_int($value)) {
        return 'INT(11)';
    }

    if (is_float($value)) {
        return 'DOUBLE';
    }

    if ($value === null) {
        return 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    if (is_string($value)) {
        if (function_exists('mb_strlen')) {
            $length = mb_strlen($value, 'UTF-8');
        } else {
            $length = strlen($value);
        }

        if ($length <= 191) {
            return 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        if ($length <= 500) {
            return 'VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
}
function ensureColumnExistsForUpdate($tableName, $fieldName, $valueSample = null)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$tableName, $fieldName]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $datatype = determineColumnTypeFromValue($valueSample);

        $defaultValue = null;
        if (is_bool($valueSample)) {
            $defaultValue = $valueSample ? '1' : '0';
        } elseif (is_scalar($valueSample) && $valueSample !== null) {
            $defaultValue = (string) $valueSample;
        }

        addFieldToTable($tableName, $fieldName, $defaultValue, $datatype);
    } catch (PDOException $e) {
        error_log('Failed to ensure column exists: ' . $e->getMessage());
    }
}
function update($table, $field, $newValue, $whereField = null, $whereValue = null)
{
    global $pdo, $user;

    $valueToStore = normaliseUpdateValue($newValue);

    ensureColumnExistsForUpdate($table, $field, $valueToStore);

    $executeUpdate = function ($value) use ($pdo, $table, $field, $whereField, $whereValue) {
        if ($whereField !== null) {
            $stmt = $pdo->prepare("SELECT $field FROM $table WHERE $whereField = ? FOR UPDATE");
            $stmt->execute([$whereValue]);
            $stmt = $pdo->prepare("UPDATE $table SET $field = ? WHERE $whereField = ?");
            $stmt->execute([$value, $whereValue]);
        } else {
            $stmt = $pdo->prepare("UPDATE $table SET $field = ?");
            $stmt->execute([$value]);
        }
    };

    try {
        $executeUpdate($valueToStore);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Incorrect string value') !== false) {
            $tableConverted = ensureTableUtf8mb4($table);
            if ($tableConverted) {
                try {
                    $executeUpdate($valueToStore);
                } catch (PDOException $retryException) {
                    error_log('Retry after charset conversion failed: ' . $retryException->getMessage());
                    throw $retryException;
                }
            } else {
                $fallbackValue = is_string($valueToStore) ? @iconv('UTF-8', 'UTF-8//IGNORE', $valueToStore) : $valueToStore;
                if ($fallbackValue === false) {
                    $fallbackValue = '';
                }
                $executeUpdate($fallbackValue);
            }
        } else {
            throw $e;
        }
    }

    $date = date("Y-m-d H:i:s");
    if (!isset($user['step'])) {
        $user['step'] = '';
    }
    $logValue = is_scalar($valueToStore) ? $valueToStore : json_encode($valueToStore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $logss = "{$table}_{$field}_{$logValue}_{$whereField}_{$whereValue}_{$user['step']}_$date";
    if ($field !== "message_count" && $field !== "last_message_time") {
        $logFile = __DIR__ . '/logs/update.log';
        $logDir = dirname($logFile);
        if (is_dir($logDir) && is_writable($logDir)) {
            @file_put_contents($logFile, "\n" . $logss, FILE_APPEND | LOCK_EX);
        }
    }

    clearSelectCache($table);
}
function &getSelectCacheStore()
{
    static $store = [
    'results' => [],
    'tableIndex' => [],
    ];

    return $store;
}

function clearSelectCache($table = null)
{
    $store = &getSelectCacheStore();

    if ($table === null) {
        $store['results'] = [];
        $store['tableIndex'] = [];
        return;
    }

    if (!isset($store['tableIndex'][$table])) {
        return;
    }

    foreach (array_keys($store['tableIndex'][$table]) as $cacheKey) {
        unset($store['results'][$cacheKey]);
    }

    unset($store['tableIndex'][$table]);
}

function select($table, $field, $whereField = null, $whereValue = null, $type = "select", $options = [])
{
    global $pdo;

    $useCache = true;
    if (is_array($options) && array_key_exists('cache', $options)) {
        $useCache = (bool) $options['cache'];
    }

    $cacheKey = null;
    if ($useCache) {
        $cacheKey = hash('sha256', json_encode([
            $table,
            $field,
            $whereField,
            $whereValue,
            $type,
        ], JSON_UNESCAPED_UNICODE));

        $store = &getSelectCacheStore();
        if (isset($store['results'][$cacheKey])) {
            return $store['results'][$cacheKey];
        }
    }

    if ($type == "count") {
        $query = "SELECT COUNT(*) FROM $table";
    } else {
        $query = "SELECT $field FROM $table";
    }

    if ($whereField !== null) {
        $query .= " WHERE $whereField = :whereValue";
    }

    try {
        $stmt = $pdo->prepare($query);
        if ($whereField !== null) {
            $stmt->bindParam(':whereValue', $whereValue, PDO::PARAM_STR);
        }

        $stmt->execute();
        if ($type == "count") {
            $result = (int) $stmt->fetchColumn();
        } elseif ($type == "FETCH_COLUMN") {
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($table === 'admin' && $field === 'id_admin') {
                global $adminnumber;
                if (!is_array($results)) {
                    $results = [];
                }

                $results = array_values(array_unique(array_filter($results, function ($value) {
                    return $value !== null && $value !== '';
                })));

                if (empty($results) && isset($adminnumber) && $adminnumber !== '') {
                    $results[] = (string) $adminnumber;
                }
            }
            $result = $results;
        } elseif ($type == "fetchAll") {
            $result = $stmt->fetchAll();
        } else {
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            $result = $fetched === false ? null : $fetched;
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        die("Query failed: " . $e->getMessage());
    }

    if ($useCache && $cacheKey !== null) {
        $store = &getSelectCacheStore();
        $store['results'][$cacheKey] = $result;
        if (!isset($store['tableIndex'][$table])) {
            $store['tableIndex'][$table] = [];
        }
        $store['tableIndex'][$table][$cacheKey] = true;
    }

    return $result;
}

/**
 * True when a row exists. $table / $field are trusted identifiers, not user input.
 */
function rowExists(string $table, string $field, $value, ?string $andField = null, $andValue = null): bool
{
    global $pdo;
    if ($value === null || $value === '') {
        return false;
    }
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $field = preg_replace('/[^A-Za-z0-9_]/', '', $field);
    if ($table === '' || $field === '' || !($pdo instanceof PDO)) {
        return false;
    }
    $sql = "SELECT 1 FROM `$table` WHERE `$field` = :v";
    $params = [':v' => $value];
    if ($andField !== null) {
        $andField = preg_replace('/[^A-Za-z0-9_]/', '', $andField);
        if ($andField === '') {
            return false;
        }
        $sql .= " AND `$andField` = :v2";
        $params[':v2'] = $andValue;
    }
    $sql .= ' LIMIT 1';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('rowExists: ' . $e->getMessage());
        return false;
    }
}

function request_user_is_admin(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    global $from_id;
    if (empty($from_id)) {
        $cached = false;
        return false;
    }
    $row = select('admin', '*', 'id_admin', $from_id, 'select');
    $cached = is_array($row) && in_array((string) ($row['rule'] ?? ''), admin_telegram_staff_rules(), true);
    return $cached;
}

function ensureIndex(string $table, string $indexName, string $columnsSql): void
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return;
    }
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $indexName = preg_replace('/[^A-Za-z0-9_]/', '', $indexName);
    if ($table === '' || $indexName === '') {
        return;
    }
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$db, $table, $indexName]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
        $pdo->exec("ALTER TABLE `$table` ADD INDEX `$indexName` ($columnsSql)");
    } catch (Throwable $e) {
        error_log('ensureIndex: ' . $e->getMessage());
    }
}

function ensure_hot_path_indexes(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $logsDir = __DIR__ . '/logs';
    $marker = $logsDir . '/.hot_path_indexes_v1';
    if (is_file($marker)) {
        return;
    }
    ensureIndex('invoice', 'idx_invoice_id_user_status', '`id_user`(100), `Status`(50)');
    ensureIndex('invoice', 'idx_invoice_username', '`username`(191)');
    ensureIndex('Payment_report', 'idx_payment_id_user_status', '`id_user`(100), `payment_Status`(50)');
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    @file_put_contents($marker, (string) time());
}

function getPaySettingValue($name, $default = null)
{
    $result = select("PaySetting", "ValuePay", "NamePay", $name, "select");
    if (!is_array($result) || !array_key_exists('ValuePay', $result)) {
        return $default;
    }

    return $result['ValuePay'];
}
function generateUUID()
{
    $data = openssl_random_pseudo_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    return $uuid;
}
function rate_arze()
{
    $arze_rate = [];
    $requests_tron = json_decode(file_get_contents('https://api.diadata.org/v1/assetQuotation/Tron/0x0000000000000000000000000000000000000000'), true);
    $html_read = file_get_contents("https://www.bon-bast.com/");
    preg_match('/<span>\s*([\d,]+)\s*<\/span>/', $html_read, $matches);
    if (!empty($matches[1])) {
        $requestsusd = str_replace(',', '', $matches[1]);
    }
    $arze_rate['USD'] = intval($requestsusd);
    $arze_rate['TRX'] = intval($requests_tron['Price'] * $arze_rate['USD']);

    return $arze_rate;
}
function updatePaymentMessageId($response, $orderId)
{
    if (!is_array($response)) {
        error_log("Failed to send payment message for order {$orderId}: unexpected response");
        return false;
    }

    if (empty($response['ok'])) {
        error_log("Failed to send payment message for order {$orderId}: " . json_encode($response));
        return false;
    }

    if (!isset($response['result']['message_id'])) {
        error_log("Missing message_id for order {$orderId}: " . json_encode($response));
        return false;
    }

    update("Payment_report", "message_id", intval($response['result']['message_id']), "id_order", $orderId);
    return true;
}

function telegramSentMessageId($response)
{
    if (!is_array($response) || empty($response['ok']) || !isset($response['result']['message_id'])) {
        return 0;
    }
    return intval($response['result']['message_id']);
}

function paymentReceiptAutoConfirmedKeyboard()
{
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => 'رسید توسط ربات تایید شده', 'callback_data' => 'receipt_bot_confirmed'],
            ],
        ],
    ]);
}

function paymentReceiptAdminConfirmedKeyboard()
{
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => 'رسید توسط ادمین تایید شد', 'callback_data' => 'receipt_admin_confirmed'],
            ],
        ],
    ]);
}

function ensureAdminReceiptMsgsColumn()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    addFieldToTable('Payment_report', 'admin_receipt_msgs', null, 'TEXT');
}

function paymentReportIsPaid($orderId)
{
    $row = select("Payment_report", "payment_Status", "id_order", $orderId, "select");
    return is_array($row) && ($row['payment_Status'] ?? '') === 'paid';
}

function decodeAdminReceiptMessages($raw)
{
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function appendAdminReceiptMessages($orderId, array $messages)
{
    if ($orderId === null || $orderId === '' || $messages === []) {
        return;
    }
    ensureAdminReceiptMsgsColumn();
    $row = select("Payment_report", "admin_receipt_msgs", "id_order", $orderId, "select");
    $existing = [];
    if (is_array($row)) {
        $existing = decodeAdminReceiptMessages($row['admin_receipt_msgs'] ?? '');
    }
    $merged = array_merge($existing, $messages);
    update(
        "Payment_report",
        "admin_receipt_msgs",
        json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        "id_order",
        $orderId
    );
}

function notifyAdminsCardReceipt($orderId, $text, $keyboard, $photoId = null, $photoCaption = null)
{
    $admin_ids = admin_telegram_ids();
    $stored = [];
    foreach ($admin_ids as $id_admin) {
        $adminrulecheck = select("admin", "*", "id_admin", $id_admin, "select");
        if (!is_array($adminrulecheck) || ($adminrulecheck['rule'] ?? '') === 'support') {
            continue;
        }
        if ($photoId) {
            telegram('sendphoto', [
                'chat_id' => $id_admin,
                'photo' => $photoId,
                'caption' => $photoCaption,
                'parse_mode' => "HTML",
            ]);
        }
        $sent = sendmessage($id_admin, $text, $keyboard, 'HTML');
        $mid = telegramSentMessageId($sent);
        if ($mid > 0) {
            $stored[] = [
                'chat_id' => (int) $id_admin,
                'message_id' => $mid,
            ];
        }
    }
    appendAdminReceiptMessages($orderId, $stored);
}

function updateAdminReceiptKeyboards($orderId, $keyboard, $excludeChatId = 0)
{
    if ($orderId === null || $orderId === '' || $keyboard === null || $keyboard === '') {
        return;
    }
    ensureAdminReceiptMsgsColumn();
    $row = select("Payment_report", "admin_receipt_msgs", "id_order", $orderId, "select");
    if (!is_array($row)) {
        return;
    }
    $messages = decodeAdminReceiptMessages($row['admin_receipt_msgs'] ?? '');
    if ($messages === []) {
        return;
    }
    $excludeChatId = intval($excludeChatId);
    foreach ($messages as $item) {
        $chatId = intval($item['chat_id'] ?? 0);
        $messageId = intval($item['message_id'] ?? 0);
        if ($chatId === 0 || $messageId === 0) {
            continue;
        }
        if ($excludeChatId > 0 && $chatId === $excludeChatId) {
            continue;
        }
        if (function_exists('EditMessageReplyMarkup')) {
            EditMessageReplyMarkup($chatId, $messageId, $keyboard);
        } else {
            telegram('editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => $keyboard,
            ]);
        }
    }
}

function markAdminReceiptsAutoConfirmed($orderId)
{
    updateAdminReceiptKeyboards($orderId, paymentReceiptAutoConfirmedKeyboard());
}

function markAdminReceiptsAdminConfirmed($orderId, $excludeChatId = 0)
{
    updateAdminReceiptKeyboards($orderId, paymentReceiptAdminConfirmedKeyboard(), $excludeChatId);
}

function finalizeAdminReceiptAfterSend($orderId)
{
    if (paymentReportIsPaid($orderId)) {
        markAdminReceiptsAutoConfirmed($orderId);
        return true;
    }
    return false;
}

function markPaymentWaitingIfStillOpen($orderId)
{
    global $pdo;
    if ($orderId === null || $orderId === '' || !isset($pdo)) {
        return false;
    }
    $stmt = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'waiting' WHERE id_order = ? AND payment_Status NOT IN ('paid', 'reject', 'expire')");
    $stmt->execute([$orderId]);
    clearSelectCache('Payment_report');
    if (paymentReportIsPaid($orderId)) {
        markAdminReceiptsAutoConfirmed($orderId);
        return false;
    }
    return $stmt->rowCount() > 0;
}

/** Unpaid payment invoices are valid for 24 hours. */
function paymentInvoiceTtlSeconds()
{
    return 86400;
}

function paymentInvoiceExpiredMessage()
{
    return "❗زمان این تراکنش به پایان رسیده و امکان پرداخت این تراکنش وجود ندارد.\nلطفاً مجدداً فاکتور ایجاد نمایید.";
}

/**
 * Parse Payment_report.time (Y/m/d H:i:s) or unix timestamp string into unix time.
 */
function paymentReportCreatedAt($payment)
{
    if (!is_array($payment) || empty($payment['time'])) {
        return 0;
    }
    $raw = trim((string) $payment['time']);
    if ($raw === '') {
        return 0;
    }
    if (ctype_digit($raw)) {
        return (int) $raw;
    }
    $normalized = str_replace('/', '-', $raw);
    $ts = strtotime($normalized);
    return $ts === false ? 0 : (int) $ts;
}

/**
 * Parse invoice.time_sell (usually unix timestamp) into unix time.
 */
function invoiceCreatedAt($invoice)
{
    if (!is_array($invoice) || !isset($invoice['time_sell']) || $invoice['time_sell'] === '' || $invoice['time_sell'] === null) {
        return 0;
    }
    $raw = trim((string) $invoice['time_sell']);
    if (ctype_digit($raw)) {
        return (int) $raw;
    }
    $normalized = str_replace('/', '-', $raw);
    $ts = strtotime($normalized);
    return $ts === false ? 0 : (int) $ts;
}

function isTimestampPastPaymentTtl($createdAt, $now = null)
{
    $createdAt = (int) $createdAt;
    if ($createdAt <= 0) {
        return false;
    }
    $now = $now === null ? time() : (int) $now;
    return ($now - $createdAt) >= paymentInvoiceTtlSeconds();
}

function isPaymentReportExpiredOrPastTtl($payment)
{
    if (!is_array($payment)) {
        return true;
    }
    $status = (string) ($payment['payment_Status'] ?? '');
    if ($status === 'expire') {
        return true;
    }
    if ($status === 'Unpaid' && isTimestampPastPaymentTtl(paymentReportCreatedAt($payment))) {
        return true;
    }
    return false;
}

function isUnpaidInvoicePastTtl($invoice)
{
    if (!is_array($invoice)) {
        return false;
    }
    $status = strtolower((string) ($invoice['Status'] ?? ''));
    if ($status !== 'unpaid') {
        return false;
    }
    return isTimestampPastPaymentTtl(invoiceCreatedAt($invoice));
}

/**
 * If an unpaid Payment_report is past TTL, mark it expire (and linked unpaid invoice).
 * Returns true when the payment may still be completed.
 */
function ensurePaymentReportActive($payment)
{
    if (!is_array($payment) || empty($payment['id_order'])) {
        return false;
    }
    $status = (string) ($payment['payment_Status'] ?? '');
    if (in_array($status, ['paid', 'waiting', 'reject'], true)) {
        return $status !== 'reject';
    }
    if ($status === 'expire') {
        return false;
    }
    if ($status === 'Unpaid' && isTimestampPastPaymentTtl(paymentReportCreatedAt($payment))) {
        expirePaymentReportRow($payment);
        return false;
    }
    return true;
}

function expirePaymentReportLinkedInvoice($payment)
{
    if (!is_array($payment) || empty($payment['id_invoice'])) {
        return;
    }
    $parts = explode('|', (string) $payment['id_invoice']);
    if (($parts[0] ?? '') !== 'getconfigafterpay' || ($parts[1] ?? '') === '') {
        return;
    }
    $invoice = select('invoice', '*', 'username', $parts[1], 'select');
    if ($invoice && strtolower((string) ($invoice['Status'] ?? '')) === 'unpaid') {
        update('invoice', 'Status', 'expire', 'username', $parts[1]);
    }
}

function expirePaymentReportRow($payment)
{
    if (!is_array($payment) || empty($payment['id_order'])) {
        return;
    }
    if (($payment['payment_Status'] ?? '') === 'expire') {
        return;
    }
    update('Payment_report', 'payment_Status', 'expire', 'id_order', $payment['id_order']);
    expirePaymentReportLinkedInvoice($payment);
    if (!empty($payment['message_id']) && !empty($payment['id_user'])) {
        deletemessage($payment['id_user'], $payment['message_id']);
    }
}

/**
 * Expire unpaid Payment_report and unpaid invoice rows older than 24 hours.
 * @return array{payments:int,invoices:int}
 */
function expireStalePaymentInvoices()
{
    global $pdo;
    $expiredPayments = 0;
    $expiredInvoices = 0;
    $cutoffUnix = time() - paymentInvoiceTtlSeconds();
    $cutoffDate = date('Y/m/d H:i:s', $cutoffUnix);

    $stmt = $pdo->prepare("SELECT * FROM Payment_report WHERE payment_Status = 'Unpaid' AND time IS NOT NULL AND time != '' AND time < :cutoff");
    $stmt->bindValue(':cutoff', $cutoffDate, PDO::PARAM_STR);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Double-check with parsed timestamp (handles mixed formats).
        if (!isTimestampPastPaymentTtl(paymentReportCreatedAt($row), time())) {
            continue;
        }
        expirePaymentReportRow($row);
        $expiredPayments++;
    }

    $stmtInv = $pdo->prepare("SELECT * FROM invoice WHERE Status IN ('unpaid', 'Unpaid') AND time_sell IS NOT NULL AND time_sell != ''");
    $stmtInv->execute();
    while ($invoice = $stmtInv->fetch(PDO::FETCH_ASSOC)) {
        if (!isUnpaidInvoicePastTtl($invoice)) {
            continue;
        }
        update('invoice', 'Status', 'expire', 'id_invoice', $invoice['id_invoice']);
        $expiredInvoices++;
    }

    return ['payments' => $expiredPayments, 'invoices' => $expiredInvoices];
}
function nowPayments($payment, $price_amount, $order_id, $order_description)
{
    global $domainhosts;
    $apinowpayments = select("PaySetting", "*", "NamePay", "marchent_tronseller", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.nowpayments.io/v1/' . $payment,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 7000,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => 1,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apinowpayments,
            'Content-Type: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'price_amount' => $price_amount,
        'price_currency' => 'usd',
        'order_id' => $order_id,
        'order_description' => $order_description,
        'ipn_callback_url' => "https://" . $domainhosts . "/payment/nowpayment.php"
    ]));

    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function StatusPayment($paymentid)
{
    $apinowpayments = select("PaySetting", "*", "NamePay", "marchent_tronseller", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.nowpayments.io/v1/payment/' . $paymentid,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apinowpayments
        ),
    ));
    $response = curl_exec($curl);
    $response = json_decode($response, true);
    curl_close($curl);
    return $response;
}
function normalize_forced_join_channel_id($input): string
{
    $input = trim((string) $input);
    if ($input === '') {
        return '';
    }
    $input = str_replace(['telegram.me/', 'telegram.dog/'], 't.me/', $input);
    if (preg_match('/^(?:https?:\/\/)?(?:t\.me|telegram\.me)\/(?:s\/)?([A-Za-z0-9_]+)\/?$/i', $input, $m)) {
        return '@' . $m[1];
    }
    if (preg_match('/^-100\d{5,}$/', $input)) {
        return $input;
    }
    if (preg_match('/^@?[A-Za-z][A-Za-z0-9_]{3,31}$/', $input)) {
        return '@' . ltrim($input, '@');
    }
    return '';
}

function normalize_forced_join_url($url, $channel_id = ''): string
{
    $url = trim((string) $url);
    if ($url === '') {
        if (preg_match('/^@([A-Za-z0-9_]+)$/', (string) $channel_id, $m)) {
            return 'https://t.me/' . $m[1];
        }
        return '';
    }
    if (preg_match('/^(?:t\.me|telegram\.me)\//i', $url)) {
        $url = 'https://' . $url;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!in_array($host, ['t.me', 'telegram.me', 'telegram.dog', 'www.t.me'], true)) {
        return '';
    }
    return $url;
}

function forced_join_user_is_bypassed($user): bool
{
    return is_array($user) && (($user['joinchannel'] ?? '') === 'bypass');
}

function forced_join_member_statuses(): array
{
    return ['creator', 'administrator', 'member', 'restricted'];
}

function forced_join_ensure_telegram(): bool
{
    if (function_exists('telegram')) {
        return true;
    }
    $path = __DIR__ . '/botapi.php';
    if (!is_file($path)) {
        return false;
    }
    if (!isset($GLOBALS['_mirza_telegram_update']) || !is_array($GLOBALS['_mirza_telegram_update'])) {
        $GLOBALS['_mirza_telegram_update'] = [];
    }
    require_once $path;
    return function_exists('telegram');
}

function verify_bot_is_channel_admin($channel_id): array
{
    if (!forced_join_ensure_telegram()) {
        return ['ok' => true, 'skipped' => true];
    }
    global $APIKEY;
    $channel_id = trim((string) $channel_id);
    if ($channel_id === '') {
        return ['ok' => false, 'error' => 'empty'];
    }
    $chat = telegram('getChat', ['chat_id' => $channel_id], null, 8);
    if (!is_array($chat) || empty($chat['ok'])) {
        $desc = strtolower((string) ($chat['description'] ?? ''));
        if ($desc === '' || strpos($desc, 'timed out') !== false || strpos($desc, 'curl') !== false) {
            return ['ok' => true, 'skipped' => true, 'error' => 'network'];
        }
        return ['ok' => false, 'error' => 'not_found'];
    }
    $bot_id = explode(':', (string) $APIKEY)[0];
    $member = telegram('getChatMember', ['chat_id' => $channel_id, 'user_id' => $bot_id], null, 8);
    $status = (string) ($member['result']['status'] ?? '');
    if (!is_array($member) || empty($member['ok']) || !in_array($status, ['administrator', 'creator'], true)) {
        return ['ok' => false, 'error' => 'not_admin'];
    }
    return [
        'ok' => true,
        'title' => (string) ($chat['result']['title'] ?? $channel_id),
        'channel_id' => (string) ($chat['result']['id'] ?? $channel_id),
    ];
}

function save_forced_join_channel($link, $remark, $linkjoin): array
{
    global $pdo;
    $link = normalize_forced_join_channel_id($link);
    $remark = trim((string) $remark);
    $linkjoin = normalize_forced_join_url($linkjoin, $link);
    if ($link === '') {
        return ['ok' => false, 'msg' => 'یوزرنیم یا آیدی کانال نامعتبر است. از @channel یا آیدی عددی که با ‎-100 شروع می‌شود استفاده کنید.'];
    }
    if ($remark === '') {
        return ['ok' => false, 'msg' => 'نام دکمه عضویت را وارد کنید.'];
    }
    if (mb_strlen($remark) > 64) {
        return ['ok' => false, 'msg' => 'نام دکمه عضویت حداکثر ۶۴ کاراکتر باشد.'];
    }
    if ($linkjoin === '') {
        return ['ok' => false, 'msg' => 'لینک عضویت معتبر نیست. لینک t.me کانال یا دعوت را وارد کنید.'];
    }
    $exists = select('channels', '*', 'link', $link, 'select');
    if (is_array($exists) && !empty($exists['link'])) {
        return ['ok' => false, 'msg' => 'این کانال از قبل ثبت شده است.'];
    }
    $verify = verify_bot_is_channel_admin($link);
    if (empty($verify['ok'])) {
        if (($verify['error'] ?? '') === 'not_admin') {
            return ['ok' => false, 'msg' => 'ربات ادمین این کانال نیست. ابتدا ربات را ادمین کانال کنید، سپس دوباره ثبت کنید.'];
        }
        if (($verify['error'] ?? '') === 'not_found') {
            return ['ok' => false, 'msg' => 'کانال یافت نشد. یوزرنیم/آیدی را بررسی کنید و مطمئن شوید ربات عضو کانال است.'];
        }
        return ['ok' => false, 'msg' => 'ثبت کانال ناموفق بود.'];
    }

    $insertChannel = function ($remarkValue) use ($pdo, $link, $linkjoin) {
        $stmt = $pdo->prepare('INSERT INTO channels (link, remark, linkjoin) VALUES (:link, :remark, :linkjoin)');
        $stmt->bindValue(':remark', $remarkValue, PDO::PARAM_STR);
        $stmt->bindValue(':link', $link, PDO::PARAM_STR);
        $stmt->bindValue(':linkjoin', $linkjoin, PDO::PARAM_STR);
        $stmt->execute();
    };

    try {
        $insertChannel($remark);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Incorrect string value') !== false) {
            if (function_exists('ensureTableUtf8mb4')) {
                ensureTableUtf8mb4('channels');
            }
            try {
                $insertChannel($remark);
            } catch (PDOException $retryException) {
                if (strpos($retryException->getMessage(), 'Incorrect string value') === false) {
                    throw $retryException;
                }
                $sanitisedRemark = is_string($remark) ? @iconv('UTF-8', 'UTF-8//IGNORE', $remark) : '';
                if ($sanitisedRemark === false || $sanitisedRemark === '') {
                    return ['ok' => false, 'msg' => 'نام دکمه قابل ذخیره نیست. متن ساده‌تری وارد کنید.'];
                }
                $insertChannel($sanitisedRemark);
            }
        } else {
            throw $e;
        }
    }
    if (function_exists('clearSelectCache')) {
        clearSelectCache('channels');
    }
    $warning = !empty($verify['skipped']) ? ' کانال ذخیره شد، اما وضعیت ادمین بودن ربات الان قابل بررسی نبود. مطمئن شوید ربات ادمین کانال است.' : '';
    return [
        'ok' => true,
        'msg' => 'کانال جوین اجباری با موفقیت ثبت شد.' . $warning,
        'link' => $link,
        'linkjoin' => $linkjoin,
        'title' => $verify['title'] ?? '',
    ];
}

function delete_forced_join_channel($link): array
{
    global $pdo;
    $link = trim((string) $link);
    if ($link === '') {
        return ['ok' => false, 'msg' => 'کانال مشخص نشده است.'];
    }
    $stmt = $pdo->prepare('DELETE FROM channels WHERE link = :link');
    $stmt->bindParam(':link', $link, PDO::PARAM_STR);
    $stmt->execute();
    if (function_exists('clearSelectCache')) {
        clearSelectCache('channels');
    }
    if ($stmt->rowCount() === 0) {
        $normalized = normalize_forced_join_channel_id($link);
        if ($normalized !== '' && $normalized !== $link) {
            $stmt = $pdo->prepare('DELETE FROM channels WHERE link = :link');
            $stmt->bindParam(':link', $normalized, PDO::PARAM_STR);
            $stmt->execute();
        }
    }
    return ['ok' => true, 'msg' => 'کانال با موفقیت حذف شد.'];
}

function build_forced_join_keyboard(array $missing_channels, $textbotlang): string
{
    $keyboard = ['inline_keyboard' => []];
    foreach ($missing_channels as $channel) {
        $row = select('channels', '*', 'link', $channel, 'select');
        if (!is_array($row)) {
            continue;
        }
        $remark = trim((string) ($row['remark'] ?? ''));
        $url = normalize_forced_join_url((string) ($row['linkjoin'] ?? ''), (string) ($row['link'] ?? ''));
        if ($url === '') {
            continue;
        }
        if ($remark === '') {
            $remark = $textbotlang['users']['channel']['text_join'] ?? 'عضویت در کانال';
        }
        $keyboard['inline_keyboard'][] = [[
            'text' => $remark,
            'url' => $url,
        ]];
    }
    $keyboard['inline_keyboard'][] = [[
        'text' => $textbotlang['users']['channel']['confirmjoin'] ?? 'بررسی عضویت',
        'callback_data' => 'confirmchannel',
    ]];
    return json_encode($keyboard);
}

function channel(array $id_channel, $user_id = null)
{
    global $from_id;
    $user_id = $user_id ?? $from_id;
    $missing = [];
    $allowed = forced_join_member_statuses();
    foreach ($id_channel as $channel) {
        $channel = trim((string) $channel);
        if ($channel === '') {
            continue;
        }
        $response = telegram('getChatMember', [
            'chat_id' => $channel,
            'user_id' => $user_id,
        ], null, 3);
        if (!is_array($response) || empty($response['ok'])) {
            $desc = strtolower((string) ($response['description'] ?? ''));
            if (
                strpos($desc, 'user not found') !== false
                || strpos($desc, 'participant_id_invalid') !== false
                || strpos($desc, 'user_id_invalid') !== false
            ) {
                $missing[] = $channel;
            }
            continue;
        }
        $status = (string) ($response['result']['status'] ?? '');
        if (!in_array($status, $allowed, true)) {
            $missing[] = $channel;
        }
    }
    return $missing;
}

function forced_join_member_cache_ttl(): int
{
    return 300;
}

function forced_join_ensure_check_column(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    global $pdo;
    if (!($pdo instanceof PDO)) {
        $ok = false;
        return false;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `user` LIKE 'join_check_at'");
        if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ok = true;
            return true;
        }
        $pdo->exec("ALTER TABLE `user` ADD `join_check_at` VARCHAR(20) NULL DEFAULT '0'");
        $ok = true;
        return true;
    } catch (Throwable $e) {
        $ok = false;
        return false;
    }
}

function forced_join_should_skip_telegram(array $user, bool $force_refresh = false): bool
{
    if ($force_refresh) {
        return false;
    }
    if (($user['joinchannel'] ?? '') !== 'active') {
        return false;
    }
    if (!array_key_exists('join_check_at', $user)) {
        return false;
    }
    $checked_at = (int) $user['join_check_at'];
    if ($checked_at <= 0) {
        return false;
    }
    $age = time() - $checked_at;
    return $age >= 0 && $age < forced_join_member_cache_ttl();
}

function forced_join_remember_check(array &$user, $user_id, bool $joined): void
{
    $now = (string) time();
    $fields = [];
    if ($joined) {
        if (($user['joinchannel'] ?? '') !== 'active') {
            $fields['joinchannel'] = 'active';
            $user['joinchannel'] = 'active';
        }
    } elseif (($user['joinchannel'] ?? '') === 'active') {
        $fields['joinchannel'] = '0';
        $user['joinchannel'] = '0';
    }
    if (array_key_exists('join_check_at', $user) || forced_join_ensure_check_column()) {
        $fields['join_check_at'] = $now;
        $user['join_check_at'] = $now;
    }
    if ($fields === []) {
        return;
    }
    try {
        userUpdate($user_id, $fields);
    } catch (Throwable $e) {
        error_log('forced_join_remember_check: ' . $e->getMessage());
    }
}

function forced_join_missing_channels(array $channel_ids, array &$user, $user_id, bool $force_refresh = false): array
{
    if ($channel_ids === []) {
        return [];
    }
    if (forced_join_should_skip_telegram($user, $force_refresh)) {
        return [];
    }
    $missing = channel($channel_ids, $user_id);
    forced_join_remember_check($user, $user_id, count($missing) === 0);
    return $missing;
}
function isValidDate($date)
{
    if (isValidJalaliDate($date)) {
        return true;
    }
    return (strtotime($date) != false);
}
function trnado($order_id, $price)
{
    global $domainhosts;
    $apitronseller = select("PaySetting", "*", "NamePay", "apiternado", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];
    $urlpay = select("PaySetting", "*", "NamePay", "urlpaymenttron", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);
    $data = array(
        "PaymentID" => $order_id,
        "WalletAddress" => $walletaddress,
        "TronAmount" => $price,
        "CallbackUrl" => "https://" . $domainhosts . "/payment/tronado.php"
    );
    $datasend = json_encode($data);
    curl_setopt_array($curl, array(
        CURLOPT_URL => "$urlpay",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apitronseller,
            'Content-Type: application/json',
            'Cookie: ASP.NET_SessionId=spou2s5lo4nnxkjtavscrrlo'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, $datasend);

    $response = curl_exec($curl);

    curl_close($curl);
    return json_decode($response, true);
}
function formatBytes($bytes, $precision = 2): string
{
    $base = log($bytes, 1024);
    $power = $bytes > 0 ? floor($base) : 0;
    $suffixes = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'];
    return round(pow(1024, $base - $power), $precision) . ' ' . $suffixes[$power];
}
function panel_method_uses_namecustom($method): bool
{
    return in_array((string) $method, [
        'نام کاربری + عدد به ترتیب',
        'متن دلخواه + عدد رندوم',
        'متن دلخواه + عدد ترتیبی',
        'متن دلخواه نماینده + عدد ترتیبی',
    ], true);
}

function panel_username_prefix_valid($prefix): bool
{
    return (bool) preg_match('/^[@A-Za-z0-9._-]{3,32}$/', (string) $prefix);
}

/**
 * Username prefix for a panel. Test accounts can use a separate prefix;
 * empty / "none" falls back to the normal-product prefix.
 */
function panel_username_prefix($panel, bool $isTest = false): string
{
    if (!is_array($panel)) {
        return 'vpn';
    }
    $normal = trim((string) ($panel['namecustom'] ?? ''));
    if ($normal === '' || strcasecmp($normal, 'none') === 0) {
        $normal = 'vpn';
    }
    if (!$isTest) {
        return $normal;
    }
    $test = trim((string) ($panel['namecustom_test'] ?? ''));
    if ($test === '' || strcasecmp($test, 'none') === 0) {
        return $normal;
    }
    return $test;
}

function generateUsername($from_id, $Metode, $username, $randomString, $text, $namecustome, $usernamecustom)
{
    $setting = select("setting", "*", null, null, "select");
    $user = select("user", "*", "id", $from_id, "select");
    if ($user == false) {
        $user = array();
        $user = array(
            'number_username' => '',
        );
    }
    if ($Metode == "آیدی عددی + حروف و عدد رندوم") {
        return $from_id . "_" . $randomString;
    } elseif ($Metode == "نام کاربری + عدد به ترتیب") {
        if ($username == "NOT_USERNAME") {
            if (preg_match('/^\w{3,32}$/', $namecustome)) {
                $username = $namecustome;
            }
        }
        return $username . "_" . $user['number_username'];
    } elseif ($Metode == "نام کاربری دلخواه")
        return $text;
    elseif ($Metode == "نام کاربری دلخواه + عدد رندوم") {
        $random_number = rand(1000000, 9999999);
        return $text . "_" . $random_number;
    } elseif ($Metode == "متن دلخواه + عدد رندوم") {
        return $namecustome . "_" . $randomString;
    } elseif ($Metode == "متن دلخواه + عدد ترتیبی") {
        return $namecustome . "_" . $setting['numbercount'];
    } elseif ($Metode == "آیدی عددی+عدد ترتیبی") {
        return $from_id . "_" . $user['number_username'];
    } elseif ($Metode == "متن دلخواه نماینده + عدد ترتیبی") {
        if ($usernamecustom == "none") {
            return $namecustome . "_" . $setting['numbercount'];
        }
        return $usernamecustom . "_" . $user['number_username'];
    }
}

function panel_method_asks_custom_username($method): bool
{
    return in_array((string) $method, [
        'نام کاربری دلخواه',
        'نام کاربری دلخواه + عدد رندوم',
    ], true);
}

function panel_method_uses_sequential_username($method): bool
{
    return in_array((string) $method, [
        'متن دلخواه + عدد ترتیبی',
        'نام کاربری + عدد به ترتیب',
        'آیدی عددی+عدد ترتیبی',
        'متن دلخواه نماینده + عدد ترتیبی',
    ], true);
}

function bump_username_sequence_counters($panel, $userId): void
{
    if (!is_array($panel) || !panel_method_uses_sequential_username($panel['MethodUsername'] ?? '')) {
        return;
    }
    $user = select("user", "*", "id", $userId, "select");
    if (!is_array($user)) {
        return;
    }
    update("user", "number_username", intval($user['number_username']) + 1, "id", $userId);
    $method = (string) ($panel['MethodUsername'] ?? '');
    if ($method === 'متن دلخواه + عدد ترتیبی' || $method === 'متن دلخواه نماینده + عدد ترتیبی') {
        $setting = select("setting", "*", null, null, "select");
        update("setting", "numbercount", intval($setting['numbercount'] ?? 0) + 1);
    }
}

/**
 * Build a VPN username from the panel MethodUsername setting.
 *
 * @return array{ok:bool,username:string,msg:string}
 */
function allocate_service_username($panel, $user, string $customText = '', $ManagePanel = null): array
{
    if (!is_array($panel) || !is_array($user)) {
        return ['ok' => false, 'username' => '', 'msg' => 'پنل یا کاربر نامعتبر است.'];
    }
    $method = (string) ($panel['MethodUsername'] ?? '');
    $customText = trim($customText);
    if (panel_method_asks_custom_username($method)) {
        if ($customText === '' || !preg_match('/^\w{3,32}$/', strtolower($customText))) {
            return ['ok' => false, 'username' => '', 'msg' => 'نام کاربری باید ۳ تا ۳۲ کاراکتر و فقط حروف، عدد و _ باشد.'];
        }
    }

    $userId = $user['id'] ?? '';
    $tgUsername = (string) ($user['username'] ?? '');
    $namecustomUser = (string) ($user['namecustom'] ?? 'none');
    $prefix = panel_username_prefix($panel);
    $panelName = (string) ($panel['name_panel'] ?? '');

    for ($i = 0; $i < 8; $i++) {
        $randomString = bin2hex(random_bytes(2));
        $username = generateUsername(
            $userId,
            $method,
            $tgUsername,
            $randomString,
            $customText,
            $prefix,
            $namecustomUser
        );
        $username = strtolower(trim((string) $username));
        if ($username === '') {
            $username = strtolower($prefix . '_' . $randomString);
        }
        $taken = rowExists('invoice', 'username', $username);
        if (!$taken && is_object($ManagePanel) && $panelName !== '') {
            $existing = $ManagePanel->DataUser($panelName, $username);
            if (($existing['status'] ?? '') !== 'Unsuccessful' && !empty($existing['username'])) {
                $taken = true;
            }
        }
        if (!$taken) {
            return ['ok' => true, 'username' => $username, 'msg' => ''];
        }
        if (panel_method_asks_custom_username($method)) {
            return ['ok' => false, 'username' => '', 'msg' => 'این نام کاربری از قبل ثبت شده است.'];
        }
        $username = strtolower(rand(1000000, 9999999) . '_' . $username);
        if (strlen($username) > 32) {
            $username = substr($username, 0, 32);
        }
        $takenRetry = rowExists('invoice', 'username', $username);
        if (!$takenRetry) {
            return ['ok' => true, 'username' => $username, 'msg' => ''];
        }
    }

    return ['ok' => false, 'username' => '', 'msg' => 'ساخت نام کاربری طبق تنظیمات پنل ناموفق بود. دوباره تلاش کنید.'];
}

function admin_custom_service_product_token(): string
{
    return '__customvolume__';
}

function is_custom_service_product_choice($panel, string $productName): bool
{
    $productName = trim($productName);
    if ($productName === '' || $productName === 'customvolume' || $productName === admin_custom_service_product_token()) {
        return $productName !== '';
    }
    if (preg_match('/^customvolume_\d+_\d+$/', $productName)) {
        return true;
    }
    $label = is_array($panel) ? panel_custom_button_text($panel) : '';
    return $productName === $label
        || $productName === '⚙️ سرویس دلخواه'
        || $productName === '🛍 حجم دلخواه';
}

/**
 * Create a catalog or custom service for a user from admin (web panel / Telegram).
 *
 * @param array{panel:string,product?:string,username?:string,gb?:int,months?:int,custom?:bool,record_payment?:bool} $opts
 * @return array{ok:bool,msg:string,username?:string}
 */
function admin_provision_user_service($userId, array $opts): array
{
    global $pdo, $ManagePanel, $textbotlang, $datatextbot;

    $userId = (string) $userId;
    $panelName = trim((string) ($opts['panel'] ?? ''));
    $productName = trim((string) ($opts['product'] ?? ''));
    $customText = trim((string) ($opts['username'] ?? ''));
    $gb = (int) ($opts['gb'] ?? 0);
    $months = (int) ($opts['months'] ?? 0);

    $user = select("user", "*", "id", $userId, "select");
    if (!is_array($user)) {
        return ['ok' => false, 'msg' => 'کاربر یافت نشد.'];
    }
    $panel = select("marzban_panel", "*", "name_panel", $panelName, "select");
    if (!is_array($panel)) {
        return ['ok' => false, 'msg' => 'پنل یافت نشد.'];
    }

    if (!class_exists('ManagePanel', false)) {
        require_once __DIR__ . '/panels.php';
    }
    if (!isset($ManagePanel) || !is_object($ManagePanel)) {
        $ManagePanel = new ManagePanel();
    }

    if (preg_match('/^customvolume_(\d+)_(\d+)$/', $productName, $m)) {
        $daysFromToken = (int) $m[1];
        $gb = (int) $m[2];
        $months = (int) round($daysFromToken / 30);
        $productName = admin_custom_service_product_token();
    }

    $isCustom = !empty($opts['custom']) || is_custom_service_product_choice($panel, $productName);
    if ($isCustom) {
        if (($panel['type'] ?? '') === 'Manualsale') {
            return ['ok' => false, 'msg' => 'سرویس دلخواه برای فروش دستی در دسترس نیست.'];
        }
        $minVol = (int) panel_agent_field($panel, 'mainvolume', (string) ($user['agent'] ?? 'f'), '1');
        $maxVol = (int) panel_agent_field($panel, 'maxvolume', (string) ($user['agent'] ?? 'f'), '1000');
        if ($gb < $minVol || $gb > $maxVol) {
            return ['ok' => false, 'msg' => "حجم باید بین {$minVol} تا {$maxVol} گیگابایت باشد."];
        }
        if (!panel_custom_month_option($panel, $months)) {
            return ['ok' => false, 'msg' => 'مدت انتخاب‌شده نامعتبر است.'];
        }
        $days = panel_custom_months_to_days($months);
        $price = panel_custom_service_price_for_user($panel, $user, $gb, $days);
        if ($price === null) {
            return ['ok' => false, 'msg' => 'قیمت سرویس دلخواه قابل محاسبه نیست.'];
        }
        $info_product = [
            'Volume_constraint' => $gb,
            'name_product' => panel_custom_button_text($panel),
            'code_product' => 'customvolume',
            'Service_time' => $days,
            'price_product' => $price,
        ];
    } else {
        if ($productName === '') {
            return ['ok' => false, 'msg' => 'محصول را انتخاب کنید.'];
        }
        $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = ? AND (Location = ? OR Location = '/all') LIMIT 1");
        $stmt->execute([$productName, $panelName]);
        $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$info_product) {
            return ['ok' => false, 'msg' => 'محصول انتخاب‌شده برای این پنل یافت نشد.'];
        }
    }

    $alloc = allocate_service_username($panel, $user, $customText, $ManagePanel);
    if (!$alloc['ok']) {
        return $alloc;
    }
    $username = $alloc['username'];

    $DataUserOut = $ManagePanel->DataUser($panelName, $username);
    $reused = (($DataUserOut['status'] ?? '') !== 'Unsuccessful' && !empty($DataUserOut['username']));
    if (!$reused) {
        $serviceTime = (int) ($info_product['Service_time'] ?? 0);
        $datetimestep = $serviceTime === 0 ? 0 : strtotime('+' . $serviceTime . ' days');
        $datac = [
            'expire' => $datetimestep,
            'data_limit' => (int) $info_product['Volume_constraint'] * pow(1024, 3),
            'from_id' => $userId,
            'username' => (string) ($user['username'] ?? ''),
            'type' => 'buy',
        ];
        $DataUserOut = $ManagePanel->createUser($panelName, $info_product['code_product'], $username, $datac);
        if (empty($DataUserOut['username'])) {
            $err = is_string($DataUserOut['msg'] ?? null) ? $DataUserOut['msg'] : json_encode($DataUserOut['msg'] ?? 'unknown');
            return ['ok' => false, 'msg' => 'خطا در ساخت سرویس روی پنل: ' . $err];
        }
    } else {
        $DataUserOut['configs'] = $DataUserOut['configs'] ?? ($DataUserOut['links'] ?? []);
    }

    $idInvoice = bin2hex(random_bytes(4));
    $notifctions = json_encode(['volume' => false, 'time' => false]);
    $stmt = $pdo->prepare('INSERT INTO invoice (id_user, id_invoice, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status, notifctions) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $userId,
        $idInvoice,
        $username,
        time(),
        $panelName,
        $info_product['name_product'],
        $info_product['price_product'],
        $info_product['Volume_constraint'],
        $info_product['Service_time'],
        'active',
        $notifctions,
    ]);

    $recordPayment = !array_key_exists('record_payment', $opts) || !empty($opts['record_payment']);
    if ($recordPayment) {
        record_admin_order_payment(
            $pdo,
            $userId,
            $info_product['price_product'] ?? 0,
            $username,
            $idInvoice,
            (string) ($info_product['name_product'] ?? '')
        );
    }

    bump_username_sequence_counters($panel, $userId);

    if (!is_array($datatextbot) || empty($datatextbot['textafterpay'])) {
        $datatextbot = $pdo->query('SELECT id_text, text FROM textbot')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }

    $output_config_link = ($panel['sublink'] ?? '') === 'onsublink' ? ($DataUserOut['subscription_url'] ?? '') : '';
    $config = '';
    if (($panel['config'] ?? '') === 'onconfig' && is_array($DataUserOut['configs'] ?? null)) {
        foreach ($DataUserOut['configs'] as $link) {
            $config .= "\n" . $link;
        }
    }

    $textTemplate = $datatextbot['textafterpay'] ?? '✅ سرویس {name_service} برای {username} ایجاد شد.';
    if (($panel['type'] ?? '') === 'Manualsale') {
        $textTemplate = $datatextbot['textmanual'] ?? $textTemplate;
    } elseif (in_array($panel['type'] ?? '', ['ibsng', 'mikrotik'], true)) {
        $textTemplate = $datatextbot['textafterpayibsng'] ?? $textTemplate;
    } elseif (($panel['type'] ?? '') === 'WGDashboard') {
        $textTemplate = $datatextbot['text_wgdashboard'] ?? $textTemplate;
    }

    $dayLabel = (int) ($info_product['Service_time'] ?? 0) === 0
        ? ($textbotlang['users']['stateus']['Unlimited'] ?? 'نامحدود')
        : $info_product['Service_time'];
    $volumeLabel = (int) ($info_product['Volume_constraint'] ?? 0) === 0
        ? ($textbotlang['users']['stateus']['Unlimited'] ?? 'نامحدود')
        : $info_product['Volume_constraint'];

    $createdUsername = (string) ($DataUserOut['username'] ?? $username);
    $textcreatuser = str_replace(
        ['{username}', '{name_service}', '{location}', '{day}', '{volume}', '{config}', '{links}', '{links2}'],
        [
            '<code>' . $createdUsername . '</code>',
            $info_product['name_product'],
            $panelName,
            $dayLabel,
            $volumeLabel,
            '<code>' . $output_config_link . '</code>',
            $config,
            $output_config_link,
        ],
        $textTemplate
    );
    if ((int) ($info_product['Volume_constraint'] ?? 0) === 0) {
        $textcreatuser = str_replace('گیگابایت', '', $textcreatuser);
    }
    if (in_array($panel['type'] ?? '', ['Manualsale', 'ibsng', 'mikrotik'], true)) {
        $textcreatuser = str_replace('{password}', $DataUserOut['subscription_url'] ?? '', $textcreatuser);
        update("invoice", "user_info", $DataUserOut['subscription_url'] ?? '', "id_invoice", $idInvoice);
    }

    if (function_exists('sendMessageService')) {
        $Shoppinginfo = json_encode([
            'inline_keyboard' => [[['text' => $textbotlang['users']['help']['btninlinebuy'] ?? 'راهنما', 'callback_data' => 'helpbtn']]],
        ]);
        sendMessageService(
            $panel,
            $DataUserOut['configs'] ?? [],
            $output_config_link,
            $createdUsername,
            $Shoppinginfo,
            $textcreatuser,
            $idInvoice,
            $userId
        );
    }

    return ['ok' => true, 'msg' => 'سرویس «' . $createdUsername . '» با موفقیت برای کاربر ایجاد شد.', 'username' => $createdUsername];
}
function outputlink($text)
{
    $ch = curl_init();
    curl_disable_proxy($ch);
    curl_setopt($ch, CURLOPT_URL, $text);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 6000);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        return null;
    } else {
        return $response;
    }

    curl_close($ch);
}
function DirectPayment($order_id, $image = 'images.jpg')
{
    global $pdo, $ManagePanel, $textbotlang, $keyboardextendfnished, $keyboard, $Confirm_pay, $from_id, $message_id, $datatextbot;
    $buyreport = select("topicid", "idreport", "report", "buyreport", "select")['idreport'] ?? null;
    $admin_ids = admin_telegram_ids();
    $otherservice = select("topicid", "idreport", "report", "otherservice", "select")['idreport'] ?? null;
    $otherreport = select("topicid", "idreport", "report", "otherreport", "select")['idreport'] ?? null;
    $errorreport = select("topicid", "idreport", "report", "errorreport", "select")['idreport'] ?? null;
    $porsantreport = select("topicid", "idreport", "report", "porsantreport", "select")['idreport'] ?? null;
    $setting = select("setting", "*");
    if (!is_array($datatextbot)) {
        $datatextbot = [];
    }
    // Payment callbacks often include keyboard.php first, which may omit delivery texts.
    // Always ensure post-purchase templates are present so users get sub link + product data, not only QR.
    if (empty($datatextbot['textafterpay']) || empty($datatextbot['textmanual']) || empty($datatextbot['text_wgdashboard']) || empty($datatextbot['textafterpayibsng'])) {
        $textbotRows = $pdo->query("SELECT id_text, text FROM textbot")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $datatextbot = array_merge($datatextbot, $textbotRows);
    }
    $Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
    if ($Payment_report == false || !is_array($Payment_report)) {
        return;
    }
    if (!ensurePaymentReportActive($Payment_report)) {
        return;
    }
    $format_price_cart = number_format($Payment_report['price']);
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    if ($Balance_id == false || !is_array($Balance_id)) {
        return;
    }
    $steppay = explode("|", $Payment_report['id_invoice']);
    update("user", "Processing_value", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_one", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_tow", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_four", "0", "id", $Balance_id['id']);
    if ($steppay[0] == "getconfigafterpay") {
        $get_invoice = select("invoice", "*", "username", $steppay[1], "select");
        $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name_product AND (Location = :Service_location  or Location = '/all')");
        $stmt->bindParam(':name_product', $get_invoice['name_product'], PDO::PARAM_STR);
        $stmt->bindParam(':Service_location', $get_invoice['Service_location'], PDO::PARAM_STR);
        $stmt->execute();
        $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($get_invoice['name_product'] == "🛍 حجم دلخواه" || $get_invoice['name_product'] == "⚙️ سرویس دلخواه") {
            $info_product['data_limit_reset'] = "no_reset";
            $info_product['Volume_constraint'] = $get_invoice['Volume'];
            $info_product['name_product'] = $textbotlang['users']['customsellvolume']['title'];
            $info_product['code_product'] = "customvolume";
            $info_product['Service_time'] = $get_invoice['Service_time'];
            $info_product['price_product'] = $get_invoice['price_product'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name_product AND (Location = :Service_location  or Location = '/all')");
            $stmt->bindParam(':name_product', $get_invoice['name_product'], PDO::PARAM_STR);
            $stmt->bindParam(':Service_location', $get_invoice['Service_location'], PDO::PARAM_STR);
            $stmt->execute();
            $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $username_ac = $get_invoice['username'];
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $get_invoice['Service_location'], "select");
        $date = strtotime("+" . $get_invoice['Service_time'] . "days");
        if (intval($get_invoice['Service_time']) == 0) {
            $timestamp = 0;
        } else {
            $timestamp = strtotime(date("Y-m-d H:i:s", $date));
        }
        $datac = array(
            'expire' => $timestamp,
            'data_limit' => $get_invoice['Volume'] * pow(1024, 3),
            'from_id' => $Balance_id['id'],
            'username' => $Balance_id['username'],
            'type' => 'buy'
        );
        $dataoutput = $ManagePanel->createUser($marzban_list_get['name_panel'], $info_product['code_product'], $username_ac, $datac);
        // If panel already has this username (e.g. prior partial confirm), reuse existing account
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
            $balance = $Balance_id['Balance'] + $Payment_report['price'];
            update("user", "Balance", $balance, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], $textbotlang['users']['sell']['ErrorConfig'], $keyboard, 'HTML');
            sendmessage($Balance_id['id'], "💎  کاربر عزیز بدلیل ساخته نشدن سرویس مبلغ $balance تومان به کیف پول شما اضافه گردید.", $keyboard, 'HTML');
            $texterros = "
⭕️ خطا در ساخت کانفیگ
✍️ دلیل خطا : 
{$dataoutput['msg']}
آیدی کابر : {$Balance_id['id']}
نام کاربری کاربر : @{$Balance_id['username']}
نام پنل : {$marzban_list_get['name_panel']}";
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $texterros,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        product_discount_consume($info_product['code_product'] ?? '', $Balance_id);
        $Shoppinginfo = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => "📚 مشاهده آموزش استفاده ", 'callback_data' => "helpbtn"],
                ]
            ]
        ]);
        $output_config_link = "";
        $config = "";
        if ($marzban_list_get['config'] == "onconfig" && is_array($dataoutput['configs'])) {
            foreach ($dataoutput['configs'] as $link) {
                $config .= "\n" . $link;
            }
        }
        $output_config_link = $marzban_list_get['sublink'] == "onsublink" ? $dataoutput['subscription_url'] : "";
        $datatextbot['textafterpay'] = $marzban_list_get['type'] == "Manualsale" ? $datatextbot['textmanual'] : $datatextbot['textafterpay'];
        $datatextbot['textafterpay'] = $marzban_list_get['type'] == "WGDashboard" ? $datatextbot['text_wgdashboard'] : $datatextbot['textafterpay'];
        $datatextbot['textafterpay'] = $marzban_list_get['type'] == "ibsng" || $marzban_list_get['type'] == "mikrotik" ? $datatextbot['textafterpayibsng'] : $datatextbot['textafterpay'];
        if (intval($get_invoice['Service_time']) == 0)
            $get_invoice['Service_time'] = $textbotlang['users']['stateus']['Unlimited'];
        $textcreatuser = str_replace('{username}', $dataoutput['username'], $datatextbot['textafterpay']);
        $textcreatuser = str_replace('{name_service}', $get_invoice['name_product'], $textcreatuser);
        $textcreatuser = str_replace('{location}', $marzban_list_get['name_panel'], $textcreatuser);
        $textcreatuser = str_replace('{day}', $get_invoice['Service_time'], $textcreatuser);
        $textcreatuser = str_replace('{volume}', $get_invoice['Volume'], $textcreatuser);
        $textcreatuser = str_replace('{config}', "<code>{$output_config_link}</code>", $textcreatuser);
        $textcreatuser = str_replace('{links}', $config, $textcreatuser);
        $textcreatuser = str_replace('{links2}', "{$output_config_link}", $textcreatuser);
        if ($marzban_list_get['type'] == "Manualsale" || $marzban_list_get['type'] == "ibsng" || $marzban_list_get['type'] == "mikrotik") {
            $textcreatuser = str_replace('{password}', $dataoutput['subscription_url'], $textcreatuser);
            update("invoice", "user_info", $dataoutput['subscription_url'], "id_invoice", $get_invoice['id_invoice']);
        }
        sendMessageService($marzban_list_get, $dataoutput['configs'], $output_config_link, $dataoutput['username'], $Shoppinginfo, $textcreatuser, $get_invoice['id_invoice'], $get_invoice['id_user'], $image);
        $partsdic = explode("_", $Balance_id['Processing_value_four'], $get_invoice['id_user']);
        if ($partsdic[0] == "dis") {
            discount_sell_record_usage([
                'code' => $partsdic[1],
                'id_user' => $Balance_id['id'],
                'type' => 'buy',
                'code_product' => $get_invoice['code_product'] ?? null,
                'name_product' => $get_invoice['name_product'] ?? null,
                'code_panel' => $marzban_list_get['code_panel'] ?? null,
                'name_panel' => $marzban_list_get['name_panel'] ?? ($get_invoice['Service_location'] ?? null),
                'id_invoice' => $get_invoice['id_invoice'] ?? null,
                'price_original' => null,
                'price_final' => $get_invoice['price_product'] ?? ($Payment_report['price'] ?? null),
            ]);
            $text_report = "⭕️ یک کاربر با نام کاربری @{$Balance_id['username']}  و آیدی عددی {$Balance_id['id']} از کد تخفیف {$partsdic[1]} استفاده کرد.";
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $otherreport,
                    'text' => $text_report,
                ]);
            }
        }
        pay_affiliate_commission($Balance_id, $Payment_report['price'] ?? 0, 'buy', $get_invoice['id_invoice'] ?? null);
        if ($marzban_list_get['MethodUsername'] == "متن دلخواه + عدد ترتیبی" || $marzban_list_get['MethodUsername'] == "نام کاربری + عدد به ترتیب" || $marzban_list_get['MethodUsername'] == "آیدی عددی+عدد ترتیبی" || $marzban_list_get['MethodUsername'] == "متن دلخواه نماینده + عدد ترتیبی") {
            $value = intval($Balance_id['number_username']) + 1;
            update("user", "number_username", $value, "id", $Balance_id['id']);
            if ($marzban_list_get['MethodUsername'] == "متن دلخواه + عدد ترتیبی" || $marzban_list_get['MethodUsername'] == "متن دلخواه نماینده + عدد ترتیبی") {
                $value = intval($setting['numbercount']) + 1;
                update("setting", "numbercount", $value);
            }
        }
        $Balance_prims = $Balance_id['Balance'] - $get_invoice['price_product'];
        if ($Balance_prims <= 0)
            $Balance_prims = 0;
        update("user", "Balance", $Balance_prims, "id", $Balance_id['id']);
        $balanceformatsell = select("user", "Balance", "id", $get_invoice['id_user'], "select")['Balance'];
        $balanceformatsell = number_format($balanceformatsell, 0);
        $balancebefore = number_format($Balance_id['Balance'], 0);
        $timejalali = jdate('Y/m/d H:i:s');
        $textonebuy = "";
        if (bot_is_first_product_purchase($pdo, $Balance_id['id'], $get_invoice['id_invoice'] ?? null)) {
            $textonebuy = "📌 خرید اول کاربر";
        }
        $Response = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['Admin']['ManageUser']['mangebtnuser'], 'callback_data' => 'manageuser_' . $Balance_id['id']],
                ],
            ]
        ]);
        $text_report = "📣 جزئیات ساخت اکانت در ربات بعد پرداخت ثبت شد .

$textonebuy
▫️آیدی عددی کاربر : <code>{$Balance_id['id']}</code>
▫️نام کاربری کاربر :@{$Balance_id['username']}
▫️نام کاربری کانفیگ :$username_ac
▫️لوکیشن سرویس : {$get_invoice['Service_location']}
▫️زمان خریداری شده :{$get_invoice['Service_time']} روز
▫️نام محصول خریداری شده :{$get_invoice['name_product']}
▫️حجم خریداری شده : {$get_invoice['Volume']} GB
▫️موجودی قبل خرید : $balancebefore تومان
▫️موجودی بعد خرید : $balanceformatsell تومان
▫️کد پیگیری: {$get_invoice['id_invoice']}
▫️نوع کاربر : {$Balance_id['agent']}
▫️شماره تلفن کاربر : {$Balance_id['number']}
▫️قیمت محصول : {$get_invoice['price_product']} تومان
▫️قیمت نهایی : {$Payment_report['price']} تومان
▫️زمان خرید : $timejalali";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $buyreport,
                'text' => $text_report,
                'parse_mode' => "HTML",
                'reply_markup' => $Response
            ]);
        }
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], "📌شما 1 امتیاز جدید کسب کردید.", null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        update("invoice", "Status", "active", "username", $get_invoice['username']);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            update("invoice", "Status", "active", "id_invoice", $get_invoice['id_invoice']);
            $textconfrom = "✅ پرداخت تایید شده
 🛍خرید سرویس 
 ▫️نام کاربری کانفیگ :$username_ac
▫️لوکیشن سرویس : {$get_invoice['Service_location']}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💎 موجودی قبل خرید  : {$Balance_id['Balance']}
💸 مبلغ پرداختی: $format_price_cart تومان
✍️ توضیحات : {$Payment_report['dec_not_confirmed']}

";
            if (!empty($from_id) && !empty($message_id)) {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
    } elseif ($steppay[0] == "getextenduser") {
        $balanceformatsell = number_format(select("user", "Balance", "id", $Balance_id['id'], "select")['Balance'], 0);
        $partsdic = explode("%", $steppay[1]);
        $usernamepanel = $partsdic[0];
        $sql = "SELECT * FROM service_other WHERE username = :username  AND value  LIKE CONCAT('%', :value, '%') AND id_user = :id_user ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':username', $usernamepanel, PDO::PARAM_STR);
        $stmt->bindParam(':value', $partsdic[1], PDO::PARAM_STR);
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->execute();
        $data_order = $stmt->fetch(PDO::FETCH_ASSOC);
        $service_other = $data_order;
        if ($service_other == false) {
            sendmessage($Balance_id['id'], '❌ خطایی در هنگام تمدید رخ داده با پشتیبانی در ارتباط باشید', $keyboard, 'HTML');
            return;
        }
        $service_other = json_decode($service_other['value'], true);
        $codeproduct = $service_other['code_product'];
        $nameloc = select("invoice", "*", "username", $usernamepanel, "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        if ($codeproduct == "custom_volume") {
            $prodcut['code_product'] = "custom_volume";
            $prodcut['name_product'] = $nameloc['name_product'];
            $prodcut['price_product'] = $data_order['price'];
            $prodcut['Service_time'] = $service_other['Service_time'];
            $prodcut['Volume_constraint'] = $service_other['volumebuy'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE (Location = '{$nameloc['Service_location']}' OR Location = '/all') AND agent= '{$Balance_id['agent']}' AND code_product = '$codeproduct'");
            $stmt->execute();
            $prodcut = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($nameloc['name_product'] == "سرویس تست") {
            update("invoice", "name_product", $prodcut['name_product'], "id_invoice", $nameloc['id_invoice']);
            update("invoice", "price_product", $prodcut['price_product'], "id_invoice", $nameloc['id_invoice']);
        }
        $dateacc = date('Y/m/d H:i:s');
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $nameloc['username']);
        $Balance_Low_user = 0;
        update("user", "Balance", $Balance_Low_user, "id", $Balance_id['id']);
        $extend = $ManagePanel->extend($marzban_list_get['Methodextend'], $prodcut['Volume_constraint'], $prodcut['Service_time'], $nameloc['username'], $prodcut['code_product'], $marzban_list_get['code_panel']);
        if ($extend['status'] == false) {
            $balance = $Balance_id['Balance'] + $Payment_report['price'];
            update("user", "Balance", $balance, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], $textbotlang['users']['sell']['ErrorConfig'], $keyboard, 'HTML');
            sendmessage($Balance_id['id'], "💎  کاربر عزیز بدلیل تمدید نشدن سرویس مبلغ $balance تومان به کیف پول شما اضافه گردید.", $keyboard, 'HTML');
            $extend['msg'] = json_encode($extend['msg']);
            $textreports = "
        خطای تمدید سرویس
نام پنل : {$marzban_list_get['name_panel']}
نام کاربری سرویس : {$nameloc['username']}
دلیل خطا : {$extend['msg']}";
            sendmessage($nameloc['id_user'], "❌خطایی در تمدید سرویس رخ داده با پشتیبانی در ارتباط باشید", null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }

        update("service_other", "output", json_encode($extend), "id", $data_order['id']);
        update("service_other", "status", "paid", "id", $data_order['id']);
        update("service_other", "time", $dateacc, "id", $data_order['id']);
        pay_affiliate_commission($Balance_id, $Payment_report['price'] ?? ($data_order['price'] ?? 0), 'extend');
        $partsdic = explode("_", $Balance_id['Processing_value_four']);
        if ($partsdic[0] == "dis") {
            discount_sell_record_usage([
                'code' => $partsdic[1],
                'id_user' => $Balance_id['id'],
                'type' => 'extend',
                'code_product' => $prodcut['code_product'] ?? null,
                'name_product' => $prodcut['name_product'] ?? ($nameloc['name_product'] ?? null),
                'code_panel' => $marzban_list_get['code_panel'] ?? null,
                'name_panel' => $marzban_list_get['name_panel'] ?? ($nameloc['Service_location'] ?? null),
                'id_invoice' => $nameloc['id_invoice'] ?? null,
                'price_original' => $prodcut['price_product'] ?? null,
                'price_final' => $Payment_report['price'] ?? null,
            ]);
            $text_report = "⭕️ یک کاربر با نام کاربری @{$Balance_id['username']}  و آیدی عددی {$Balance_id['id']} از کد تخفیف {$partsdic[1]} استفاده کرد.";
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $otherreport,
                    'text' => $text_report,
                ]);
            }
        }
        $keyboardextendfnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['stateus']['backlist'], 'callback_data' => "backorder"],
                ],
                [
                    ['text' => $textbotlang['users']['stateus']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        if ($Balance_id['agent'] == "f") {
            $valurcashbackextend = select("shopSetting", "*", "Namevalue", "chashbackextend", "select")['value'];
        } else {
            $valurcashbackextend = json_decode(select("shopSetting", "*", "Namevalue", "chashbackextend_agent", "select")['value'], true)[$Balance_id['agenr']];
        }
        if (intval($valurcashbackextend) != 0) {
            $result = ($prodcut['price_product'] * $valurcashbackextend) / 100;
            $pricelastextend = $result;
            update("user", "Balance", $pricelastextend, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], "تبریک 🎉
📌 به عنوان هدیه تمدید مبلغ $result تومان حساب شما شارژ گردید", null, 'HTML');
        }
        $priceproductformat = number_format($prodcut['price_product']);
        $textextend = "✅ تمدید برای سرویس شما با موفقیت صورت گرفت
 
▫️نام سرویس : $usernamepanel
▫️نام محصول : {$prodcut['name_product']}
▫️مبلغ تمدید $priceproductformat تومان
";
        sendmessage($Balance_id['id'], $textextend, $keyboardextendfnished, 'HTML');
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], "📌شما 2 امتیاز جدید کسب کردید.", null, 'html');
            $scorenew = $Balance_id['score'] + 2;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $timejalali = jdate('Y/m/d H:i:s');
        $text_report = "📣 جزئیات تمدید اکانت در ربات شما ثبت شد .
    
▫️آیدی عددی کاربر : <code>{$Balance_id['id']}</code>
▫️نام کاربری کاربر : @{$Balance_id['username']}
▫️نام کاربری کانفیگ :$usernamepanel
▫️موقعیت سرویس سرویس : {$nameloc['Service_location']}
▫️نام محصول : {$prodcut['name_product']}
▫️حجم محصول : {$prodcut['Volume_constraint']}
▫️زمان محصول : {$prodcut['Service_time']}
▫️مبلغ تمدید : $priceproductformat تومان
▫️موجودی قبل از خرید : $balanceformatsell تومان
▫️زمان خرید : $timejalali";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
                'parse_mode' => "HTML"
            ]);
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {

            $textconfrom = "✅ پرداخت تایید شده
🔋 تمدید سرویس
🪪 نام کاربری کانفیگ : $usernamepanel
🛍 نام محصول : {$prodcut['name_product']}
🌏 نام لوکیشن : {$nameloc['Service_location']}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💎 موجودی قبل تمدید  : {$Balance_id['Balance']}
💸 مبلغ پرداختی: $format_price_cart تومان
✍️ توضیحات : {$Payment_report['dec_not_confirmed']}

";
            if (!empty($from_id) && !empty($message_id)) {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
    } elseif ($steppay[0] == "getextravolumeuser") {
        $steppay = explode("%", $steppay[1]);
        $volume = $steppay[1];
        $nameloc = select("invoice", "*", "username", $steppay[0], "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        $Balance_Low_user = 0;
        $inboundid = $marzban_list_get['inboundid'];
        if ($nameloc['inboundid'] != null) {
            $inboundid = $nameloc['inboundid'];
        }
        update("user", "Balance", $Balance_Low_user, "id", $Balance_id['id']);
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $steppay[0]);
        $data_for_database = json_encode(array(
            'volume_value' => $volume,
            'old_volume' => $DataUserOut['data_limit'],
            'expire_old' => $DataUserOut['expire']
        ));
        $dateacc = date('Y/m/d H:i:s');
        $type = "extra_user";
        $extra_volume = $ManagePanel->extra_volume($nameloc['username'], $marzban_list_get['code_panel'], $volume);
        if ($extra_volume['status'] == false) {
            $extra_volume['msg'] = json_encode($extra_volume['msg']);
            $textreports = "خطای خرید حجم اضافه
نام پنل : {$marzban_list_get['name_panel']}
نام کاربری سرویس : {$nameloc['username']}
دلیل خطا : {$extra_volume['msg']}";
            sendmessage($nameloc['id_user'], "❌خطایی در خرید حجم اضافه سرویس رخ داده با پشتیبانی در ارتباط باشید", null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO service_other (id_user, username,value,type,time,price,output) VALUES (:id_user,:username,:value,:type,:time,:price,:output)");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':username', $steppay[0]);
        $stmt->bindParam(':value', $data_for_database);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':time', $dateacc);
        $stmt->bindParam(':price', $Payment_report['price']);
        $stmt->bindValue(':output', json_encode($extra_volume));
        $stmt->execute();
        $keyboardextrafnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['stateus']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        $volumesformat = number_format($Payment_report['price'], 0);
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], "📌شما 1 امتیاز جدید کسب کردید.", null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $textvolume = "✅ افزایش حجم برای سرویس شما با موفقیت صورت گرفت
 
▫️نام سرویس  : {$steppay[0]}
▫️حجم اضافه : $volume گیگ

▫️مبلغ افزایش حجم : $volumesformat تومان";
        sendmessage($Balance_id['id'], $textvolume, $keyboardextrafnished, 'HTML');
        $volumes = $volume;
        if ($Payment_report['Payment_Method'] == "cart to cart") {
            $textconfrom = "✅ پرداخت تایید شده
🔋 خرید حجم اضافه
🛍 حجم خریداری شده  : $volumes گیگ
👤 نام کاربری کانفیگ {$steppay[0]}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💎 موجودی قبل ازافزایش موجودی : {$Balance_id['Balance']}
💸 مبلغ پرداختی: $format_price_cart تومان
";
            if (!empty($from_id) && !empty($message_id)) {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        $text_report = "⭕️ یک کاربر حجم اضافه خریده است
        
اطلاعات کاربر : 
🪪 آیدی عددی : {$Balance_id['id']}
🛍 حجم خریداری شده  : $volumes گیگ
💰 مبلغ پرداختی : {$Payment_report['price']} تومان
👤 نام کاربری کانفیگ {$steppay[0]}
موجودی کاربر قبل خرید : {$Balance_id['Balance']}
";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
                'parse_mode' => "HTML"
            ]);
        }
    } elseif ($steppay[0] == "getextratimeuser") {
        $steppay = explode("%", $steppay[1]);
        $tmieextra = $steppay[1];
        $nameloc = select("invoice", "*", "username", $steppay[0], "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        $Balance_Low_user = 0;
        $inboundid = $marzban_list_get['inboundid'];
        if ($nameloc['inboundid'] != false) {
            $inboundid = $nameloc['inboundid'];
        }
        update("user", "Balance", $Balance_Low_user, "id", $nameloc['id_user']);
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $steppay[0]);
        $data_for_database = json_encode(array(
            'day' => $tmieextra,
            'old_volume' => $DataUserOut['data_limit'],
            'expire_old' => $DataUserOut['expire']
        ));
        $dateacc = date('Y/m/d H:i:s');
        $type = "extra_time_user";
        $timeservice = $DataUserOut['expire'] - time();
        $day = floor($timeservice / 86400);
        $extra_time = $ManagePanel->extra_time($nameloc['username'], $marzban_list_get['code_panel'], $tmieextra);
        if ($extra_time['status'] == false) {
            $extra_time['msg'] = json_encode($extra_time['msg']);
            $textreports = "خطای خرید حجم اضافه
نام پنل : {$marzban_list_get['name_panel']}
نام کاربری سرویس : {$nameloc['username']}
دلیل خطا : {$extra_time['msg']}";
            sendmessage($from_id, "❌خطایی در خرید حجم اضافه سرویس رخ داده با پشتیبانی در ارتباط باشید", null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO service_other (id_user, username,value,type,time,price,output) VALUES (:id_user,:username,:value,:type,:time,:price,:output)");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':username', $steppay[0]);
        $stmt->bindParam(':value', $data_for_database);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':time', $dateacc);
        $stmt->bindParam(':price', $Payment_report['price']);
        $stmt->bindValue(':output', json_encode($extra_time));
        $stmt->execute();
        $keyboardextrafnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['stateus']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        $volumesformat = number_format($Payment_report['price']);
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], "📌شما 1 امتیاز جدید کسب کردید.", null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $textextratime = "✅ افزایش زمان برای سرویس شما با موفقیت صورت گرفت
 
▫️نام سرویس : {$steppay[0]}
▫️زمان اضافه : $tmieextra روز

▫️مبلغ افزایش زمان : $volumesformat تومان";
        sendmessage($Balance_id['id'], $textextratime, $keyboardextrafnished, 'HTML');
        if ($Payment_report['Payment_Method'] == "cart to cart") {
            $volumes = $tmieextra;
            $textconfrom = "✅ پرداخت تایید شده
🔋 خرید زمان اضافه
🛍 زمان خریداری شده  : $volumes روز
👤 نام کاربری کانفیگ {$steppay[0]}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💎 موجودی قبل ازافزایش موجودی : {$Balance_id['Balance']}
💸 مبلغ پرداختی: $format_price_cart تومان
";
            if (!empty($from_id) && !empty($message_id)) {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        $text_report = "⭕️ یک کاربر زمان اضافه خریده است
        
اطلاعات کاربر : 
🪪 آیدی عددی : {$Balance_id['id']}
🛍 زمان خریداری شده  : $volumes روز
💰 مبلغ پرداختی : {$Payment_report['price']} تومان
👤 نام کاربری کانفیگ {$steppay[0]}";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
            ]);
        }
    } else {
        $Balance_confrim = intval($Balance_id['Balance']) + intval($Payment_report['price']);
        update("user", "Balance", $Balance_confrim, "id", $Payment_report['id_user']);
        update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);
        $Payment_report['price'] = number_format($Payment_report['price'], 0);
        $format_price_cart = $Payment_report['price'];
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            $textconfrom = "⭕️ یک پرداخت جدید انجام شده است
        افزایش موجودی.
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💸 مبلغ پرداختی: $format_price_cart تومان
💎 موجودی قبل ازافزایش موجودی : {$Balance_id['Balance']}
✍️ توضیحات : {$Payment_report['dec_not_confirmed']}";
            if (!empty($from_id) && !empty($message_id)) {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
        sendmessage($Payment_report['id_user'], "💎 کاربر گرامی مبلغ {$Payment_report['price']} تومان به کیف پول شما واریز گردید با تشکراز پرداخت شما.
                
🛒 کد پیگیری شما: {$Payment_report['id_order']}", null, 'HTML');
    }
}
function plisio($order_id, $price)
{
    $apinowpayments = select("PaySetting", "ValuePay", "NamePay", "apinowpayment", "select")['ValuePay'];
    $api_key = $apinowpayments;

    $url = 'https://api.plisio.net/api/v1/invoices/new';
    $url .= '?source_currency=USD';
    $url .= '&source_amount=' . urlencode($price);
    $url .= '&order_number=' . urlencode($order_id);
    $url .= '&email=customer@plisio.net';
    $url .= '&order_name=plisio';
    $url .= '&language=fa';
    $url .= '&api_key=' . urlencode($api_key);
    $ch = curl_init($url);
    curl_disable_proxy($ch);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = json_decode(curl_exec($ch), true);
    return $response['data'];
    curl_close($ch);
}
function checkConnection($address, $port)
{
    $socket = @stream_socket_client("tcp://$address:$port", $errno, $errstr, 5);
    if ($socket) {
        fclose($socket);
        return true;
    } else {
        return false;
    }
}
function savedata($type, $namefiled, $valuefiled)
{
    global $from_id;
    if ($type == "clear") {
        $datauser = [];
        $datauser[$namefiled] = $valuefiled;
        $data = json_encode($datauser);
        update("user", "Processing_value", $data, "id", $from_id);
    } elseif ($type == "save") {
        $userdata = select("user", "*", "id", $from_id, "select");
        $dataperevieos = json_decode($userdata['Processing_value'], true);
        $dataperevieos[$namefiled] = $valuefiled;
        update("user", "Processing_value", json_encode($dataperevieos), "id", $from_id);
    }
}
function addFieldToTable($tableName, $fieldName, $defaultValue = null, $datatype = "VARCHAR(500)")
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_name = :tableName");
    $stmt->bindParam(':tableName', $tableName);
    $stmt->execute();
    $tableExists = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tableExists['count'] == 0)
        return;
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$pdo->query("SELECT DATABASE()")->fetchColumn(), $tableName, $fieldName]);
    $filedExists = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($filedExists['count'] != 0)
        return;
    $query = "ALTER TABLE $tableName ADD $fieldName $datatype";
    $statement = $pdo->prepare($query);
    $statement->execute();
    if ($defaultValue != null) {
        $stmt = $pdo->prepare("UPDATE $tableName SET $fieldName= ?");
        $stmt->bindParam(1, $defaultValue);
        $stmt->execute();
    }
    echo "The $fieldName field was added ✅";
}

/** Test-account quota window length in seconds (10 days). */
function usertestPeriodSeconds(): int
{
    return 10 * 86400;
}

/**
 * Count non-disabled test invoices for a user.
 */
function countActiveUsertestAccounts($user_id): int
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE id_user = :id_user AND name_product = 'سرویس تست' AND Status NOT IN ('disabled', 'Unsuccessful')");
    $stmt->bindParam(':id_user', $user_id);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

/**
 * Remove a user's test accounts from panel + mark invoices disabled.
 * If $olderThanSeconds is set, only accounts older than that are removed.
 */
function removeUserTestAccounts($user_id = null, $olderThanSeconds = null): void
{
    global $pdo, $ManagePanel;
    if (!isset($ManagePanel) || !is_object($ManagePanel)) {
        $ManagePanel = new ManagePanel();
    }
    $query = "SELECT * FROM invoice WHERE name_product = 'سرویس تست' AND Status NOT IN ('disabled', 'Unsuccessful')";
    $params = [];
    if ($user_id !== null) {
        $query .= " AND id_user = :id_user";
        $params[':id_user'] = $user_id;
    }
    if ($olderThanSeconds !== null) {
        $cutoff = time() - (int) $olderThanSeconds;
        $query .= " AND CAST(time_sell AS UNSIGNED) > 0 AND CAST(time_sell AS UNSIGNED) <= :cutoff";
        $params[':cutoff'] = $cutoff;
    }
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    while ($invoice = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $username = trim($invoice['username']);
        if ($username === '') {
            continue;
        }
        try {
            $ManagePanel->RemoveUser($invoice['Service_location'], $username);
        } catch (Throwable $e) {
            error_log('removeUserTestAccounts: ' . $e->getMessage());
        }
        update("invoice", "Status", "disabled", "username", $username);
    }
}

/**
 * If the user's 10-day test period has ended: remove their test accounts,
 * restore limit_usertest from settings, and clear the period start.
 * Returns the (possibly refreshed) user row.
 */
function ensureUsertestPeriodReset($user_id)
{
    global $pdo, $setting;
    ensureColumnExistsForUpdate('user', 'time_usertest', '0');
    $user = select("user", "*", "id", $user_id, "select");
    if ($user === false || !is_array($user)) {
        return $user;
    }
    $periodStart = intval($user['time_usertest'] ?? 0);
    // Backfill period start for users who already used their quota before this feature
    if ($periodStart <= 0 && intval($user['limit_usertest']) <= 0) {
        $stmt = $pdo->prepare("SELECT time_sell FROM invoice WHERE id_user = :id_user AND name_product = 'سرویس تست' AND Status != 'Unsuccessful' ORDER BY CAST(time_sell AS UNSIGNED) DESC LIMIT 1");
        $stmt->bindParam(':id_user', $user_id);
        $stmt->execute();
        $lastSell = $stmt->fetchColumn();
        $periodStart = intval($lastSell) > 0 ? intval($lastSell) : time();
        update("user", "time_usertest", $periodStart, "id", $user_id);
        $user['time_usertest'] = (string) $periodStart;
    }
    if ($periodStart > 0 && (time() - $periodStart) >= usertestPeriodSeconds()) {
        removeUserTestAccounts($user_id);
        $resetLimit = $setting['limit_usertest_all'] ?? '1';
        update("user", "limit_usertest", $resetLimit, "id", $user_id);
        update("user", "time_usertest", "0", "id", $user_id);
        $user = select("user", "*", "id", $user_id, "select");
    }
    return $user;
}

/**
 * Warning text when the user cannot create another test account.
 */
function getUsertestLimitWarningMessage($user): string
{
    global $textbotlang, $setting;
    $limit = $setting['limit_usertest_all'] ?? ($user['limit_usertest'] ?? '1');
    if (countActiveUsertestAccounts($user['id']) > 0) {
        $msg = $textbotlang['users']['usertest']['limitwarningactive']
            ?? "محدودیت اکانت تست هر {limit}‌ اکانت در ۱۰ روز است . شما یک اکانت تست فعال دارید. لطفا چند روز دیگر مجددا امتحان نمایید .";
        return str_replace('{limit}', $limit, $msg);
    }
    return $textbotlang['users']['usertest']['limitwarning'];
}

/**
 * Cron helper: remove test accounts older than 10 days and reset expired periods.
 */
function cronCleanupUsertestAccounts(): void
{
    global $pdo, $setting;
    ensureColumnExistsForUpdate('user', 'time_usertest', '0');
    removeUserTestAccounts(null, usertestPeriodSeconds());
    $cutoff = time() - usertestPeriodSeconds();
    $stmt = $pdo->prepare("SELECT id FROM user WHERE CAST(time_usertest AS UNSIGNED) > 0 AND CAST(time_usertest AS UNSIGNED) <= :cutoff");
    $stmt->bindParam(':cutoff', $cutoff);
    $stmt->execute();
    $resetLimit = $setting['limit_usertest_all'] ?? '1';
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        update("user", "limit_usertest", $resetLimit, "id", $row['id']);
        update("user", "time_usertest", "0", "id", $row['id']);
    }
}

/**
 * Decode category/panel style agent JSON: {"f":"...","n":"...","n2":"..."}.
 */
/** Read an agent-scoped JSON field from a marzban_panel row. */
function panel_agent_field($panel, string $field, string $agent, string $default = '0'): string
{
    if (!is_array($panel)) {
        return $default;
    }
    $decoded = json_decode((string) ($panel[$field] ?? ''), true);
    if (!is_array($decoded)) {
        return $default;
    }
    $agent = $agent !== '' ? $agent : 'f';
    if (isset($decoded[$agent]) && $decoded[$agent] !== '' && $decoded[$agent] !== null) {
        return (string) $decoded[$agent];
    }
    return (string) ($decoded['f'] ?? $default);
}

/** Custom volume/time sell is enabled on this panel for the given agent. */
function panel_custom_enabled($panel, string $agent): bool
{
    if (!is_array($panel)) {
        return false;
    }
    if (($panel['type'] ?? '') === 'Manualsale') {
        return false;
    }
    return panel_agent_field($panel, 'customvolume', $agent, '0') === '1';
}

/** Button label for custom service on the buy keyboard. */
function panel_custom_button_text($panel): string
{
    global $textbotlang;
    $default = $textbotlang['users']['customsellvolume']['title'] ?? '⚙️ سرویس دلخواه';
    if (!is_array($panel)) {
        return $default;
    }
    $text = strip_html_for_button_label((string) ($panel['customvolume_text'] ?? ''));
    return $text !== '' ? $text : $default;
}

/** Premium icon id for سرویس دلخواه inline button, if set. */
function panel_custom_button_emoji_id($panel): string
{
    if (!is_array($panel)) {
        return '';
    }
    $normalized = normalize_main_keyboard_custom_emoji_id($panel['customvolume_emoji_id'] ?? '');
    return ($normalized !== null && $normalized !== '') ? $normalized : '';
}

/** Build inline button array for سرویس دلخواه (optional Premium icon). */
function panel_custom_service_inline_button($panel, string $callback_data = 'customsellvolume'): array
{
    $btn = [
        'text' => panel_custom_button_text($panel),
        'callback_data' => $callback_data,
    ];
    $emojiId = panel_custom_button_emoji_id($panel);
    if ($emojiId !== '') {
        $btn['icon_custom_emoji_id'] = $emojiId;
    }
    return $btn;
}

/** Default month options for سرویس دلخواه (1 month = 30 days). */
function panel_default_custommonths_list(): array
{
    return [
        ['months' => 1, 'magnifier' => 1.0],
        ['months' => 2, 'magnifier' => 1.8],
        ['months' => 3, 'magnifier' => 2.5],
    ];
}

/**
 * Decode and validate marzban_panel.custommonths JSON.
 * @return list<array{months:int,magnifier:float}>
 */
function panel_custom_months($panel): array
{
    $raw = is_array($panel) ? ($panel['custommonths'] ?? null) : null;
    if (!$raw) {
        return panel_default_custommonths_list();
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($decoded) || $decoded === []) {
        return panel_default_custommonths_list();
    }
    $seen = [];
    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $months = (int) ($row['months'] ?? $row['m'] ?? 0);
        $mag = (float) ($row['magnifier'] ?? $row['x'] ?? 0);
        if ($months < 1 || $mag <= 0 || isset($seen[$months])) {
            continue;
        }
        $seen[$months] = true;
        $out[] = ['months' => $months, 'magnifier' => $mag];
    }
    if ($out === []) {
        return panel_default_custommonths_list();
    }
    usort($out, static function ($a, $b) {
        return $a['months'] <=> $b['months'];
    });
    return $out;
}

/** @return array{months:int,magnifier:float}|null */
function panel_custom_month_option($panel, int $months): ?array
{
    if ($months < 1) {
        return null;
    }
    foreach (panel_custom_months($panel) as $opt) {
        if ((int) $opt['months'] === $months) {
            return $opt;
        }
    }
    return null;
}

/** Service length in days for a custom-month option. */
function panel_custom_months_to_days(int $months): int
{
    return max(0, $months) * 30;
}

/**
 * Price for custom service: GB × price_per_GB × magnifier(months).
 * Returns null if months is not an allowed option.
 */
function panel_custom_service_price(int $gb, int $months, float $pricePerGb, $panel): ?int
{
    $opt = panel_custom_month_option($panel, $months);
    if ($opt === null || $gb < 0 || $pricePerGb < 0) {
        return null;
    }
    return (int) round($gb * $pricePerGb * (float) $opt['magnifier']);
}

/**
 * Resolve custom service price for a user (agent n uses wholesale GB cost × magnifier).
 * $days must equal months×30 for a configured month option.
 */
function panel_custom_service_price_for_user($panel, $user, int $gb, int $days): ?int
{
    $months = (int) round($days / 30);
    $opt = panel_custom_month_option($panel, $months);
    if ($opt === null || panel_custom_months_to_days($months) !== $days) {
        return null;
    }
    $agent = is_array($user) ? (string) ($user['agent'] ?? 'f') : 'f';
    if ($agent === 'n' && is_array($user)) {
        return (int) round(agent_wholesale_cost($user, $gb) * (float) $opt['magnifier']);
    }
    $pricePerGb = (float) panel_agent_field($panel, 'pricecustomvolume', $agent, '4000');
    return panel_custom_service_price($gb, $months, $pricePerGb, $panel);
}

/**
 * Inline keyboard of configured month options (one button per row).
 * When $gb > 0, labels include duration surcharge vs 1-month baseline.
 * Callback: {$prefix}{months} e.g. custommonth_2
 */
function KeyboardCustomMonths($panel, string $prefix = 'custommonth_', string $backCallback = 'backuser', int $gb = 0, $user = null): string
{
    global $textbotlang;
    $keyboard = ['inline_keyboard' => []];
    $monthsList = panel_custom_months($panel);
    $baselineMonths = 1;
    $baselineFound = false;
    foreach ($monthsList as $opt) {
        $m = (int) $opt['months'];
        if ($m === 1) {
            $baselineMonths = 1;
            $baselineFound = true;
            break;
        }
    }
    if (!$baselineFound && $monthsList !== []) {
        $baselineMonths = (int) $monthsList[0]['months'];
    }
    $baseCost = null;
    if ($gb > 0) {
        $baseCost = panel_custom_service_price_for_user($panel, $user, $gb, panel_custom_months_to_days($baselineMonths));
    }
    foreach ($monthsList as $opt) {
        $m = (int) $opt['months'];
        $label = $m . ' ماهه';
        if ($gb > 0 && $baseCost !== null) {
            $cost = panel_custom_service_price_for_user($panel, $user, $gb, panel_custom_months_to_days($m));
            if ($cost !== null) {
                $extra = (int) $cost - (int) $baseCost;
                if ($extra <= 0) {
                    $label .= ' - هزینه ی مدت : بدون هزینه';
                } else {
                    $label .= ' - هزینه ی مدت : + ' . number_format($extra);
                }
            }
        }
        $keyboard['inline_keyboard'][] = [
            ['text' => $label, 'callback_data' => $prefix . $m],
        ];
    }
    $keyboard['inline_keyboard'][] = [
        ['text' => $textbotlang['users']['stateus']['backinfo'] ?? '🏠 بازگشت', 'callback_data' => $backCallback],
    ];
    return json_encode($keyboard);
}

function category_decode_agent_json(?string $json, string $default = '0'): array
{
    if (!$json) {
        return ['f' => $default, 'n' => $default, 'n2' => $default];
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return ['f' => $default, 'n' => $default, 'n2' => $default];
    }
    return [
        'f' => (string) ($d['f'] ?? $default),
        'n' => (string) ($d['n'] ?? $default),
        'n2' => (string) ($d['n2'] ?? $default),
    ];
}

function category_encode_agent_json(array $values): string
{
    return json_encode([
        'f' => (string) ($values['f'] ?? '0'),
        'n' => (string) ($values['n'] ?? '0'),
        'n2' => (string) ($values['n2'] ?? '0'),
    ], JSON_UNESCAPED_UNICODE);
}

function category_agent_field($category, string $field, string $agent, string $default = '0'): string
{
    if (!is_array($category) || !array_key_exists($field, $category) || $category[$field] === null) {
        return $default;
    }
    $decoded = category_decode_agent_json((string) $category[$field], $default);
    return (string) ($decoded[$agent] ?? $default);
}

/** Custom volume/time sell is enabled on this category for the given agent. */
function category_custom_enabled($category, string $agent, $panelType = null): bool
{
    if (!is_array($category)) {
        return false;
    }
    if ($panelType === 'Manualsale') {
        return false;
    }
    return category_agent_field($category, 'customvolume', $agent, '0') === '1';
}

/** Whether a category is visible in the purchase flow. Missing/empty status counts as active. */
function category_is_active($category): bool
{
    if (!is_array($category)) {
        return false;
    }
    $status = $category['status'] ?? 'active';
    return $status === '' || $status === 'active';
}

/** Whether a panel is currently active for buy/renew. */
function panel_is_active($panel): bool
{
    return is_array($panel) && ($panel['status'] ?? '') === 'active';
}

/** Whether a product's category is currently active. Empty/missing category counts as active. */
function product_category_is_active($product): bool
{
    static $cache = [];
    if (!is_array($product)) {
        return false;
    }
    $remark = trim((string) ($product['category'] ?? ''));
    if ($remark === '') {
        return true;
    }
    $ownerKey = trim((string) ($product['agent_id'] ?? ''));
    $cacheKey = $ownerKey . '|' . $remark;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    if ($ownerKey !== '') {
        $own = agent_own_get_category_by_remark($ownerKey, $remark);
        $cache[$cacheKey] = $own ? category_is_active($own) : false;
        return $cache[$cacheKey];
    }
    $category = select("category", "*", "remark", $remark, "select");
    $cache[$cacheKey] = category_is_active($category);
    return $cache[$cacheKey];
}

/**
 * Whether a user can renew on this panel (and optionally this product).
 * Custom-volume products skip the category check.
 */
function extend_can_proceed($panel, $product = null): array
{
    if (!panel_is_active($panel) || (($panel['status_extend'] ?? '') === 'off_extend')) {
        return ['ok' => false, 'msg' => '❌ امکان تمدید در این پنل وجود ندارد'];
    }
    if ($product === null) {
        return ['ok' => true, 'msg' => ''];
    }
    if (!is_array($product)) {
        return ['ok' => false, 'msg' => '❌ این محصول غیرفعال است و امکان تمدید آن وجود ندارد'];
    }
    $code = (string) ($product['code_product'] ?? '');
    if (in_array($code, ['custom_volume', 'customvolume', 'pre'], true)) {
        return ['ok' => true, 'msg' => ''];
    }
    if (!product_category_is_active($product)) {
        return ['ok' => false, 'msg' => '❌ این محصول غیرفعال است و امکان تمدید آن وجود ندارد'];
    }
    return ['ok' => true, 'msg' => ''];
}

function keyboard_insert_rows_before_last(string $keyboardJson, array $rows): string
{
    if ($rows === []) {
        return $keyboardJson;
    }
    $decoded = json_decode($keyboardJson, true);
    if (!is_array($decoded) || !isset($decoded['inline_keyboard']) || !is_array($decoded['inline_keyboard'])) {
        return $keyboardJson;
    }
    $keyboard = $decoded['inline_keyboard'];
    $last = array_pop($keyboard);
    foreach ($rows as $row) {
        $keyboard[] = $row;
    }
    if ($last !== null) {
        $keyboard[] = $last;
    }
    $decoded['inline_keyboard'] = $keyboard;
    return json_encode($decoded);
}

function extend_categories_enabled(): bool
{
    global $setting;
    return ($setting['statuscategorygenral'] ?? '') === 'oncategorys';
}

function extend_current_plan_row($invoice): ?array
{
    if (!is_array($invoice)) {
        return null;
    }
    $currentProd = select("product", "*", "name_product", $invoice['name_product'] ?? '', "select");
    if (!$currentProd || product_category_is_active($currentProd)) {
        return [['text' => "♻️ تمدید پلن فعلی", 'callback_data' => "exntedagei"]];
    }
    return null;
}

function extend_category_select_text($panel): string
{
    return purchase_description_or_fallback(
        is_array($panel) ? ($panel['description'] ?? '') : '',
        'text_category_select',
        '📌 دسته بندی خود را انتخاب نمایید!'
    );
}

function extend_products_message($category = null): string
{
    global $textbotlang;
    $fallback = $textbotlang['users']['extend']['selectservice'] ?? '🛍 محصول خود را برای تمدید انتخاب نمایید';
    if (!is_array($category)) {
        return $fallback;
    }
    $defaultCategoryMessage = textbot_get(
        'text_service_select_first',
        $textbotlang['users']['sell']['Service-select-first'] ?? $fallback
    );
    return purchase_description_or_fallback(
        $category['description'] ?? '',
        'text_service_select_first',
        $defaultCategoryMessage
    );
}

function extend_product_query(string $location, array $user, $from_id, $categoryRemark = null, $month = null): string
{
    $locEsc = addslashes($location);
    $where = "(Location = '{$locEsc}' OR Location = '/all') AND one_buy_status = '0'";
    if (is_string($categoryRemark) && $categoryRemark !== '') {
        $where .= " AND category = '" . addslashes($categoryRemark) . "'";
    }
    if ($month !== null && $month !== '' && preg_match('/^\d+$/', (string) $month)) {
        $where .= " AND Service_time = '" . $month . "'";
    }
    return agent_product_select_sql($user['agent'] ?? '', $from_id, $where);
}

function extend_category_keyboard($location, $agent, $backCb, $invoice = null, $month = null): string
{
    $extraSql = "AND one_buy_status = '0'";
    if ($month !== null && $month !== '' && preg_match('/^\d+$/', (string) $month)) {
        $extraSql .= " AND Service_time = '" . $month . "'";
    }
    $json = KeyboardCategory($location, $agent, $backCb, null, [
        'callback_prefix' => 'categoryextend_',
        'custom_volume' => false,
        'product_extra_sql' => $extraSql,
    ]);
    $plan = extend_current_plan_row($invoice);
    return $plan ? keyboard_insert_rows_before_last($json, [$plan]) : $json;
}

function extend_product_keyboard($location, $query, array $user, $backCb, $invoice = null, array $extraRows = []): string
{
    $json = KeyboardProduct(
        $location,
        $query,
        $user['pricediscount'] ?? 0,
        'serviceextendselect_',
        false,
        $backCb
    );
    $insert = [];
    $plan = extend_current_plan_row($invoice);
    if ($plan) {
        $insert[] = $plan;
    }
    foreach ($extraRows as $row) {
        $insert[] = $row;
    }
    return $insert === [] ? $json : keyboard_insert_rows_before_last($json, $insert);
}

function extend_edit_categories($from_id, $message_id, array $invoice, array $user, $panel, $backCb, $month = null): void
{
    $location = $invoice['Service_location'] ?? '';
    Editmessagetext(
        $from_id,
        $message_id,
        extend_category_select_text($panel),
        extend_category_keyboard($location, $user['agent'] ?? '', $backCb, $invoice, $month),
        'HTML'
    );
}

function extend_edit_products($from_id, $message_id, array $invoice, array $user, $query, $backCb, $category = null, array $extraRows = []): void
{
    Editmessagetext(
        $from_id,
        $message_id,
        extend_products_message($category),
        extend_product_keyboard($invoice['Service_location'] ?? '', $query, $user, $backCb, $invoice, $extraRows),
        'HTML'
    );
}

function invoice_auto_renew_is_on($invoice): bool
{
    if (!is_array($invoice)) {
        return false;
    }
    if (($invoice['name_product'] ?? '') === 'سرویس تست') {
        return false;
    }
    return (string) ($invoice['auto_renew'] ?? '0') === '1';
}

function invoice_auto_renew_button_label($invoice, $textbotlang = null): string
{
    $on = invoice_auto_renew_is_on($invoice);
    $onLabel = '✅ تمدید خودکار';
    $offLabel = '❌ تمدید خودکار';
    if (is_array($textbotlang)) {
        $onLabel = $textbotlang['users']['extend']['autorenew_on'] ?? $onLabel;
        $offLabel = $textbotlang['users']['extend']['autorenew_off'] ?? $offLabel;
    }
    return $on ? $onLabel : $offLabel;
}

function invoice_volume_cron_auto_renew_notice($invoice, $textbotlang = null): array
{
    $offText = '💡 با روشن کردن تمدید خودکار برای این سرویس و شارژ کیف پول، این اشتراک خود را همیشه متصل نگه دارید';
    $onText = '✅ تمدید خودکار برای این سرویس روشن است.';
    $enableLabel = '✅ روشن کردن تمدید خودکار';
    if (is_array($textbotlang)) {
        $offText = $textbotlang['users']['extend']['autorenew_cron_off'] ?? $offText;
        $onText = $textbotlang['users']['extend']['autorenew_cron_on'] ?? $onText;
        $enableLabel = $textbotlang['users']['extend']['autorenew_cron_enable'] ?? $enableLabel;
    }
    if (invoice_auto_renew_is_on($invoice)) {
        return [
            'text' => "\n\n" . $onText,
            'button' => null,
        ];
    }
    $invoiceId = (string) ($invoice['id_invoice'] ?? '');
    return [
        'text' => "\n\n" . $offText,
        'button' => $invoiceId === '' ? null : [
            'text' => $enableLabel,
            'callback_data' => 'autorenew_' . $invoiceId,
        ],
    ];
}

function invoice_volume_cron_keyboard($invoice, $textbotlang = null): string
{
    $invoiceId = (string) ($invoice['id_invoice'] ?? '');
    $rows = [
        [
            ['text' => '💊 تمدید سرویس', 'callback_data' => 'extend_' . $invoiceId],
        ],
    ];
    $notice = invoice_volume_cron_auto_renew_notice($invoice, $textbotlang);
    if (!empty($notice['button'])) {
        $rows[] = [$notice['button']];
    }
    return json_encode(['inline_keyboard' => $rows]);
}

function invoice_auto_renew_stats($db = null): array
{
    if (!($db instanceof PDO)) {
        global $pdo;
        $db = $pdo ?? null;
    }
    if (!($db instanceof PDO)) {
        return ['users' => 0, 'services' => 0];
    }
    try {
        $stmt = $db->query("SELECT COUNT(*) AS services, COUNT(DISTINCT id_user) AS users
            FROM invoice
            WHERE auto_renew = '1'
              AND name_product != 'سرویس تست'
              AND Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold')");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
        return [
            'users' => (int) ($row['users'] ?? 0),
            'services' => (int) ($row['services'] ?? 0),
        ];
    } catch (PDOException $e) {
        return ['users' => 0, 'services' => 0];
    }
}

function invoice_auto_renew_notify_enabled(array $invoice, $fromId, $telegramUsername = '', $firstName = ''): void
{
    $setting = select('setting', '*');
    if (!is_array($setting) || strlen($setting['Channel_Report'] ?? '') <= 0) {
        return;
    }
    $otherreportRow = select('topicid', 'idreport', 'report', 'otherreport', 'select');
    $otherreport = is_array($otherreportRow) ? ($otherreportRow['idreport'] ?? null) : null;
    $tgUser = ltrim((string) $telegramUsername, '@');
    $tgUser = $tgUser !== '' ? '@' . $tgUser : 'ندارد';
    $firstName = $firstName !== '' ? $firstName : 'نامشخص';
    $timeText = function_exists('jdate') ? jdate('Y/m/d H:i:s') : date('Y/m/d H:i:s');
    $text_report = "♻️ یک کاربر تمدید خودکار را روشن کرد.

▫️آیدی عددی کاربر : <code>{$fromId}</code>
▫️نام کاربر : {$firstName}
▫️نام کاربری تلگرام : {$tgUser}
▫️نام کاربری سرویس : <code>{$invoice['username']}</code>
▫️نام محصول : {$invoice['name_product']}
▫️موقعیت سرویس : {$invoice['Service_location']}
▫️زمان : {$timeText}";
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $otherreport,
        'text' => $text_report,
        'parse_mode' => 'HTML',
    ]);
}

function invoice_is_custom_volume_product($invoice, $codeProduct = ''): bool
{
    $name = (string) ($invoice['name_product'] ?? '');
    $code = (string) $codeProduct;
    return in_array($name, ['🛍 حجم دلخواه', '⚙️ سرویس دلخواه'], true)
        || in_array($code, ['custom_volume', 'customvolume', 'pre'], true);
}

function invoice_notifctions_decode($invoice): array
{
    $data = json_decode($invoice['notifctions'] ?? '', true);
    if (!is_array($data)) {
        $data = [];
    }
    if (!array_key_exists('volume', $data)) {
        $data['volume'] = false;
    }
    if (!array_key_exists('time', $data)) {
        $data['time'] = false;
    }
    return $data;
}

function invoice_notifctions_patch($invoice, array $patch): void
{
    if (!is_array($invoice) || empty($invoice['id_invoice'])) {
        return;
    }
    $data = invoice_notifctions_decode($invoice);
    foreach ($patch as $key => $value) {
        $data[$key] = $value;
    }
    update('invoice', 'notifctions', json_encode($data), 'id_invoice', $invoice['id_invoice']);
}

function invoice_auto_renew_clear_insufficient($invoice): void
{
    $data = invoice_notifctions_decode($invoice);
    if (empty($data['auto_renew_insufficient'])) {
        return;
    }
    invoice_notifctions_patch($invoice, ['auto_renew_insufficient' => false]);
}

function invoice_auto_renew_clear_prewarn($invoice): void
{
    $data = invoice_notifctions_decode($invoice);
    if (empty($data['auto_renew_prewarn'])) {
        return;
    }
    invoice_notifctions_patch($invoice, ['auto_renew_prewarn' => false]);
}

function invoice_auto_renew_final_price(array $product, array $user): int
{
    $price = (int) round((float) ($product['price_product'] ?? 0));
    if (intval($user['pricediscount'] ?? 0) != 0) {
        $price = (int) round($price - (($price * $user['pricediscount']) / 100));
    }
    return max(0, $price);
}

function invoice_auto_renew_balance_short(array $user, int $price, $volumeGb = null): bool
{
    $agent = (string) ($user['agent'] ?? 'f');
    if (agent_is_n2($agent)) {
        $check = agent_check_volume_quota($user['id'] ?? 0, (int) ($volumeGb ?? 0));
        return empty($check['ok']);
    }
    $balance = (int) ($user['Balance'] ?? 0);
    return $balance < $price && $price != 0;
}

function invoice_auto_renew_volume_low(array $userData, $volumewarnGb): bool
{
    $dataLimit = (float) ($userData['data_limit'] ?? 0);
    if ($dataLimit <= 0) {
        return false;
    }
    $status = (string) ($userData['status'] ?? '');
    if (!in_array($status, ['active', 'Unknown', 'limited'], true)) {
        return false;
    }
    if ($status === 'limited') {
        return true;
    }
    $remaining = $dataLimit - (float) ($userData['used_traffic'] ?? 0);
    $threshold = ((float) $volumewarnGb) * pow(1024, 3);
    if ($remaining > $threshold) {
        return false;
    }
    if ($remaining <= 0) {
        return true;
    }
    if ($dataLimit <= $threshold) {
        return $remaining <= (50 * 1024 * 1024);
    }
    return true;
}

function invoice_auto_renew_time_low(array $userData, $daywarn, $invoice = null): bool
{
    $expire = (float) ($userData['expire'] ?? 0);
    if ($expire <= 0) {
        return false;
    }
    $status = (string) ($userData['status'] ?? '');
    if (!in_array($status, ['active', 'Unknown', 'expired'], true)) {
        return false;
    }
    if ($status === 'expired') {
        return true;
    }
    $remaining = $expire - time();
    $threshold = ((int) $daywarn) * 86400;
    if ($remaining > $threshold) {
        return false;
    }
    if ($remaining <= 0) {
        return true;
    }
    $packageDays = is_array($invoice) ? (int) ($invoice['Service_time'] ?? 0) : 0;
    if ($packageDays > 0 && ($packageDays * 86400) <= $threshold) {
        return $remaining <= (6 * 3600);
    }
    return true;
}

function invoice_auto_renew_should_run(array $invoice, array $userData, $setting = null): bool
{
    if (!invoice_auto_renew_is_on($invoice)) {
        return false;
    }
    if (!is_array($setting)) {
        $setting = select('setting', '*') ?: [];
    }
    return invoice_auto_renew_volume_low($userData, $setting['volumewarn'] ?? 0)
        || invoice_auto_renew_time_low($userData, $setting['daywarn'] ?? 0, $invoice);
}

function invoice_auto_renew_volume_early(array $userData, $volumewarnGb): bool
{
    $dataLimit = (float) ($userData['data_limit'] ?? 0);
    if ($dataLimit <= 0) {
        return false;
    }
    $status = (string) ($userData['status'] ?? '');
    if (!in_array($status, ['active', 'Unknown'], true)) {
        return false;
    }
    $remaining = $dataLimit - (float) ($userData['used_traffic'] ?? 0);
    $oneX = ((float) $volumewarnGb) * pow(1024, 3);
    if ($oneX <= 0) {
        return false;
    }
    return $remaining <= ($oneX * 2) && $remaining > $oneX;
}

function invoice_auto_renew_time_early(array $userData, $daywarn): bool
{
    $expire = (float) ($userData['expire'] ?? 0);
    if ($expire <= 0) {
        return false;
    }
    $status = (string) ($userData['status'] ?? '');
    if (!in_array($status, ['active', 'Unknown'], true)) {
        return false;
    }
    $remaining = $expire - time();
    $oneX = ((int) $daywarn) * 86400;
    if ($oneX <= 0) {
        return false;
    }
    return $remaining <= ($oneX * 2) && $remaining > $oneX;
}

function invoice_auto_renew_in_early_window(array $invoice, array $userData, $setting = null): bool
{
    if (!invoice_auto_renew_is_on($invoice)) {
        return false;
    }
    if (invoice_auto_renew_should_run($invoice, $userData, $setting)) {
        return false;
    }
    if (!is_array($setting)) {
        $setting = select('setting', '*') ?: [];
    }
    return invoice_auto_renew_volume_early($userData, $setting['volumewarn'] ?? 0)
        || invoice_auto_renew_time_early($userData, $setting['daywarn'] ?? 0);
}

function invoice_auto_renew_wallet_prewarn(array $invoice, array $user, array $userData, $panel = null, $setting = null): void
{
    global $textbotlang;
    if (!invoice_auto_renew_in_early_window($invoice, $userData, $setting)) {
        invoice_auto_renew_clear_prewarn($invoice);
        return;
    }
    $notif = invoice_notifctions_decode($invoice);
    if (!empty($notif['auto_renew_prewarn'])) {
        return;
    }
    $freshUser = select('user', '*', 'id', $invoice['id_user'], 'select');
    if ($freshUser == false) {
        return;
    }
    $user = $freshUser;
    if (!is_array($panel)) {
        $panel = select('marzban_panel', '*', 'name_panel', $invoice['Service_location'] ?? '', 'select');
    }
    if ($panel == false || in_array($panel['type'] ?? '', ['ibsng', 'mikrotik'], true)) {
        return;
    }
    if (!extend_can_proceed($panel)['ok']) {
        return;
    }
    $product = invoice_similar_extend_product($invoice, $user);
    if ($product == false || !extend_can_proceed($panel, $product)['ok']) {
        return;
    }
    $price = invoice_auto_renew_final_price($product, $user);
    if (!invoice_auto_renew_balance_short($user, $price, (int) ($product['Volume_constraint'] ?? 0))) {
        return;
    }
    if (!is_array($textbotlang)) {
        $textbotlang = languagechange(__DIR__ . '/text.json');
    }
    $template = $textbotlang['users']['extend']['autorenew_prewarn']
        ?? 'سرویس %s دارای تمدید خودکار می باشد اما اعتبار کیف پول شما کافی نیست. برای تمدید موفق، لطفا کیف پول خود را به مقدار حداقل %s شارژ نمایید.';
    $message = sprintf($template, $invoice['username'] ?? '', number_format($price));
    $botToken = (!empty($invoice['bottype']) && $invoice['bottype'] !== '0') ? $invoice['bottype'] : null;
    sendmessage($invoice['id_user'], $message, null, 'HTML', $botToken);
    invoice_notifctions_patch($invoice, ['auto_renew_prewarn' => true]);
}

function invoice_last_paid_extend_row($username)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM service_other WHERE username = :username AND type IN ('extend_user', 'extend_user_by_admin') AND (status = 'paid' OR status IS NULL OR status = '') ORDER BY time DESC, id DESC LIMIT 1");
    $stmt->execute([':username' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function invoice_extend_time_to_ts($time): int
{
    $time = trim((string) $time);
    if ($time === '') {
        return 0;
    }
    if (ctype_digit($time) && strlen($time) >= 9) {
        return (int) $time;
    }
    $dt = DateTime::createFromFormat('Y/m/d H:i:s', $time);
    if ($dt instanceof DateTime) {
        return $dt->getTimestamp();
    }
    $parsed = strtotime(str_replace('/', '-', $time));
    return $parsed !== false ? (int) $parsed : 0;
}

function invoice_similar_extend_product(array $invoice, array $user)
{
    global $pdo;
    $panelName = $invoice['Service_location'] ?? '';
    $lastExtend = invoice_last_paid_extend_row($invoice['username'] ?? '');
    $lastValue = [];
    if ($lastExtend && is_string($lastExtend['value'] ?? null)) {
        $decoded = json_decode($lastExtend['value'], true);
        if (is_array($decoded)) {
            $lastValue = $decoded;
        }
    }
    $codeProduct = (string) ($lastValue['code_product'] ?? '');
    if (invoice_is_custom_volume_product($invoice, $codeProduct)) {
        $panel = select('marzban_panel', '*', 'name_panel', $panelName, 'select');
        if ($panel == false) {
            return false;
        }
        $volume = (int) ($lastValue['volumebuy'] ?? $invoice['Volume'] ?? 0);
        $days = (int) ($lastValue['Service_time'] ?? $invoice['Service_time'] ?? 0);
        $price = panel_custom_service_price_for_user($panel, $user, $volume, $days);
        if ($price === null) {
            $price = (int) ($invoice['price_product'] ?? 0);
        }
        return [
            'code_product' => 'custom_volume',
            'name_product' => $invoice['name_product'],
            'Service_time' => $days,
            'Volume_constraint' => $volume,
            'price_product' => $price,
            'note' => '',
        ];
    }
    if ($codeProduct === '') {
        $named = select('product', '*', 'name_product', $invoice['name_product'] ?? '', 'select');
        if ($named == false && agent_is_n2($user['agent'] ?? 'f')) {
            $ownList = agent_own_list_products($user['id'] ?? '');
            foreach ($ownList as $ownRow) {
                if (($ownRow['name_product'] ?? '') === ($invoice['name_product'] ?? '')) {
                    $named = $ownRow;
                    break;
                }
            }
        }
        if ($named == false) {
            return false;
        }
        $codeProduct = (string) ($named['code_product'] ?? '');
    }
    if ($codeProduct === '') {
        return false;
    }
    $sql = agent_product_select_sql($user['agent'] ?? 'f', $user['id'] ?? '', "(Location = :service_location OR Location = '/all') AND code_product = :code_product") . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':service_location' => $panelName,
        ':code_product' => $codeProduct,
    ]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($product == false) {
        return false;
    }
    if (($user['agent'] ?? '') === 'n') {
        $product['price_product'] = agent_wholesale_cost($user, (int) ($product['Volume_constraint'] ?? 0));
    }
    return $product;
}

/**
 * Attempt wallet auto-renew with the similar package.
 * @return string renewed|insufficient|cooldown|skipped
 */
function invoice_try_auto_renew(array $invoice, array $user, array $userData, $panel = null, $setting = null): string
{
    global $pdo, $textbotlang;
    if (!invoice_auto_renew_is_on($invoice)) {
        return 'skipped';
    }
    if (!is_array($setting)) {
        $setting = select('setting', '*') ?: [];
    }
    if (!is_array($panel)) {
        $panel = select('marzban_panel', '*', 'name_panel', $invoice['Service_location'] ?? '', 'select');
    }
    if ($panel == false) {
        return 'skipped';
    }
    if (in_array($panel['type'] ?? '', ['ibsng', 'mikrotik'], true)) {
        return 'skipped';
    }
    $extendGate = extend_can_proceed($panel);
    if (!$extendGate['ok']) {
        return 'skipped';
    }
    $lastExtend = invoice_last_paid_extend_row($invoice['username'] ?? '');
    if ($lastExtend) {
        $lastTs = invoice_extend_time_to_ts($lastExtend['time'] ?? '');
        if ($lastTs > 0 && (time() - $lastTs) < 7200) {
            return 'cooldown';
        }
    }
    $freshUser = select('user', '*', 'id', $invoice['id_user'], 'select');
    if ($freshUser == false) {
        return 'skipped';
    }
    $user = $freshUser;
    $product = invoice_similar_extend_product($invoice, $user);
    if ($product == false) {
        return 'skipped';
    }
    $extendGate = extend_can_proceed($panel, $product);
    if (!$extendGate['ok']) {
        return 'skipped';
    }
    $price = invoice_auto_renew_final_price($product, $user);
    $agent = (string) ($user['agent'] ?? 'f');
    $balance = (int) ($user['Balance'] ?? 0);
    $notEnough = invoice_auto_renew_balance_short($user, $price, (int) ($product['Volume_constraint'] ?? 0));
    if (!is_array($textbotlang)) {
        $textbotlang = languagechange(__DIR__ . '/text.json');
    }
    $insufficientText = $textbotlang['users']['extend']['autorenew_insufficient'] ?? 'موجودی کیف پول شما برای تمدید خودکار کافی نیست';
    $botToken = (!empty($invoice['bottype']) && $invoice['bottype'] !== '0') ? $invoice['bottype'] : null;
    if ($notEnough) {
        $notif = invoice_notifctions_decode($invoice);
        if (empty($notif['auto_renew_insufficient'])) {
            sendmessage($invoice['id_user'], $insufficientText, null, 'HTML', $botToken);
            invoice_notifctions_patch($invoice, ['auto_renew_insufficient' => true]);
        }
        return 'insufficient';
    }
    $ManagePanel = invoice_panel_manager();
    $extend = $ManagePanel->extend(
        $panel['Methodextend'],
        $product['Volume_constraint'],
        $product['Service_time'],
        $invoice['username'],
        $product['code_product'],
        $panel['code_panel']
    );
    if (($extend['status'] ?? false) == false) {
        $extendMsg = json_encode($extend['msg'] ?? $extend, JSON_UNESCAPED_UNICODE);
        $textreports = "خطای تمدید خودکار سرویس
نام پنل : {$panel['name_panel']}
نام کاربری سرویس : {$invoice['username']}
دلیل خطا : {$extendMsg}";
        $errorreportRow = select('topicid', 'idreport', 'report', 'errorreport', 'select');
        $errorreport = is_array($errorreportRow) ? ($errorreportRow['idreport'] ?? null) : null;
        if (strlen($setting['Channel_Report'] ?? '') > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $errorreport,
                'text' => $textreports,
                'parse_mode' => 'HTML',
            ]);
        }
        return 'skipped';
    }
    if ($agent === 'f') {
        $cashbackRow = select('shopSetting', '*', 'Namevalue', 'chashbackextend', 'select');
        $cashback = is_array($cashbackRow) ? ($cashbackRow['value'] ?? 0) : 0;
    } else {
        $cashbackRow = select('shopSetting', '*', 'Namevalue', 'chashbackextend_agent', 'select');
        $cashbackJson = is_array($cashbackRow) ? ($cashbackRow['value'] ?? '') : '';
        $cashbackMap = json_decode($cashbackJson, true);
        $cashback = is_array($cashbackMap) ? ($cashbackMap[$agent] ?? 0) : 0;
    }
    if (intval($cashback) != 0 && $price != 0) {
        $gift = ((int) ($product['price_product'] ?? 0) * intval($cashback)) / 100;
        $price = (int) round($price - $gift);
        if ($price < 0) {
            $price = 0;
        }
    }
    update('user', 'Balance', $balance - $price, 'id', $user['id']);
    $randomString = bin2hex(random_bytes(2));
    $value = json_encode([
        'volumebuy' => $product['Volume_constraint'],
        'Service_time' => $product['Service_time'],
        'oldvolume' => $userData['data_limit'] ?? null,
        'oldtime' => $userData['expire'] ?? null,
        'code_product' => $product['code_product'],
        'id_order' => $randomString,
        'auto_renew' => true,
    ], JSON_UNESCAPED_UNICODE);
    $dateacc = date('Y/m/d H:i:s');
    $extendJson = json_encode($extend);
    $type = 'extend_user';
    $status = 'paid';
    $stmt = $pdo->prepare('INSERT IGNORE INTO service_other (id_user, username, value, type, time, price, output, status) VALUES (:id_user, :username, :value, :type, :time, :price, :output, :status)');
    $stmt->execute([
        ':id_user' => $user['id'],
        ':username' => $invoice['username'],
        ':value' => $value,
        ':type' => $type,
        ':time' => $dateacc,
        ':price' => $price,
        ':output' => $extendJson,
        ':status' => $status,
    ]);
    update('invoice', 'Status', 'active', 'id_invoice', $invoice['id_invoice']);
    pay_affiliate_commission($user, $price, 'extend');
    $priceFormat = number_format($price);
    $success = sprintf(
        $textbotlang['users']['extend']['autorenew_success'] ?? "✅ سرویس %s به‌صورت خودکار تمدید شد.\n\n🛍 بسته: %s\n💸 مبلغ کسر شده: %s تومان",
        $invoice['username'],
        $product['name_product'],
        $priceFormat
    );
    sendmessage($invoice['id_user'], $success, null, 'HTML', $botToken);
    $balanceAfterRow = select('user', 'Balance', 'id', $user['id'], 'select');
    $balanceAfter = number_format((int) (is_array($balanceAfterRow) ? ($balanceAfterRow['Balance'] ?? 0) : 0));
    $balanceBefore = number_format($balance);
    $timeText = function_exists('jdate') ? jdate('Y/m/d H:i:s') : $dateacc;
    $text_report = "📣 تمدید خودکار سرویس ثبت شد.

▫️آیدی عددی کاربر : <code>{$user['id']}</code>
▫️نام کاربری کانفیگ : {$invoice['username']}
▫️موقعیت سرویس : {$invoice['Service_location']}
▫️نام محصول : {$product['name_product']}
▫️حجم محصول : {$product['Volume_constraint']}
▫️زمان محصول : {$product['Service_time']}
▫️مبلغ تمدید : {$priceFormat} تومان
▫️موجودی قبل : {$balanceBefore} تومان
▫️موجودی بعد : {$balanceAfter} تومان
▫️زمان : {$timeText}";
    $otherserviceRow = select('topicid', 'idreport', 'report', 'otherservice', 'select');
    $otherservice = is_array($otherserviceRow) ? ($otherserviceRow['idreport'] ?? null) : null;
    if (strlen($setting['Channel_Report'] ?? '') > 0) {
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $otherservice,
            'text' => $text_report,
            'parse_mode' => 'HTML',
        ]);
    }
    return 'renewed';
}

function invoice_panel_manager(): ManagePanel
{
    global $ManagePanel;
    if (isset($ManagePanel) && $ManagePanel instanceof ManagePanel) {
        return $ManagePanel;
    }
    return new ManagePanel();
}

/** Load category saved during buy flow (categorynames_*). */
function category_from_processing($userdate)
{
    if (!is_array($userdate)) {
        return null;
    }
    if (!empty($userdate['own_category_id'])) {
        $own = agent_own_get_category_by_id($userdate['own_category_id']);
        return $own ?: null;
    }
    if (empty($userdate['category_id'])) {
        return null;
    }
    $category = select("category", "*", "id", $userdate['category_id'], "select");
    if ($category) {
        return $category;
    }
    return agent_own_get_category_by_id($userdate['category_id']);
}

/** Active panels visible to this agent (same filter as the buy location list). */
function purchase_agent_panel_count(string $agent): int
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM marzban_panel WHERE status = 'active' AND (agent = :agent OR agent = 'all')");
    $stmt->execute([':agent' => $agent]);
    return (int) $stmt->fetchColumn();
}

/**
 * Inline callback for Back on the product list during buy.
 * After a category is chosen, Back must reopen the category list instead of closing the message.
 */
function purchase_products_back_callback(array $userdate, string $agent = 'f'): string
{
    global $setting, $statusnote;

    $categoriesOn = ($setting['statuscategorygenral'] ?? '') === 'oncategorys';
    $monthsOn = ($setting['statuscategory'] ?? '') !== 'offcategory';
    $singlePanel = purchase_agent_panel_count($agent) <= 1;

    if ($categoriesOn && !empty($userdate['monthproduct'])) {
        return 'productmonth_' . $userdate['monthproduct'];
    }
    if ($categoriesOn || $monthsOn) {
        return $singlePanel ? 'buybacktow' : 'backproduct';
    }
    if ($singlePanel) {
        return !empty($statusnote) ? 'buyback' : 'backuser';
    }
    return isset($userdate['nameconfig']) ? 'buybacktow' : 'buyback';
}

/** Inline callback for Back on the pre-invoice / username step — return to the product list. */
function purchase_invoice_back_callback(array $userdate, string $agent = 'f'): string
{
    if (!empty($userdate['category_id'])) {
        return 'categorynames_' . $userdate['category_id'];
    }
    if (!empty($userdate['monthproduct'])) {
        return 'productmonth_' . $userdate['monthproduct'];
    }
    if (!empty($userdate['name_panel'])) {
        return 'backproduct';
    }
    return purchase_products_back_callback($userdate, $agent);
}

function outtypepanel($typepanel, $message)
{
    global $from_id, $optionMarzban, $optionX_ui_single, $optionhiddfy, $optionalireza, $optionalireza_single, $optionmarzneshin, $option_mikrotik, $optionwg, $options_ui, $optioneylanpanel, $optionibsng;
    if ($typepanel == "marzban") {
        sendmessage($from_id, $message, $optionMarzban, 'HTML');
    } elseif ($typepanel == "x-ui_single") {
        sendmessage($from_id, $message, $optionX_ui_single, 'HTML');
    } elseif ($typepanel == "hiddify") {
        sendmessage($from_id, $message, $optionhiddfy, 'HTML');
    } elseif ($typepanel == "alireza_single") {
        sendmessage($from_id, $message, $optionalireza_single, 'HTML');
    } elseif ($typepanel == "marzneshin") {
        sendmessage($from_id, $message, $optionmarzneshin, 'HTML');
    } elseif ($typepanel == "WGDashboard") {
        sendmessage($from_id, $message, $optionwg, 'HTML');
    } elseif ($typepanel == "s_ui") {
        sendmessage($from_id, $message, $options_ui, 'HTML');
    } elseif ($typepanel == "ibsng") {
        sendmessage($from_id, $message, $optionibsng, 'HTML');
    } elseif ($typepanel == "mikrotik") {
        sendmessage($from_id, $message, $option_mikrotik, 'HTML');
    }
}

function addBackgroundImage($urlimage, $qrCodeResult, $backgroundPath)
{
    if (!file_exists($backgroundPath)) {
        error_log("addBackgroundImage: File not found at $backgroundPath");
        file_put_contents($urlimage, $qrCodeResult->getString());
        return;
    }

    $qrString = $qrCodeResult->getString();
    $qrCodeImage = imagecreatefromstring($qrString);
    if (!$qrCodeImage) {
        error_log("addBackgroundImage: Failed to create QR Code resource");
        return;
    }

    $backgroundImage = null;

    try {
        $backgroundImage = imagecreatefromjpeg($backgroundPath);
    } catch (Throwable $t) {
        error_log("addBackgroundImage::EXCEPTION loading image: " . $t->getMessage());
    }

    if (!$backgroundImage) {
        $lastError = error_get_last();
        error_log("addBackgroundImage::System Error: " . $lastError['message']);

        imagepng($qrCodeImage, $urlimage);
        imagedestroy($qrCodeImage);
        return;
    }

    $qrCodeWidth = imagesx($qrCodeImage);
    $qrCodeHeight = imagesy($qrCodeImage);
    $backgroundWidth = imagesx($backgroundImage);
    $backgroundHeight = imagesy($backgroundImage);

    $x = ($backgroundWidth - $qrCodeWidth) / 2;
    $y = ($backgroundHeight - $qrCodeHeight) / 2;

    imagecopy($backgroundImage, $qrCodeImage, $x, $y, 0, 0, $qrCodeWidth, $qrCodeHeight);

    imagepng($backgroundImage, $urlimage);

    imagedestroy($qrCodeImage);
    imagedestroy($backgroundImage);
}

function resolveTelegramClientIp()
{
    $candidates = [];

    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidates[] = $_SERVER['HTTP_X_REAL_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidates[] = trim($parts[0]);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = $_SERVER['REMOTE_ADDR'];
    }

    foreach ($candidates as $ip) {
        $ip = trim((string) $ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

function checktelegramip()
{
    $clientIp = resolveTelegramClientIp();
    if ($clientIp === '') {
        return false;
    }

    global $telegram_polling_mode;
    if (!empty($telegram_polling_mode) && in_array($clientIp, ['127.0.0.1', '::1'], true)) {
        return true;
    }

    $telegramIpRanges = [
        ['lower' => '149.154.160.0', 'upper' => '149.154.175.255'],
        ['lower' => '91.108.4.0', 'upper' => '91.108.7.255'],
        ['lower' => '2001:67c:4e8::', 'upper' => '2001:67c:4e8:ffff:ffff:ffff:ffff:ffff']
    ];

    foreach ($telegramIpRanges as $range) {
        if (isClientIpInRange($clientIp, $range['lower'], $range['upper'])) {
            return true;
        }
    }

    return false;
}

function isClientIpInRange($clientIp, $lowerBound, $upperBound)
{
    $clientPacked = inet_pton($clientIp);
    $lowerPacked = inet_pton($lowerBound);
    $upperPacked = inet_pton($upperBound);

    if ($clientPacked === false || $lowerPacked === false || $upperPacked === false) {
        return false;
    }

    $length = strlen($clientPacked);
    if ($length !== strlen($lowerPacked) || $length !== strlen($upperPacked)) {
        return false;
    }

    return strcmp($clientPacked, $lowerPacked) >= 0 && strcmp($clientPacked, $upperPacked) <= 0;
}
function addCronIfNotExists($cronCommand, $removePatterns = [])
{
    $commands = is_array($cronCommand) ? $cronCommand : [$cronCommand];
    $commands = array_values(array_filter(array_map('trim', $commands), static function ($command) {
        return $command !== '';
    }));
    $removePatterns = is_array($removePatterns) ? $removePatterns : [$removePatterns];
    $removePatterns = array_values(array_filter(array_map('trim', $removePatterns), static function ($pattern) {
        return $pattern !== '';
    }));

    if (empty($commands) && empty($removePatterns)) {
        return true;
    }

    $logContext = implode('; ', $commands);

    if (!isShellExecAvailable()) {
        error_log('shell_exec is not available; unable to register cron job(s): ' . $logContext);
        return false;
    }

    $crontabBinary = getCrontabBinary();
    if ($crontabBinary === null) {
        error_log('crontab executable not found; unable to register cron job(s): ' . $logContext);
        return false;
    }

    $existingCronJobs = runShellCommand(sprintf('%s -l 2>/dev/null', escapeshellarg($crontabBinary)));
    $existingCronJobs = trim((string) $existingCronJobs);
    $cronLines = $existingCronJobs === '' ? [] : preg_split('/\r?\n/', $existingCronJobs);
    $cronLines = array_values(array_filter(array_map('trim', $cronLines), static function ($line) {
        return $line !== '' && strpos($line, '#') !== 0;
    }));

    $changed = false;
    if (!empty($removePatterns)) {
        $filtered = [];
        foreach ($cronLines as $line) {
            $shouldRemove = false;
            foreach ($removePatterns as $pattern) {
                if (strpos($line, $pattern) !== false) {
                    $shouldRemove = true;
                    break;
                }
            }
            if ($shouldRemove) {
                $changed = true;
                continue;
            }
            $filtered[] = $line;
        }
        $cronLines = $filtered;
    }

    foreach ($commands as $command) {
        if (!in_array($command, $cronLines, true)) {
            $cronLines[] = $command;
            $changed = true;
        }
    }

    if (!$changed) {
        return true;
    }

    $cronLines = array_values(array_unique($cronLines));
    $cronContent = implode(PHP_EOL, $cronLines) . PHP_EOL;

    $temporaryFile = tempnam(sys_get_temp_dir(), 'cron');
    if ($temporaryFile === false) {
        error_log('Unable to create temporary file for cron job registration.');
        return false;
    }

    if (file_put_contents($temporaryFile, $cronContent) === false) {
        error_log('Unable to write cron configuration to temporary file: ' . $temporaryFile);
        unlink($temporaryFile);
        return false;
    }

    runShellCommand(sprintf('%s %s', escapeshellarg($crontabBinary), escapeshellarg($temporaryFile)));
    unlink($temporaryFile);

    return true;
}

function activecron()
{
    $php = '/usr/bin/php';
    if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY) && strpos(PHP_BINARY, 'php-fpm') === false) {
        $php = PHP_BINARY;
    }
    $cronDir = __DIR__ . '/cronbot';
    $flock = '/usr/bin/flock';

    $job = static function (string $schedule, string $script) use ($php, $cronDir, $flock): string {
        $lock = '/tmp/mirza.' . basename($script, '.php') . '.lock';
        return $schedule . ' cd ' . $cronDir . ' && ' . $flock . ' -n ' . $lock . ' ' . $php . ' ' . $script;
    };

    $cronCommands = [
        $job('45 23 * * *', 'statusday.php'),
        $job('*/1 * * * *', 'croncard.php'),
        $job('*/5 * * * *', 'NoticationsService.php'),
        $job('0 * * * *', 'payment_expire.php'),
        $job('*/1 * * * *', 'sendmessage.php'),
        $job('*/5 * * * *', 'activeconfig.php'),
        $job('*/5 * * * *', 'disableconfig.php'),
        $job('0 */5 * * *', 'backupbot.php'),
        $job('*/1 * * * *', 'gift.php'),
        $job('*/30 * * * *', 'expireagent.php'),
        $job('*/15 * * * *', 'on_hold.php'),
        $job('*/2 * * * *', 'configtest.php'),
        $job('*/15 * * * *', 'uptime_node.php'),
        $job('*/15 * * * *', 'uptime_panel.php'),
    ];

    // Replace curl/old schedules and drop unused payment crons (plisio, iranpay1).
    addCronIfNotExists($cronCommands, [
        'statusday.php',
        'croncard.php',
        'NoticationsService.php',
        'payment_expire.php',
        'sendmessage.php',
        'plisio.php',
        'activeconfig.php',
        'disableconfig.php',
        'iranpay1.php',
        'backupbot.php',
        'gift.php',
        'expireagent.php',
        'on_hold.php',
        'configtest.php',
        'uptime_node.php',
        'uptime_panel.php',
    ]);
}
function createInvoice($amount)
{
    global $from_id, $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "apiiranpay", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];

    $curl = curl_init();
    curl_disable_proxy($curl);

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://pay.melorinabeauty.com/api/factor/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array('amount' => $amount, 'address' => $walletaddress, 'base' => 'trx'),
        CURLOPT_HTTPHEADER => array(
            'Authorization: Token ' . $PaySetting
        ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    return json_decode($response, true);
}
function verifpay($id)
{
    global $from_id, $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "apiiranpay", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://pay.melorinabeauty.ir/api/factor/status?id=' . $id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Token ' . $PaySetting
        ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    return $response;
}
function createInvoiceiranpay1($amount, $id_invoice)
{
    global $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "marchent_floypay", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);
    $amount = intval($amount);
    $data = [
        "ApiKey" => $PaySetting,
        "Hash_id" => $id_invoice,
        "Amount" => $amount . "0",
        "CallbackURL" => "https://$domainhosts/payment/iranpay1.php"
    ];
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://tetra98.com/api/create_order",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => array(
            'accept: application/json',
            'Content-Type: application/json'
        ),
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function createInvoiceTetraminator($price, $order_id)
{
    global $domainhosts, $tetraminator_api_key;
    $curl = curl_init();
    curl_disable_proxy($curl);
    $price = intval($price);
    $data = [
        "price" => $price,
        "callback_url" => "https://$domainhosts/payment/tetraminator.php?order_id=" . urlencode($order_id)
    ];
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.tetraminator.com/v1/invoice/create",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: ' . $tetraminator_api_key
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function inquireTetraminatorPayment($pay_id)
{
    global $tetraminator_api_key;
    $pay_id = rawurlencode($pay_id);
    $curl = curl_init();
    curl_disable_proxy($curl);
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.tetraminator.com/v1/payment/inquiry/" . $pay_id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'X-API-KEY: ' . $tetraminator_api_key
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function sanitizeUserName($userName)
{
    $forbiddenCharacters = [
        "'",
        "\"",
        "<",
        ">",
        "--",
        "#",
        ";",
        "\\",
        "%",
        "(",
        ")"
    ];

    foreach ($forbiddenCharacters as $char) {
        $userName = str_replace($char, "", $userName);
    }

    return $userName;
}
function publickey()
{
    $privateKey = sodium_crypto_box_keypair();
    $privateKeyEncoded = base64_encode(sodium_crypto_box_secretkey($privateKey));
    $publicKey = sodium_crypto_box_publickey($privateKey);
    $publicKeyEncoded = base64_encode($publicKey);
    $presharedKey = base64_encode(random_bytes(32));
    return [
        'private_key' => $privateKeyEncoded,
        'public_key' => $publicKeyEncoded,
        'preshared_key' => $presharedKey
    ];
}
function languagechange($path_dir)
{
    if (!is_string($path_dir) || $path_dir === '') {
        return [];
    }
    if ($path_dir[0] !== '/' && !preg_match('#^[A-Za-z]:[/\\\\]#', $path_dir)) {
        $path_dir = __DIR__ . '/' . ltrim($path_dir, './');
    }

    $raw = @file_get_contents($path_dir);
    if ($raw === false) {
        error_log('languagechange: cannot read ' . $path_dir);
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_log('languagechange: invalid JSON in ' . $path_dir);
        return [];
    }

    $setting = select("setting", "*");
    if (intval($setting['languageen'] ?? 0) === 1 && isset($decoded['en'])) {
        return $decoded['en'];
    }
    if (intval($setting['languageru'] ?? 0) === 1 && isset($decoded['ru'])) {
        return $decoded['ru'];
    }
    return $decoded['fa'] ?? $decoded['en'] ?? [];
}
function generateAuthStr($length = 10)
{
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    return substr(str_shuffle(str_repeat($characters, ceil($length / strlen($characters)))), 0, $length);
}
function createqrcode($contents)
{
    $builder = new Builder(
        writer: new PngWriter(),
        writerOptions: [],
        data: $contents,
        encoding: new Encoding('UTF-8'),
        errorCorrectionLevel: ErrorCorrectionLevel::High,
        size: 500,
        margin: 10,
    );

    $result = $builder->build();
    return $result;
}
function sanitize_recursive(array $data): array
{
    $sanitized_data = [];
    foreach ($data as $key => $value) {
        $sanitized_key = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        if (is_array($value)) {
            $sanitized_data[$sanitized_key] = sanitize_recursive($value);
        } elseif (is_string($value)) {
            $sanitized_data[$sanitized_key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        } elseif (is_int($value)) {
            $sanitized_data[$sanitized_key] = filter_var($value, FILTER_SANITIZE_NUMBER_INT);
        } elseif (is_float($value)) {
            $sanitized_data[$sanitized_key] = filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        } elseif (is_bool($value) || is_null($value)) {
            $sanitized_data[$sanitized_key] = $value;
        } else {
            $sanitized_data[$sanitized_key] = $value;
        }
    }
    return $sanitized_data;
}

function get_main_keyboard_button_ids()
{
    $ids = [];
    foreach (get_default_main_keyboard_layout() as $row) {
        $ids = array_merge($ids, $row);
    }
    return array_values(array_unique($ids));
}

function keyboardmain_label_to_id_map($datatextbot = null)
{
    if (!is_array($datatextbot)) {
        $datatextbot = [];
        foreach (select("textbot", "*", null, null, "fetchAll") as $row) {
            $datatextbot[$row['id_text']] = $row['text'];
        }
    }
    $map = [];
    foreach (get_main_keyboard_button_ids() as $id) {
        $map[$id] = $id;
        if (!empty($datatextbot[$id])) {
            $map[$datatextbot[$id]] = $id;
        }
    }
    return $map;
}

function resolve_main_keyboard_button_id($text, $datatextbot = null)
{
    if ($text === '' || $text === null) {
        return null;
    }
    $map = keyboardmain_label_to_id_map($datatextbot);
    return $map[$text] ?? null;
}

function normalize_keyboardmain_to_ids($keyboardmain_json, $datatextbot = null)
{
    $layout = json_decode($keyboardmain_json, true);
    if (!is_array($layout) || empty($layout['keyboard']) || !is_array($layout['keyboard'])) {
        return get_default_main_keyboard_json();
    }
    $rows = [];
    $seen = [];
    foreach ($layout['keyboard'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $new_row = [];
        foreach ($row as $btn) {
            $id = resolve_main_keyboard_button_id($btn['text'] ?? '', $datatextbot);
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $new_row[] = ['text' => $id];
        }
        if ($new_row !== []) {
            $rows[] = $new_row;
        }
    }
    if ($rows === []) {
        return get_default_main_keyboard_json();
    }
    $full_width = [];
    if (array_key_exists('full_width', $layout) && is_array($layout['full_width'])) {
        foreach ($layout['full_width'] as $id) {
            if (isset($seen[$id]) && !in_array($id, $full_width, true)) {
                $full_width[] = $id;
            }
        }
    } elseif (isset($seen['text_help'])) {
        // Legacy layouts had no explicit width metadata; only Help was full-width by default.
        $full_width[] = 'text_help';
    }
    return json_encode([
        'keyboard' => $rows,
        'full_width' => $full_width,
    ], JSON_UNESCAPED_UNICODE);
}

function check_active_btn($keyboard, $text_var, $datatextbot = null)
{
    $active = get_active_main_keyboard_buttons($keyboard, $datatextbot);
    return in_array($text_var, $active, true);
}

function get_default_main_keyboard_layout()
{
    return [
        ['text_sell', 'text_extend'],
        ['text_usertest', 'text_wheel_luck'],
        ['text_Purchased_services', 'accountwallet'],
        ['text_affiliates', 'text_Tariff_list'],
        ['text_referral', 'text_support'],
        ['text_help'],
    ];
}

function get_default_main_keyboard_json()
{
    $keyboard = [
        'keyboard' => [],
        'full_width' => ['text_help'],
    ];
    foreach (get_default_main_keyboard_layout() as $row) {
        $row_buttons = [];
        foreach ($row as $btn) {
            $row_buttons[] = ['text' => $btn];
        }
        $keyboard['keyboard'][] = $row_buttons;
    }
    return json_encode($keyboard);
}

function get_active_main_keyboard_buttons($keyboardmain_json, $datatextbot = null)
{
    $layout = json_decode($keyboardmain_json, true);
    $active = [];
    if (!empty($layout['keyboard']) && is_array($layout['keyboard'])) {
        foreach ($layout['keyboard'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $btn) {
                $id = resolve_main_keyboard_button_id($btn['text'] ?? '', $datatextbot);
                if ($id !== null) {
                    $active[] = $id;
                }
            }
        }
    }
    return array_values(array_unique($active));
}

function pack_main_keyboard_buttons(array $button_ids, $per_row = 2)
{
    $allowed = get_main_keyboard_button_ids();
    $ordered = [];
    foreach ($button_ids as $btn) {
        if (in_array($btn, $allowed, true) && !in_array($btn, $ordered, true)) {
            $ordered[] = $btn;
        }
    }
    $keyboard = ['keyboard' => []];
    $row = [];
    foreach ($ordered as $btn) {
        $row[] = ['text' => $btn];
        if (count($row) >= $per_row) {
            $keyboard['keyboard'][] = $row;
            $row = [];
        }
    }
    if ($row !== []) {
        $keyboard['keyboard'][] = $row;
    }
    return json_encode($keyboard, JSON_UNESCAPED_UNICODE);
}

function encode_main_keyboard_rows(array $rows, array $full_width = [])
{
    $keyboard = [
        'keyboard' => [],
        'full_width' => [],
    ];
    $included = [];
    foreach ($rows as $row) {
        if (!is_array($row) || $row === []) {
            continue;
        }
        $encoded_row = [];
        foreach ($row as $id) {
            if ($id === '' || $id === null) {
                continue;
            }
            $encoded_row[] = ['text' => $id];
            $included[$id] = true;
        }
        if ($encoded_row !== []) {
            $keyboard['keyboard'][] = $encoded_row;
        }
    }
    if ($keyboard['keyboard'] === []) {
        return get_default_main_keyboard_json();
    }
    foreach ($full_width as $id) {
        if (isset($included[$id]) && !in_array($id, $keyboard['full_width'], true)) {
            $keyboard['full_width'][] = $id;
        }
    }
    return json_encode($keyboard, JSON_UNESCAPED_UNICODE);
}

function get_main_keyboard_rows_as_ids($keyboardmain_json, $datatextbot = null)
{
    $keyboardmain_json = normalize_keyboardmain_to_ids($keyboardmain_json, $datatextbot);
    $layout = json_decode($keyboardmain_json, true);
    $rows = [];
    if (!is_array($layout) || empty($layout['keyboard']) || !is_array($layout['keyboard'])) {
        return $rows;
    }
    foreach ($layout['keyboard'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ids = [];
        foreach ($row as $btn) {
            $id = $btn['text'] ?? '';
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if ($ids !== []) {
            $rows[] = $ids;
        }
    }
    return $rows;
}

function get_main_keyboard_solo_button_ids($keyboardmain_json, $datatextbot = null)
{
    $keyboardmain_json = normalize_keyboardmain_to_ids($keyboardmain_json, $datatextbot);
    $layout = json_decode($keyboardmain_json, true);
    return is_array($layout) && !empty($layout['full_width']) && is_array($layout['full_width'])
        ? array_values(array_unique($layout['full_width']))
        : [];
}

function rebuild_main_keyboard_layout(array $ordered_ids, array $solo_ids)
{
    $allowed = get_main_keyboard_button_ids();
    $ordered = [];
    foreach ($ordered_ids as $id) {
        if (in_array($id, $allowed, true) && !in_array($id, $ordered, true)) {
            $ordered[] = $id;
        }
    }
    if ($ordered === []) {
        return get_default_main_keyboard_json();
    }
    $solo_map = [];
    foreach ($solo_ids as $id) {
        if (in_array($id, $ordered, true)) {
            $solo_map[$id] = true;
        }
    }
    $rows = [];
    $pending = [];
    foreach ($ordered as $id) {
        if (isset($solo_map[$id])) {
            if ($pending !== []) {
                $rows[] = $pending;
                $pending = [];
            }
            $rows[] = [$id];
            continue;
        }
        $pending[] = $id;
        if (count($pending) >= 2) {
            $rows[] = $pending;
            $pending = [];
        }
    }
    if ($pending !== []) {
        $rows[] = $pending;
    }
    return encode_main_keyboard_rows($rows, array_keys($solo_map));
}

function build_keyboardmain_from_active_buttons($active_buttons)
{
    // Preserve caller order when packing; empty input falls back to default layout.
    if (!is_array($active_buttons) || $active_buttons === []) {
        return get_default_main_keyboard_json();
    }
    return pack_main_keyboard_buttons($active_buttons, 2);
}

function get_main_keyboard_button_fallback_labels()
{
    return [
        'text_sell' => '🔐 خرید اشتراک',
        'text_extend' => '♻️ تمدید سرویس',
        'text_usertest' => '🔑 اکانت تست',
        'text_wheel_luck' => '🎲 گردونه شانس',
        'text_Purchased_services' => '🛍 سرویس های من',
        'accountwallet' => '🏦 کیف پول + شارژ',
        'text_affiliates' => '👥 زیر مجموعه گیری',
        'text_referral' => '🎁 دعوت دوستان',
        'text_Tariff_list' => '💵 تعرفه اشتراک ها',
        'text_support' => '☎️ پشتیبانی',
        'text_help' => '📚 آموزش',
    ];
}

function is_main_keyboard_internal_id($text)
{
    return in_array($text, get_main_keyboard_button_ids(), true);
}

function get_main_keyboard_button_label($button_id, $datatextbot)
{
    $fallbacks = get_main_keyboard_button_fallback_labels();
    $fallback = $fallbacks[$button_id] ?? $button_id;

    if (!is_array($datatextbot) || empty($datatextbot[$button_id])) {
        return $fallback;
    }

    $label = trim((string) $datatextbot[$button_id]);
    if ($label === '' || is_main_keyboard_internal_id($label)) {
        return $fallback;
    }

    // Menu buttons must stay short; long/multi-line textbot entries are message copy, not labels.
    if (str_contains($label, "\n") || mb_strlen($label) > 32) {
        return $fallback;
    }

    return $label;
}

function user_text_matches_main_button($text, $button_id, $datatextbot)
{
    if ($text === '' || $text === null) {
        return false;
    }

    $candidates = [
        $button_id,
        get_main_keyboard_button_label($button_id, $datatextbot),
        get_main_keyboard_button_fallback_labels()[$button_id] ?? '',
    ];

    if (is_array($datatextbot) && !empty($datatextbot[$button_id])) {
        $raw = trim((string) $datatextbot[$button_id]);
        if ($raw !== '') {
            $candidates[] = $raw;
            $first_line = trim(strtok($raw, "\n"));
            if ($first_line !== '') {
                $candidates[] = $first_line;
            }
        }
    }

    $candidates = array_values(array_unique(array_filter($candidates, static function ($value) {
        return $value !== '';
    })));

    return in_array($text, $candidates, true);
}

function attach_main_keyboard_inline_callbacks($keyboard_rows)
{
    $callback_map = [
        'text_sell' => 'buy',
        'accountwallet' => 'account',
        'text_Tariff_list' => 'Tariff_list',
        'text_wheel_luck' => 'wheel_luck',
        'text_affiliates' => 'affiliatesbtn',
        'text_referral' => 'referralbtn',
        'text_extend' => 'extendbtn',
        'text_support' => 'supportbtns',
        'text_Purchased_services' => 'backorder',
        'text_help' => 'helpbtns',
        'text_usertest' => 'usertestbtn',
    ];
    $rows = [];
    foreach ($keyboard_rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $new_row = [];
        foreach ($row as $button) {
            if (!is_array($button)) {
                continue;
            }
            $new_button = $button;
            $button_id = $button['text'] ?? '';
            if (isset($callback_map[$button_id])) {
                $new_button['callback_data'] = $callback_map[$button_id];
            }
            $new_row[] = $new_button;
        }
        if ($new_row !== []) {
            $rows[] = $new_row;
        }
    }
    return $rows;
}

function apply_main_keyboard_button_labels($keyboard_rows, $datatextbot, $styles = null, $icons = null)
{
    if (!is_array($styles)) {
        $styles = get_main_keyboard_button_styles();
    }
    if (!is_array($icons)) {
        $icons = get_main_keyboard_button_icons();
    }
    $labeled = [];
    foreach ($keyboard_rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $labeled_row = [];
        foreach ($row as $button) {
            if (!is_array($button)) {
                continue;
            }
            $button_id = resolve_main_keyboard_button_id($button['text'] ?? '', $datatextbot);
            if ($button_id === null) {
                continue;
            }
            $label = get_main_keyboard_button_label($button_id, $datatextbot);
            if ($label === '') {
                continue;
            }
            $new_button = $button;
            $new_button['text'] = $label;
            unset($new_button['style'], $new_button['icon_custom_emoji_id']);
            if (!empty($styles[$button_id])) {
                $new_button['style'] = $styles[$button_id];
            }
            if (!empty($icons[$button_id])) {
                $new_button['icon_custom_emoji_id'] = $icons[$button_id];
            }
            $labeled_row[] = $new_button;
        }
        if ($labeled_row !== []) {
            $labeled[] = $labeled_row;
        }
    }
    return $labeled;
}

function get_main_keyboard_allowed_styles()
{
    return [
        'primary' => 'آبی',
        'success' => 'سبز',
        'danger' => 'قرمز',
    ];
}

function ensure_main_keyboard_styles_column()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    addFieldToTable('setting', 'keyboardmain_styles', '{}', 'TEXT');
}

function get_main_keyboard_button_styles($styles_json = null)
{
    ensure_main_keyboard_styles_column();
    if ($styles_json === null) {
        $row = select('setting', 'keyboardmain_styles', null, null, 'select', ['cache' => false]);
        $styles_json = is_array($row) ? ($row['keyboardmain_styles'] ?? '{}') : '{}';
    }
    $styles = json_decode((string) $styles_json, true);
    if (!is_array($styles)) {
        return [];
    }
    $allowed = array_keys(get_main_keyboard_allowed_styles());
    $button_ids = get_main_keyboard_button_ids();
    $out = [];
    foreach ($styles as $id => $style) {
        $style = (string) $style;
        if (in_array($id, $button_ids, true) && in_array($style, $allowed, true)) {
            $out[$id] = $style;
        }
    }
    return $out;
}

function set_main_keyboard_button_style($button_id, $style)
{
    $allowed_ids = get_main_keyboard_button_ids();
    if (!in_array($button_id, $allowed_ids, true)) {
        return false;
    }
    $allowed_styles = array_keys(get_main_keyboard_allowed_styles());
    $style = trim((string) $style);
    if ($style === '' || $style === 'default') {
        $style = '';
    } elseif (!in_array($style, $allowed_styles, true)) {
        return false;
    }
    ensure_main_keyboard_styles_column();
    $styles = get_main_keyboard_button_styles();
    if ($style === '') {
        unset($styles[$button_id]);
    } else {
        $styles[$button_id] = $style;
    }
    update('setting', 'keyboardmain_styles', json_encode($styles, JSON_UNESCAPED_UNICODE), null, null);
    clearSelectCache('setting');
    return true;
}

function reset_main_keyboard_button_styles()
{
    ensure_main_keyboard_styles_column();
    update('setting', 'keyboardmain_styles', '{}', null, null);
    clearSelectCache('setting');
}

function ensure_main_keyboard_icons_column()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    addFieldToTable('setting', 'keyboardmain_icons', '{}', 'TEXT');
}

function normalize_main_keyboard_custom_emoji_id($emoji_id)
{
    $emoji_id = trim((string) $emoji_id);
    if ($emoji_id === '') {
        return '';
    }
    // Telegram custom emoji IDs are numeric strings (often 16+ digits).
    if (!preg_match('/^\d{1,64}$/', $emoji_id)) {
        return null;
    }
    return $emoji_id;
}

function get_main_keyboard_button_icons($icons_json = null)
{
    ensure_main_keyboard_icons_column();
    if ($icons_json === null) {
        $row = select('setting', 'keyboardmain_icons', null, null, 'select', ['cache' => false]);
        $icons_json = is_array($row) ? ($row['keyboardmain_icons'] ?? '{}') : '{}';
    }
    $icons = json_decode((string) $icons_json, true);
    if (!is_array($icons)) {
        return [];
    }
    $button_ids = get_main_keyboard_button_ids();
    $out = [];
    foreach ($icons as $id => $emoji_id) {
        $normalized = normalize_main_keyboard_custom_emoji_id($emoji_id);
        if (in_array($id, $button_ids, true) && $normalized !== null && $normalized !== '') {
            $out[$id] = $normalized;
        }
    }
    return $out;
}

function set_main_keyboard_button_icon($button_id, $emoji_id)
{
    $allowed_ids = get_main_keyboard_button_ids();
    if (!in_array($button_id, $allowed_ids, true)) {
        return false;
    }
    $normalized = normalize_main_keyboard_custom_emoji_id($emoji_id);
    if ($normalized === null) {
        return false;
    }
    ensure_main_keyboard_icons_column();
    $icons = get_main_keyboard_button_icons();
    if ($normalized === '') {
        unset($icons[$button_id]);
    } else {
        $icons[$button_id] = $normalized;
    }
    update('setting', 'keyboardmain_icons', json_encode($icons, JSON_UNESCAPED_UNICODE), null, null);
    clearSelectCache('setting');
    return true;
}

function reset_main_keyboard_button_icons()
{
    ensure_main_keyboard_icons_column();
    update('setting', 'keyboardmain_icons', '{}', null, null);
    clearSelectCache('setting');
}

/**
 * Split a button label into leading unicode emoji(s) and remaining title text.
 * Used by the panel so emoji can be edited separately from title.
 *
 * @return array{emoji: string, title: string}
 */
function split_main_keyboard_button_label($label)
{
    $label = trim((string) $label);
    if ($label === '') {
        return ['emoji' => '', 'title' => ''];
    }

    // Leading emoji / pictograph sequences (including ZWJ and variation selectors).
    $pattern = '/^((?:' .
        '[\x{1F1E0}-\x{1F1FF}]{2}' .
        '|[\x{2600}-\x{27BF}\x{1F300}-\x{1F9FF}\x{1FA00}-\x{1FAFF}\x{2300}-\x{23FF}\x{2B50}\x{2B55}\x{231A}\x{231B}\x{23E9}-\x{23FA}\x{25AA}-\x{25FE}\x{2934}\x{2935}\x{2B05}-\x{2B07}\x{2B1B}\x{2B1C}]' .
        '[\x{FE0E}\x{FE0F}]?' .
        '(?:\x{200D}[\x{2600}-\x{27BF}\x{1F300}-\x{1F9FF}\x{1FA00}-\x{1FAFF}\x{2300}-\x{23FF}][\x{FE0E}\x{FE0F}]?)*' .
        '(?:\x{20E3})?' .
        '\s*' .
        ')+)/u';

    if (preg_match($pattern, $label, $m)) {
        $emoji = trim($m[1]);
        $title = trim(mb_substr($label, mb_strlen($m[1])));
        if ($emoji !== '' && $title !== '') {
            return ['emoji' => $emoji, 'title' => $title];
        }
    }

    return ['emoji' => '', 'title' => $label];
}

function build_user_main_keyboard_markup($setting, $datatextbot, $textbotlang, $from_id, array $options = [])
{
    $persist = $options['persist'] ?? true;
    $users = $options['users'] ?? select('user', '*', 'id', $from_id, 'select');
    if ($users === false || $users === null) {
        $users = [
            'agent' => '',
            'step' => '',
        ];
    }
    $admin_idss = $options['admin_idss'] ?? select('admin', '*', 'id_admin', $from_id, 'count');

    $keyboardmain = normalize_keyboardmain_to_ids($setting['keyboardmain'] ?? '', $datatextbot);
    if ($persist && $keyboardmain !== ($setting['keyboardmain'] ?? '')) {
        update('setting', 'keyboardmain', $keyboardmain, null, null);
        $setting['keyboardmain'] = $keyboardmain;
    }

    $layout = json_decode($keyboardmain, true);
    $keyboard_rows = [];
    if (is_array($layout) && !empty($layout['keyboard']) && is_array($layout['keyboard'])) {
        $keyboard_rows = $layout['keyboard'];
    }
    if ($keyboard_rows === []) {
        $keyboard_rows = json_decode(get_default_main_keyboard_json(), true)['keyboard'] ?? [];
    }

    $button_styles = get_main_keyboard_button_styles($setting['keyboardmain_styles'] ?? null);
    $button_icons = get_main_keyboard_button_icons($setting['keyboardmain_icons'] ?? null);

    $inline = ($setting['inlinebtnmain'] ?? '') === 'oninline';
    $extra_row = [];
    $adminLabel = trim((string) ($textbotlang['Admin']['textpaneladmin'] ?? ''));
    if (intval($admin_idss) !== 0 && $adminLabel !== '') {
        $extra_button = ['text' => $adminLabel];
        if ($inline) {
            $extra_button['callback_data'] = 'admin';
        }
        $extra_row[] = $extra_button;
    }
    if (($users['agent'] ?? '') !== 'f') {
        $agentLabel = trim((string) ($datatextbot['textpanelagent'] ?? 'نمایندگی'));
        if ($agentLabel !== '') {
            $extra_button = ['text' => $agentLabel];
            if ($inline) {
                $extra_button['callback_data'] = 'agentpanel';
            }
            $extra_row[] = $extra_button;
        }
    }
    if (($users['agent'] ?? '') === 'f' && ($setting['statusagentrequest'] ?? '') === 'onrequestagent') {
        $requestLabel = trim((string) ($datatextbot['textrequestagent'] ?? 'درخواست نمایندگی'));
        if ($requestLabel !== '') {
            $extra_button = ['text' => $requestLabel];
            if ($inline) {
                $extra_button['callback_data'] = 'requestagent';
            }
            $extra_row[] = $extra_button;
        }
    }

    if ($inline) {
        $keyboard_rows = attach_main_keyboard_inline_callbacks($keyboard_rows);
        $keyboardcustom = apply_main_keyboard_button_labels($keyboard_rows, $datatextbot, $button_styles, $button_icons);
        if ($keyboardcustom === []) {
            $keyboard_rows = json_decode(get_default_main_keyboard_json(), true)['keyboard'] ?? [];
            $keyboard_rows = attach_main_keyboard_inline_callbacks($keyboard_rows);
            $keyboardcustom = apply_main_keyboard_button_labels($keyboard_rows, $datatextbot, $button_styles, $button_icons);
        }
        if ($extra_row !== []) {
            $keyboardcustom[] = $extra_row;
        }
        return json_encode(['inline_keyboard' => $keyboardcustom], JSON_UNESCAPED_UNICODE);
    }

    $keyboardcustom = apply_main_keyboard_button_labels($keyboard_rows, $datatextbot, $button_styles, $button_icons);
    if ($keyboardcustom === []) {
        $keyboard_rows = json_decode(get_default_main_keyboard_json(), true)['keyboard'] ?? [];
        $keyboardcustom = apply_main_keyboard_button_labels($keyboard_rows, $datatextbot, $button_styles, $button_icons);
    }
    if ($extra_row !== []) {
        $keyboardcustom[] = $extra_row;
    }

    return json_encode([
        'keyboard' => $keyboardcustom,
        'resize_keyboard' => true,
    ], JSON_UNESCAPED_UNICODE);
}

function toggle_main_keyboard_button($keyboardmain_json, $button_id, $datatextbot = null)
{
    $allowed = get_main_keyboard_button_ids();
    if (!in_array($button_id, $allowed, true)) {
        return $keyboardmain_json;
    }
    $keyboardmain_json = normalize_keyboardmain_to_ids($keyboardmain_json, $datatextbot);
    $active = get_active_main_keyboard_buttons($keyboardmain_json, $datatextbot);
    $solos = get_main_keyboard_solo_button_ids($keyboardmain_json, $datatextbot);

    if (in_array($button_id, $active, true)) {
        $active = array_values(array_diff($active, [$button_id]));
        $solos = array_values(array_diff($solos, [$button_id]));
    } else {
        $active[] = $button_id;
    }
    if ($active === []) {
        return get_default_main_keyboard_json();
    }
    return rebuild_main_keyboard_layout($active, $solos);
}

function move_main_keyboard_button($keyboardmain_json, $button_id, $direction, $datatextbot = null)
{
    $allowed = get_main_keyboard_button_ids();
    if (!in_array($button_id, $allowed, true)) {
        return $keyboardmain_json;
    }
    $direction = $direction === 'up' ? 'up' : 'down';
    $keyboardmain_json = normalize_keyboardmain_to_ids($keyboardmain_json, $datatextbot);
    $active = get_active_main_keyboard_buttons($keyboardmain_json, $datatextbot);
    $solos = get_main_keyboard_solo_button_ids($keyboardmain_json, $datatextbot);
    $idx = array_search($button_id, $active, true);
    if ($idx === false) {
        return $keyboardmain_json;
    }
    $swap_with = $direction === 'up' ? $idx - 1 : $idx + 1;
    if ($swap_with < 0 || $swap_with >= count($active)) {
        return $keyboardmain_json;
    }
    $tmp = $active[$idx];
    $active[$idx] = $active[$swap_with];
    $active[$swap_with] = $tmp;
    return rebuild_main_keyboard_layout($active, $solos);
}

function set_main_keyboard_button_width($keyboardmain_json, $button_id, $width, $datatextbot = null)
{
    $allowed = get_main_keyboard_button_ids();
    if (!in_array($button_id, $allowed, true)) {
        return $keyboardmain_json;
    }
    $width = $width === 'full' ? 'full' : 'half';
    $keyboardmain_json = normalize_keyboardmain_to_ids($keyboardmain_json, $datatextbot);
    $active = get_active_main_keyboard_buttons($keyboardmain_json, $datatextbot);
    if (!in_array($button_id, $active, true)) {
        return $keyboardmain_json;
    }
    $solos = get_main_keyboard_solo_button_ids($keyboardmain_json, $datatextbot);
    if ($width === 'full') {
        if (!in_array($button_id, $solos, true)) {
            $solos[] = $button_id;
        }
    } else {
        $solos = array_values(array_diff($solos, [$button_id]));
    }
    return rebuild_main_keyboard_layout($active, $solos);
}

function build_main_keyboard_admin_markup($datatextbot, $keyboardmain_json)
{
    global $textbotlang;
    $icons = get_main_keyboard_button_icons();
    $rows = [];
    foreach (get_default_main_keyboard_layout() as $row) {
        $inline_row = [];
        foreach ($row as $btn_id) {
            $label = get_main_keyboard_button_label($btn_id, $datatextbot);
            $status = check_active_btn($keyboardmain_json, $btn_id, $datatextbot)
                ? $textbotlang['Admin']['Status']['statuson']
                : $textbotlang['Admin']['Status']['statusoff'];
            $premium = !empty($icons[$btn_id]) ? '✦ ' : '';
            $inline_row[] = [
                'text' => "$status $premium$label",
                'callback_data' => "editmainbtn-$btn_id",
            ];
        }
        if (!empty($inline_row)) {
            $rows[] = $inline_row;
        }
    }
    $rows[] = [
        ['text' => "♻️ بازنشانی پیش‌فرض", 'callback_data' => 'resetmainbtn'],
    ];
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function build_main_keyboard_button_edit_text($button_id, $datatextbot, $keyboardmain_json)
{
    $label = get_main_keyboard_button_label($button_id, $datatextbot);
    $parts = split_main_keyboard_button_label($label);
    $title = $parts['title'] !== '' ? $parts['title'] : $label;
    $active = check_active_btn($keyboardmain_json, $button_id, $datatextbot) ? 'نمایش ✅' : 'مخفی ❌';
    $icons = get_main_keyboard_button_icons();
    $icon = $icons[$button_id] ?? '';
    if ($icon !== '') {
        $emoji_line = "✨ ایموجی پرمیوم: <tg-emoji emoji-id=\"{$icon}\">⭐</tg-emoji>\n🆔 <code>{$icon}</code>";
    } else {
        $emoji_line = '✨ ایموجی پرمیوم: تنظیم نشده';
    }
    $title_esc = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return "⌨️ تنظیم دکمه منو\n\n📌 عنوان: <b>{$title_esc}</b>\n👁 وضعیت: {$active}\n{$emoji_line}\n\nاز دکمه‌های زیر برای مدیریت استفاده کنید.";
}

function build_main_keyboard_button_edit_markup($button_id, $datatextbot, $keyboardmain_json)
{
    $active = check_active_btn($keyboardmain_json, $button_id, $datatextbot);
    $icons = get_main_keyboard_button_icons();
    $has_icon = !empty($icons[$button_id]);
    $rows = [
        [
            [
                'text' => $active ? '🔴 مخفی کردن دکمه' : '🟢 نمایش دادن دکمه',
                'callback_data' => "togglemainbtn-$button_id",
            ],
        ],
        [
            [
                'text' => '✨ تنظیم ایموجی پرمیوم',
                'callback_data' => "setmainbtnemoji-$button_id",
            ],
        ],
    ];
    if ($has_icon) {
        $rows[] = [
            [
                'text' => '🗑 حذف ایموجی پرمیوم',
                'callback_data' => "clearmainbtnemoji-$button_id",
            ],
        ];
    }
    $rows[] = [
        ['text' => '🔙 بازگشت به لیست', 'callback_data' => 'listmainbtn'],
    ];
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function extract_custom_emoji_id_from_update($update)
{
    if (!is_array($update)) {
        return '';
    }
    $message = $update['message'] ?? null;
    if (!is_array($message)) {
        return '';
    }
    $entity_groups = [];
    if (!empty($message['entities']) && is_array($message['entities'])) {
        $entity_groups[] = $message['entities'];
    }
    if (!empty($message['caption_entities']) && is_array($message['caption_entities'])) {
        $entity_groups[] = $message['caption_entities'];
    }
    foreach ($entity_groups as $entities) {
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            if (($entity['type'] ?? '') !== 'custom_emoji') {
                continue;
            }
            $normalized = normalize_main_keyboard_custom_emoji_id($entity['custom_emoji_id'] ?? '');
            if ($normalized !== null && $normalized !== '') {
                return $normalized;
            }
        }
    }
    return '';
}

/** UTF-16 code unit length (Telegram entity offsets). */
function telegram_utf16_strlen(string $text): int
{
    return (int) (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
}

/** Substring by Telegram UTF-16 offset/length. */
function telegram_utf16_substr(string $text, int $offset, ?int $length = null): string
{
    $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
    $byteOffset = max(0, $offset) * 2;
    if ($length === null) {
        $slice = substr($utf16, $byteOffset);
    } else {
        $slice = substr($utf16, $byteOffset, max(0, $length) * 2);
    }
    if ($slice === false || $slice === '') {
        return '';
    }
    return mb_convert_encoding($slice, 'UTF-8', 'UTF-16LE');
}

/**
 * Convert Telegram message text + entities to HTML (preserves Premium custom emoji).
 */
function message_text_to_html(string $text, $entities = null): string
{
    if ($text === '') {
        return '';
    }
    if (!is_array($entities) || $entities === []) {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $valid = [];
    foreach ($entities as $entity) {
        if (!is_array($entity)) {
            continue;
        }
        $offset = isset($entity['offset']) ? (int) $entity['offset'] : -1;
        $length = isset($entity['length']) ? (int) $entity['length'] : -1;
        if ($offset < 0 || $length <= 0) {
            continue;
        }
        $valid[] = $entity;
    }
    if ($valid === []) {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    usort($valid, static function ($a, $b) {
        $ao = (int) ($a['offset'] ?? 0);
        $bo = (int) ($b['offset'] ?? 0);
        if ($ao === $bo) {
            return (int) ($b['length'] ?? 0) <=> (int) ($a['length'] ?? 0);
        }
        return $ao <=> $bo;
    });

    $total = telegram_utf16_strlen($text);
    $out = '';
    $cursor = 0;
    $i = 0;
    $n = count($valid);

    while ($i < $n) {
        $entity = $valid[$i];
        $offset = (int) $entity['offset'];
        $length = (int) $entity['length'];
        if ($offset < $cursor) {
            $i++;
            continue;
        }
        if ($offset > $cursor) {
            $out .= htmlspecialchars(telegram_utf16_substr($text, $cursor, $offset - $cursor), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $inner = telegram_utf16_substr($text, $offset, $length);
        $type = (string) ($entity['type'] ?? '');
        $end = $offset + $length;

        // Nest non-overlapping child entities fully inside this span.
        $childEntities = [];
        $j = $i + 1;
        while ($j < $n) {
            $child = $valid[$j];
            $co = (int) ($child['offset'] ?? 0);
            $cl = (int) ($child['length'] ?? 0);
            if ($co >= $end) {
                break;
            }
            if ($co >= $offset && ($co + $cl) <= $end) {
                $childEntities[] = $child;
            }
            $j++;
        }
        $innerHtml = $childEntities === []
            ? htmlspecialchars($inner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : message_text_to_html($inner, array_map(static function ($c) use ($offset) {
                $c['offset'] = (int) $c['offset'] - $offset;
                return $c;
            }, $childEntities));

        switch ($type) {
            case 'custom_emoji':
                $emojiId = normalize_main_keyboard_custom_emoji_id($entity['custom_emoji_id'] ?? '');
                if ($emojiId !== null && $emojiId !== '') {
                    $fallback = htmlspecialchars($inner !== '' ? $inner : '⭐', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $out .= '<tg-emoji emoji-id="' . $emojiId . '">' . $fallback . '</tg-emoji>';
                } else {
                    $out .= $innerHtml;
                }
                break;
            case 'bold':
                $out .= '<b>' . $innerHtml . '</b>';
                break;
            case 'italic':
                $out .= '<i>' . $innerHtml . '</i>';
                break;
            case 'underline':
                $out .= '<u>' . $innerHtml . '</u>';
                break;
            case 'strikethrough':
                $out .= '<s>' . $innerHtml . '</s>';
                break;
            case 'spoiler':
                $out .= '<tg-spoiler>' . $innerHtml . '</tg-spoiler>';
                break;
            case 'code':
                $out .= '<code>' . htmlspecialchars($inner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                break;
            case 'pre':
                $lang = trim((string) ($entity['language'] ?? ''));
                if ($lang !== '') {
                    $out .= '<pre><code class="language-' . htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                        . htmlspecialchars($inner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
                } else {
                    $out .= '<pre>' . htmlspecialchars($inner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
                }
                break;
            case 'text_link':
                $url = htmlspecialchars((string) ($entity['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $out .= '<a href="' . $url . '">' . $innerHtml . '</a>';
                break;
            case 'text_mention':
                $userId = (int) ($entity['user']['id'] ?? 0);
                if ($userId > 0) {
                    $out .= '<a href="tg://user?id=' . $userId . '">' . $innerHtml . '</a>';
                } else {
                    $out .= $innerHtml;
                }
                break;
            case 'blockquote':
                $out .= '<blockquote>' . $innerHtml . '</blockquote>';
                break;
            default:
                $out .= $innerHtml;
                break;
        }

        $cursor = $end;
        // Skip entities fully covered by this one.
        while ($i + 1 < $n) {
            $next = $valid[$i + 1];
            $no = (int) ($next['offset'] ?? 0);
            $nl = (int) ($next['length'] ?? 0);
            if ($no >= $offset && ($no + $nl) <= $end) {
                $i++;
                continue;
            }
            break;
        }
        $i++;
    }

    if ($cursor < $total) {
        $out .= htmlspecialchars(telegram_utf16_substr($text, $cursor), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    return $out;
}

/**
 * Build HTML from a Telegram message/caption (Premium emoji → tg-emoji).
 */
function text_from_telegram_message($message): string
{
    if (!is_array($message)) {
        return '';
    }
    if (isset($message['text']) && is_string($message['text'])) {
        $entities = $message['entities'] ?? [];
        return message_text_to_html($message['text'], is_array($entities) ? $entities : []);
    }
    if (isset($message['caption']) && is_string($message['caption'])) {
        $entities = $message['caption_entities'] ?? [];
        return message_text_to_html($message['caption'], is_array($entities) ? $entities : []);
    }
    return '';
}

function text_from_telegram_update($update): string
{
    if (!is_array($update)) {
        return '';
    }
    return text_from_telegram_message($update['message'] ?? null);
}

/** textbot value with fallback (empty DB value uses fallback). */
function textbot_get(string $id_text, string $fallback = ''): string
{
    global $datatextbot;
    if (is_array($datatextbot) && isset($datatextbot[$id_text])) {
        $val = (string) $datatextbot[$id_text];
        if (trim(strip_tags($val)) !== '' || strpos($val, 'tg-emoji') !== false) {
            return $val;
        }
    }
    return $fallback;
}

/** Panel/category description as trusted HTML, or global textbot fallback. */
function purchase_description_or_fallback($description, string $fallback_key, string $fallback_default): string
{
    if (is_string($description)) {
        $trimmed = trim($description);
        if ($trimmed !== '') {
            // New admin HTML (Premium emoji / formatting) is trusted; legacy plain text is escaped.
            if (strpos($trimmed, '<tg-emoji') !== false || preg_match('/<\/?[a-z][a-z0-9]*\b/i', $trimmed)) {
                return $trimmed;
            }
            return htmlspecialchars($trimmed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
    return textbot_get($fallback_key, $fallback_default);
}

function textbot_custom_volume_ask($price, $min, $max): string
{
    $tpl = textbot_get(
        'text_custom_volume_ask',
        "📌 حجم درخواستی خود را ارسال کنید.\n🔔قیمت هر گیگ حجم {price} تومان می باشد.\n🔔 حداقل حجم {min} گیگابایت و حداکثر {max} گیگابایت می باشد."
    );
    return strtr($tpl, [
        '{price}' => (string) $price,
        '{min}' => (string) $min,
        '{max}' => (string) $max,
    ]);
}

function textbot_custom_volume_invalid($min, $max): string
{
    $tpl = textbot_get(
        'text_custom_volume_invalid',
        "❌ حجم نامعتبر است.\n🔔 حداقل حجم {min} گیگابایت و حداکثر {max} گیگابایت می باشد"
    );
    return strtr($tpl, [
        '{min}' => (string) $min,
        '{max}' => (string) $max,
    ]);
}

/** Strip HTML tags for inline keyboard button labels. */
function strip_html_for_button_label(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/** Normalized custom emoji id, or empty string if missing/invalid. */
function stored_custom_emoji_id($emoji_id): string
{
    $normalized = normalize_main_keyboard_custom_emoji_id($emoji_id);
    return ($normalized !== null && $normalized !== '') ? (string) $normalized : '';
}

/** Attach Telegram Premium icon to a keyboard button when an id is stored. */
function telegram_button_with_icon(array $button, $emoji_id): array
{
    ensure_shop_button_emoji_columns();
    $id = stored_custom_emoji_id($emoji_id);
    if ($id !== '') {
        $button['icon_custom_emoji_id'] = $id;
    } else {
        unset($button['icon_custom_emoji_id']);
    }
    return $button;
}

/**
 * Ensure category.emoji_id and product.emoji_id exist (silent; table.php is not run by the webhook).
 */
function ensure_shop_button_emoji_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return;
    }
    foreach (['category', 'product'] as $table) {
        try {
            $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$db, $table, 'emoji_id']);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }
            $pdo->exec("ALTER TABLE `$table` ADD `emoji_id` VARCHAR(64) NULL");
        } catch (Throwable $e) {
            // column may already exist under race, or insufficient privileges
        }
    }
}

/**
 * Parse a posted premium-emoji id from the web panel.
 * @return string|null empty string if cleared, id if valid, null if invalid
 */
function parse_posted_custom_emoji_id($input): ?string
{
    $input = trim((string) $input);
    if ($input === '') {
        return '';
    }
    return normalize_main_keyboard_custom_emoji_id($input);
}

/**
 * Button label + first custom emoji id from an admin Telegram message.
 * The first premium emoji is removed from the label so it is not duplicated next to the button icon.
 * @return array{text: string, emoji_id: string}
 */
function button_label_and_icon_from_message($message): array
{
    $html = text_from_telegram_message($message);
    $emojiId = '';
    if (is_array($message)) {
        $emojiId = extract_custom_emoji_id_from_update(['message' => $message]);
    }
    $labelHtml = $html;
    if ($emojiId !== '') {
        $quoted = preg_quote($emojiId, '/');
        $stripped = preg_replace('/<tg-emoji\b[^>]*emoji-id="' . $quoted . '"[^>]*>.*?<\/tg-emoji>/is', '', $html, 1);
        if (is_string($stripped)) {
            $labelHtml = $stripped;
        }
    }
    $plain = strip_html_for_button_label($labelHtml);
    if ($plain === '') {
        $plain = strip_html_for_button_label($html);
    }
    return ['text' => $plain, 'emoji_id' => $emojiId];
}

function button_label_and_icon_from_update($update): array
{
    if (!is_array($update)) {
        return ['text' => '', 'emoji_id' => ''];
    }
    return button_label_and_icon_from_message($update['message'] ?? null);
}

/**
 * Category name from a Telegram update (original text; premium emoji stripped).
 * Do not use globally converted $text — Persian digits in names would be rewritten
 * and can match a different category.
 */
function category_remark_from_update($update): string
{
    $parsed = button_label_and_icon_from_update($update);
    if ($parsed['text'] !== '') {
        return trim($parsed['text']);
    }
    if (is_array($update) && isset($update['message']['text']) && is_string($update['message']['text'])) {
        return trim($update['message']['text']);
    }
    return '';
}

function fetch_categories_by_remark(string $remark): array
{
    global $pdo;
    $remark = trim($remark);
    if ($remark === '' || !($pdo instanceof PDO)) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM category WHERE remark = ?');
    $stmt->execute([$remark]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/**
 * Unique category row from a keyboard tap / typed name.
 * @return array{ok: true, category: array}|array{ok: false, error: 'empty'|'missing'|'ambiguous'}
 */
function resolve_category_from_update($update): array
{
    $remark = category_remark_from_update($update);
    if ($remark === '') {
        return ['ok' => false, 'error' => 'empty'];
    }
    $rows = fetch_categories_by_remark($remark);
    if ($rows === []) {
        return ['ok' => false, 'error' => 'missing'];
    }
    if (count($rows) > 1) {
        return ['ok' => false, 'error' => 'ambiguous'];
    }
    return ['ok' => true, 'category' => $rows[0]];
}

function category_remark_taken(string $remark, $exceptId = null): bool
{
    $rows = fetch_categories_by_remark($remark);
    if ($exceptId === null) {
        return $rows !== [];
    }
    $exceptId = (int) $exceptId;
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) !== $exceptId) {
            return true;
        }
    }
    return false;
}

function category_resolve_error_text(string $error): string
{
    if ($error === 'ambiguous') {
        return '❌ چند دسته‌بندی با این نام وجود دارد. از پنل وب ویرایش کنید تا فقط همان یکی عوض شود.';
    }
    if ($error === 'empty') {
        return '❌ نام دسته‌بندی خالی است.';
    }
    return '❌ دسته بندی انتخاب شده وجود ندارد از بخش پلن ها > اضافه کردن دسته بندی دسته بندی خود را اضافه کنید سپس محصول را اضافه نمایید.';
}

/**
 * Plain button label + first custom emoji id from an admin message.
 * @return array{text: string, emoji_id: string}
 */
function plain_text_and_custom_emoji_from_message($message): array
{
    return button_label_and_icon_from_message($message);
}

function save_textbot_from_update(string $id_text, $update): bool
{
    $html = text_from_telegram_update($update);
    if ($html === '') {
        return false;
    }
    $stripped = trim(strip_tags($html));
    if ($stripped === '' && strpos($html, 'tg-emoji') === false) {
        return false;
    }
    update('textbot', 'text', $html, 'id_text', $id_text);
    return true;
}

/**
 * Prompt admin to edit a textbot row: instruction + current HTML preview.
 */
function prompt_textbot_edit(int $from_id, string $id_text, string $step_name, $back_keyboard = null, string $extra_note = ''): void
{
    global $datatextbot, $textbotlang, $backadmin;
    $keyboard = $back_keyboard ?? $backadmin;
    $current = is_array($datatextbot) ? (string) ($datatextbot[$id_text] ?? '') : '';
    $prompt = $textbotlang['Admin']['ManageUser']['ChangeTextGet'] ?? "📝 متن جدید را ارسال کنید:";
    sendmessage($from_id, $prompt, $keyboard, 'HTML');
    if ($current !== '') {
        sendmessage($from_id, $current, null, 'HTML');
    }
    if ($extra_note !== '') {
        sendmessage($from_id, $extra_note, null, 'HTML');
    }
    step($step_name, $from_id);
}

/** Inline keyboard: pick a panel for purchase-text editing. */
function keyboard_panels_purchase_text_edit(string $callback_prefix): string
{
    $panels = select('marzban_panel', '*', null, null, 'fetchAll');
    $rows = [];
    if (is_array($panels)) {
        foreach ($panels as $panel) {
            if (!is_array($panel)) {
                continue;
            }
            $name = (string) ($panel['name_panel'] ?? '');
            $code = (string) ($panel['code_panel'] ?? '');
            if ($name === '' || $code === '') {
                continue;
            }
            $rows[] = [['text' => $name, 'callback_data' => $callback_prefix . $code]];
        }
    }
    if ($rows === []) {
        $rows[] = [['text' => '❌ پنلی یافت نشد', 'callback_data' => 'purchase_texts_back']];
    }
    $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'purchase_texts_back']];
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

/** Inline keyboard: pick a category for description editing. */
function keyboard_categories_purchase_text_edit(string $callback_prefix): string
{
    $categories = select('category', '*', null, null, 'fetchAll');
    $rows = [];
    if (is_array($categories)) {
        foreach ($categories as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $id = (int) ($cat['id'] ?? 0);
            $remark = (string) ($cat['remark'] ?? '');
            if ($id <= 0 || $remark === '') {
                continue;
            }
            $rows[] = [telegram_button_with_icon(
                ['text' => $remark, 'callback_data' => $callback_prefix . $id],
                $cat['emoji_id'] ?? ''
            )];
        }
    }
    if ($rows === []) {
        $rows[] = [['text' => '❌ دسته‌بندی یافت نشد', 'callback_data' => 'purchase_texts_back']];
    }
    $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'purchase_texts_back']];
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

/**
 * When a premium icon is set, keep only the plain title text (no leading unicode emoji).
 */
function strip_unicode_emoji_from_main_keyboard_button_title($button_id, $datatextbot = null)
{
    if (!in_array($button_id, get_main_keyboard_button_ids(), true)) {
        return false;
    }
    if (!is_array($datatextbot)) {
        $datatextbot = [];
    }
    $label = get_main_keyboard_button_label($button_id, $datatextbot);
    $parts = split_main_keyboard_button_label($label);
    if ($parts['emoji'] === '' || $parts['title'] === '') {
        return false;
    }
    $title = $parts['title'];
    $exists = select('textbot', 'id_text', 'id_text', $button_id, 'select');
    if ($exists) {
        update('textbot', 'text', $title, 'id_text', $button_id);
    } else {
        global $pdo;
        $stmt = $pdo->prepare('INSERT INTO textbot (id_text, text) VALUES (?, ?)');
        $stmt->execute([$button_id, $title]);
    }
    clearSelectCache('textbot');
    return true;
}

function deleteFolder($folderPath)
{
    if (!is_dir($folderPath))
        return false;

    $files = array_diff(scandir($folderPath), ['.', '..']);

    foreach ($files as $file) {
        $filePath = $folderPath . DIRECTORY_SEPARATOR . $file;
        if (is_dir($filePath)) {
            deleteFolder($filePath);
        } else {
            unlink($filePath);
        }
    }

    return rmdir($folderPath);
}
function isBase64($string)
{
    if (base64_encode(base64_decode($string, true)) === $string) {
        return true;
    }
    return false;
}
function sendMessageService($panel_info, $config, $sub_link, $username_service, $reply_markup, $caption, $invoice_id, $user_id = null, $image = 'images.jpg')
{
    global $setting, $from_id;
    if (!check_active_btn($setting['keyboardmain'], "text_help"))
        $reply_markup = null;
    $user_id = $user_id == null ? $from_id : $user_id;
    if (!is_array($config)) {
        $config = (is_string($config) && $config !== '') ? [$config] : [];
    }
    $sendAsPhoto = !($panel_info['config'] == "onconfig" && count($config) != 1);
    $out_put_qrcode = "";
    if ($panel_info['sublink'] == "onsublink") {
        $out_put_qrcode = $sub_link;
    } elseif ($panel_info['config'] == "onconfig") {
        $out_put_qrcode = $config[0] ?? '';
    }
    $caption = is_string($caption) ? $caption : '';
    // Telegram media caption max is 1024 chars — oversize captions must go as a separate text message.
    $captionFitsMedia = $caption !== '' && mb_strlen($caption, 'UTF-8') <= 1024;
    $captionDelivered = false;

    if ($sendAsPhoto && $out_put_qrcode !== '' && $out_put_qrcode !== null) {
        if ($panel_info['type'] == "WGDashboard") {
            $urlimage = "{$panel_info['inboundid']}_{$invoice_id}.conf";
            file_put_contents($urlimage, $sub_link);
            $result = telegram('senddocument', [
                'chat_id' => $user_id,
                'document' => new CURLFile($urlimage),
                'reply_markup' => $captionFitsMedia ? $reply_markup : null,
                'caption' => $captionFitsMedia ? $caption : null,
                'parse_mode' => "HTML",
            ]);
            unlink($urlimage);
        } else {
            $urlimage = "$user_id$invoice_id.png";
            $qrCode = createqrcode($out_put_qrcode);
            file_put_contents($urlimage, $qrCode->getString());
            addBackgroundImage($urlimage, $qrCode, $image);
            $result = telegram('sendphoto', [
                'chat_id' => $user_id,
                'photo' => new CURLFile($urlimage),
                'reply_markup' => $captionFitsMedia ? $reply_markup : null,
                'caption' => $captionFitsMedia ? $caption : null,
                'parse_mode' => "HTML",
            ]);
            unlink($urlimage);
        }
        $captionDelivered = $captionFitsMedia && !empty($result['ok']);
    }

    // Deliver product data + sub/config text when it was not attached to the QR/media.
    if ($caption !== '' && !$captionDelivered) {
        sendmessage($user_id, $caption, $reply_markup, 'HTML');
    }

    if ($panel_info['config'] == "onconfig" && $setting['status_keyboard_config'] == "1") {
        if (is_array($config) && count($config) > 0) {
            sendmessage($user_id, "📌 جهت دریافت کانفیگ روی دکمه دریافت کانفیگ کلیک کنید", keyboard_config($config, $invoice_id, false), 'HTML');
        }
    }
    // Keep the latest delivered sub link so "subscription link" button can fallback reliably.
    if (is_string($sub_link) && trim($sub_link) !== '') {
        update("invoice", "user_info", trim($sub_link), "id_invoice", $invoice_id);
    }
}
function isValidInvitationCode($setting, $fromId, $verfy_status)
{

    if ($setting['verifybucodeuser'] == "onverify" && $verfy_status != 1) {
        sendmessage($fromId, "حساب کاربری شما با موفقیت احرازهویت گردید", null, 'html');
        update("user", "verify", "1", "id", $fromId);
        update("user", "cardpayment", "1", "id", $fromId);
    }
}
function createPayZarinpal($price, $order_id)
{
    global $domainhosts;
    $marchent_zarinpal = select("PaySetting", "ValuePay", "NamePay", "merchant_zarinpal", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.zarinpal.com/pg/v4/payment/request.json',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        "merchant_id" => $marchent_zarinpal,
        "currency" => "IRT",
        "amount" => $price,
        "callback_url" => "https://$domainhosts/payment/zarinpal.php",
        "description" => $order_id,
        "metadata" => array(
            "order_id" => $order_id
        )
    ]));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function createPayaqayepardakht($price, $order_id)
{
    global $domainhosts;
    $merchant_aqayepardakht = select("PaySetting", "ValuePay", "NamePay", "merchant_id_aqayepardakht", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://panel.aqayepardakht.ir/api/v2/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'pin' => $merchant_aqayepardakht,
        'amount' => $price,
        'callback' => $domainhosts . "/payment/aqayepardakht.php",
        'invoice_id' => $order_id,
    ]));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function parseConfigs($input)
{
    $lines = explode("\n", $input);
    $configs = [];

    $currentName = null;
    $currentData = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if (strpos($line, '#') === 0) {
            if ($currentName && $currentData) {
                $configs[] = [
                    'name' => $currentName,
                    'config' => implode("\n", $currentData)
                ];
            }
            $currentName = trim(substr($line, 1));
            $currentData = [];
        } else {
            if ($line !== '') {
                $currentData[] = $line;
            }
        }
    }
    if ($currentName && $currentData) {
        $configs[] = [
            'name' => $currentName,
            'config' => implode("\n", $currentData)
        ];
    }

    return $configs;
}

function affiliates_page_size(): int
{
    return 10;
}

function affiliates_btn_label(string $text, int $max = 64): string
{
    $text = trim($text);
    if ($text === '' || strcasecmp($text, 'none') === 0) {
        $text = '—';
    }
    if (mb_strlen($text, 'UTF-8') > $max) {
        return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    }
    return $text;
}

function affiliates_parse_timestamp($value): ?int
{
    if ($value === null || $value === '' || $value === '0') {
        return null;
    }
    if (is_numeric($value)) {
        $n = (int) $value;
        if ($n > 20000000000) {
            $n = (int) floor($n / 1000);
        }
        return $n > 0 ? $n : null;
    }
    $ts = strtotime((string) $value);
    return ($ts !== false && $ts > 0) ? $ts : null;
}

function affiliates_user_can_claim_start_gift(array $user): bool
{
    $affSettings = select('affiliates', '*', null, null, 'select');
    if (!is_array($affSettings) || ($affSettings['Discount'] ?? '') !== 'onDiscountaffiliates') {
        return false;
    }
    $parentId = (string) ($user['affiliates'] ?? '0');
    if ($parentId === '' || $parentId === '0' || !rowExists('user', 'id', $parentId)) {
        return false;
    }
    $reagent = select('reagent_report', '*', 'user_id', $user['id'] ?? '', 'select');
    if (!is_array($reagent)
        || (string) ($reagent['reagent'] ?? '0') !== $parentId
        || affiliates_relationship_is_migrated($user['id'] ?? '', $parentId)
    ) {
        return false;
    }
    $gift = $reagent['get_gift'] ?? 0;
    if ($gift === true || $gift === 1 || $gift === '1' || $gift === 'true') {
        return false;
    }
    return true;
}

function affiliates_porsant_text(array $affiliatesRow, $percentage): string
{
    if (($affiliatesRow['status_commission'] ?? '') !== 'oncommission') {
        return '';
    }
    $percent = $percentage;
    if (($affiliatesRow['porsant_one_buy'] ?? 'off_buy_porsant') === 'off_buy_porsant') {
        return "<b>💸 پورسانت خرید:</b>  
•  $percent درصد از  مبلغ هر خرید زیرمجموعه به شما تعلق می‌گیره.";
    }
    return "<b>💸 پورسانت خرید:</b>  
•  $percent درصد از مبلغ خرید زیرمجموعه به شما تعلق می‌گیره";
}

function affiliates_main_keyboard($from_id, array $user, $usernamebot): string
{
    $shareUrl = "https://t.me/share/url?url=https://t.me/{$usernamebot}?start={$from_id}";
    $rows = [
        [
            ['text' => '👥 زیر مجموعه ها', 'callback_data' => 'aff_list_1'],
            ['text' => '🔗 اشتراک گذاری لینک', 'url' => $shareUrl],
        ],
        [
            ['text' => '💸 درخواست برداشت', 'callback_data' => 'Wallet_Withdraw'],
        ],
    ];
    if (affiliates_user_can_claim_start_gift($user)) {
        $rows[] = [
            ['text' => '🎁 دریافت هدیه عضویت', 'callback_data' => 'get_gift_start'],
        ];
    }
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function affiliates_main_view($from_id, array $user): array
{
    global $pdo, $setting, $usernamebot;

    $affiliatescommission = select('affiliates', '*', null, null, 'select');
    if (!is_array($affiliatescommission)) {
        $affiliatescommission = [];
    }

    $sqlPanel = "SELECT COUNT(*) AS orders, SUM(price_product) AS total_price
                 FROM invoice
                 WHERE Status IN ('active', 'end_of_time', 'sendedwarn', 'send_on_hold')
                 AND refral = :from_id
                 AND name_product != 'سرویس تست'";
    $stmt = $pdo->prepare($sqlPanel);
    $stmt->execute([':from_id' => $from_id]);
    $inforefral = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['orders' => 0, 'total_price' => 0];
    $inforefral['total_price'] = (($inforefral['total_price'] ?? 0) * ($setting['affiliatespercentage'] ?? 0)) / 100;

    $text_start = '';
    if (($affiliatescommission['Discount'] ?? '') === 'onDiscountaffiliates') {
        $text_start = "<b>🎁 هدیه عضویت:</b>
• 🎉 مجموع هدیه: {$affiliatescommission['price_Discount']} تومان  
• 🔻 ۵۰٪ برای شما (معرف)  
• 🔻 ۵۰٪ برای زیرمجموعه (کاربر جدید)
";
    }
    $text_porsant = affiliates_porsant_text($affiliatescommission, $setting['affiliatespercentage'] ?? 0);
    $sum_order = number_format((float) ($inforefral['total_price'] ?? 0), 0);
    $orders = (int) ($inforefral['orders'] ?? 0);
    $affiliatescount = $user['affiliatescount'] ?? 0;
    try {
        $notMigrated = affiliates_invitee_not_migrated_sql('u');
        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM user u
             WHERE CAST(u.affiliates AS CHAR) = CAST(:rid AS CHAR)
               AND IFNULL(u.affiliates, '') != ''
               AND u.affiliates != '0'
               AND $notMigrated"
        );
        $countStmt->execute([':rid' => (string) $from_id]);
        $affiliatescount = (int) $countStmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('affiliates_main_view count: ' . $e->getMessage());
    }

    $text = "<b>💼 زیرمجموعه‌گیری و هدیه خوش‌آمد</b>

با دعوت دوستان از طریق <b>لینک اختصاصی</b>، بدون پرداخت حتی ۱ ریال کیف پولت شارژ میشه و از خدمات ربات استفاده می‌کنی!

$text_start
$text_porsant

<b>📊 آمار شما:</b>
• 👥 زیرمجموعه‌ها: {$affiliatescount} نفر
• 🛒 خریدها: {$orders} عدد
• 💵 مجموع خرید: $sum_order تومان

<b>📢 دعوت کن، هدیه بگیر، رشد کن!</b>
";

    return [
        'text' => $text,
        'keyboard' => affiliates_main_keyboard($from_id, $user, $usernamebot ?? ''),
    ];
}

function affiliates_invitee_not_migrated_sql(string $userAlias = 'u'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $userAlias)) {
        return '1=0';
    }
    $timeSql = affiliates_report_time_sql('aff_mig');
    $regSql = affiliates_register_time_sql($userAlias);
    $cutoff = affiliates_ads_cutoff();
    return "NOT EXISTS (
        SELECT 1 FROM reagent_report aff_mig
        WHERE CAST(aff_mig.user_id AS CHAR) = CAST({$userAlias}.id AS CHAR)
          AND CAST(aff_mig.reagent AS CHAR) = CAST({$userAlias}.affiliates AS CHAR)
          AND (
            COALESCE(aff_mig.migrated_to_ads, 0) = 1
            OR ({$timeSql} IS NOT NULL AND {$timeSql} < '{$cutoff}')
          )
    )
    AND (
        EXISTS (
            SELECT 1 FROM reagent_report aff_has
            WHERE CAST(aff_has.user_id AS CHAR) = CAST({$userAlias}.id AS CHAR)
        )
        OR {$regSql} IS NULL
        OR {$regSql} >= '{$cutoff}'
    )";
}

function affiliates_user_is_invitee($childId, $referrerId): bool
{
    $child = select('user', '*', 'id', $childId, 'select');
    if (!is_array($child)) {
        return false;
    }
    $parent = (string) ($child['affiliates'] ?? '0');
    if ($parent === '' || $parent === '0' || (string) $parent !== (string) $referrerId) {
        return false;
    }
    return !affiliates_relationship_is_migrated($childId, $referrerId);
}

function affiliates_list_view($referrerId, int $page): array
{
    global $pdo, $textbotlang;

    $page = max(1, $page);
    $limit = affiliates_page_size();
    $offset = ($page - 1) * $limit;
    $paidSql = invoice_paid_status_sql('i.Status');

    $notMigrated = affiliates_invitee_not_migrated_sql('user');
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM user
         WHERE CAST(affiliates AS CHAR) = CAST(:rid AS CHAR)
           AND IFNULL(affiliates, '') != ''
           AND affiliates != '0'
           AND $notMigrated"
    );
    $countStmt->execute([':rid' => (string) $referrerId]);
    $total = (int) $countStmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $limit));
    if ($page > $pages) {
        $page = $pages;
        $offset = ($page - 1) * $limit;
    }

    $rows = [];
    if ($total > 0) {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.username, COALESCE(c.cnt, 0) AS service_count
             FROM user u
             LEFT JOIN (
                SELECT i.id_user, COUNT(*) AS cnt
                FROM invoice i
                WHERE i.name_product != 'سرویس تست'
                  AND ($paidSql)
                GROUP BY i.id_user
             ) c ON CAST(c.id_user AS CHAR) = CAST(u.id AS CHAR)
             WHERE CAST(u.affiliates AS CHAR) = CAST(:rid AS CHAR)
               AND IFNULL(u.affiliates, '') != ''
               AND u.affiliates != '0'
               AND " . affiliates_invitee_not_migrated_sql('u') . "
             ORDER BY CAST(u.register AS UNSIGNED) DESC, u.id DESC
             LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute([':rid' => (string) $referrerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $keyboard = ['inline_keyboard' => []];
    if ($total === 0) {
        $keyboard['inline_keyboard'][] = [
            ['text' => '🔙 بازگشت', 'callback_data' => 'affiliatesbtn'],
        ];
        return [
            'text' => 'هنوز زیرمجموعه‌ای ندارید.',
            'keyboard' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ];
    }

    $keyboard['inline_keyboard'][] = [
        ['text' => 'یوزرنیم', 'callback_data' => 'aff_noop'],
        ['text' => 'آیدی', 'callback_data' => 'aff_noop'],
        ['text' => 'تعداد', 'callback_data' => 'aff_noop'],
        ['text' => 'مشاهده', 'callback_data' => 'aff_noop'],
    ];

    foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '');
        $username = (string) ($row['username'] ?? '');
        if ($username !== '' && strcasecmp($username, 'none') !== 0) {
            $usernameLabel = '@' . ltrim($username, '@');
        } else {
            $usernameLabel = '—';
        }
        $cb = 'aff_svc_' . $id . '_1';
        $keyboard['inline_keyboard'][] = [
            ['text' => affiliates_btn_label($usernameLabel), 'callback_data' => $cb],
            ['text' => affiliates_btn_label($id), 'callback_data' => $cb],
            ['text' => affiliates_btn_label((string) (int) ($row['service_count'] ?? 0)), 'callback_data' => $cb],
            ['text' => '👁', 'callback_data' => $cb],
        ];
    }

    $nextLabel = is_array($textbotlang) ? ($textbotlang['users']['page']['next'] ?? '▶️') : '▶️';
    $prevLabel = is_array($textbotlang) ? ($textbotlang['users']['page']['previous'] ?? '◀️') : '◀️';
    $nav = [];
    if ($page > 1) {
        $nav[] = ['text' => $prevLabel, 'callback_data' => 'aff_list_' . ($page - 1)];
    }
    if ($page < $pages) {
        $nav[] = ['text' => $nextLabel, 'callback_data' => 'aff_list_' . ($page + 1)];
    }
    if ($nav) {
        $keyboard['inline_keyboard'][] = $nav;
    }
    $keyboard['inline_keyboard'][] = [
        ['text' => '🔙 بازگشت', 'callback_data' => 'affiliatesbtn'],
    ];

    return [
        'text' => "👥 لیست زیرمجموعه‌های شما\nصفحه {$page} از {$pages} · {$total} نفر\nروی هر ردیف بزنید تا سرویس‌های همان کاربر را ببینید.",
        'keyboard' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
    ];
}

function affiliates_format_volume_usage(?array $dataUser, array $invoice): string
{
    $panelOk = is_array($dataUser) && ($dataUser['status'] ?? '') !== 'Unsuccessful';
    if ($panelOk) {
        $used = $dataUser['used_traffic'] ?? 0;
        $limit = $dataUser['data_limit'] ?? 0;
        $usedText = (is_numeric($used) && (float) $used > 0)
            ? formatBytes((float) $used)
            : 'مصرف نشده';
        $limitText = (is_numeric($limit) && (float) $limit > 0)
            ? formatBytes((float) $limit)
            : 'نامحدود';
        return $usedText . ' / ' . $limitText;
    }
    $volume = $invoice['Volume'] ?? ($invoice['Volume_constraint'] ?? '0');
    $totalText = (is_numeric($volume) && (float) $volume > 0)
        ? ((float) $volume . ' گیگ')
        : 'نامحدود';
    return 'نامشخص / ' . $totalText;
}

function affiliates_format_time_usage(?array $dataUser, array $invoice): string
{
    $totalDays = (int) ($invoice['Service_time'] ?? 0);
    $panelOk = is_array($dataUser) && ($dataUser['status'] ?? '') !== 'Unsuccessful';
    $expire = $panelOk ? ($dataUser['expire'] ?? 0) : 0;
    $expireTs = is_numeric($expire) ? (int) $expire : 0;

    if ($totalDays <= 0 && $expireTs <= 0) {
        return 'نامحدود';
    }

    $consumed = null;
    if ($expireTs > 0 && $totalDays > 0) {
        $remaining = (int) max(0, floor(($expireTs - time()) / 86400));
        $consumed = (int) max(0, min($totalDays, $totalDays - $remaining));
    } else {
        $soldTs = affiliates_parse_timestamp($invoice['time_sell'] ?? null);
        if ($soldTs !== null && $totalDays > 0) {
            $elapsed = (int) max(0, floor((time() - $soldTs) / 86400));
            $consumed = (int) min($totalDays, $elapsed);
        }
    }

    if ($totalDays <= 0) {
        return $consumed === null ? 'نامحدود' : ($consumed . ' روز / نامحدود');
    }
    if ($consumed === null) {
        return 'نامشخص / ' . $totalDays . ' روز';
    }
    return $consumed . ' / ' . $totalDays . ' روز';
}

function affiliates_fetch_invoice_usage(array $invoice): ?array
{
    global $ManagePanel;
    $username = (string) ($invoice['username'] ?? '');
    $location = (string) ($invoice['Service_location'] ?? '');
    if ($username === '' || $location === '' || !isset($ManagePanel) || !is_object($ManagePanel)) {
        return null;
    }
    try {
        $data = $ManagePanel->DataUser($location, $username, true);
        return is_array($data) ? $data : null;
    } catch (Throwable $e) {
        error_log('affiliates_fetch_invoice_usage: ' . $e->getMessage());
        return null;
    }
}

function affiliates_services_view($referrerId, $childId, int $page): array
{
    global $pdo, $textbotlang;

    if (!affiliates_user_is_invitee($childId, $referrerId)) {
        return [
            'text' => '❌ این کاربر زیرمجموعه شما نیست.',
            'keyboard' => json_encode([
                'inline_keyboard' => [
                    [['text' => '🔙 بازگشت', 'callback_data' => 'aff_list_1']],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    $child = select('user', '*', 'id', $childId, 'select');
    $childUsername = is_array($child) ? (string) ($child['username'] ?? '') : '';
    $childLabel = ($childUsername !== '' && strcasecmp($childUsername, 'none') !== 0)
        ? '@' . ltrim($childUsername, '@')
        : (string) $childId;
    $childLabel = htmlspecialchars($childLabel, ENT_QUOTES, 'UTF-8');

    $page = max(1, $page);
    $limit = affiliates_page_size();
    $offset = ($page - 1) * $limit;
    $paidSql = invoice_paid_status_sql('Status');

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM invoice
         WHERE CAST(id_user AS CHAR) = CAST(:uid AS CHAR)
           AND name_product != 'سرویس تست'
           AND ($paidSql)"
    );
    $countStmt->execute([':uid' => (string) $childId]);
    $total = (int) $countStmt->fetchColumn();
    $pages = $total > 0 ? max(1, (int) ceil($total / $limit)) : 1;
    if ($page > $pages) {
        $page = $pages;
        $offset = ($page - 1) * $limit;
    }

    $keyboard = ['inline_keyboard' => []];
    if ($total === 0) {
        $keyboard['inline_keyboard'][] = [
            ['text' => '🔙 بازگشت به لیست', 'callback_data' => 'aff_list_1'],
        ];
        return [
            'text' => "کاربر {$childLabel} هنوز سرویسی نخریده است.",
            'keyboard' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT username, name_product, Volume, Service_time, time_sell, Service_location, Status
         FROM invoice
         WHERE CAST(id_user AS CHAR) = CAST(:uid AS CHAR)
           AND name_product != 'سرویس تست'
           AND ($paidSql)
         ORDER BY time_sell DESC
         LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset
    );
    $stmt->execute([':uid' => (string) $childId]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $lines = ["🛍 سرویس‌های {$childLabel}", "صفحه {$page} از {$pages} · {$total} سرویس", ''];
    foreach ($invoices as $invoice) {
        $usage = affiliates_fetch_invoice_usage($invoice);
        $volumeLine = affiliates_format_volume_usage($usage, $invoice);
        $timeLine = affiliates_format_time_usage($usage, $invoice);
        $svcUser = htmlspecialchars((string) ($invoice['username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $product = htmlspecialchars((string) ($invoice['name_product'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lines[] = "🔹 <code>{$svcUser}</code> — {$product}";
        $lines[] = "📦 حجم: {$volumeLine}";
        $lines[] = "⏱ زمان: {$timeLine}";
        $lines[] = '';
    }

    $nextLabel = is_array($textbotlang) ? ($textbotlang['users']['page']['next'] ?? '▶️') : '▶️';
    $prevLabel = is_array($textbotlang) ? ($textbotlang['users']['page']['previous'] ?? '◀️') : '◀️';
    $nav = [];
    if ($page > 1) {
        $nav[] = ['text' => $prevLabel, 'callback_data' => 'aff_svc_' . $childId . '_' . ($page - 1)];
    }
    if ($page < $pages) {
        $nav[] = ['text' => $nextLabel, 'callback_data' => 'aff_svc_' . $childId . '_' . ($page + 1)];
    }
    if ($nav) {
        $keyboard['inline_keyboard'][] = $nav;
    }
    $keyboard['inline_keyboard'][] = [
        ['text' => '🔙 بازگشت به لیست', 'callback_data' => 'aff_list_1'],
    ];

    return [
        'text' => rtrim(implode("\n", $lines)),
        'keyboard' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
    ];
}

function referral_ensure_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    global $pdo;

    if (!($pdo instanceof PDO)) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_campaign (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(32) NOT NULL,
        title VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        code_product VARCHAR(100) NOT NULL,
        panel_name VARCHAR(255) NOT NULL,
        required_invites INT UNSIGNED NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'inactive',
        new_users_only TINYINT(1) NOT NULL DEFAULT 1,
        created_at VARCHAR(50) NOT NULL,
        UNIQUE KEY uniq_referral_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_invite (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT UNSIGNED NOT NULL,
        referrer_id BIGINT NOT NULL,
        invited_user_id BIGINT NOT NULL,
        created_at VARCHAR(50) NOT NULL,
        UNIQUE KEY uniq_campaign_invited (campaign_id, invited_user_id),
        KEY idx_campaign_referrer (campaign_id, referrer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_reward (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT UNSIGNED NOT NULL,
        user_id BIGINT NOT NULL,
        id_invoice VARCHAR(100) NOT NULL,
        granted_at VARCHAR(50) NOT NULL,
        UNIQUE KEY uniq_campaign_user_reward (campaign_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    addFieldToTable('setting', 'referralstatus', 'offreferral', 'VARCHAR(200)');

    $stmt = $pdo->prepare("INSERT IGNORE INTO textbot (id_text, text) VALUES ('text_referral', ?)");
    $stmt->execute(['🎁 دعوت دوستان']);

    $ready = true;
}

function ads_ensure_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS ad_advertiser (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        code VARCHAR(32) NOT NULL,
        join_count INT UNSIGNED NOT NULL DEFAULT 0,
        amount INT UNSIGNED NOT NULL DEFAULT 0,
        started_at VARCHAR(50) NOT NULL,
        payment_order_id VARCHAR(100) NULL,
        source_user_id VARCHAR(50) NULL,
        created_at VARCHAR(50) NOT NULL,
        UNIQUE KEY uniq_ad_code (code),
        UNIQUE KEY uniq_ad_source_user (source_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ad_join (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        advertiser_id INT UNSIGNED NOT NULL,
        user_id BIGINT NOT NULL,
        created_at VARCHAR(50) NOT NULL,
        UNIQUE KEY uniq_ad_join_user (advertiser_id, user_id),
        KEY idx_ad_join_advertiser (advertiser_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    $reagentColumns = [
        'migrated_to_ads' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'ad_advertiser_id' => 'INT UNSIGNED NULL',
    ];
    foreach ($reagentColumns as $column => $definition) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(['reagent_report', $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE reagent_report ADD $column $definition");
        }
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM setting LIKE 'ads_affiliates_migrated'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE setting ADD ads_affiliates_migrated VARCHAR(10) NOT NULL DEFAULT '0'");
        }
    } catch (Throwable $e) {
        error_log('ads_ensure_schema setting flag: ' . $e->getMessage());
    }

    $ready = true;
    affiliates_migrate_pre_cutoff_to_ads();
}

function ads_generate_code(): string
{
    global $pdo;
    ads_ensure_schema();
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max = strlen($chars) - 1;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        $stmt = $pdo->prepare('SELECT id FROM ad_advertiser WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            return $code;
        }
    }
    throw new RuntimeException('تولید کد یکتای تبلیغ ناموفق بود.');
}

function ads_build_link(string $code): string
{
    global $usernamebot;
    return 'https://t.me/' . $usernamebot . '?start=ad_' . $code;
}

function ads_migrate_from_affiliates(): void
{
    affiliates_migrate_pre_cutoff_to_ads();
}

function affiliates_migrate_pre_cutoff_to_ads(): void
{
    global $pdo;
    static $done = false;
    if ($done || !($pdo instanceof PDO)) {
        return;
    }
    $done = true;

    try {
        $col = $pdo->query("SHOW COLUMNS FROM setting LIKE 'affiliates_ads_cutoff_migrated'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec("ALTER TABLE setting ADD affiliates_ads_cutoff_migrated VARCHAR(10) NOT NULL DEFAULT '0'");
        }
        $flag = $pdo->query('SELECT affiliates_ads_cutoff_migrated FROM setting LIMIT 1')->fetchColumn();
        if ((string) $flag === '1') {
            return;
        }
    } catch (Throwable $e) {
        error_log('affiliates_migrate_pre_cutoff_to_ads flag: ' . $e->getMessage());
        return;
    }

    $cutoff = affiliates_ads_cutoff();
    $timeSql = affiliates_report_time_sql('r');
    $regSql = affiliates_register_time_sql('u');

    try {
        $pdo->beginTransaction();

        $reports = $pdo->query(
            "SELECT r.id, r.user_id, r.reagent, r.time
             FROM reagent_report r
             WHERE COALESCE(r.migrated_to_ads, 0) = 0
               AND IFNULL(r.reagent, '') != ''
               AND r.reagent != '0'
               AND {$timeSql} IS NOT NULL
               AND {$timeSql} < '{$cutoff}'"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $orphans = $pdo->query(
            "SELECT u.id, u.affiliates, u.register
             FROM user u
             WHERE IFNULL(u.affiliates, '') != ''
               AND u.affiliates != '0'
               AND {$regSql} IS NOT NULL
               AND {$regSql} < '{$cutoff}'
               AND NOT EXISTS (SELECT 1 FROM reagent_report r WHERE r.user_id = u.id)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $insertReport = $pdo->prepare(
            'INSERT INTO reagent_report (user_id, get_gift, time, reagent, migrated_to_ads, ad_advertiser_id)
             VALUES (?, 1, ?, ?, 0, NULL)'
        );
        foreach ($orphans as $orphan) {
            $ts = affiliates_parse_timestamp($orphan['register'] ?? null);
            $time = $ts ? date('Y/m/d H:i:s', $ts) : date('Y/m/d H:i:s');
            $insertReport->execute([(string) $orphan['id'], $time, (string) $orphan['affiliates']]);
            $reports[] = [
                'id' => (int) $pdo->lastInsertId(),
                'user_id' => $orphan['id'],
                'reagent' => $orphan['affiliates'],
                'time' => $time,
            ];
        }

        $findAdvertiser = $pdo->prepare('SELECT id FROM ad_advertiser WHERE source_user_id = ? LIMIT 1');
        $insertAdvertiser = $pdo->prepare(
            'INSERT INTO ad_advertiser (name, code, join_count, amount, started_at, payment_order_id, source_user_id, created_at)
             VALUES (?, ?, 0, 0, ?, NULL, ?, ?)'
        );
        $insertJoin = $pdo->prepare(
            'INSERT IGNORE INTO ad_join (advertiser_id, user_id, created_at) VALUES (?, ?, ?)'
        );
        $markReport = $pdo->prepare(
            'UPDATE reagent_report SET migrated_to_ads = 1, ad_advertiser_id = ? WHERE id = ?'
        );
        $userLookup = $pdo->prepare('SELECT username, namecustom FROM user WHERE id = ? LIMIT 1');

        $advertiserIds = [];
        foreach ($reports as $row) {
            $referrerId = (string) ($row['reagent'] ?? '');
            $invitedId = (string) ($row['user_id'] ?? '');
            if ($referrerId === '' || $invitedId === '') {
                continue;
            }
            if (!isset($advertiserIds[$referrerId])) {
                $findAdvertiser->execute([$referrerId]);
                $existing = $findAdvertiser->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $advertiserIds[$referrerId] = (int) $existing['id'];
                } else {
                    $userLookup->execute([$referrerId]);
                    $userRow = $userLookup->fetch(PDO::FETCH_ASSOC) ?: [];
                    $custom = trim((string) ($userRow['namecustom'] ?? ''));
                    $username = trim((string) ($userRow['username'] ?? ''));
                    if ($custom !== '' && strcasecmp($custom, 'none') !== 0) {
                        $name = $custom;
                    } elseif ($username !== '' && strcasecmp($username, 'none') !== 0) {
                        $name = '@' . ltrim($username, '@');
                    } else {
                        $name = 'کاربر ' . $referrerId;
                    }
                    $started = (string) ($row['time'] ?? date('Y/m/d'));
                    if (preg_match('/^(\d{4}[\/-]\d{2}[\/-]\d{2})/', $started, $m)) {
                        $started = str_replace('-', '/', $m[1]);
                    }
                    $insertAdvertiser->execute([
                        $name,
                        ads_generate_code(),
                        $started,
                        $referrerId,
                        date('Y/m/d H:i:s'),
                    ]);
                    $advertiserIds[$referrerId] = (int) $pdo->lastInsertId();
                }
            }
            $advertiserId = $advertiserIds[$referrerId];
            $joinTime = (string) ($row['time'] ?? date('Y/m/d H:i:s'));
            $insertJoin->execute([$advertiserId, $invitedId, $joinTime]);
            $markReport->execute([$advertiserId, (int) $row['id']]);
            $pdo->prepare(
                "UPDATE user SET affiliates = '0'
                 WHERE id = ? AND CAST(affiliates AS CHAR) = CAST(? AS CHAR)"
            )->execute([$invitedId, $referrerId]);
        }

        if ($advertiserIds) {
            $ids = implode(',', array_map('intval', $advertiserIds));
            $pdo->exec(
                "UPDATE ad_advertiser a
                 LEFT JOIN (
                    SELECT advertiser_id, COUNT(*) AS cnt FROM ad_join GROUP BY advertiser_id
                 ) j ON j.advertiser_id = a.id
                 SET a.join_count = COALESCE(j.cnt, 0)
                 WHERE a.id IN ({$ids})"
            );
        }

        $activeSql = affiliates_report_is_active_sql('r');
        $pdo->exec(
            "UPDATE user u
             LEFT JOIN (
                SELECT referrer_id, COUNT(DISTINCT invited_id) AS cnt
                FROM (
                    SELECT CAST(r.reagent AS CHAR) AS referrer_id, CAST(r.user_id AS CHAR) AS invited_id
                    FROM reagent_report r
                    WHERE {$activeSql}
                    UNION
                    SELECT CAST(pointer.affiliates AS CHAR), CAST(pointer.id AS CHAR)
                    FROM user pointer
                    WHERE IFNULL(pointer.affiliates, '') != '' AND pointer.affiliates != '0'
                      AND NOT EXISTS (
                          SELECT 1 FROM reagent_report r2
                          WHERE CAST(r2.user_id AS CHAR) = CAST(pointer.id AS CHAR)
                      )
                ) inv
                GROUP BY referrer_id
             ) s ON CAST(u.id AS CHAR) = s.referrer_id
             SET u.affiliatescount = CAST(COALESCE(s.cnt, 0) AS CHAR)
             WHERE (IFNULL(u.affiliatescount, '') != '' AND u.affiliatescount != '0')
                OR s.cnt IS NOT NULL"
        );

        $pdo->exec("UPDATE setting SET affiliates_ads_cutoff_migrated = '1'");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('affiliates_migrate_pre_cutoff_to_ads: ' . $e->getMessage());
    }
}

function ads_is_collaboration_link_disabled($user_id): bool
{
    // Ads use start=ad_{code}. Numeric start={id} is collaboration and must stay active
    // even if that user was copied into ad_advertiser by the one-time migration.
    return false;
}

function ads_detach_migrated_affiliates(): void
{
    // No-op: previously zeroed user.affiliatescount for anyone copied into ad_advertiser,
    // which emptied the collaboration referrers list while reagent_report still had invites.
}

function handle_ad_start($code, $from_id, $was_new_user = false, $invited_username = '')
{
    global $pdo, $keyboard, $datatextbot;

    ads_ensure_schema();
    $code = (string) $code;
    $welcome = $datatextbot['text_start'] ?? 'خوش آمدید';

    $stmt = $pdo->prepare('SELECT * FROM ad_advertiser WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    $advertiser = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$advertiser) {
        sendmessage($from_id, $welcome, $keyboard, 'html');
        return ['ok' => false, 'reason' => 'not_found'];
    }

    $createdAt = date('Y/m/d H:i:s');
    try {
        $ins = $pdo->prepare('INSERT INTO ad_join (advertiser_id, user_id, created_at) VALUES (?, ?, ?)');
        $ins->execute([(int) $advertiser['id'], (string) $from_id, $createdAt]);
        $pdo->prepare('UPDATE ad_advertiser SET join_count = join_count + 1 WHERE id = ?')->execute([(int) $advertiser['id']]);
    } catch (Throwable $e) {
        // already counted for this advertiser
    }

    sendmessage($from_id, $welcome, $keyboard, 'html');
    return ['ok' => true];
}

function referral_get_campaign_by_code($code)
{
    referral_ensure_schema();
    return select("referral_campaign", "*", "code", $code, "select");
}

function referral_get_campaign_by_id($id)
{
    referral_ensure_schema();
    return select("referral_campaign", "*", "id", $id, "select");
}

function referral_get_active_campaigns()
{
    referral_ensure_schema();
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM referral_campaign WHERE status = 'active' ORDER BY id DESC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function referral_count_invites($campaign_id, $referrer_id)
{
    referral_ensure_schema();
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referral_invite WHERE campaign_id = ? AND referrer_id = ?");
    $stmt->execute([(int) $campaign_id, (string) $referrer_id]);
    return (int) $stmt->fetchColumn();
}

function referral_has_reward($campaign_id, $user_id)
{
    referral_ensure_schema();
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM referral_reward WHERE campaign_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([(int) $campaign_id, (string) $user_id]);
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

/** Each user's referral identifier is their Telegram numeric ID (auto, no manual code). */
function referral_get_user_code($user_id)
{
    return (string) $user_id;
}

function referral_resolve_campaign($campaign_key)
{
    if (ctype_digit((string) $campaign_key)) {
        return referral_get_campaign_by_id((int) $campaign_key);
    }
    return referral_get_campaign_by_code($campaign_key);
}

function referral_build_link($campaign_id, $referrer_id)
{
    global $usernamebot;
    $campaign_id = (int) $campaign_id;
    $referrer_id = referral_get_user_code($referrer_id);
    return "https://t.me/$usernamebot?start=ref_{$campaign_id}_{$referrer_id}";
}

function referral_validate_campaign_code($code)
{
    return (bool) preg_match('/^[A-Za-z0-9]{2,20}$/', (string) $code);
}

function referral_auto_campaign_code($campaign_id)
{
    return 'REF' . (int) $campaign_id;
}

function provision_free_service($user_id, $product, $panel, $note = 'referral_reward')
{
    global $pdo, $connect, $textbotlang, $setting, $admin_ids, $errorreport, $datatextbot;

    if (!is_array($product) || !is_array($panel)) {
        return ['ok' => false, 'invoice_id' => null, 'msg' => 'invalid product or panel'];
    }

    if (!class_exists('ManagePanel')) {
        require_once __DIR__ . '/panels.php';
    }
    $ManagePanel = new ManagePanel();

    $user_info = select("user", "*", "id", $user_id, "select");
    if (!$user_info) {
        return ['ok' => false, 'invoice_id' => null, 'msg' => 'user not found'];
    }

    $randomString = bin2hex(random_bytes(4));
    $username_ac = generateUsername(
        $user_info['id'],
        $panel['MethodUsername'],
        $user_info['username'],
        $randomString,
        '',
        panel_username_prefix($panel),
        $user_info['namecustom']
    );
    $username_ac = strtolower((string) $username_ac);

    $DataUserOut = $ManagePanel->DataUser($panel['name_panel'], $username_ac);
    if (isset($DataUserOut['username']) || rowExists('invoice', 'username', $username_ac)) {
        return ['ok' => false, 'invoice_id' => null, 'msg' => 'username exists'];
    }

    $notifctions = json_encode(['volume' => false, 'time' => false]);
    $date = time();
    $Status = "active";
    $price = 0;
    $stmt = $connect->prepare("INSERT IGNORE INTO invoice (id_user, id_invoice, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status, note, refral, notifctions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $refral = '0';
    $stmt->bind_param(
        "sssssssssssss",
        $user_id,
        $randomString,
        $username_ac,
        $date,
        $panel['name_panel'],
        $product['name_product'],
        $price,
        $product['Volume_constraint'],
        $product['Service_time'],
        $Status,
        $note,
        $refral,
        $notifctions
    );
    $stmt->execute();
    $stmt->close();

    $datetimestep = strtotime("+" . $product['Service_time'] . "days");
    if (intval($product['Service_time']) == 0) {
        $datetimestep = 0;
    } else {
        $datetimestep = strtotime(date("Y-m-d H:i:s", $datetimestep));
    }

    $datac = [
        'expire' => $datetimestep,
        'data_limit' => intval($product['Volume_constraint']) * pow(1024, 3),
        'from_id' => $user_id,
        'username' => $user_info['username'],
        'type' => 'buy',
    ];

    $dataoutput = $ManagePanel->createUser($panel['name_panel'], $product['code_product'], $username_ac, $datac);
    if (!isset($dataoutput['username']) || $dataoutput['username'] === null || $dataoutput['username'] === '') {
        $errorMessage = $dataoutput['msg'] ?? 'unknown error';
        if (is_array($errorMessage) || is_object($errorMessage)) {
            $errorMessage = json_encode($errorMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (strlen($setting['Channel_Report'] ?? '') > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $errorreport ?? 0,
                'text' => "⭕️ خطای ساخت هدیه دعوت\n{$errorMessage}\nکاربر: {$user_id}",
                'parse_mode' => "HTML",
            ]);
        }
        return ['ok' => false, 'invoice_id' => $randomString, 'msg' => (string) $errorMessage];
    }

    update("invoice", "Status", "active", "username", $username_ac);

    $output_config_link = $panel['sublink'] == "onsublink" ? ($dataoutput['subscription_url'] ?? '') : "";
    $config = "";
    if ($panel['config'] == "onconfig" && is_array($dataoutput['configs'] ?? null)) {
        foreach ($dataoutput['configs'] as $link) {
            $config .= "\n" . $link;
        }
    }

    if (!is_array($datatextbot)) {
        $datatextbot = $pdo->query("SELECT id_text, text FROM textbot")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    $textafterpay = $datatextbot['textafterpay'] ?? '';
    $textafterpay = $panel['type'] == "Manualsale" ? ($datatextbot['textmanual'] ?? $textafterpay) : $textafterpay;
    $textafterpay = $panel['type'] == "WGDashboard" ? ($datatextbot['text_wgdashboard'] ?? $textafterpay) : $textafterpay;
    $textafterpay = ($panel['type'] == "ibsng" || $panel['type'] == "mikrotik") ? ($datatextbot['textafterpayibsng'] ?? $textafterpay) : $textafterpay;

    $service_time = $product['Service_time'];
    $volume = $product['Volume_constraint'];
    if (intval($service_time) == 0) {
        $service_time = $textbotlang['users']['stateus']['Unlimited'] ?? 'نامحدود';
    }
    if (intval($volume) == 0) {
        $volume = $textbotlang['users']['stateus']['Unlimited'] ?? 'نامحدود';
    }

    $textcreatuser = str_replace('{username}', "<code>{$dataoutput['username']}</code>", $textafterpay);
    $textcreatuser = str_replace('{name_service}', $product['name_product'], $textcreatuser);
    $textcreatuser = str_replace('{location}', $panel['name_panel'], $textcreatuser);
    $textcreatuser = str_replace('{day}', $service_time, $textcreatuser);
    $textcreatuser = str_replace('{volume}', $volume, $textcreatuser);
    $textcreatuser = str_replace('{config}', "<code>{$output_config_link}</code>", $textcreatuser);
    $textcreatuser = str_replace('{links}', $config, $textcreatuser);
    $textcreatuser = str_replace('{links2}', $output_config_link, $textcreatuser);
    if (intval($product['Volume_constraint']) == 0) {
        $textcreatuser = str_replace('گیگابایت', "", $textcreatuser);
    }
    if (in_array($panel['type'], ['Manualsale', 'ibsng', 'mikrotik'], true)) {
        $textcreatuser = str_replace('{password}', $dataoutput['subscription_url'] ?? '', $textcreatuser);
        update("invoice", "user_info", $dataoutput['subscription_url'] ?? '', "id_invoice", $randomString);
    }

    $Shoppinginfo = json_encode([
        'inline_keyboard' => [
            [['text' => $textbotlang['users']['help']['btninlinebuy'] ?? 'راهنما', 'callback_data' => "helpbtn"]],
        ],
    ]);

    sendMessageService($panel, $dataoutput['configs'] ?? [], $output_config_link, $dataoutput['username'], $Shoppinginfo, $textcreatuser, $randomString, $user_id);

    if ($panel['MethodUsername'] == "متن دلخواه + عدد ترتیبی" || $panel['MethodUsername'] == "نام کاربری + عدد به ترتیب" || $panel['MethodUsername'] == "آیدی عددی+عدد ترتیبی" || $panel['MethodUsername'] == "متن دلخواه نماینده + عدد ترتیبی") {
        $value = intval($user_info['number_username']) + 1;
        update("user", "number_username", $value, "id", $user_id);
        if ($panel['MethodUsername'] == "متن دلخواه + عدد ترتیبی" || $panel['MethodUsername'] == "متن دلخواه نماینده + عدد ترتیبی") {
            $value = intval($setting['numbercount']) + 1;
            update("setting", "numbercount", $value);
        }
    }

    return ['ok' => true, 'invoice_id' => $randomString, 'msg' => 'ok', 'username' => $dataoutput['username']];
}

function referral_check_and_grant_reward($campaign, $referrer_id)
{
    global $setting, $pdo, $buyreport, $usernamebot;

    if (!is_array($campaign)) {
        return false;
    }

    $campaign_id = (int) $campaign['id'];
    if (referral_has_reward($campaign_id, $referrer_id)) {
        return false;
    }

    $invite_count = referral_count_invites($campaign_id, $referrer_id);
    if ($invite_count < (int) $campaign['required_invites']) {
        return false;
    }

    $product = select("product", "*", "code_product", $campaign['code_product'], "select");
    $panel = select("marzban_panel", "*", "name_panel", $campaign['panel_name'], "select");
    if (!$product || !$panel) {
        return false;
    }

    $result = provision_free_service($referrer_id, $product, $panel, 'referral_reward_' . $campaign['code']);
    if (!$result['ok']) {
        return false;
    }

    $granted_at = date('Y/m/d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO referral_reward (campaign_id, user_id, id_invoice, granted_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$campaign_id, (string) $referrer_id, $result['invoice_id'], $granted_at]);

    $reward_text = "<b>🎉 تبریک! هدیه دعوت دریافت شد</b>\n\n";
    $reward_text .= "کمپین: <b>{$campaign['title']}</b>\n";
    $reward_text .= "سرویس: <b>{$product['name_product']}</b>\n";
    $reward_text .= "نام کاربری: <code>{$result['username']}</code>";
    sendmessage($referrer_id, $reward_text, null, 'HTML');

    if (strlen($setting['Channel_Report'] ?? '') > 0) {
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $buyreport ?? 0,
            'text' => "🎁 هدیه دعوت\nکمپین: {$campaign['title']}\nکاربر: {$referrer_id}\nسرویس: {$product['name_product']}",
            'parse_mode' => "HTML",
        ]);
    }

    return true;
}

function handle_referral_start($campaign_key, $referrer_id, $invited_user_id, $was_new_user, $invited_username = '')
{
    global $setting, $pdo, $keyboard;

    $referrer_id = referral_get_user_code($referrer_id);
    $campaign = referral_resolve_campaign($campaign_key);
    if (!$campaign || ($campaign['status'] ?? '') !== 'active') {
        return ['ok' => false, 'reason' => 'inactive'];
    }
    if (($setting['referralstatus'] ?? 'offreferral') !== 'onreferral') {
        return ['ok' => false, 'reason' => 'disabled'];
    }
    if ((string) $referrer_id === (string) $invited_user_id) {
        sendmessage($invited_user_id, "❌ نمی‌توانید از لینک دعوت خودتان استفاده کنید.", null, 'HTML');
        return ['ok' => false, 'reason' => 'self'];
    }
    if (!rowExists('user', 'id', $referrer_id)) {
        return ['ok' => false, 'reason' => 'invalid_referrer'];
    }
    if (intval($campaign['new_users_only'] ?? 1) === 1 && !$was_new_user) {
        sendmessage($invited_user_id, "❌ این لینک دعوت فقط برای کاربران جدید معتبر است.", $keyboard, 'HTML');
        return ['ok' => false, 'reason' => 'not_new_user'];
    }

    $stmt = $pdo->prepare("SELECT id FROM referral_invite WHERE campaign_id = ? AND invited_user_id = ? LIMIT 1");
    $stmt->execute([(int) $campaign['id'], (string) $invited_user_id]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return ['ok' => false, 'reason' => 'already_invited'];
    }

    $created_at = date('Y/m/d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO referral_invite (campaign_id, referrer_id, invited_user_id, created_at) VALUES (?, ?, ?, ?)");
    try {
        $stmt->execute([(int) $campaign['id'], (string) $referrer_id, (string) $invited_user_id, $created_at]);
    } catch (Exception $e) {
        return ['ok' => false, 'reason' => 'duplicate'];
    }

    $referrer = select("user", "*", "id", $referrer_id, "select");
    $referrer_name = '';
    $chat = telegram('getChat', ['chat_id' => $referrer_id]);
    if (!empty($chat['ok']) && !empty($chat['result']['first_name'])) {
        $referrer_name = sanitizeUserName($chat['result']['first_name']);
        if (!empty($chat['result']['last_name'])) {
            $referrer_name .= ' ' . sanitizeUserName($chat['result']['last_name']);
        }
    }
    if ($referrer_name === '') {
        $referrer_name = $referrer['namecustom'] ?? '';
        if ($referrer_name === '' || $referrer_name === 'none') {
            $referrer_name = 'یک کاربر';
        }
    }
    sendmessage($invited_user_id, "<b>🎉 خوش آمدید!</b>\n\nشما با دعوت <b>{$referrer_name}</b> وارد ربات شدید.", $keyboard, 'HTML');

    $invite_count = referral_count_invites($campaign['id'], $referrer_id);
    $required = (int) $campaign['required_invites'];
    sendmessage($referrer_id, "✅ یک دعوت جدید ثبت شد!\n\nکمپین: <b>{$campaign['title']}</b>\nپیشرفت: {$invite_count} / {$required}", null, 'HTML');

    referral_check_and_grant_reward($campaign, $referrer_id);

    return ['ok' => true, 'reason' => 'registered', 'count' => $invite_count];
}

function referral_render_user_message($campaign, $user_id)
{
    global $usernamebot;

    if (!is_array($campaign)) {
        return '';
    }

    $user_id = referral_get_user_code($user_id);
    $link = referral_build_link($campaign['id'], $user_id);
    $invite_count = referral_count_invites($campaign['id'], $user_id);
    $required = (int) $campaign['required_invites'];
    $rewarded = referral_has_reward($campaign['id'], $user_id);

    $product = select("product", "*", "code_product", $campaign['code_product'], "select");
    $product_name = $product['name_product'] ?? $campaign['code_product'];

    $text = "<b>🎁 {$campaign['title']}</b>\n\n";
    if (!empty($campaign['description']) && $campaign['description'] !== 'none') {
        $text .= $campaign['description'] . "\n\n";
    }
    $text .= "🎯 هدف: <b>{$required}</b> دعوت\n";
    $text .= "🏆 جایزه: <b>{$product_name}</b>\n";
    $text .= "📊 پیشرفت شما: <b>{$invite_count} / {$required}</b>\n\n";
    $text .= "🆔 کد دعوت شما: <code>{$user_id}</code>\n";
    $text .= "🔗 لینک اختصاصی:\n<code>{$link}</code>\n";

    if ($rewarded) {
        $text .= "\n✅ جایزه این کمپین قبلاً برای شما فعال شده است.";
    } elseif ($invite_count >= $required) {
        $text .= "\n⏳ جایزه در حال آماده‌سازی است...";
    }

    $keyboard_rows = [
        [['text' => "🔗 اشتراک‌گذاری لینک", 'url' => "https://t.me/share/url?url=" . urlencode($link)]],
    ];

    return [
        'text' => $text,
        'keyboard' => json_encode(['inline_keyboard' => $keyboard_rows], JSON_UNESCAPED_UNICODE),
    ];
}

function admin_telegram_staff_rules(): array
{
    return ['administrator', 'support', 'Seller'];
}

function admin_telegram_ids(): array
{
    global $pdo;
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    try {
        $stmt = $pdo->query("SELECT id_admin FROM admin WHERE rule IN ('administrator','support','Seller')");
        $ids = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
    } catch (Throwable $e) {
        $ids = [];
    }
    return $ids;
}

function get_support_admin_ids()
{
    $admin_ids = admin_telegram_ids();
    if (!is_array($admin_ids)) {
        return [];
    }

    $support_admins = [];
    foreach ($admin_ids as $id_admin) {
        $admin = select("admin", "*", "id_admin", $id_admin, "select");
        if (!$admin || $admin['rule'] === 'Seller') {
            continue;
        }
        $support_admins[] = $id_admin;
    }

    return $support_admins;
}

function notify_support_admins($text, $keyboard, $photo = false, $video = false, $photoid = null, $videoid = null)
{
    foreach (get_support_admin_ids() as $id_admin) {
        if ($photo && $photoid) {
            sendphoto($id_admin, $photoid, null);
        }
        if ($video && $videoid) {
            sendvideo($id_admin, $videoid, null);
        }
        sendmessage($id_admin, $text, $keyboard, 'HTML');
    }
}

function support_incoming_media($photo, $photoid, $video, $videoid, $document, $fileid, $audio = 0, $audioid = 0, $voice = 0, $voiceid = 0): array
{
    $media = [];
    if ($photo && $photoid) {
        $largest = is_array($photo) ? (end($photo) ?: []) : [];
        $media[] = ['photo', $photoid, $largest['file_unique_id'] ?? null, 'image/jpeg', null, $largest['file_size'] ?? null];
    }
    if (is_array($video) && $videoid) {
        $media[] = ['video', $videoid, $video['file_unique_id'] ?? null, $video['mime_type'] ?? null, $video['file_name'] ?? null, $video['file_size'] ?? null];
    }
    if (is_array($document) && $fileid) {
        $media[] = ['document', $fileid, $document['file_unique_id'] ?? null, $document['mime_type'] ?? null, $document['file_name'] ?? null, $document['file_size'] ?? null];
    }
    if (is_array($audio) && $audioid) {
        $media[] = ['audio', $audioid, $audio['file_unique_id'] ?? null, $audio['mime_type'] ?? null, $audio['file_name'] ?? null, $audio['file_size'] ?? null];
    }
    if (is_array($voice) && $voiceid) {
        $media[] = ['voice', $voiceid, $voice['file_unique_id'] ?? null, $voice['mime_type'] ?? 'audio/ogg', null, $voice['file_size'] ?? null];
    }
    return $media;
}

function support_add_column_if_missing(PDO $pdo, string $table, string $column, string $datatype): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    $pdo->exec("ALTER TABLE `$table` ADD `$column` $datatype");
}

function support_ensure_schema(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        support_add_column_if_missing(
            $pdo,
            'support_message',
            'user_name',
            'VARCHAR(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL'
        );
        support_add_column_if_missing($pdo, 'support_message', 'answered_by_admin_id', 'VARCHAR(100) NULL');
        support_add_column_if_missing($pdo, 'support_message', 'answered_by_admin_username', 'VARCHAR(1000) NULL');
        support_add_column_if_missing($pdo, 'support_message', 'answered_at', 'VARCHAR(200) NULL');
        support_add_column_if_missing($pdo, 'support_message', 'bottype', 'VARCHAR(200) NULL');
        support_ensure_media_table($pdo);
        support_ensure_conversation_table($pdo);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Unable to ensure support schema: ' . $e->getMessage());
        return $ready = false;
    }
}

function support_conversation_statuses(): array
{
    return ['Unseen', 'Answered', 'close', 'flagged', 'کمپین'];
}

function support_conversation_status_enum_sql(): string
{
    return "ENUM('Unseen','Answered','close','flagged','کمپین') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unseen'";
}

function support_ensure_conversation_status_enum(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM support_conversation LIKE 'status'");
    $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    $type = (string) ($col['Type'] ?? '');
    if (mb_strpos($type, 'کمپین') === false) {
        $pdo->exec('ALTER TABLE support_conversation MODIFY status ' . support_conversation_status_enum_sql());
    }
    $done = true;
}

function support_ensure_conversation_table(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS support_conversation (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            iduser VARCHAR(100) NOT NULL,
            idsupport VARCHAR(100) NULL,
            name_departman VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
            user_name VARCHAR(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
            status " . support_conversation_status_enum_sql() . ",
            last_message_id INT UNSIGNED NULL,
            last_message_at VARCHAR(200) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_support_conversation_user (iduser),
            INDEX idx_support_conversation_status (status),
            INDEX idx_support_conversation_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        support_ensure_conversation_status_enum($pdo);
        support_add_column_if_missing($pdo, 'support_conversation', 'bottype', 'VARCHAR(200) NULL');
        support_backfill_conversations($pdo);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Unable to create support_conversation table: ' . $e->getMessage());
        return $ready = false;
    }
}

function support_conversation_status_from_messages(array $messages): string
{
    if (!$messages) {
        return 'Unseen';
    }

    $unanswered = ['Unseen', 'Customerresponse', 'Pending'];
    $hasOpen = false;
    foreach ($messages as $message) {
        if (($message['status'] ?? '') !== 'close') {
            $hasOpen = true;
        }
    }
    foreach (array_reverse($messages) as $message) {
        $status = (string) ($message['status'] ?? '');
        if (in_array($status, $unanswered, true)) {
            return 'Unseen';
        }
    }

    return $hasOpen ? 'Answered' : 'close';
}

function support_backfill_conversations(PDO $pdo): void
{
    try {
        $users = $pdo->query(
            "SELECT s.iduser
             FROM support_message s
             LEFT JOIN support_conversation c ON c.iduser = s.iduser
             WHERE c.id IS NULL
             GROUP BY s.iduser"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($users as $iduser) {
            $messages = $pdo->prepare('SELECT * FROM support_message WHERE iduser = ? ORDER BY id ASC');
            $messages->execute([(string) $iduser]);
            $rows = $messages->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                continue;
            }
            $latest = $rows[count($rows) - 1];
            $status = support_conversation_status_from_messages($rows);
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO support_conversation
                 (iduser, idsupport, name_departman, user_name, status, last_message_id, last_message_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (string) $iduser,
                $latest['idsupport'] ?? null,
                $latest['name_departman'] ?? null,
                $latest['user_name'] ?? null,
                $status,
                (int) ($latest['id'] ?? 0) ?: null,
                $latest['time'] ?? null,
            ]);
        }
    } catch (Throwable $e) {
        error_log('support_backfill_conversations: ' . $e->getMessage());
    }
}

/**
 * @param array{idsupport?:mixed,name_departman?:mixed,user_name?:mixed} $meta
 */
function support_conversation_touch(PDO $pdo, string $iduser, array $meta = [], ?string $status = null, ?int $lastMessageId = null, ?string $lastMessageAt = null): void
{
    if ($iduser === '' || !support_ensure_conversation_table($pdo)) {
        return;
    }
    if ($status !== null && !in_array($status, support_conversation_statuses(), true)) {
        $status = 'Unseen';
    }

    try {
        $current = $pdo->prepare('SELECT id, status FROM support_conversation WHERE iduser = ? LIMIT 1');
        $current->execute([$iduser]);
        $row = $current->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $stmt = $pdo->prepare(
                'INSERT INTO support_conversation
                 (iduser, idsupport, name_departman, user_name, status, last_message_id, last_message_at, bottype)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $iduser,
                $meta['idsupport'] ?? null,
                $meta['name_departman'] ?? null,
                $meta['user_name'] ?? null,
                $status ?? 'Unseen',
                $lastMessageId,
                $lastMessageAt,
                $meta['bottype'] ?? null,
            ]);
            return;
        }

        $fields = [];
        $params = [];
        foreach (['idsupport', 'name_departman', 'user_name', 'bottype'] as $key) {
            if (array_key_exists($key, $meta) && $meta[$key] !== null && $meta[$key] !== '') {
                $fields[] = "$key = ?";
                $params[] = $meta[$key];
            }
        }
        if ($status !== null) {
            $fields[] = 'status = ?';
            $params[] = $status;
        }
        if ($lastMessageId !== null) {
            $fields[] = 'last_message_id = ?';
            $params[] = $lastMessageId;
        }
        if ($lastMessageAt !== null) {
            $fields[] = 'last_message_at = ?';
            $params[] = $lastMessageAt;
        }
        if (!$fields) {
            return;
        }
        $params[] = $iduser;
        $pdo->prepare('UPDATE support_conversation SET ' . implode(', ', $fields) . ' WHERE iduser = ?')->execute($params);
    } catch (Throwable $e) {
        error_log('support_conversation_touch: ' . $e->getMessage());
    }
}

function support_record_inbound_ticket(PDO $pdo, $fromId, string $text, array $meta = []): array
{
    if (!support_ensure_schema($pdo)) {
        return ['ok' => false, 'msg' => 'سامانه پشتیبانی در دسترس نیست.'];
    }
    $fromId = (string) $fromId;
    $text = trim($text);
    if ($fromId === '' || $text === '') {
        return ['ok' => false, 'msg' => 'متن پیام خالی است.'];
    }
    $time = date('Y/m/d H:i:s');
    $tracking = bin2hex(random_bytes(4));
    $status = 'Unseen';
    $bottype = isset($meta['bottype']) ? (string) $meta['bottype'] : null;
    $idsupport = (string) ($meta['idsupport'] ?? 'sellbot');
    $departman = (string) ($meta['name_departman'] ?? 'ربات فروش');
    $userName = (string) ($meta['user_name'] ?? '');
    try {
        $stmt = $pdo->prepare('INSERT INTO support_message (Tracking,idsupport,iduser,user_name,name_departman,text,time,status,bottype) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$tracking, $idsupport, $fromId, $userName, $departman, $text, $time, $status, $bottype]);
        $messageId = (int) $pdo->lastInsertId();
        if ($messageId > 0 && !empty($meta['media']) && is_array($meta['media'])) {
            support_store_media($pdo, $messageId, 'in', $meta['media']);
        }
        support_conversation_touch(
            $pdo,
            $fromId,
            [
                'idsupport' => $idsupport,
                'name_departman' => $departman,
                'user_name' => $userName,
                'bottype' => $bottype,
            ],
            'Unseen',
            $messageId > 0 ? $messageId : null,
            $time
        );
        return ['ok' => true, 'tracking' => $tracking, 'id' => $messageId];
    } catch (Throwable $e) {
        error_log('support_record_inbound_ticket: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'ثبت پیام پشتیبانی ناموفق بود.'];
    }
}

function support_record_campaign_message(PDO $pdo, string $userId, string $message, array $admin = []): void
{
    if ($userId === '' || $message === '' || !support_ensure_schema($pdo)) {
        return;
    }
    $now = date('Y/m/d H:i:s');
    $tracking = bin2hex(random_bytes(4));
    $userName = '';
    try {
        $stmt = $pdo->prepare('SELECT username, namecustom FROM user WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $name = (string) ($user['namecustom'] ?? '');
        $uname = (string) ($user['username'] ?? '');
        if ($name !== '' && $name !== 'none') {
            $userName = $name;
        } elseif ($uname !== '' && $uname !== 'none') {
            $userName = '@' . $uname;
        }
        $insert = $pdo->prepare(
            "INSERT INTO support_message
             (Tracking, idsupport, iduser, user_name, name_departman, text, time, status, result,
              answered_by_admin_id, answered_by_admin_username, answered_at)
             VALUES (?, ?, ?, ?, ?, '', ?, 'Answered', ?, ?, ?, ?)"
        );
        $insert->execute([
            $tracking,
            (string) ($admin['id_admin'] ?? ''),
            $userId,
            $userName,
            'کمپین',
            $now,
            $message,
            (string) ($admin['id_admin'] ?? ''),
            (string) ($admin['username'] ?? ''),
            $now,
        ]);
        $messageId = (int) $pdo->lastInsertId();
        support_conversation_touch(
            $pdo,
            $userId,
            [
                'idsupport' => $admin['id_admin'] ?? null,
                'name_departman' => 'کمپین',
                'user_name' => $userName !== '' ? $userName : null,
            ],
            'کمپین',
            $messageId > 0 ? $messageId : null,
            $now
        );
    } catch (Throwable $e) {
        error_log('support_record_campaign_message: ' . $e->getMessage());
    }
}

function support_conversation_set_status(PDO $pdo, string $iduser, string $status): bool
{
    if ($iduser === '' || !in_array($status, support_conversation_statuses(), true)) {
        return false;
    }
    if (!support_ensure_conversation_table($pdo)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('UPDATE support_conversation SET status = ? WHERE iduser = ?');
        $stmt->execute([$status, $iduser]);
        if ($stmt->rowCount() > 0) {
            return true;
        }
        support_conversation_touch($pdo, $iduser, [], $status);
        return true;
    } catch (Throwable $e) {
        error_log('support_conversation_set_status: ' . $e->getMessage());
        return false;
    }
}

function support_conversation_get(PDO $pdo, string $iduser): ?array
{
    if ($iduser === '' || !support_ensure_conversation_table($pdo)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM support_conversation WHERE iduser = ? LIMIT 1');
        $stmt->execute([$iduser]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function support_ensure_media_table(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        // No FOREIGN KEY: avoids metadata locks / engine mismatches that can freeze webhook requests.
        $pdo->exec("CREATE TABLE IF NOT EXISTS support_media (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id INT(6) UNSIGNED NOT NULL,
            direction ENUM('in','out') NOT NULL,
            media_type ENUM('photo','video','document','audio','voice') NOT NULL,
            telegram_file_id VARCHAR(255) NOT NULL,
            telegram_file_unique_id VARCHAR(255) NULL,
            mime_type VARCHAR(255) NULL,
            file_name VARCHAR(500) NULL,
            file_size INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_support_media_message (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ready = true;
    } catch (PDOException $e) {
        error_log('Unable to create support_media table: ' . $e->getMessage());
        return $ready = false;
    }
}

function support_store_media(PDO $pdo, int $messageId, string $direction, array $media): void
{
    if ($messageId < 1 || !$media || !support_ensure_media_table($pdo)) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO support_media (message_id, direction, media_type, telegram_file_id, telegram_file_unique_id, mime_type, file_name, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($media as [$type, $fileId, $uniqueId, $mime, $fileName, $fileSize]) {
            $stmt->execute([$messageId, $direction, $type, $fileId, $uniqueId, $mime, $fileName, $fileSize]);
        }
    } catch (Throwable $e) {
        error_log('support_store_media: ' . $e->getMessage());
    }
}

function product_ensure_sort_order_column(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'sort_order'");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE product ADD sort_order INT NOT NULL DEFAULT 0');
            $pdo->exec('UPDATE product SET sort_order = id WHERE sort_order = 0');
        }
    } catch (Throwable $e) {
        error_log('product_ensure_sort_order_column: ' . $e->getMessage());
    }
    $ensured = true;
}

function product_sort_value(array $product): int
{
    $sort = (int) ($product['sort_order'] ?? 0);
    if ($sort > 0) {
        return $sort;
    }
    return (int) ($product['id'] ?? 0);
}

function sortProductsByOrder(array $products): array
{
    usort($products, function ($a, $b) {
        $cmp = product_sort_value($a) <=> product_sort_value($b);
        if ($cmp !== 0) {
            return $cmp;
        }
        return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
    });
    return $products;
}

function product_category_where(string $category, string $column = 'category'): array
{
    if ($category === '') {
        return ['sql' => "({$column} IS NULL OR {$column} = '')", 'params' => []];
    }
    return ['sql' => "{$column} = ?", 'params' => [$category]];
}

function product_renormalize_category_sort_orders(PDO $pdo, string $category): void
{
    $where = product_category_where($category);
    $stmt = $pdo->prepare("SELECT id FROM product WHERE {$where['sql']} ORDER BY sort_order ASC, id ASC");
    $stmt->execute($where['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $i => $row) {
        $update = $pdo->prepare('UPDATE product SET sort_order = ? WHERE id = ?');
        $update->execute([$i + 1, (int) $row['id']]);
    }
}

function product_apply_category_sort_order(PDO $pdo, string $category, array $orderedIds): void
{
    $where = product_category_where($category);
    $stmt = $pdo->prepare("SELECT id FROM product WHERE {$where['sql']}");
    $stmt->execute($where['params']);
    $existingIds = array_map(static fn($row) => (int) $row['id'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    sort($existingIds);

    $ids = array_values(array_unique(array_map('intval', $orderedIds)));
    sort($ids);
    if ($ids !== $existingIds) {
        throw new InvalidArgumentException('ترتیب محصولات نامعتبر است.');
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE product SET sort_order = ? WHERE id = ?');
        foreach ($orderedIds as $i => $id) {
            $update->execute([$i + 1, (int) $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function product_next_sort_order(string $category = ''): int
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return 1;
    }
    try {
        $where = product_category_where($category);
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM product WHERE {$where['sql']}");
        $stmt->execute($where['params']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return max(1, (int) ($row['n'] ?? 1));
    } catch (Throwable $e) {
        return 1;
    }
}

function product_ensure_hwid_limit_column(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return;
    }
    addFieldToTable('product', 'hwid_limit', null, 'INT');
    $ensured = true;
}

product_ensure_hwid_limit_column();
product_ensure_sort_order_column();

#----------- agent volume / sell bot ------------#

function agent_ensure_volume_columns(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    addFieldToTable('user', 'agent_volume_remaining', '0', 'VARCHAR(100)');
    addFieldToTable('user', 'agent_price_per_gb', '0', 'VARCHAR(100)');
    addFieldToTable('user', 'agent_price_tiers', '[]', 'TEXT');
    addFieldToTable('user', 'agent_n2_consumed', '0', 'VARCHAR(100)');
    addFieldToTable('user', 'agent_consumed_total', '0', 'VARCHAR(100)');
    $ensured = true;
}

function agent_ensure_n2_tables(): void
{
    global $pdo;
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_n2_product (
            agent_id VARCHAR(200) NOT NULL,
            code_product VARCHAR(200) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (agent_id, code_product)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_n2_category (
            agent_id VARCHAR(200) NOT NULL,
            category VARCHAR(300) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (agent_id, category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_n2_purchase (
            id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agent_id VARCHAR(200) NOT NULL,
            code_product VARCHAR(200) NULL,
            name_product VARCHAR(300) NULL,
            volume VARCHAR(100) NULL,
            service_time VARCHAR(100) NULL,
            panel VARCHAR(300) NULL,
            username_service VARCHAR(300) NULL,
            id_invoice VARCHAR(200) NULL,
            price_product VARCHAR(200) NULL,
            created_at INT(11) NOT NULL,
            INDEX idx_agent_created (agent_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_own_category (
            id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agent_id VARCHAR(200) NOT NULL,
            remark VARCHAR(300) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            description TEXT NULL,
            UNIQUE KEY uniq_agent_remark (agent_id, remark),
            INDEX idx_agent_own_cat (agent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_own_product (
            id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agent_id VARCHAR(200) NOT NULL,
            code_product VARCHAR(200) NOT NULL,
            name_product VARCHAR(500) NOT NULL,
            price_product VARCHAR(200) NOT NULL DEFAULT '0',
            Volume_constraint VARCHAR(200) NOT NULL DEFAULT '0',
            Service_time VARCHAR(200) NOT NULL DEFAULT '0',
            category VARCHAR(300) NULL,
            Location VARCHAR(200) NOT NULL DEFAULT '/all',
            note TEXT NULL,
            data_limit_reset VARCHAR(200) NOT NULL DEFAULT 'no_reset',
            one_buy_status VARCHAR(20) NOT NULL DEFAULT '0',
            hide_panel TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            emoji_id VARCHAR(64) NULL,
            UNIQUE KEY uniq_own_code (code_product),
            INDEX idx_agent_own_prod (agent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        // One-time migrate: product whitelist → category whitelist
        agent_n2_migrate_products_to_categories();
    } catch (Throwable $e) {
        error_log('agent_ensure_n2_tables: ' . $e->getMessage());
    }
    $ensured = true;
}

/**
 * If agent has product whitelist but no categories yet, derive categories from those products.
 */
function agent_n2_migrate_products_to_categories(): void
{
    global $pdo;
    static $migrated = false;
    if ($migrated) {
        return;
    }
    $migrated = true;
    try {
        $agents = $pdo->query("SELECT DISTINCT agent_id FROM agent_n2_product WHERE enabled = 1")->fetchAll(PDO::FETCH_COLUMN);
        if (!$agents) {
            return;
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO agent_n2_category (agent_id, category, enabled) VALUES (?, ?, 1)');
        foreach ($agents as $agentId) {
            $hasCat = $pdo->prepare('SELECT COUNT(*) FROM agent_n2_category WHERE agent_id = ? AND enabled = 1');
            $hasCat->execute([(string) $agentId]);
            if ((int) $hasCat->fetchColumn() > 0) {
                continue;
            }
            $stmt = $pdo->prepare("SELECT DISTINCT p.category
                FROM agent_n2_product ap
                INNER JOIN product p ON p.code_product = ap.code_product
                WHERE ap.agent_id = ? AND ap.enabled = 1 AND p.category IS NOT NULL AND p.category != ''");
            $stmt->execute([(string) $agentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cat) {
                $ins->execute([(string) $agentId, (string) $cat]);
            }
        }
    } catch (Throwable $e) {
        error_log('agent_n2_migrate_products_to_categories: ' . $e->getMessage());
    }
}

function agent_is_reseller($agent): bool
{
    return in_array((string) $agent, ['n', 'n2'], true);
}

function agent_is_n2($agent): bool
{
    return (string) $agent === 'n2';
}

function agent_n2_custom_volume_denied_text(): string
{
    return '❌ در ربات نماینده پیشرفته خرید با حجم و زمان درخواستی غیرفعال است. فقط محصولات تعریف‌شده قابل خرید هستند.';
}

/**
 * Roles that buy only from panel-assigned category whitelist (n).
 * n2 uses its own product catalog instead.
 */
function agent_uses_category_whitelist($agent): bool
{
    return (string) $agent === 'n';
}

function agent_owns_catalog($agent): bool
{
    return agent_is_n2($agent);
}

/**
 * Total GB volume used when creating volumetric services (from invoices).
 */
function agent_sum_volume_created($agentUserId): float
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CAST(Volume AS DECIMAL(12,2))), 0)
            FROM invoice
            WHERE id_user = :id
              AND name_product != 'سرویس تست'
              AND Status IN ('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold')");
        $stmt->execute([':id' => (string) $agentUserId]);
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('agent_sum_volume_created: ' . $e->getMessage());
        return 0.0;
    }
}

/**
 * n2 period counter (resettable). Other roles: invoice sum.
 */
function agent_sum_volume_consumed($agentUserId, $agent = null): float
{
    $user = select('user', '*', 'id', $agentUserId, 'select');
    if ($agent === null) {
        $agent = $user['agent'] ?? 'f';
    }
    if (agent_is_n2($agent)) {
        agent_ensure_volume_columns();
        if (!is_array($user) || !array_key_exists('agent_n2_consumed', $user)) {
            $user = select('user', '*', 'id', $agentUserId, 'select');
        }
        return (float) (($user['agent_n2_consumed'] ?? 0));
    }
    return agent_sum_volume_created($agentUserId);
}

function agent_consumed_total($agentUserId, $user = null): float
{
    agent_ensure_volume_columns();
    if (!is_array($user)) {
        $user = select('user', '*', 'id', $agentUserId, 'select');
    }
    return (float) ($user['agent_consumed_total'] ?? 0);
}

function agent_n2_add_consumed($agentUserId, $volumeGb): void
{
    agent_ensure_volume_columns();
    $volumeGb = (float) $volumeGb;
    if ($volumeGb <= 0) {
        return;
    }
    $user = select('user', '*', 'id', $agentUserId, 'select');
    if (!$user) {
        return;
    }
    $period = (float) ($user['agent_n2_consumed'] ?? 0) + $volumeGb;
    $total = (float) ($user['agent_consumed_total'] ?? 0) + $volumeGb;
    update('user', 'agent_n2_consumed', (string) $period, 'id', $agentUserId);
    update('user', 'agent_consumed_total', (string) $total, 'id', $agentUserId);
}

function agent_n2_reset_period_counter($agentUserId): void
{
    agent_ensure_volume_columns();
    update('user', 'agent_n2_consumed', '0', 'id', $agentUserId);
}

/**
 * When a user/n becomes n2: period counter starts at zero.
 * Lifetime keeps previous value; if empty, seed from invoice/purchase history.
 */
function agent_on_role_changed($agentUserId, $oldRole, $newRole): void
{
    if ((string) $newRole !== 'n2' || (string) $oldRole === 'n2') {
        return;
    }
    agent_ensure_volume_columns();
    agent_ensure_n2_tables();
    $user = select('user', '*', 'id', $agentUserId, 'select');
    $lifetime = (float) ($user['agent_consumed_total'] ?? 0);
    if ($lifetime <= 0) {
        $lifetime = agent_sum_volume_created($agentUserId);
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(CAST(volume AS DECIMAL(12,2))), 0)
                FROM agent_n2_purchase WHERE agent_id = :id");
            $stmt->execute([':id' => (string) $agentUserId]);
            $lifetime = max($lifetime, (float) $stmt->fetchColumn());
        } catch (Throwable $e) {
        }
        update('user', 'agent_consumed_total', (string) $lifetime, 'id', $agentUserId);
    }
    update('user', 'agent_n2_consumed', '0', 'id', $agentUserId);
}

/**
 * SQL fragment restricting products by role.
 * n: products whose category is enabled for the agent (catalog).
 * n2: own products only (use with agent_own_product / agent_product_select_sql).
 * others: product.agent = role.
 */
function agent_product_access_sql($agent, $agentUserId): string
{
    agent_ensure_n2_tables();
    if (agent_is_n2($agent)) {
        $aid = agent_n2_agent_id($agentUserId);
        if ($aid === '') {
            return '0=1';
        }
        return "(agent_id = '{$aid}' OR agent_id = '" . addslashes((string) $agentUserId) . "')";
    }
    if (agent_uses_category_whitelist($agent)) {
        $aid = preg_replace('/\D/', '', (string) $agentUserId);
        if ($aid === '') {
            return '0=1';
        }
        return "EXISTS (SELECT 1 FROM agent_n2_category ac WHERE (ac.agent_id = '{$aid}' OR ac.agent_id = '" . addslashes((string) $agentUserId) . "') AND ac.enabled = 1 AND ac.category = product.category)";
    }
    return "product.agent = '" . addslashes((string) $agent) . "'";
}

function agent_product_table($agent): string
{
    return agent_is_n2($agent) ? 'agent_own_product' : 'product';
}

/**
 * SELECT * FROM the correct product table with role access applied.
 */
function agent_product_select_sql($agent, $agentUserId, string $where = ''): string
{
    agent_ensure_n2_tables();
    $table = agent_product_table($agent);
    $access = agent_product_access_sql($agent, $agentUserId);
    $where = trim($where);
    if ($where === '') {
        return "SELECT * FROM {$table} WHERE {$access}";
    }
    return "SELECT * FROM {$table} WHERE {$where} AND {$access}";
}

function agent_shop_product_count($agent, $agentUserId): int
{
    if (agent_is_n2($agent)) {
        return agent_own_product_count($agentUserId);
    }
    return count_products();
}

function agent_shop_get_product($agent, $agentUserId, $codeProduct, $location = null): ?array
{
    if (agent_is_n2($agent)) {
        return agent_own_get_product($agentUserId, $codeProduct, $location);
    }
    if ($location) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT * FROM product WHERE code_product = :code AND (Location = :loc OR Location = '/all') LIMIT 1");
        $stmt->execute([':code' => $codeProduct, ':loc' => $location]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    $row = select('product', '*', 'code_product', $codeProduct, 'select');
    return $row ?: null;
}

function agent_n2_agent_id($agentUserId): string
{
    $raw = trim((string) $agentUserId);
    $digits = preg_replace('/\D/', '', $raw);
    return $digits !== '' ? $digits : $raw;
}

function agent_own_ids($agentUserId): array
{
    $raw = (string) $agentUserId;
    $aid = agent_n2_agent_id($agentUserId);
    $ids = [$aid];
    if ($raw !== '' && $raw !== $aid) {
        $ids[] = $raw;
    }
    return $ids;
}

function agent_n2_botsaz_row($agentUserId): ?array
{
    foreach (agent_own_ids($agentUserId) as $id) {
        $row = select('botsaz', '*', 'id_user', $id, 'select');
        if (is_array($row)) {
            return $row;
        }
    }
    return null;
}

function agent_n2_hidden_panel_names($agentUserId): array
{
    $bot = agent_n2_botsaz_row($agentUserId);
    if (!$bot) {
        return [];
    }
    $decoded = json_decode($bot['hide_panel'] ?? '[]', true);
    if (!is_array($decoded)) {
        return [];
    }
    $names = [];
    foreach ($decoded as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names;
}

function agent_n2_visible_panels($agentUserId): array
{
    global $pdo;
    $hidden = agent_n2_hidden_panel_names($agentUserId);
    try {
        $stmt = $pdo->query("SELECT * FROM marzban_panel WHERE status = 'active' ORDER BY name_panel ASC");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
    $preferred = [];
    $fallback = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['name_panel'] ?? ''));
        if ($name === '' || in_array($name, $hidden, true)) {
            continue;
        }
        $fallback[] = $row;
        $role = (string) ($row['agent'] ?? '');
        if ($role === '' || in_array($role, ['n2', 'all'], true)) {
            $preferred[] = $row;
        }
    }
    return $preferred !== [] ? $preferred : $fallback;
}

function agent_own_allowed_locations($agentUserId): array
{
    $names = ['/all'];
    foreach (agent_n2_visible_panels($agentUserId) as $panel) {
        $name = trim((string) ($panel['name_panel'] ?? ''));
        if ($name !== '' && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    }
    return $names;
}

function agent_own_normalize_location($agentUserId, $location): string
{
    $location = trim((string) $location);
    $allowed = agent_own_allowed_locations($agentUserId);
    if ($location !== '' && in_array($location, $allowed, true)) {
        return $location;
    }
    $named = array_values(array_filter($allowed, static fn($name) => $name !== '/all'));
    return count($named) === 1 ? $named[0] : '/all';
}

function agent_own_panel_pick_keyboard($agentUserId): string
{
    $kb = ['inline_keyboard' => []];
    $panels = agent_n2_visible_panels($agentUserId);
    foreach ($panels as $panel) {
        $name = trim((string) ($panel['name_panel'] ?? ''));
        $code = trim((string) ($panel['code_panel'] ?? ''));
        if ($name === '' || $code === '') {
            continue;
        }
        $kb['inline_keyboard'][] = [[
            'text' => $name,
            'callback_data' => 'n2ownpickpanel_' . $code,
        ]];
    }
    $kb['inline_keyboard'][] = [['text' => 'همه پنل‌های فعال', 'callback_data' => 'n2ownpickpanel_all']];
    $kb['inline_keyboard'][] = [['text' => '▶️ بازگشت', 'callback_data' => 'n2ownmenu']];
    return json_encode($kb, JSON_UNESCAPED_UNICODE);
}

function agent_own_new_code(): string
{
    return 'n2' . bin2hex(random_bytes(4));
}

function agent_own_list_categories($agentUserId, bool $activeOnly = false): array
{
    global $pdo;
    agent_ensure_n2_tables();
    $ids = agent_own_ids($agentUserId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT * FROM agent_own_category WHERE agent_id IN ({$placeholders})";
    if ($activeOnly) {
        $sql .= " AND (status = 'active' OR status = '' OR status IS NULL)";
    }
    $sql .= ' ORDER BY remark ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function agent_resolve_shop_category($agent, $agentUserId, $categoryId): ?array
{
    $categoryId = (int) $categoryId;
    if ($categoryId <= 0) {
        return null;
    }
    if (agent_is_n2($agent)) {
        $own = agent_own_get_category_by_id($categoryId);
        if (!$own || empty($own['remark'])) {
            return null;
        }
        if (!in_array((string) ($own['agent_id'] ?? ''), agent_own_ids($agentUserId), true)) {
            return null;
        }
        return category_is_active($own) ? $own : null;
    }
    $category = select('category', '*', 'id', $categoryId, 'select');
    if (!$category || empty($category['remark']) || !category_is_active($category)) {
        return null;
    }
    return $category;
}

function agent_own_get_category_by_id($categoryId): ?array
{
    global $pdo;
    agent_ensure_n2_tables();
    $stmt = $pdo->prepare('SELECT * FROM agent_own_category WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $categoryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function agent_own_get_category_by_remark($agentUserId, $remark): ?array
{
    global $pdo;
    agent_ensure_n2_tables();
    $remark = trim((string) $remark);
    if ($remark === '') {
        return null;
    }
    $ids = agent_own_ids($agentUserId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM agent_own_category WHERE agent_id IN ({$placeholders}) AND remark = ? LIMIT 1");
    $stmt->execute(array_merge($ids, [$remark]));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function agent_own_add_category($agentUserId, string $remark, string $description = ''): array
{
    global $pdo;
    agent_ensure_n2_tables();
    $remark = trim($remark);
    if ($remark === '' || mb_strlen($remark) > 80) {
        return ['ok' => false, 'msg' => '❌ نام دسته‌بندی نامعتبر است.'];
    }
    if (agent_own_get_category_by_remark($agentUserId, $remark)) {
        return ['ok' => false, 'msg' => '❌ این دسته‌بندی از قبل وجود دارد.'];
    }
    $stmt = $pdo->prepare('INSERT INTO agent_own_category (agent_id, remark, status, description) VALUES (?, ?, ?, ?)');
    $stmt->execute([agent_n2_agent_id($agentUserId), $remark, 'active', $description]);
    return ['ok' => true, 'msg' => '✅ دسته‌بندی اضافه شد.', 'id' => (int) $pdo->lastInsertId(), 'remark' => $remark];
}

function agent_own_delete_category($agentUserId, $categoryId): array
{
    global $pdo;
    agent_ensure_n2_tables();
    $cat = agent_own_get_category_by_id($categoryId);
    if (!$cat || !in_array((string) $cat['agent_id'], agent_own_ids($agentUserId), true)) {
        return ['ok' => false, 'msg' => '❌ دسته‌بندی یافت نشد.'];
    }
    $ids = agent_own_ids($agentUserId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM agent_own_product WHERE agent_id IN ({$placeholders}) AND category = ?");
    $cnt->execute(array_merge($ids, [(string) $cat['remark']]));
    if ((int) $cnt->fetchColumn() > 0) {
        return ['ok' => false, 'msg' => '❌ ابتدا محصولات این دسته را حذف کنید.'];
    }
    $del = $pdo->prepare('DELETE FROM agent_own_category WHERE id = ?');
    $del->execute([(int) $categoryId]);
    return ['ok' => true, 'msg' => '✅ دسته‌بندی حذف شد.'];
}

function agent_own_list_products($agentUserId, $location = null, $categoryRemark = null): array
{
    global $pdo;
    agent_ensure_n2_tables();
    $ids = agent_own_ids($agentUserId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT * FROM agent_own_product WHERE agent_id IN ({$placeholders})";
    $params = $ids;
    if ($location !== null && $location !== '') {
        $sql .= ' AND (Location = ? OR Location = \'/all\')';
        $params[] = $location;
    }
    if ($categoryRemark !== null && $categoryRemark !== '') {
        $sql .= ' AND category = ?';
        $params[] = $categoryRemark;
    }
    $sql .= ' ORDER BY sort_order ASC, name_product ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function agent_own_product_count($agentUserId): int
{
    global $pdo;
    agent_ensure_n2_tables();
    $ids = agent_own_ids($agentUserId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM agent_own_product WHERE agent_id IN ({$placeholders})");
    $stmt->execute($ids);
    return (int) $stmt->fetchColumn();
}

function agent_own_get_product($agentUserId, $codeProduct, $location = null): ?array
{
    global $pdo;
    agent_ensure_n2_tables();
    $codeProduct = (string) $codeProduct;
    if ($codeProduct === '' || $codeProduct === 'customvolume') {
        return null;
    }
    $ids = agent_own_ids($agentUserId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT * FROM agent_own_product WHERE agent_id IN ({$placeholders}) AND code_product = ?";
    $params = array_merge($ids, [$codeProduct]);
    if ($location !== null && $location !== '') {
        $sql .= ' AND (Location = ? OR Location = \'/all\')';
        $params[] = $location;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function agent_own_get_product_by_id($agentUserId, $productId): ?array
{
    global $pdo;
    agent_ensure_n2_tables();
    $ids = agent_own_ids($agentUserId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM agent_own_product WHERE agent_id IN ({$placeholders}) AND id = ? LIMIT 1");
    $stmt->execute(array_merge($ids, [(int) $productId]));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function agent_own_add_product($agentUserId, array $data): array
{
    global $pdo;
    agent_ensure_n2_tables();
    $name = trim((string) ($data['name_product'] ?? ''));
    $price = (int) ($data['price_product'] ?? 0);
    $volume = (int) ($data['Volume_constraint'] ?? 0);
    $time = (int) ($data['Service_time'] ?? 0);
    $category = trim((string) ($data['category'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        return ['ok' => false, 'msg' => '❌ نام محصول نامعتبر است.'];
    }
    if ($price < 0 || $volume < 0 || $time < 0) {
        return ['ok' => false, 'msg' => '❌ قیمت / حجم / زمان نامعتبر است.'];
    }
    if ($volume <= 0) {
        return ['ok' => false, 'msg' => '❌ حجم محصول باید بیشتر از صفر گیگ باشد.'];
    }
    if ($category === '' || !agent_own_get_category_by_remark($agentUserId, $category)) {
        return ['ok' => false, 'msg' => '❌ ابتدا یک دسته‌بندی معتبر انتخاب کنید.'];
    }
    $ids = agent_own_ids($agentUserId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $dup = $pdo->prepare("SELECT COUNT(*) FROM agent_own_product WHERE agent_id IN ({$placeholders}) AND name_product = ?");
    $dup->execute(array_merge($ids, [$name]));
    if ((int) $dup->fetchColumn() > 0) {
        return ['ok' => false, 'msg' => '❌ محصولی با این نام از قبل وجود دارد.'];
    }
    $code = agent_own_new_code();
    $stmt = $pdo->prepare('INSERT INTO agent_own_product
        (agent_id, code_product, name_product, price_product, Volume_constraint, Service_time, category, Location, note, data_limit_reset, one_buy_status, hide_panel, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        agent_n2_agent_id($agentUserId),
        $code,
        $name,
        (string) $price,
        (string) $volume,
        (string) $time,
        $category,
        agent_own_normalize_location($agentUserId, $data['Location'] ?? ''),
        (string) ($data['note'] ?? ''),
        'no_reset',
        '0',
        '{}',
        agent_own_next_sort_order($agentUserId, $category),
    ]);
    return ['ok' => true, 'msg' => '✅ محصول اضافه شد.', 'code_product' => $code];
}

function agent_own_next_sort_order($agentUserId, string $category = ''): int
{
    global $pdo;
    agent_ensure_n2_tables();
    $ids = agent_own_ids($agentUserId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM agent_own_product WHERE agent_id IN ({$placeholders})";
    $params = $ids;
    if ($category !== '') {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return max(1, (int) ($stmt->fetchColumn() ?: 1));
    } catch (Throwable $e) {
        return 1;
    }
}

function agent_own_renormalize_category_sort_orders($agentUserId, string $category): void
{
    global $pdo;
    agent_ensure_n2_tables();
    $ids = agent_own_ids($agentUserId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT id FROM agent_own_product WHERE agent_id IN ({$placeholders})";
    $params = $ids;
    if ($category === '') {
        $sql .= " AND (category IS NULL OR category = '')";
    } else {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }
    $sql .= ' ORDER BY sort_order ASC, name_product ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $update = $pdo->prepare('UPDATE agent_own_product SET sort_order = ? WHERE id = ?');
    foreach ($rows as $i => $row) {
        $update->execute([$i + 1, (int) $row['id']]);
    }
}

function agent_own_apply_category_sort_order($agentUserId, string $category, array $orderedIds): void
{
    global $pdo;
    agent_ensure_n2_tables();
    $ids = agent_own_ids($agentUserId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT id FROM agent_own_product WHERE agent_id IN ({$placeholders})";
    $params = $ids;
    if ($category === '') {
        $sql .= " AND (category IS NULL OR category = '')";
    } else {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $existingIds = array_map(static fn($row) => (int) $row['id'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    sort($existingIds);

    $ordered = array_values(array_unique(array_map('intval', $orderedIds)));
    $sorted = $ordered;
    sort($sorted);
    if ($sorted !== $existingIds) {
        throw new InvalidArgumentException('ترتیب محصولات نامعتبر است.');
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE agent_own_product SET sort_order = ? WHERE id = ?');
        foreach ($ordered as $i => $id) {
            $update->execute([$i + 1, $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function agent_own_update_product($agentUserId, $productId, array $data): array
{
    global $pdo;
    agent_ensure_n2_tables();
    $prod = agent_own_get_product_by_id($agentUserId, $productId);
    if (!$prod) {
        return ['ok' => false, 'msg' => '❌ محصول یافت نشد.'];
    }
    $location = array_key_exists('Location', $data)
        ? agent_own_normalize_location($agentUserId, $data['Location'])
        : (string) ($prod['Location'] ?? '/all');
    $stmt = $pdo->prepare('UPDATE agent_own_product SET Location = ? WHERE id = ?');
    $stmt->execute([$location, (int) $productId]);
    return ['ok' => true, 'msg' => '✅ محصول به‌روزرسانی شد.'];
}

function agent_own_delete_product($agentUserId, $productId): array
{
    global $pdo;
    agent_ensure_n2_tables();
    $prod = agent_own_get_product_by_id($agentUserId, $productId);
    if (!$prod) {
        return ['ok' => false, 'msg' => '❌ محصول یافت نشد.'];
    }
    $del = $pdo->prepare('DELETE FROM agent_own_product WHERE id = ?');
    $del->execute([(int) $productId]);
    agent_own_renormalize_category_sort_orders($agentUserId, trim((string) ($prod['category'] ?? '')));
    return ['ok' => true, 'msg' => '✅ محصول حذف شد.'];
}

function keyboard_agent_for_user(array $user): string
{
    global $keyboardagent;
    $kb = json_decode((string) $keyboardagent, true);
    if (!agent_is_n2($user['agent'] ?? 'f') || !is_array($kb)) {
        return (string) $keyboardagent;
    }
    if (isset($kb['inline_keyboard'])) {
        array_unshift($kb['inline_keyboard'], [
            ['text' => '📦 مدیریت محصولات', 'callback_data' => 'n2ownmenu'],
            ['text' => 'فعالسازی پنل تحت وب', 'callback_data' => 'n2webpanel'],
        ]);
    } elseif (isset($kb['keyboard'])) {
        array_unshift($kb['keyboard'], [
            ['text' => '📦 مدیریت محصولات'],
            ['text' => 'فعالسازی پنل تحت وب'],
        ]);
    }
    return json_encode($kb, JSON_UNESCAPED_UNICODE);
}

function agent_n2_activate_web_panel($telegramId): array
{
    global $pdo, $domainhosts;
    $telegramId = (string) $telegramId;
    $user = select('user', '*', 'id', $telegramId, 'select');
    if (!$user || !agent_is_n2($user['agent'] ?? 'f')) {
        return ['ok' => false, 'msg' => '❌ این قابلیت فقط برای نماینده پیشرفته است.'];
    }
    $existing = select('admin', '*', 'id_admin', $telegramId, 'select');
    if (is_array($existing) && in_array((string) ($existing['rule'] ?? ''), admin_telegram_staff_rules(), true)) {
        return ['ok' => false, 'msg' => '❌ این حساب از قبل نقش ادمین پنل دارد. نقش آن عوض نمی‌شود.'];
    }
    $plain = bin2hex(random_bytes(6));
    $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    if (is_array($existing)) {
        $stmt = $pdo->prepare('UPDATE admin SET username = ?, password = ?, rule = ? WHERE id_admin = ?');
        $stmt->execute([$telegramId, $hash, 'n2_panel', $telegramId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO admin (id_admin, username, password, rule) VALUES (?, ?, ?, ?)');
        $stmt->execute([$telegramId, $telegramId, $hash, 'n2_panel']);
    }
    if (function_exists('clearSelectCache')) {
        clearSelectCache('admin');
    }
    $host = trim((string) ($domainhosts ?? ''));
    $url = $host !== '' ? ('https://' . $host . '/panel') : '/panel';
    return [
        'ok' => true,
        'username' => $telegramId,
        'password' => $plain,
        'url' => $url,
        'msg' => "✅ پنل تحت وب شما فعال شد.\n\n🔗 آدرس ورود : {$url}\n👤 نام کاربری : <code>{$telegramId}</code>\n🔑 رمز عبور : <code>{$plain}</code>\n\n⚠️ با کلیک دوباره، رمز جدید می‌گیرید.",
    ];
}

function agent_own_menu_keyboard(): string
{
    return json_encode([
        'keyboard' => [
            [['text' => '➕ افزودن دسته'], ['text' => '➕ افزودن محصول']],
            [['text' => '🗂 لیست دسته‌ها'], ['text' => '🛍 لیست محصولات']],
            [['text' => '▶️ بازگشت']],
        ],
        'resize_keyboard' => true,
    ], JSON_UNESCAPED_UNICODE);
}

function agent_own_draft_get(array $user): array
{
    $raw = $user['Processing_value_tow'] ?? '';
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function agent_own_draft_save($from_id, array $draft): void
{
    update('user', 'Processing_value_tow', json_encode($draft, JSON_UNESCAPED_UNICODE), 'id', $from_id);
}

function agent_own_target_id(array $user, $fallback): string
{
    $stored = trim((string) ($user['Processing_value_one'] ?? ''));
    return $stored !== '' ? $stored : (string) $fallback;
}

function agent_own_category_pick_keyboard($agentUserId): string
{
    $cats = agent_own_list_categories($agentUserId, true);
    $kb = ['inline_keyboard' => []];
    foreach ($cats as $cat) {
        $kb['inline_keyboard'][] = [[
            'text' => (string) ($cat['remark'] ?? ''),
            'callback_data' => 'n2ownpickcat_' . (int) $cat['id'],
        ]];
    }
    $kb['inline_keyboard'][] = [['text' => '▶️ بازگشت', 'callback_data' => 'n2ownmenu']];
    return json_encode($kb, JSON_UNESCAPED_UNICODE);
}

/**
 * Shared telegram wizard for n2 own categories/products.
 * $targetAgentId = catalog owner. $menuKeyboard = reply keyboard to return to.
 */
function agent_own_telegram_handle($from_id, $text, $datain, array $user, $targetAgentId, $menuKeyboard): bool
{
    $text = (string) $text;
    $datain = (string) $datain;
    $step = (string) ($user['step'] ?? '');
    $ownTexts = ['📦 مدیریت محصولات', '➕ افزودن دسته', '➕ افزودن محصول', '🗂 لیست دسته‌ها', '🛍 لیست محصولات', '▶️ بازگشت'];
    $isOwnStep = strpos($step, 'n2own') === 0;
    $isOwnCb = strpos($datain, 'n2own') === 0;
    $isOwnText = in_array($text, $ownTexts, true);
    if (!$isOwnStep && !$isOwnCb && !$isOwnText) {
        return false;
    }

    $targetAgentId = agent_n2_agent_id($targetAgentId);
    if ($targetAgentId === '') {
        $targetAgentId = agent_own_target_id($user, $from_id);
    }
    $targetUser = select('user', '*', 'id', $targetAgentId, 'select');
    if (!$targetUser || !agent_is_n2($targetUser['agent'] ?? 'f')) {
        sendmessage($from_id, '❌ این کاربر نماینده پیشرفته نیست.', $menuKeyboard, 'HTML');
        step('home', $from_id);
        return true;
    }

    if ($text === '▶️ بازگشت' && in_array($step, ['n2own_menu', 'home', ''], true) && $datain === '') {
        sendmessage($from_id, '🏠 به منوی قبل بازگشتید.', $menuKeyboard, 'HTML');
        step('home', $from_id);
        return true;
    }
    if ($text === '▶️ بازگشت' || $datain === 'n2ownmenu' || $text === '📦 مدیریت محصولات') {
        update('user', 'Processing_value_one', $targetAgentId, 'id', $from_id);
        sendmessage($from_id, "📦 مدیریت محصولات نماینده <code>{$targetAgentId}</code>\nدسته بسازید، سپس محصول اضافه کنید.", agent_own_menu_keyboard(), 'HTML');
        step('n2own_menu', $from_id);
        return true;
    }

    if ($text === '➕ افزودن دسته' || $datain === 'n2own_addcat') {
        update('user', 'Processing_value_one', $targetAgentId, 'id', $from_id);
        sendmessage($from_id, '📌 نام دسته‌بندی را ارسال کنید.', agent_own_menu_keyboard(), 'HTML');
        step('n2own_cat_add', $from_id);
        return true;
    }
    if ($step === 'n2own_cat_add' && $text !== '' && $datain === '') {
        $result = agent_own_add_category($targetAgentId, $text);
        sendmessage($from_id, $result['msg'], agent_own_menu_keyboard(), 'HTML');
        step('n2own_menu', $from_id);
        return true;
    }

    if ($text === '🗂 لیست دسته‌ها' || $datain === 'n2own_listcat') {
        $cats = agent_own_list_categories($targetAgentId);
        if (!$cats) {
            sendmessage($from_id, '❌ هنوز دسته‌بندی‌ای ساخته نشده است.', agent_own_menu_keyboard(), 'HTML');
            step('n2own_menu', $from_id);
            return true;
        }
        $kb = ['inline_keyboard' => []];
        foreach ($cats as $cat) {
            $kb['inline_keyboard'][] = [[
                'text' => '🗑 ' . ($cat['remark'] ?? ''),
                'callback_data' => 'n2owndelcat_' . (int) $cat['id'],
            ]];
        }
        $kb['inline_keyboard'][] = [['text' => '▶️ بازگشت', 'callback_data' => 'n2ownmenu']];
        sendmessage($from_id, "🗂 دسته‌ها — برای حذف روی مورد بزنید:", json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
        step('n2own_menu', $from_id);
        return true;
    }
    if (preg_match('/^n2owndelcat_(\d+)$/', $datain, $m)) {
        $result = agent_own_delete_category($targetAgentId, $m[1]);
        sendmessage($from_id, $result['msg'], agent_own_menu_keyboard(), 'HTML');
        step('n2own_menu', $from_id);
        return true;
    }

    if ($text === '🛍 لیست محصولات' || $datain === 'n2own_listprod') {
        $prods = agent_own_list_products($targetAgentId);
        if (!$prods) {
            sendmessage($from_id, '❌ هنوز محصولی ساخته نشده است.', agent_own_menu_keyboard(), 'HTML');
            step('n2own_menu', $from_id);
            return true;
        }
        $kb = ['inline_keyboard' => []];
        foreach ($prods as $prod) {
            $label = ($prod['name_product'] ?? '') . ' | ' . ($prod['Volume_constraint'] ?? '0') . 'GB | ' . number_format((int) ($prod['price_product'] ?? 0)) . 'ت';
            $kb['inline_keyboard'][] = [[
                'text' => '🗑 ' . $label,
                'callback_data' => 'n2owndelprod_' . (int) $prod['id'],
            ]];
        }
        $kb['inline_keyboard'][] = [['text' => '▶️ بازگشت', 'callback_data' => 'n2ownmenu']];
        sendmessage($from_id, "🛍 محصولات — برای حذف روی مورد بزنید:", json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
        step('n2own_menu', $from_id);
        return true;
    }
    if (preg_match('/^n2owndelprod_(\d+)$/', $datain, $m)) {
        $result = agent_own_delete_product($targetAgentId, $m[1]);
        sendmessage($from_id, $result['msg'], agent_own_menu_keyboard(), 'HTML');
        step('n2own_menu', $from_id);
        return true;
    }

    if ($text === '➕ افزودن محصول' || $datain === 'n2own_addprod') {
        if (!agent_own_list_categories($targetAgentId, true)) {
            sendmessage($from_id, '❌ ابتدا یک دسته‌بندی بسازید.', agent_own_menu_keyboard(), 'HTML');
            step('n2own_menu', $from_id);
            return true;
        }
        update('user', 'Processing_value_one', $targetAgentId, 'id', $from_id);
        agent_own_draft_save($from_id, []);
        sendmessage($from_id, '📌 نام محصول را ارسال کنید.', agent_own_menu_keyboard(), 'HTML');
        step('n2own_prod_name', $from_id);
        return true;
    }
    if ($step === 'n2own_prod_name' && $text !== '' && $datain === '') {
        if (mb_strlen($text) > 150) {
            sendmessage($from_id, '❌ نام محصول حداکثر ۱۵۰ کاراکتر باشد.', agent_own_menu_keyboard(), 'HTML');
            return true;
        }
        agent_own_draft_save($from_id, ['name_product' => $text]);
        sendmessage($from_id, '💰 قیمت محصول را به تومان ارسال کنید (فقط عدد).', agent_own_menu_keyboard(), 'HTML');
        step('n2own_prod_price', $from_id);
        return true;
    }
    if ($step === 'n2own_prod_price' && $text !== '' && $datain === '') {
        if (!ctype_digit($text)) {
            sendmessage($from_id, '❌ قیمت باید عدد باشد.', agent_own_menu_keyboard(), 'HTML');
            return true;
        }
        $draft = agent_own_draft_get($user);
        $draft['price_product'] = (int) $text;
        agent_own_draft_save($from_id, $draft);
        sendmessage($from_id, '🔋 حجم محصول را به گیگابایت ارسال کنید (فقط عدد، بیشتر از صفر).', agent_own_menu_keyboard(), 'HTML');
        step('n2own_prod_volume', $from_id);
        return true;
    }
    if ($step === 'n2own_prod_volume' && $text !== '' && $datain === '') {
        if (!ctype_digit($text) || (int) $text <= 0) {
            sendmessage($from_id, '❌ حجم باید عدد بزرگ‌تر از صفر باشد.', agent_own_menu_keyboard(), 'HTML');
            return true;
        }
        $draft = agent_own_draft_get($user);
        $draft['Volume_constraint'] = (int) $text;
        agent_own_draft_save($from_id, $draft);
        sendmessage($from_id, '⏳ مدت سرویس را به روز ارسال کنید (۰ = نامحدود).', agent_own_menu_keyboard(), 'HTML');
        step('n2own_prod_time', $from_id);
        return true;
    }
    if ($step === 'n2own_prod_time' && $text !== '' && $datain === '') {
        if (!ctype_digit($text)) {
            sendmessage($from_id, '❌ مدت باید عدد باشد.', agent_own_menu_keyboard(), 'HTML');
            return true;
        }
        $draft = agent_own_draft_get($user);
        $draft['Service_time'] = (int) $text;
        agent_own_draft_save($from_id, $draft);
        sendmessage($from_id, '🗂 دسته‌بندی محصول را انتخاب کنید.', agent_own_category_pick_keyboard($targetAgentId), 'HTML');
        step('n2own_prod_cat', $from_id);
        return true;
    }
    if ($step === 'n2own_prod_cat' && preg_match('/^n2ownpickcat_(\d+)$/', $datain, $m)) {
        $cat = agent_own_get_category_by_id($m[1]);
        if (!$cat || !in_array((string) $cat['agent_id'], agent_own_ids($targetAgentId), true)) {
            sendmessage($from_id, '❌ دسته‌بندی نامعتبر است.', agent_own_menu_keyboard(), 'HTML');
            step('n2own_menu', $from_id);
            return true;
        }
        $draft = agent_own_draft_get($user);
        $draft['category'] = (string) $cat['remark'];
        $panels = agent_n2_visible_panels($targetAgentId);
        if (count($panels) <= 1) {
            $draft['Location'] = trim((string) ($panels[0]['name_panel'] ?? '')) ?: '/all';
            $result = agent_own_add_product($targetAgentId, $draft);
            sendmessage($from_id, $result['msg'], agent_own_menu_keyboard(), 'HTML');
            step('n2own_menu', $from_id);
            return true;
        }
        agent_own_draft_save($from_id, $draft);
        sendmessage($from_id, '🖥 پنل این محصول را انتخاب کنید.', agent_own_panel_pick_keyboard($targetAgentId), 'HTML');
        step('n2own_prod_panel', $from_id);
        return true;
    }
    if ($step === 'n2own_prod_panel' && preg_match('/^n2ownpickpanel_(.+)$/', $datain, $m)) {
        $code = (string) $m[1];
        $location = '/all';
        if ($code !== 'all') {
            $panel = select('marzban_panel', '*', 'code_panel', $code, 'select');
            $name = is_array($panel) ? trim((string) ($panel['name_panel'] ?? '')) : '';
            if ($name === '' || !in_array($name, agent_own_allowed_locations($targetAgentId), true)) {
                sendmessage($from_id, '❌ پنل نامعتبر است.', agent_own_menu_keyboard(), 'HTML');
                step('n2own_menu', $from_id);
                return true;
            }
            $location = $name;
        }
        $userFresh = select('user', '*', 'id', $from_id, 'select');
        $draft = agent_own_draft_get(is_array($userFresh) ? $userFresh : $user);
        $draft['Location'] = $location;
        $result = agent_own_add_product($targetAgentId, $draft);
        sendmessage($from_id, $result['msg'], agent_own_menu_keyboard(), 'HTML');
        step('n2own_menu', $from_id);
        return true;
    }

    return $isOwnStep || $isOwnCb;
}

function agent_n2_category_enabled($agentUserId, $categoryRemark): bool
{
    global $pdo;
    agent_ensure_n2_tables();
    $categoryRemark = trim((string) $categoryRemark);
    if ($categoryRemark === '') {
        return false;
    }
    $aid = agent_n2_agent_id($agentUserId);
    $stmt = $pdo->prepare('SELECT enabled FROM agent_n2_category WHERE agent_id = ? AND category = ? AND enabled = 1 LIMIT 1');
    $stmt->execute([$aid, $categoryRemark]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return true;
    }
    // legacy rows that may store non-normalized agent_id
    if ($aid !== (string) $agentUserId) {
        $stmt->execute([(string) $agentUserId, $categoryRemark]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }
    }
    return false;
}

/**
 * True if this product belongs to the n2 agent's own catalog.
 */
function agent_n2_product_enabled($agentUserId, $codeProduct, $categoryHint = null): bool
{
    if ($codeProduct === '' || $codeProduct === null || $codeProduct === 'customvolume') {
        return false;
    }
    $prod = agent_own_get_product($agentUserId, $codeProduct);
    if (!$prod) {
        return false;
    }
    $hint = trim((string) ($categoryHint ?? ''));
    if ($hint !== '' && (string) ($prod['category'] ?? '') !== $hint) {
        return false;
    }
    return true;
}

/**
 * Fetch n2 agent's own products.
 */
function agent_n2_list_products($agentUserId, $location = null): array
{
    return agent_own_list_products($agentUserId, $location);
}

function agent_n2_log_purchase(array $data): void
{
    global $pdo;
    agent_ensure_n2_tables();
    $stmt = $pdo->prepare('INSERT INTO agent_n2_purchase
        (agent_id, code_product, name_product, volume, service_time, panel, username_service, id_invoice, price_product, created_at)
        VALUES (:agent_id, :code_product, :name_product, :volume, :service_time, :panel, :username_service, :id_invoice, :price_product, :created_at)');
    $stmt->execute([
        ':agent_id' => (string) ($data['agent_id'] ?? ''),
        ':code_product' => (string) ($data['code_product'] ?? ''),
        ':name_product' => (string) ($data['name_product'] ?? ''),
        ':volume' => (string) ($data['volume'] ?? ''),
        ':service_time' => (string) ($data['service_time'] ?? ''),
        ':panel' => (string) ($data['panel'] ?? ''),
        ':username_service' => (string) ($data['username_service'] ?? ''),
        ':id_invoice' => (string) ($data['id_invoice'] ?? ''),
        ':price_product' => (string) ($data['price_product'] ?? '0'),
        ':created_at' => (int) ($data['created_at'] ?? time()),
    ]);
}

/**
 * After a successful catalog service create by n2: verify access + log purchase.
 * Returns ['ok'=>bool, 'msg'=>string].
 */
function agent_n2_assert_and_log_purchase($agentUserId, $codeProduct, array $meta = []): array
{
    if ($codeProduct === 'customvolume') {
        return ['ok' => false, 'msg' => '❌ نماینده پیشرفته فقط می‌تواند محصولات خودش را خریداری کند.'];
    }
    if (!agent_n2_product_enabled($agentUserId, $codeProduct, $meta['category'] ?? null)) {
        return ['ok' => false, 'msg' => '❌ این محصول برای نمایندگی شما تعریف نشده است.'];
    }
    agent_n2_log_purchase([
        'agent_id' => $agentUserId,
        'code_product' => $codeProduct,
        'name_product' => $meta['name_product'] ?? '',
        'volume' => $meta['volume'] ?? '',
        'service_time' => $meta['service_time'] ?? '',
        'panel' => $meta['panel'] ?? '',
        'username_service' => $meta['username_service'] ?? '',
        'id_invoice' => $meta['id_invoice'] ?? '',
        'price_product' => $meta['price_product'] ?? '0',
        'created_at' => $meta['created_at'] ?? time(),
    ]);
    return ['ok' => true, 'msg' => ''];
}

/**
 * Users whose active subscription has used at least $percent of volume.
 * Returns list of ['id' => userId] suitable for sendmessage cron.
 */
function getUsersHighVolumeUsage($percent = 80, $agent = 'all', $panelName = 'all'): array
{
    global $pdo, $ManagePanel;
    if (!isset($ManagePanel) || !($ManagePanel instanceof ManagePanel)) {
        if (!class_exists('ManagePanel')) {
            require_once __DIR__ . '/panels.php';
        }
        $ManagePanel = new ManagePanel();
    }

    $percent = max(1, min(100, (float) $percent));
    $sql = "SELECT DISTINCT i.id_user, i.username, i.Service_location
            FROM invoice i
            INNER JOIN user u ON u.id = i.id_user
            WHERE i.Status IN ('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold')
              AND i.name_product != 'سرویس تست'
              AND u.User_Status = 'Active'";
    $params = [];
    if ($agent !== 'all' && $agent !== null && $agent !== '') {
        $sql .= " AND u.agent = :agent";
        $params[':agent'] = $agent;
    }
    if ($panelName !== 'all' && $panelName !== null && $panelName !== '') {
        $sql .= " AND i.Service_location = :panel";
        $params[':panel'] = $panelName;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $matched = [];
    foreach ($invoices as $invoice) {
        $userId = $invoice['id_user'];
        if (isset($matched[$userId])) {
            continue;
        }
        $userData = $ManagePanel->DataUser($invoice['Service_location'], $invoice['username']);
        if (!is_array($userData) || ($userData['status'] ?? '') === 'Unsuccessful') {
            continue;
        }
        $dataLimit = isset($userData['data_limit']) && is_numeric($userData['data_limit'])
            ? (float) $userData['data_limit']
            : 0;
        if ($dataLimit <= 0) {
            continue;
        }
        $usedTraffic = isset($userData['used_traffic']) && is_numeric($userData['used_traffic'])
            ? (float) $userData['used_traffic']
            : 0;
        $usagePercent = ($usedTraffic / $dataLimit) * 100;
        if ($usagePercent >= $percent) {
            $matched[$userId] = ['id' => $userId];
        }
    }
    return array_values($matched);
}

/**
 * True if this product/category is allowed for n (whitelist) or n2 (own catalog).
 */
function agent_category_purchase_allowed($agentUserId, $codeProduct, $categoryRemark = ''): bool
{
    $user = select('user', '*', 'id', $agentUserId, 'select');
    $agent = $user['agent'] ?? 'f';
    $codeProduct = (string) ($codeProduct ?? '');
    $categoryRemark = trim((string) $categoryRemark);
    if (agent_is_n2($agent)) {
        return agent_n2_product_enabled($agentUserId, $codeProduct, $categoryRemark !== '' ? $categoryRemark : null);
    }
    if ($categoryRemark !== '' && agent_n2_category_enabled($agentUserId, $categoryRemark)) {
        return true;
    }
    $codeProduct = (string) ($codeProduct ?? '');
    if ($codeProduct === '' || $codeProduct === 'customvolume') {
        return false;
    }
    global $pdo;
    agent_ensure_n2_tables();
    $aid = agent_n2_agent_id($agentUserId);
    $stmt = $pdo->prepare("SELECT 1
        FROM product p
        INNER JOIN agent_n2_category ac
            ON ac.enabled = 1
           AND ac.category = p.category
           AND (ac.agent_id = :aid OR ac.agent_id = :aid_raw)
        WHERE p.code_product = :code
        LIMIT 1");
    $stmt->execute([
        ':aid' => $aid,
        ':aid_raw' => (string) $agentUserId,
        ':code' => $codeProduct,
    ]);
    return (bool) $stmt->fetchColumn();
}

/**
 * GB per TB for agent step pricing (binary TB).
 */
function agent_gb_per_tb(): float
{
    return 1024.0;
}

/**
 * Normalize / decode agent_price_tiers JSON.
 * Each tier: ['upto_tb' => float|null, 'price_per_gb' => int]
 * upto_tb = cumulative lifetime ceiling in TB (null = unlimited / open-ended last tier).
 * Sorted ascending by upto_tb; nulls last.
 */
function agent_decode_price_tiers($userOrJson): array
{
    if (is_array($userOrJson) && array_key_exists('agent_price_tiers', $userOrJson)) {
        $raw = $userOrJson['agent_price_tiers'];
    } else {
        $raw = $userOrJson;
    }
    $tiers = [];
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $tiers = $decoded;
        }
    } elseif (is_array($raw)) {
        $tiers = $raw;
    }
    $out = [];
    foreach ($tiers as $t) {
        if (!is_array($t)) {
            continue;
        }
        $price = (int) ($t['price_per_gb'] ?? -1);
        if ($price < 0) {
            continue;
        }
        $upto = $t['upto_tb'] ?? null;
        if ($upto === '' || $upto === null) {
            $uptoTb = null;
        } else {
            $uptoTb = (float) $upto;
            if ($uptoTb <= 0) {
                continue;
            }
        }
        $out[] = ['upto_tb' => $uptoTb, 'price_per_gb' => $price];
    }
    usort($out, static function ($a, $b) {
        if ($a['upto_tb'] === null && $b['upto_tb'] === null) {
            return 0;
        }
        if ($a['upto_tb'] === null) {
            return 1;
        }
        if ($b['upto_tb'] === null) {
            return -1;
        }
        return $a['upto_tb'] <=> $b['upto_tb'];
    });
    return $out;
}

/**
 * Encode tiers for storage. Ensures one open-ended last tier when possible.
 */
function agent_encode_price_tiers(array $tiers): string
{
    $normalized = agent_decode_price_tiers($tiers);
    return json_encode($normalized, JSON_UNESCAPED_UNICODE);
}

/**
 * Price/GB for the tier that contains $consumedGb (lifetime GB already used).
 */
function agent_tier_price_at(array $tiers, float $consumedGb): int
{
    if (empty($tiers)) {
        return 0;
    }
    $gbPerTb = agent_gb_per_tb();
    $consumedGb = max(0.0, $consumedGb);
    foreach ($tiers as $tier) {
        $ceilingGb = $tier['upto_tb'] === null
            ? INF
            : ((float) $tier['upto_tb'] * $gbPerTb);
        if ($consumedGb < $ceilingGb || $tier['upto_tb'] === null) {
            return (int) $tier['price_per_gb'];
        }
    }
    return (int) (end($tiers)['price_per_gb'] ?? 0);
}

/**
 * Wholesale cost for purchasing $volumeGb given prior lifetime consumption.
 * If the purchase stays inside the current tier → current tier price × GB.
 * If it crosses any tier ceiling → entire purchase billed at the next tier's price.
 * Falls back to flat agent_price_per_gb if no tiers.
 */
function agent_wholesale_cost_from_consumed($user, $volumeGb, $consumedGb = null): int
{
    agent_ensure_volume_columns();
    $volumeGb = max(0, (float) $volumeGb);
    if ($volumeGb <= 0) {
        return 0;
    }
    if ($consumedGb === null) {
        $consumedGb = agent_sum_volume_consumed($user['id'] ?? 0, $user['agent'] ?? 'n');
    }
    $consumedGb = max(0.0, (float) $consumedGb);
    $tiers = agent_decode_price_tiers($user);
    if (empty($tiers)) {
        $pricePerGb = (int) ($user['agent_price_per_gb'] ?? 0);
        return (int) round($volumeGb * max(0, $pricePerGb));
    }

    $startPrice = agent_tier_price_at($tiers, $consumedGb);
    $endGb = $consumedGb + $volumeGb;
    $gbPerTb = agent_gb_per_tb();
    $pricePerGb = $startPrice;

    foreach ($tiers as $tier) {
        if ($tier['upto_tb'] === null) {
            continue;
        }
        $ceilingGb = (float) $tier['upto_tb'] * $gbPerTb;
        // Purchase crosses this ceiling → bill whole purchase at the next tier
        if ($consumedGb < $ceilingGb && $endGb > $ceilingGb) {
            $pricePerGb = agent_tier_price_at($tiers, $ceilingGb);
            break;
        }
    }

    return (int) round($volumeGb * max(0, $pricePerGb));
}

/**
 * Wholesale cost for group-n pay-as-you-go (step tiers or flat price/GB).
 */
function agent_wholesale_cost($user, $volumeGb): int
{
    return agent_wholesale_cost_from_consumed($user, $volumeGb, null);
}

/**
 * Marginal price/GB for the next GB at current lifetime consumption (for UI labels).
 */
function agent_current_price_per_gb($user, $consumedGb = null): int
{
    agent_ensure_volume_columns();
    $tiers = agent_decode_price_tiers($user);
    if (empty($tiers)) {
        return (int) ($user['agent_price_per_gb'] ?? 0);
    }
    if ($consumedGb === null) {
        $consumedGb = agent_sum_volume_consumed($user['id'] ?? 0, $user['agent'] ?? 'n');
    }
    return agent_tier_price_at($tiers, max(0.0, (float) $consumedGb));
}

/**
 * Pre-check whether an agent can create the given GB volume.
 * n: pay-as-you-go — Balance >= step wholesale cost.
 * n2: remaining GB (agent_volume_remaining) minus volume must stay above -maxbuyagent (0 = unlimited).
 */
function agent_check_volume_quota($agentUserId, $volumeGb): array
{
    agent_ensure_volume_columns();
    $volumeGb = (int) $volumeGb;
    $user = select('user', '*', 'id', $agentUserId, 'select');
    if (!$user || !agent_is_reseller($user['agent'] ?? 'f')) {
        return ['ok' => true, 'msg' => '', 'cost' => 0, 'user' => $user ?: null, 'skipped' => true];
    }
    if ($volumeGb <= 0) {
        return [
            'ok' => false,
            'msg' => '❌ نمایندگان نمی‌توانند سرویس با حجم نامحدود (۰ گیگ) بسازند. حجم باید بیشتر از صفر باشد.',
            'cost' => 0,
            'user' => $user,
            'skipped' => false,
        ];
    }
    if (agent_is_n2($user['agent'] ?? 'f')) {
        $remaining = (int) ($user['agent_volume_remaining'] ?? 0);
        $after = $remaining - $volumeGb;
        $maxNeg = (int) ($user['maxbuyagent'] ?? 0);
        if ($maxNeg != 0 && $after < (-1 * $maxNeg)) {
            return [
                'ok' => false,
                'msg' => '❌ سهمیه حجم نمایندگی کافی نیست. موجودی: ' . number_format($remaining) . ' گیگ | نیاز: ' . number_format($volumeGb) . ' گیگ',
                'cost' => 0,
                'user' => $user,
                'skipped' => false,
                'volume_after' => $after,
            ];
        }
        return [
            'ok' => true,
            'msg' => '',
            'cost' => 0,
            'user' => $user,
            'skipped' => false,
            'volume_after' => $after,
        ];
    }
    $cost = agent_wholesale_cost($user, $volumeGb);
    $balance = (int) ($user['Balance'] ?? 0);
    if ($cost > $balance) {
        return [
            'ok' => false,
            'msg' => '❌ موجودی نمایندگی برای ساخت این حجم کافی نیست. هزینه: ' . number_format($cost) . ' تومان',
            'cost' => $cost,
            'user' => $user,
            'skipped' => false,
        ];
    }
    return ['ok' => true, 'msg' => '', 'cost' => $cost, 'user' => $user, 'skipped' => false];
}

/**
 * Deduct wholesale cost (n) or remaining GB (n2) after a successful create.
 */
function agent_consume_volume($agentUserId, $volumeGb): array
{
    $check = agent_check_volume_quota($agentUserId, $volumeGb);
    if (!empty($check['skipped'])) {
        return $check;
    }
    if (!$check['ok']) {
        return $check;
    }
    $user = $check['user'];
    if (agent_is_n2($user['agent'] ?? 'f')) {
        $after = (int) ($check['volume_after'] ?? ((int) ($user['agent_volume_remaining'] ?? 0) - (int) $volumeGb));
        update('user', 'agent_volume_remaining', (string) $after, 'id', $agentUserId);
        agent_n2_add_consumed($agentUserId, (int) $volumeGb);
        $check['volume_remaining'] = $after;
        return $check;
    }
    $cost = (int) $check['cost'];
    $balance = (int) ($user['Balance'] ?? 0) - $cost;
    update('user', 'Balance', $balance, 'id', $agentUserId);
    $check['balance'] = $balance;
    return $check;
}

/**
 * HTTP GET to Telegram Bot API with a hard timeout (avoids panel freeze).
 * Returns decoded JSON array, or null on network/parse failure.
 */
function agent_telegram_api_get(string $url, int $timeout = 12): ?array
{
    $timeout = max(3, min(30, $timeout));
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'mirzabot-agent/1.0',
        ]);
        if (function_exists('apply_telegram_proxy')) {
            apply_telegram_proxy($ch, $url);
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || $body === false) {
            return null;
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }
    }
    $decoded = json_decode((string) $body, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Resolve project root that contains vpnbot/.
 */
function agent_resolve_project_root($rootPath = null): string
{
    if ($rootPath !== null && $rootPath !== '') {
        $rootPath = rtrim((string) $rootPath, '/\\');
        if (is_dir($rootPath . '/vpnbot')) {
            return $rootPath;
        }
    }
    // function.php lives in project root
    $candidates = [
        __DIR__,
        getcwd(),
        dirname(getcwd()),
    ];
    foreach ($candidates as $cand) {
        $cand = rtrim((string) $cand, '/\\');
        if ($cand !== '' && is_dir($cand . '/vpnbot')) {
            return $cand;
        }
    }
    return rtrim((string) (__DIR__), '/\\');
}

/**
 * Create an agent sell bot. $rootPath is project root (contains vpnbot/).
 * Returns ['ok' => bool, 'msg' => string, 'username' => string|null]
 */
function agent_create_sell_bot($agentUserId, $token, $rootPath = null): array
{
    global $pdo, $domainhosts;

    $agentUserId = (string) $agentUserId;
    $token = trim((string) $token);
    if ($token === '') {
        return ['ok' => false, 'msg' => 'توکن خالی است.', 'username' => null];
    }
    if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token)) {
        return ['ok' => false, 'msg' => 'فرمت توکن نامعتبر است.', 'username' => null];
    }

    $existing = select('botsaz', '*', 'id_user', $agentUserId, 'count');
    $totalBots = select('botsaz', '*', null, null, 'count');
    if ((int) $totalBots >= 15) {
        return ['ok' => false, 'msg' => 'حداکثر ۱۵ ربات نماینده مجاز است.', 'username' => null];
    }
    if ((int) $existing !== 0) {
        return ['ok' => false, 'msg' => 'این نماینده از قبل ربات فروش دارد.', 'username' => null];
    }

    $getInfoToken = agent_telegram_api_get("https://api.telegram.org/bot{$token}/getMe", 12);
    if ($getInfoToken === null) {
        return ['ok' => false, 'msg' => 'ارتباط با تلگرام برقرار نشد (تایم‌اوت/شبکه). دوباره تلاش کنید.', 'username' => null];
    }
    if (empty($getInfoToken['ok'])) {
        $desc = $getInfoToken['description'] ?? '';
        return ['ok' => false, 'msg' => 'توکن نامعتبر است.' . ($desc !== '' ? " ($desc)" : ''), 'username' => null];
    }
    $botUsername = $getInfoToken['result']['username'] ?? '';
    if ($botUsername === '') {
        return ['ok' => false, 'msg' => 'نام کاربری ربات دریافت نشد.', 'username' => null];
    }
    if ((int) select('botsaz', '*', 'bot_token', $token, 'count') !== 0) {
        return ['ok' => false, 'msg' => 'این توکن از قبل ثبت شده است.', 'username' => null];
    }

    $rootPath = agent_resolve_project_root($rootPath);
    $defaultDir = $rootPath . '/vpnbot/Default';
    if (!is_dir($defaultDir)) {
        return ['ok' => false, 'msg' => 'پوشه قالب ربات (vpnbot/Default) یافت نشد.', 'username' => null];
    }
    $dirsource = $rootPath . '/vpnbot/' . $agentUserId . $botUsername;
    if (is_dir($dirsource) && !deleteDirectory($dirsource)) {
        error_log('Failed to remove existing bot directory: ' . $dirsource);
        return ['ok' => false, 'msg' => 'حذف پوشه قبلی ربات ناموفق بود.', 'username' => null];
    }
    if (!copyDirectoryContents($defaultDir, $dirsource)) {
        return ['ok' => false, 'msg' => 'کپی فایل‌های ربات ناموفق بود.', 'username' => null];
    }
    $configPath = $dirsource . '/config.php';
    if (!is_file($configPath)) {
        deleteDirectory($dirsource);
        return ['ok' => false, 'msg' => 'فایل config.php ربات یافت نشد.', 'username' => null];
    }
    $contentconfig = file_get_contents($configPath);
    if ($contentconfig === false || strpos($contentconfig, 'BotTokenNew') === false) {
        deleteDirectory($dirsource);
        return ['ok' => false, 'msg' => 'جایگذاری توکن در config ناموفق بود.', 'username' => null];
    }
    file_put_contents($configPath, str_replace('BotTokenNew', $token, $contentconfig));

    $webhookUrl = 'https://' . $domainhosts . '/vpnbot/' . $agentUserId . $botUsername . '/index.php';
    $webhook = agent_telegram_api_get(
        'https://api.telegram.org/bot' . $token . '/setWebhook?' . http_build_query(['url' => $webhookUrl]),
        12
    );
    if ($webhook === null || empty($webhook['ok'])) {
        // Bot files exist; still register in DB but warn about webhook
        error_log('agent_create_sell_bot webhook failed for ' . $agentUserId . ': ' . json_encode($webhook));
    }
    agent_telegram_api_get(
        'https://api.telegram.org/bot' . $token . '/sendMessage?' . http_build_query([
            'chat_id' => $agentUserId,
            'text' => '✅ کاربر عزیز ربات شما با موفقیت نصب گردید.',
        ]),
        8
    );

    $admin_ids = json_encode([$agentUserId]);
    $datasetting = json_encode([
        'minpricetime' => 4000,
        'pricetime' => 4000,
        'minpricevolume' => 4000,
        'pricevolume' => 4000,
        'support_username' => '@support',
        'Channel_Report' => 0,
        'card_number' => '',
        'card_holder' => '',
        'cart_info' => 'پس از واریز، تصویر رسید را در همین چت ارسال کنید.',
        'show_product' => true,
    ]);
    $hide = '{}';
    $time = date('Y/m/d H:i:s');
    try {
        $stmt = $pdo->prepare('INSERT INTO botsaz (id_user,bot_token,admin_ids,username,time,setting,hide_panel) VALUES (:id_user,:bot_token,:admin_ids,:username,:time,:setting,:hide_panel)');
        $stmt->execute([
            ':id_user' => $agentUserId,
            ':bot_token' => $token,
            ':admin_ids' => $admin_ids,
            ':username' => $botUsername,
            ':time' => $time,
            ':setting' => $datasetting,
            ':hide_panel' => $hide,
        ]);
    } catch (Throwable $e) {
        error_log('agent_create_sell_bot insert failed: ' . $e->getMessage());
        deleteDirectory($dirsource);
        return ['ok' => false, 'msg' => 'ثبت ربات در دیتابیس ناموفق بود.', 'username' => null];
    }

    $msg = 'ربات با موفقیت ساخته شد.';
    if ($webhook === null || empty($webhook['ok'])) {
        $msg .= ' (هشدار: تنظیم وبهوک تلگرام کامل نشد؛ اتصال شبکه به api.telegram.org را بررسی کنید.)';
    }
    return ['ok' => true, 'msg' => $msg, 'username' => $botUsername, 'token' => $token];
}

/**
 * Re-copy sell-bot files from template and reset webhook using existing botsaz row.
 */
function agent_repair_sell_bot($agentUserId, $rootPath = null): array
{
    global $pdo, $domainhosts;

    $agentUserId = (string) $agentUserId;
    $contentbot = select('botsaz', '*', 'id_user', $agentUserId, 'select');
    if (!$contentbot || empty($contentbot['bot_token']) || empty($contentbot['username'])) {
        return ['ok' => false, 'msg' => 'ربات فروشی برای این نماینده یافت نشد.'];
    }
    $token = $contentbot['bot_token'];
    $botUsername = $contentbot['username'];
    $rootPath = agent_resolve_project_root($rootPath);
    $defaultDir = $rootPath . '/vpnbot/Default';
    if (!is_dir($defaultDir)) {
        return ['ok' => false, 'msg' => 'پوشه قالب ربات یافت نشد.'];
    }
    $dirsource = $rootPath . '/vpnbot/' . $agentUserId . $botUsername;
    if (is_dir($dirsource) && !deleteDirectory($dirsource)) {
        return ['ok' => false, 'msg' => 'حذف پوشه قبلی ناموفق بود.'];
    }
    if (!copyDirectoryContents($defaultDir, $dirsource)) {
        return ['ok' => false, 'msg' => 'کپی فایل‌های ربات ناموفق بود.'];
    }
    $configPath = $dirsource . '/config.php';
    $contentconfig = @file_get_contents($configPath);
    if ($contentconfig === false || strpos($contentconfig, 'BotTokenNew') === false) {
        return ['ok' => false, 'msg' => 'جایگذاری توکن ناموفق بود.'];
    }
    file_put_contents($configPath, str_replace('BotTokenNew', $token, $contentconfig));

    $webhookUrl = 'https://' . $domainhosts . '/vpnbot/' . $agentUserId . $botUsername . '/index.php';
    $webhook = agent_telegram_api_get(
        'https://api.telegram.org/bot' . $token . '/setWebhook?' . http_build_query(['url' => $webhookUrl]),
        12
    );
    $msg = 'فایل‌های ربات بازسازی و وبهوک تنظیم شد.';
    if ($webhook === null || empty($webhook['ok'])) {
        $msg .= ' (هشدار: وبهوک تلگرام کامل نشد.)';
    }
    return ['ok' => true, 'msg' => $msg, 'username' => $botUsername];
}

/**
 * Remove agent sell bot (filesystem + webhook + botsaz row).
 */
function agent_remove_sell_bot($agentUserId, $rootPath = null): array
{
    global $pdo;

    $agentUserId = (string) $agentUserId;
    $contentbot = select('botsaz', '*', 'id_user', $agentUserId, 'select');
    if (!$contentbot) {
        return ['ok' => false, 'msg' => 'ربات فروشی برای این نماینده یافت نشد.'];
    }

    $rootPath = agent_resolve_project_root($rootPath);
    $dirsource = $rootPath . '/vpnbot/' . $agentUserId . $contentbot['username'];
    if (is_dir($dirsource) && !deleteDirectory($dirsource)) {
        error_log('Failed to remove bot directory: ' . $dirsource);
    }
    if (!empty($contentbot['bot_token'])) {
        agent_telegram_api_get('https://api.telegram.org/bot' . $contentbot['bot_token'] . '/deleteWebhook', 8);
    }
    $stmt = $pdo->prepare('DELETE FROM botsaz WHERE id_user = :id_user');
    $stmt->execute([':id_user' => $agentUserId]);
    return ['ok' => true, 'msg' => 'ربات فروش حذف شد.'];
}

/**
 * Normalize botsaz.setting payment fields for agent sell bots.
 */
function botsaz_normalize_setting($setting): array
{
    if (!is_array($setting)) {
        $setting = [];
    }
    if (!isset($setting['card_number'])) {
        $setting['card_number'] = '';
    }
    if (!isset($setting['card_holder'])) {
        $setting['card_holder'] = '';
    }
    if (!isset($setting['cart_info']) || $setting['cart_info'] === '') {
        $setting['cart_info'] = 'پس از واریز، تصویر رسید را در همین چت ارسال کنید.';
    }
    return $setting;
}

/**
 * Build card-to-cart payment instructions for sell-bot users.
 */
function botsaz_cart_payment_text(array $setting, $amount = null, $orderId = null, array $opts = []): string
{
    $setting = botsaz_normalize_setting($setting);
    $cardNumber = trim((string) ($setting['card_number'] ?? ''));
    $cardHolder = trim((string) ($setting['card_holder'] ?? ''));
    $help = trim((string) ($setting['cart_info'] ?? ''));
    $title = htmlspecialchars(trim((string) ($opts['title'] ?? '💳 پرداخت کارت به کارت')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $productName = htmlspecialchars(trim((string) ($opts['product'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCardNumber = htmlspecialchars($cardNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCardHolder = htmlspecialchars($cardHolder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeHelp = htmlspecialchars($help, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $lines = [$title . "\n"];
    if ($productName !== '') {
        $lines[] = "🛍 محصول: <b>{$productName}</b>";
    }
    if ($cardNumber !== '') {
        $lines[] = "💳 شماره کارت:\n<code>{$safeCardNumber}</code>";
    } else {
        $lines[] = "⚠️ شماره کارت هنوز توسط نماینده تنظیم نشده است.";
    }
    if ($cardHolder !== '') {
        $lines[] = "👤 به نام: <b>{$safeCardHolder}</b>";
    }
    if ($amount !== null && $amount !== '') {
        $lines[] = "💰 مبلغ: <b>" . number_format((int) $amount) . "</b> تومان";
    }
    if ($orderId !== null && $orderId !== '') {
        $lines[] = "🛒 کد پیگیری: <code>{$orderId}</code>";
    }
    if ($help !== '') {
        $lines[] = "\n📌 " . $safeHelp;
    }
    $lines[] = "\nلطفاً پس از واریز، تصویر یا متن رسید را ارسال کنید.";
    return implode("\n", $lines);
}

/**
 * Create an unpaid cart payment and return order id + payment text for sell-bot.
 */
function botsaz_create_cart_payment($fromId, $amount, $botToken, array $setting, $invoiceRef = '0 | 0', array $opts = []): array
{
    global $connect;
    $normalizedSetting = botsaz_normalize_setting($setting);
    if (trim((string) ($normalizedSetting['card_number'] ?? '')) === '') {
        return [
            'ok' => false,
            'error' => 'card_not_configured',
            'id_order' => '',
            'price' => (int) $amount,
            'text' => '',
            'card_ok' => false,
        ];
    }
    $amount = (int) $amount;
    if ($amount < 0) {
        $amount = 0;
    }
    $amountStr = (string) $amount;
    $fromId = (string) $fromId;
    $botToken = (string) $botToken;
    $invoiceRef = (string) $invoiceRef;
    $dateacc = date('Y/m/d H:i:s');
    $randomString = bin2hex(random_bytes(5));
    $payment_Status = 'Unpaid';
    $Payment_Method = 'cart to cart';
    try {
        $stmt = $connect->prepare('INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,bottype) VALUES (?,?,?,?,?,?,?,?)');
        if (!$stmt) {
            throw new RuntimeException($connect->error ?: 'Payment query prepare failed');
        }
        $stmt->bind_param('ssssssss', $fromId, $randomString, $dateacc, $amountStr, $payment_Status, $Payment_Method, $invoiceRef, $botToken);
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error ?: 'Payment query execute failed');
        }
        $stmt->close();
    } catch (Throwable $e) {
        error_log('botsaz direct cart payment failed: ' . $e->getMessage());
        return [
            'ok' => false,
            'error' => 'payment_create_failed',
            'id_order' => '',
            'price' => $amount,
            'text' => '',
            'card_ok' => true,
        ];
    }
    return [
        'ok' => true,
        'id_order' => $randomString,
        'price' => $amount,
        'text' => botsaz_cart_payment_text($normalizedSetting, $amount, $randomString, $opts),
        'card_ok' => true,
    ];
}

#-----------DiscountSell scope (multi product/panel/category)------------#
function discount_sell_ensure_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    global $pdo, $connect;
    try {
        if ($pdo instanceof PDO) {
            $cols = $pdo->query("SHOW COLUMNS FROM DiscountSell")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('code_category', $cols, true)) {
                $pdo->exec("ALTER TABLE DiscountSell ADD code_category TEXT NULL");
                $pdo->exec("UPDATE DiscountSell SET code_category = 'all' WHERE code_category IS NULL OR code_category = ''");
            }
            foreach (['code_product', 'code_panel', 'code_category'] as $col) {
                $pdo->exec("ALTER TABLE DiscountSell MODIFY `$col` TEXT NULL");
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS DiscountSellUsage (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(255) NOT NULL,
                id_user VARCHAR(64) NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'buy',
                code_product VARCHAR(100) NULL,
                name_product VARCHAR(255) NULL,
                code_panel VARCHAR(100) NULL,
                name_panel VARCHAR(255) NULL,
                id_invoice VARCHAR(100) NULL,
                price_original VARCHAR(50) NULL,
                price_final VARCHAR(50) NULL,
                created_at INT UNSIGNED NOT NULL,
                KEY idx_discount_usage_code (code),
                KEY idx_discount_usage_user (id_user)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } elseif (isset($connect) && $connect) {
            $check = $connect->query("SHOW COLUMNS FROM DiscountSell LIKE 'code_category'");
            if ($check && mysqli_num_rows($check) != 1) {
                $connect->query("ALTER TABLE DiscountSell ADD code_category TEXT NULL");
                $connect->query("UPDATE DiscountSell SET code_category = 'all' WHERE code_category IS NULL OR code_category = ''");
            }
            $connect->query("ALTER TABLE DiscountSell MODIFY code_product TEXT NULL");
            $connect->query("ALTER TABLE DiscountSell MODIFY code_panel TEXT NULL");
            $connect->query("ALTER TABLE DiscountSell MODIFY code_category TEXT NULL");
            $connect->query("CREATE TABLE IF NOT EXISTS DiscountSellUsage (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(255) NOT NULL,
                id_user VARCHAR(64) NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'buy',
                code_product VARCHAR(100) NULL,
                name_product VARCHAR(255) NULL,
                code_panel VARCHAR(100) NULL,
                name_panel VARCHAR(255) NULL,
                id_invoice VARCHAR(100) NULL,
                price_original VARCHAR(50) NULL,
                price_final VARCHAR(50) NULL,
                created_at INT UNSIGNED NOT NULL,
                KEY idx_discount_usage_code (code),
                KEY idx_discount_usage_user (id_user)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    } catch (Throwable $e) {
        error_log('discount_sell_ensure_schema: ' . $e->getMessage());
    }
    $ready = true;
}

function discount_scope_values(?string $raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return ['all'];
    }
    if (isset($raw[0]) && $raw[0] === '[') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $out[] = $v;
                }
            }
            return $out !== [] ? array_values(array_unique($out)) : ['all'];
        }
    }
    if (strpos($raw, ',') !== false) {
        $out = [];
        foreach (explode(',', $raw) as $v) {
            $v = trim($v);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return $out !== [] ? array_values(array_unique($out)) : ['all'];
    }
    return [$raw];
}

function discount_scope_is_all(array $values, array $allTokens = ['all', '/all']): bool
{
    if ($values === []) {
        return true;
    }
    foreach ($values as $v) {
        if (in_array($v, $allTokens, true)) {
            return true;
        }
    }
    return false;
}

function discount_scope_allows(?string $raw, string $needle, array $allTokens = ['all', '/all']): bool
{
    $values = discount_scope_values($raw);
    if (discount_scope_is_all($values, $allTokens)) {
        return true;
    }
    return in_array($needle, $values, true);
}

function discount_sell_encode_scope($values, string $allToken = 'all'): string
{
    if (!is_array($values)) {
        $values = [$values];
    }
    $clean = [];
    foreach ($values as $v) {
        $v = trim((string) $v);
        if ($v !== '') {
            $clean[] = $v;
        }
    }
    $clean = array_values(array_unique($clean));
    if ($clean === [] || discount_scope_is_all($clean, ['all', '/all'])) {
        return $allToken;
    }
    if (count($clean) === 1) {
        return $clean[0];
    }
    return json_encode($clean, JSON_UNESCAPED_UNICODE);
}

function discount_sell_applies(array $discount, string $codeProduct, string $codePanel, ?string $category = null, ?string $type = null, ?string $agent = null): bool
{
    if ($agent !== null) {
        $dAgent = (string) ($discount['agent'] ?? 'allusers');
        if ($dAgent !== 'allusers' && $dAgent !== $agent) {
            return false;
        }
    }
    if ($type !== null) {
        $dType = (string) ($discount['type'] ?? 'all');
        if ($dType !== 'all' && $dType !== $type) {
            return false;
        }
    }
    if (!discount_scope_allows($discount['code_panel'] ?? '/all', $codePanel, ['all', '/all'])) {
        return false;
    }
    if (!discount_scope_allows($discount['code_product'] ?? 'all', $codeProduct, ['all'])) {
        return false;
    }
    if (!discount_scope_allows($discount['code_category'] ?? 'all', (string) ($category ?? ''), ['all'])) {
        return false;
    }
    return true;
}

function discount_scope_label(array $values, array $nameMap, string $allLabel, int $max = 2): string
{
    if (discount_scope_is_all($values, ['all', '/all'])) {
        return $allLabel;
    }
    $labels = [];
    foreach ($values as $v) {
        $labels[] = $nameMap[$v] ?? $v;
    }
    if (count($labels) <= $max) {
        return implode('، ', $labels);
    }
    return implode('، ', array_slice($labels, 0, $max)) . ' +' . (count($labels) - $max);
}

/**
 * Record a successful DiscountSell redemption: bump usedDiscount, Giftcodeconsumed, and detailed usage log.
 *
 * @param array{
 *   code: string,
 *   id_user: string|int,
 *   type?: string,
 *   code_product?: string|null,
 *   name_product?: string|null,
 *   code_panel?: string|null,
 *   name_panel?: string|null,
 *   id_invoice?: string|null,
 *   price_original?: string|int|null,
 *   price_final?: string|int|null
 * } $data
 */
function discount_sell_record_usage(array $data): bool
{
    global $pdo, $connect;

    $code = trim((string) ($data['code'] ?? ''));
    $idUser = (string) ($data['id_user'] ?? '');
    if ($code === '' || $idUser === '') {
        return false;
    }

    discount_sell_ensure_schema();

    $type = trim((string) ($data['type'] ?? 'buy'));
    if (!in_array($type, ['buy', 'extend'], true)) {
        $type = 'buy';
    }

    try {
        $discount = select('DiscountSell', '*', 'codeDiscount', $code, 'select');
        if ($discount) {
            $value = (int) ($discount['usedDiscount'] ?? 0) + 1;
            update('DiscountSell', 'usedDiscount', $value, 'codeDiscount', $code);
        }

        if ($pdo instanceof PDO) {
            $stmt = $pdo->prepare('INSERT INTO Giftcodeconsumed (id_user, code) VALUES (:id_user, :code)');
            $stmt->execute([':id_user' => $idUser, ':code' => $code]);
            $stmt = $pdo->prepare(
                'INSERT INTO DiscountSellUsage
                (code, id_user, type, code_product, name_product, code_panel, name_panel, id_invoice, price_original, price_final, created_at)
                VALUES
                (:code, :id_user, :type, :code_product, :name_product, :code_panel, :name_panel, :id_invoice, :price_original, :price_final, :created_at)'
            );
            $stmt->execute([
                ':code' => $code,
                ':id_user' => $idUser,
                ':type' => $type,
                ':code_product' => $data['code_product'] ?? null,
                ':name_product' => $data['name_product'] ?? null,
                ':code_panel' => $data['code_panel'] ?? null,
                ':name_panel' => $data['name_panel'] ?? null,
                ':id_invoice' => $data['id_invoice'] ?? null,
                ':price_original' => isset($data['price_original']) ? (string) $data['price_original'] : null,
                ':price_final' => isset($data['price_final']) ? (string) $data['price_final'] : null,
                ':created_at' => time(),
            ]);
        } elseif (isset($connect) && $connect) {
            $stmt = $connect->prepare('INSERT INTO Giftcodeconsumed (id_user, code) VALUES (?, ?)');
            $stmt->bind_param('ss', $idUser, $code);
            $stmt->execute();
            $stmt->close();
            $stmt = $connect->prepare(
                'INSERT INTO DiscountSellUsage
                (code, id_user, type, code_product, name_product, code_panel, name_panel, id_invoice, price_original, price_final, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $codeProduct = $data['code_product'] ?? null;
            $nameProduct = $data['name_product'] ?? null;
            $codePanel = $data['code_panel'] ?? null;
            $namePanel = $data['name_panel'] ?? null;
            $idInvoice = $data['id_invoice'] ?? null;
            $priceOriginal = isset($data['price_original']) ? (string) $data['price_original'] : null;
            $priceFinal = isset($data['price_final']) ? (string) $data['price_final'] : null;
            $createdAt = time();
            $stmt->bind_param(
                'ssssssssssi',
                $code,
                $idUser,
                $type,
                $codeProduct,
                $nameProduct,
                $codePanel,
                $namePanel,
                $idInvoice,
                $priceOriginal,
                $priceFinal,
                $createdAt
            );
            $stmt->execute();
            $stmt->close();
        }
        return true;
    } catch (Throwable $e) {
        error_log('discount_sell_record_usage: ' . $e->getMessage());
        return false;
    }
}

function product_discount_ensure_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    global $pdo, $connect;
    $sql = "CREATE TABLE IF NOT EXISTS ProductDiscount (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        type VARCHAR(20) NOT NULL,
        amount INT NOT NULL DEFAULT 0,
        products TEXT NOT NULL,
        use_limit INT NULL,
        created_at INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try {
        if ($pdo instanceof PDO) {
            $pdo->exec($sql);
            $cols = $pdo->query("SHOW COLUMNS FROM ProductDiscount")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('use_limit', $cols, true)) {
                $pdo->exec("ALTER TABLE ProductDiscount ADD use_limit INT NULL");
            }
        } elseif (isset($connect) && $connect) {
            $connect->query($sql);
            $check = $connect->query("SHOW COLUMNS FROM ProductDiscount LIKE 'use_limit'");
            if ($check && mysqli_num_rows($check) != 1) {
                $connect->query("ALTER TABLE ProductDiscount ADD use_limit INT NULL");
            }
        }
    } catch (Throwable $e) {
        error_log('product_discount_ensure_schema: ' . $e->getMessage());
    }
    $ready = true;
}

function product_discount_decode_products($raw): array
{
    if (is_array($raw)) {
        $list = $raw;
    } else {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $list = $decoded;
        } else {
            $list = preg_split('/\s*,\s*/', $raw) ?: [];
        }
    }
    $out = [];
    foreach ($list as $v) {
        $v = trim((string) $v);
        if ($v !== '') {
            $out[] = $v;
        }
    }
    return array_values(array_unique($out));
}

function product_discount_encode_products($values): string
{
    $clean = product_discount_decode_products($values);
    return json_encode($clean, JSON_UNESCAPED_UNICODE);
}

function product_discount_sale_price(int $original, string $type, int $amount): int
{
    if ($original <= 0 || $amount <= 0) {
        return max(0, $original);
    }
    if ($type === 'percent') {
        $amount = min(100, $amount);
        return (int) max(0, round($original - (($original * $amount) / 100)));
    }
    return (int) max(0, $original - $amount);
}

function product_discount_row_has_quota(array $row): bool
{
    if (!array_key_exists('use_limit', $row) || $row['use_limit'] === null || $row['use_limit'] === '') {
        return true;
    }
    return (int) $row['use_limit'] > 0;
}

function product_discount_active_rows(bool $reset = false): array
{
    static $rows = null;
    if ($reset) {
        $rows = null;
        return [];
    }
    if ($rows !== null) {
        return $rows;
    }
    product_discount_ensure_schema();
    global $pdo, $connect;
    $rows = [];
    $sql = "SELECT * FROM ProductDiscount WHERE status = 'active' AND (use_limit IS NULL OR use_limit > 0) ORDER BY id DESC";
    try {
        if ($pdo instanceof PDO) {
            $stmt = $pdo->query($sql);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } elseif (isset($connect) && $connect) {
            $result = $connect->query($sql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('product_discount_active_rows: ' . $e->getMessage());
        $rows = [];
    }
    $rows = array_values(array_filter($rows, 'product_discount_row_has_quota'));
    return $rows;
}

function product_discount_consume($codeProduct, $user = null): bool
{
    $agent = is_array($user) ? (string) ($user['agent'] ?? '') : '';
    if ($agent === 'n') {
        return false;
    }
    $codeProduct = trim((string) $codeProduct);
    if ($codeProduct === '' || stripos($codeProduct, 'customvolume') === 0) {
        return false;
    }
    global $pdo, $connect;
    $original = 0;
    try {
        if ($pdo instanceof PDO) {
            $stmt = $pdo->prepare('SELECT price_product FROM product WHERE code_product = ? LIMIT 1');
            $stmt->execute([$codeProduct]);
            $original = (int) round((float) ($stmt->fetchColumn() ?: 0));
        } elseif (isset($connect) && $connect) {
            $stmt = $connect->prepare('SELECT price_product FROM product WHERE code_product = ? LIMIT 1');
            $stmt->bind_param('s', $codeProduct);
            $stmt->execute();
            $res = $stmt->get_result();
            $rowPrice = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            $original = (int) round((float) ($rowPrice['price_product'] ?? 0));
        }
    } catch (Throwable $e) {
        error_log('product_discount_consume price: ' . $e->getMessage());
        return false;
    }
    if ($original <= 0) {
        return false;
    }
    $applied = product_discount_apply($original, $codeProduct);
    if (empty($applied['applied']) || empty($applied['row']['id'])) {
        return false;
    }
    $row = $applied['row'];
    if (!product_discount_row_has_quota($row) || !array_key_exists('use_limit', $row) || $row['use_limit'] === null || $row['use_limit'] === '') {
        return false;
    }
    $id = (int) $row['id'];
    $sql = "UPDATE ProductDiscount SET status = IF(use_limit <= 1, 'inactive', status), use_limit = use_limit - 1 WHERE id = ? AND status = 'active' AND use_limit IS NOT NULL AND use_limit > 0";
    $ok = false;
    try {
        if ($pdo instanceof PDO) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            $ok = $stmt->rowCount() > 0;
        } elseif (isset($connect) && $connect) {
            $stmt = $connect->prepare($sql);
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('product_discount_consume: ' . $e->getMessage());
        $ok = false;
    }
    product_discount_active_rows(true);
    return $ok;
}

function product_discount_for_product(string $codeProduct): ?array
{
    $codeProduct = trim($codeProduct);
    if ($codeProduct === '' || stripos($codeProduct, 'customvolume') === 0) {
        return null;
    }
    foreach (product_discount_active_rows() as $row) {
        $products = product_discount_decode_products($row['products'] ?? '');
        if (in_array($codeProduct, $products, true)) {
            return $row;
        }
    }
    return null;
}

function product_discount_apply($original, $codeProduct): array
{
    $original = (int) round((float) $original);
    $codeProduct = trim((string) $codeProduct);
    $result = [
        'original' => $original,
        'sale' => $original,
        'applied' => false,
        'row' => null,
    ];
    if ($original <= 0 || $codeProduct === '' || stripos($codeProduct, 'customvolume') === 0) {
        return $result;
    }
    $bestSale = $original;
    $bestRow = null;
    foreach (product_discount_active_rows() as $row) {
        $products = product_discount_decode_products($row['products'] ?? '');
        if (!in_array($codeProduct, $products, true)) {
            continue;
        }
        $sale = product_discount_sale_price(
            $original,
            (string) ($row['type'] ?? 'value'),
            (int) ($row['amount'] ?? 0)
        );
        if ($sale < $bestSale) {
            $bestSale = $sale;
            $bestRow = $row;
        }
    }
    if ($bestRow !== null && $bestSale < $original) {
        $result['sale'] = $bestSale;
        $result['applied'] = true;
        $result['row'] = $bestRow;
    }
    return $result;
}

function product_discount_apply_for_user($original, $codeProduct, $user = null): int
{
    $agent = is_array($user) ? (string) ($user['agent'] ?? '') : '';
    if ($agent === 'n') {
        return (int) round((float) $original);
    }
    return product_discount_apply($original, $codeProduct)['sale'];
}

function product_discount_payable($original, $codeProduct, $userPercent = 0, $user = null): array
{
    $original = (int) round((float) $original);
    $agent = is_array($user) ? (string) ($user['agent'] ?? '') : '';
    if ($agent === 'n') {
        return [
            'original' => $original,
            'sale' => $original,
            'payable' => $original,
            'applied' => false,
        ];
    }
    $pd = product_discount_apply($original, $codeProduct);
    $sale = (int) $pd['sale'];
    $payable = $sale;
    $pct = (int) $userPercent;
    if ($pct !== 0) {
        $payable = (int) round($sale - (($sale * $pct) / 100));
    }
    if ($payable < 0) {
        $payable = 0;
    }
    return [
        'original' => (int) $pd['original'],
        'sale' => $sale,
        'payable' => $payable,
        'applied' => (bool) $pd['applied'],
    ];
}

function product_discount_strikethrough_text(string $text): string
{
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars)) {
        return $text;
    }
    $out = '';
    foreach ($chars as $ch) {
        if ($ch === "\n" || $ch === "\r") {
            $out .= $ch;
            continue;
        }
        $out .= $ch . "\u{0336}";
    }
    return $out;
}

function product_discount_format_html(int $original, int $sale, bool $applied): string
{
    if ($applied && $original !== $sale) {
        // Telegram HTML: <s> draws one continuous line across the whole original price.
        return '<s>' . number_format($original) . '</s> ' . number_format($sale);
    }
    return number_format($sale);
}

function product_discount_format_button(int $original, int $sale, bool $applied): string
{
    if ($applied && $original !== $sale) {
        return product_discount_strikethrough_text(number_format($original)) . ' ' . number_format($sale);
    }
    return number_format($sale);
}

function product_discount_to_latin_digits(string $text): string
{
    return str_replace(
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        $text
    );
}

function product_discount_to_persian_digits(string $text): string
{
    return str_replace(
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
        $text
    );
}

function product_discount_format_like(string $sample, int $amount): string
{
    $hasPersianDigits = (bool) preg_match('/[۰-۹٠-٩]/u', $sample);
    $latin = product_discount_to_latin_digits($sample);
    if (strpos($sample, '٬') !== false) {
        $formatted = str_replace(',', '٬', number_format($amount));
    } elseif (strpos($latin, ',') !== false) {
        $formatted = number_format($amount);
    } elseif (preg_match('/\d\.\d{3}/', $latin)) {
        $formatted = str_replace(',', '.', number_format($amount));
    } else {
        $formatted = (string) $amount;
    }
    if ($hasPersianDigits) {
        $formatted = product_discount_to_persian_digits($formatted);
    }
    return $formatted;
}

function product_discount_badge_emoji_id(): string
{
    return '5229064374403998351';
}

function product_discount_button_emoji($productEmojiId, bool $applied): string
{
    $existing = stored_custom_emoji_id($productEmojiId);
    if ($existing !== '') {
        return $existing;
    }
    if ($applied) {
        return product_discount_badge_emoji_id();
    }
    return '';
}

/**
 * Temporarily rewrite the catalog price inside a product title (display-only).
 * Matches 50000 / 50,000 / 50.000 / ۵۰٬۰۰۰ without touching other numbers like volume.
 */
function product_discount_rewrite_name(string $name, int $original, int $sale, bool $html = false): string
{
    if ($name === '' || $original <= 0 || $sale === $original) {
        return $name;
    }
    $replaced = preg_replace_callback(
        '/(?<![\d۰-۹٠-٩])([۰-۹٠-٩\d]{1,3}(?:[,.٬][۰-۹٠-٩\d]{3})+|[۰-۹٠-٩\d]+)(?![\d۰-۹٠-٩])/u',
        static function (array $m) use ($original, $sale, $html): string {
            $token = $m[1];
            $digits = preg_replace('/\D+/', '', product_discount_to_latin_digits($token));
            if ($digits === '' || (int) $digits !== $original) {
                return $m[0];
            }
            $new = product_discount_format_like($token, $sale);
            if ($html) {
                return '<s>' . $token . '</s> ' . $new;
            }
            return $new;
        },
        $name
    );
    return is_string($replaced) ? $replaced : $name;
}

function broadcast_audience_label(array $userdata)
{
    if (($userdata['typeusermessage'] ?? '') === 'channelpost') {
        $channel = $userdata['channel_id'] ?? '';
        return 'پست کانال: ' . $channel;
    }
    $audience = [
        'all' => 'همه کاربران',
        'customer' => 'مشتریان',
        'nonecustomer' => 'بدون خرید',
        'testonly' => 'کاربرانی که تست کردند ولی خرید نداشتند',
        'notestnopurchase' => 'کاربرانی که تست و خرید نداشته اند',
        'highvolume' => 'مصرف بیش از ۸۰٪',
    ][$userdata['typeusermessage'] ?? ''] ?? ($userdata['typeusermessage'] ?? '-');
    $agent = [
        'all' => 'همه نمایندگی‌ها',
        'f' => 'کاربر عادی',
        'n' => 'نماینده',
        'n2' => 'نماینده سطح ۲',
    ][$userdata['agent'] ?? ''] ?? ($userdata['agent'] ?? '-');
    $extra = '';
    if (($userdata['typeservice'] ?? '') === 'xdaynotmessage' && !empty($userdata['daynoyuse'])) {
        $extra = " | غیرفعال {$userdata['daynoyuse']} روز";
    }
    if (!empty($userdata['selectpanel']) && $userdata['selectpanel'] !== 'all') {
        $panel = select("marzban_panel", "*", "code_panel", $userdata['selectpanel'], "select");
        if (!empty($panel['name_panel'])) {
            $extra .= ' | پنل: ' . $panel['name_panel'];
        }
    }
    return "{$audience} / {$agent}{$extra}";
}

function ensure_broadcast_schema()
{
    global $pdo;
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("INSERT IGNORE INTO topicid (idreport, report) VALUES ('0', 'reportsms')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS broadcast_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id BIGINT NOT NULL,
        type VARCHAR(50) NOT NULL,
        message_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        media_type VARCHAR(20) NOT NULL DEFAULT 'text',
        photo_id VARCHAR(255) NOT NULL DEFAULT '',
        btn_type VARCHAR(50) NOT NULL DEFAULT 'none',
        audience_label VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        recipient_count INT NOT NULL DEFAULT 0,
        click_count INT NOT NULL DEFAULT 0,
        report_message_id BIGINT NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'started',
        created_at INT NOT NULL,
        payload MEDIUMTEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS broadcast_click (
        id INT AUTO_INCREMENT PRIMARY KEY,
        broadcast_id INT NOT NULL,
        user_id BIGINT NOT NULL,
        clicked_at INT NOT NULL,
        UNIQUE KEY uniq_broadcast_user (broadcast_id, user_id),
        KEY idx_broadcast_id (broadcast_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broadcast_log' AND COLUMN_NAME = 'payload'");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE broadcast_log ADD payload MEDIUMTEXT NULL");
        }
    } catch (Throwable $e) {
        error_log('ensure_broadcast_schema payload: ' . $e->getMessage());
    }
    $ready = true;
}

function broadcast_attachable_buttons()
{
    return [
        'start' => [
            'admin_label' => 'دکمه استارت',
            'text_key' => null,
            'fallback' => 'شروع',
            'callback' => 'start',
            'legacy' => 'start',
            'route_text' => 'start',
            'route_datain' => 'start',
        ],
        'buy' => [
            'admin_label' => 'دکمه خرید',
            'text_key' => 'text_sell',
            'fallback' => '🔐 خرید اشتراک',
            'callback' => 'buy',
            'legacy' => 'buy',
            'route_text' => 'buy',
            'route_datain' => 'buy',
        ],
        'usertestbtn' => [
            'admin_label' => 'دکمه اکانت تست',
            'text_key' => 'text_usertest',
            'fallback' => '🔑 اکانت تست',
            'callback' => 'usertestbtn',
            'legacy' => 'usertest',
            'route_text' => 'usertest',
            'route_datain' => 'usertestbtn',
        ],
        'services' => [
            'admin_label' => 'دکمه سرویس های من',
            'text_key' => 'text_Purchased_services',
            'fallback' => '🛍 سرویس های من',
            'callback' => 'backorder',
            'legacy' => 'services',
            'route_text' => '/services',
            'route_datain' => 'backorder',
        ],
        'extendbtn' => [
            'admin_label' => 'دکمه تمدید سرویس',
            'text_key' => 'text_extend',
            'fallback' => '♻️ تمدید سرویس',
            'callback' => 'extendbtn',
            'legacy' => 'extend',
            'route_text' => '',
            'route_datain' => 'extendbtn',
        ],
        'wallet' => [
            'admin_label' => 'دکمه کیف پول',
            'text_key' => 'accountwallet',
            'fallback' => '🏦 کیف پول + شارژ',
            'callback' => 'account',
            'legacy' => 'wallet',
            'route_text' => '/wallet',
            'route_datain' => 'account',
        ],
        'addbalance' => [
            'admin_label' => 'شارژ حساب کاربری',
            'text_key' => 'text_Add_Balance',
            'fallback' => '💰 شارژ حساب',
            'callback' => 'Add_Balance',
            'legacy' => 'bal',
            'route_text' => '',
            'route_datain' => 'Add_Balance',
        ],
        'tariff' => [
            'admin_label' => 'دکمه تعرفه اشتراک ها',
            'text_key' => 'text_Tariff_list',
            'fallback' => '💵 تعرفه اشتراک ها',
            'callback' => 'Tariff_list',
            'legacy' => 'tariff',
            'route_text' => '',
            'route_datain' => 'Tariff_list',
        ],
        'helpbtn' => [
            'admin_label' => 'دکمه آموزش',
            'text_key' => 'text_help',
            'fallback' => '📚 آموزش',
            'callback' => 'helpbtn',
            'legacy' => 'help',
            'route_text' => 'help',
            'route_datain' => 'helpbtn',
        ],
        'support' => [
            'admin_label' => 'دکمه پشتیبانی',
            'text_key' => 'text_support',
            'fallback' => '☎️ پشتیبانی',
            'callback' => 'supportbtns',
            'legacy' => 'support',
            'route_text' => '/support',
            'route_datain' => 'supportbtns',
        ],
        'affiliatesbtn' => [
            'admin_label' => 'دکمه زیرمجموعه گیری',
            'text_key' => 'text_affiliates',
            'fallback' => '👥 زیر مجموعه گیری',
            'callback' => 'affiliatesbtn',
            'legacy' => 'aff',
            'route_text' => '',
            'route_datain' => 'affiliatesbtn',
        ],
        'referral' => [
            'admin_label' => 'دکمه دعوت دوستان',
            'text_key' => 'text_referral',
            'fallback' => '🎁 دعوت دوستان',
            'callback' => 'referralbtn',
            'legacy' => 'invite',
            'route_text' => '',
            'route_datain' => 'referralbtn',
        ],
        'wheel' => [
            'admin_label' => 'دکمه گردونه شانس',
            'text_key' => 'text_wheel_luck',
            'fallback' => '🎲 گردونه شانس',
            'callback' => 'wheel_luck',
            'legacy' => 'wheel',
            'route_text' => '/gift',
            'route_datain' => 'wheel_luck',
        ],
    ];
}

function broadcast_attachable_button_keys()
{
    return array_merge(array_keys(broadcast_attachable_buttons()), ['none']);
}

function broadcast_btn_picker_keyboard($prefix, $extra_rows = [], $texts = null)
{
    global $datatextbot, $setting;
    if (!is_array($texts)) {
        $texts = is_array($datatextbot) ? $datatextbot : [];
    }
    if (!is_array($setting) || !isset($setting['keyboardmain'])) {
        $setting_row = select('setting', 'keyboardmain', null, null, 'select');
        $keyboardmain = is_array($setting_row) ? ($setting_row['keyboardmain'] ?? '') : '';
    } else {
        $keyboardmain = $setting['keyboardmain'] ?? '';
    }
    $main_ids = get_main_keyboard_button_ids();

    $rows = [];
    $row = [];
    foreach (broadcast_attachable_buttons() as $key => $meta) {
        $text_key = $meta['text_key'] ?? null;
        // Main-menu buttons: only show when currently active in the user keyboard.
        if ($text_key !== null && in_array($text_key, $main_ids, true)
            && !check_active_btn($keyboardmain, $text_key, $texts)) {
            continue;
        }
        $label = broadcast_btn_label($key, $texts);
        if ($label === '') {
            $label = $meta['admin_label'] ?? $key;
        }
        if (mb_strlen($label) > 64) {
            $label = mb_substr($label, 0, 64);
        }
        $row[] = [
            'text' => $label,
            'callback_data' => $prefix . '-' . $key,
        ];
        if (count($row) >= 2) {
            $rows[] = $row;
            $row = [];
        }
    }
    if ($row !== []) {
        $rows[] = $row;
    }
    $rows[] = [
        ['text' => 'ارسال بدون دکمه', 'callback_data' => $prefix . '-none'],
    ];
    foreach ($extra_rows as $extra) {
        if (is_array($extra) && $extra !== []) {
            $rows[] = $extra;
        }
    }
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function broadcast_btn_action_map()
{
    $map = [];
    foreach (broadcast_attachable_buttons() as $key => $meta) {
        $map[$key] = $meta['callback'];
    }
    return $map;
}

function broadcast_btn_label($btn_type, $texts = null)
{
    global $datatextbot;
    if (!is_array($texts)) {
        $texts = is_array($datatextbot) ? $datatextbot : [];
    }
    if ($btn_type === 'none') {
        return 'بدون دکمه';
    }
    $meta = broadcast_attachable_buttons()[$btn_type] ?? null;
    if ($meta === null) {
        return $btn_type;
    }
    $key = $meta['text_key'] ?? null;
    if ($key !== null && in_array($key, get_main_keyboard_button_ids(), true)) {
        return get_main_keyboard_button_label($key, $texts);
    }
    if ($key && !empty($texts[$key])) {
        $label = trim((string) $texts[$key]);
        if ($label !== '' && !str_contains($label, "\n") && mb_strlen($label) <= 64) {
            return $label;
        }
    }
    return $meta['fallback'] ?? $btn_type;
}

function broadcast_legacy_codes()
{
    $codes = [];
    foreach (broadcast_attachable_buttons() as $meta) {
        $codes[] = $meta['legacy'];
    }
    return array_values(array_unique($codes));
}

function broadcast_resolve_btn_text($btn_type, $custom_text = null, $texts = null)
{
    $custom = trim((string) $custom_text);
    if ($custom !== '') {
        return mb_substr($custom, 0, 64);
    }
    return broadcast_btn_label($btn_type, $texts);
}

function broadcast_btn_icon_id($btn_type): string
{
    $meta = broadcast_attachable_buttons()[$btn_type] ?? null;
    $text_key = $meta['text_key'] ?? null;
    if ($text_key === null || $text_key === '') {
        return '';
    }
    $icons = get_main_keyboard_button_icons();
    return stored_custom_emoji_id($icons[$text_key] ?? '');
}

function broadcast_btn_style($btn_type): string
{
    $meta = broadcast_attachable_buttons()[$btn_type] ?? null;
    $text_key = $meta['text_key'] ?? null;
    if ($text_key === null || $text_key === '') {
        return '';
    }
    $styles = get_main_keyboard_button_styles();
    $style = (string) ($styles[$text_key] ?? '');
    $allowed = array_keys(get_main_keyboard_allowed_styles());
    return in_array($style, $allowed, true) ? $style : '';
}

function broadcast_inline_button($btn_type, string $text, array $action): array
{
    $button = array_merge(['text' => $text], $action);
    $button = telegram_button_with_icon($button, broadcast_btn_icon_id($btn_type));
    $style = broadcast_btn_style($btn_type);
    if ($style !== '') {
        $button['style'] = $style;
    }
    return $button;
}

function broadcast_inline_keyboard($btn_type, string $text, array $action): ?string
{
    if ($btn_type === '' || $btn_type === 'none') {
        return null;
    }
    return json_encode([
        'inline_keyboard' => [
            [broadcast_inline_button($btn_type, $text, $action)],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function broadcast_keyboard_without_premium_fields($keyboard_json)
{
    $decoded = is_string($keyboard_json) ? json_decode($keyboard_json, true) : $keyboard_json;
    if (!is_array($decoded)) {
        return $keyboard_json;
    }
    $changed = false;
    foreach ($decoded['inline_keyboard'] ?? [] as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($row as $j => $btn) {
            if (!is_array($btn)) {
                continue;
            }
            if (isset($decoded['inline_keyboard'][$i][$j]['icon_custom_emoji_id'])) {
                unset($decoded['inline_keyboard'][$i][$j]['icon_custom_emoji_id']);
                $changed = true;
            }
            if (isset($decoded['inline_keyboard'][$i][$j]['style'])) {
                unset($decoded['inline_keyboard'][$i][$j]['style']);
                $changed = true;
            }
        }
    }
    if (!$changed) {
        return is_string($keyboard_json) ? $keyboard_json : json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }
    return json_encode($decoded, JSON_UNESCAPED_UNICODE);
}

function broadcast_ask_btn_title_step($from_id, $btn_type)
{
    global $datatextbot;
    $default = broadcast_btn_label($btn_type, $datatextbot);
    $keyboard = json_encode([
        'inline_keyboard' => [
            [
                ['text' => "استفاده از عنوان پیش‌فرض", 'callback_data' => 'btntextdefault'],
            ],
        ]
    ]);
    step("getbtntextmessage", $from_id);
    sendmessage(
        $from_id,
        "✏️ عنوان دکمه‌ای که زیر پیام نمایش داده می‌شود را ارسال کنید.\n\nعنوان پیش‌فرض: <b>" . htmlspecialchars($default, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n\n⚠️ حداکثر ۶۴ کاراکتر.",
        $keyboard,
        'HTML'
    );
}

function broadcast_continue_after_btn_title($from_id)
{
    global $datatextbot, $backadmin, $keyboardadmin;
    $userdata = json_decode(select("user", "*", "id", $from_id, "select")['Processing_value'], true);
    if (!isset($userdata['typeservice'])) {
        sendmessage($from_id, "❌ خطایی رخ داده لطفا مراحل ارسال پیام از اول انجام دهید", $keyboardadmin, 'HTML');
        step("home", $from_id);
        return;
    }
    if (($userdata['typeusermessage'] ?? '') === 'channelpost') {
        step("gettextChannelPost", $from_id);
        sendmessage($from_id, "📌 محتوای پست کانال را ارسال کنید.\nمی‌توانید متن ساده یا عکس همراه با کپشن بفرستید.\n✨ ایموجی پرمیوم هم در پست حفظ می‌شود.", $backadmin, 'HTML');
        return;
    }
    if (($userdata['typeservice'] ?? '') === 'xdaynotmessage') {
        step("gettextday", $from_id);
        sendmessage($from_id, "📌 در این قابلیت پیام به کاربرانی ارسال میشود که تعیین  میکنید چند روز از ربات استفاده نکرده اند
تعداد روز خود را ارسال نمایید.", $backadmin, 'HTML');
        return;
    }
    step("gettextSystemMessage", $from_id);
    if (($userdata['messagemediatype'] ?? 'text') == "photo") {
        sendmessage($from_id, "📌 تصویر خود را ارسال نمایید.\nمی‌توانید همراه عکس یک کپشن (متن) هم بفرستید.", $backadmin, 'HTML');
    } else {
        sendmessage($from_id, "📌 متن پیام خود را ارسال نمایید.", $backadmin, 'HTML');
    }
}

function broadcast_type_label($type)
{
    $labels = [
        'sendmessage' => 'ارسال همگانی',
        'forwardmessage' => 'فوروارد همگانی',
        'xdaynotmessage' => 'ارسال به کاربران غیرفعال',
        'channelpost' => 'ارسال پست کانال',
        'unpinmessage' => 'لغو پین پیام',
    ];
    return $labels[$type] ?? $type;
}

function ensure_reportsms_topic()
{
    global $setting, $pdo;

    ensure_broadcast_schema();
    $row = select("topicid", "*", "report", "reportsms", "select", ['cache' => false]);
    $topic_id = $row['idreport'] ?? '0';
    if (!empty($setting['Channel_Report']) && (empty($topic_id) || $topic_id === '0')) {
        $create = telegram('createForumTopic', [
            'chat_id' => $setting['Channel_Report'],
            'name' => '📨 گزارش ارسال پیام',
        ]);
        if (!empty($create['ok']) && isset($create['result']['message_thread_id'])) {
            $topic_id = $create['result']['message_thread_id'];
            update("topicid", "idreport", $topic_id, "report", "reportsms");
        }
    }
    return $topic_id;
}

function build_broadcast_report_text($row)
{
    $time = function_exists('jdate') ? jdate('Y/m/d H:i:s', intval($row['created_at'])) : date('Y/m/d H:i:s', intval($row['created_at']));
    $btn = broadcast_btn_label($row['btn_type'] ?? 'none');
    $type = broadcast_type_label($row['type'] ?? '');
    $status_map = [
        'started' => 'در حال انجام',
        'completed' => 'تمام شد',
        'cancelled' => 'لغو شد',
        'published' => 'منتشر شد',
    ];
    $status = $status_map[$row['status'] ?? 'started'] ?? ($row['status'] ?? '');
    $message = trim(html_entity_decode(strip_tags((string) ($row['message_text'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (mb_strlen($message) > 2500) {
        $message = mb_substr($message, 0, 2500) . '…';
    }
    $media = (($row['media_type'] ?? 'text') === 'photo') ? 'عکس' : 'متن';
    $click_line = '';
    if (($row['btn_type'] ?? 'none') !== 'none') {
        $click_line = "\n👆 کلیک دکمه (یونیک): <b>" . intval($row['click_count'] ?? 0) . "</b>";
    }
    $text = "📨 <b>گزارش ارسال پیام</b>\n\n"
        . "🕒 زمان: <code>{$time}</code>\n"
        . "👤 ادمین: <code>{$row['admin_id']}</code>\n"
        . "📋 نوع: {$type}\n"
        . "👥 مخاطب: " . htmlspecialchars((string) ($row['audience_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
        . "📊 تعداد مخاطب: <b>" . intval($row['recipient_count'] ?? 0) . "</b>\n"
        . "🎛 رسانه: {$media}\n"
        . "🔘 دکمه: {$btn}"
        . $click_line
        . "\n📌 وضعیت: {$status}";
    if ($message !== '') {
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text .= "\n\n💬 متن پیام:\n<blockquote>{$safe}</blockquote>";
    }
    return $text;
}

function log_broadcast_to_report(array $data)
{
    global $pdo, $setting;

    ensure_broadcast_schema();
    $admin_id = intval($data['admin_id'] ?? 0);
    $type = (string) ($data['type'] ?? 'sendmessage');
    $message_text = (string) ($data['message_text'] ?? '');
    $media_type = (string) ($data['media_type'] ?? 'text');
    $photo_id = (string) ($data['photo_id'] ?? '');
    $btn_type = (string) ($data['btn_type'] ?? 'none');
    $audience_label = (string) ($data['audience_label'] ?? '');
    $recipient_count = intval($data['recipient_count'] ?? 0);
    $status = (string) ($data['status'] ?? 'started');
    $created_at = time();
    $payload = $data['payload'] ?? null;
    if (is_array($payload)) {
        $payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    $payload = is_string($payload) && $payload !== '' ? $payload : null;

    $stmt = $pdo->prepare("INSERT INTO broadcast_log
        (admin_id, type, message_text, media_type, photo_id, btn_type, audience_label, recipient_count, click_count, report_message_id, status, created_at, payload)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?)");
    try {
        $stmt->execute([
            $admin_id,
            $type,
            $message_text,
            $media_type,
            $photo_id,
            $btn_type,
            $audience_label,
            $recipient_count,
            $status,
            $created_at,
            $payload,
        ]);
    } catch (Throwable $e) {
        error_log('log_broadcast_to_report payload insert: ' . $e->getMessage());
        $payload = null;
        $stmt = $pdo->prepare("INSERT INTO broadcast_log
            (admin_id, type, message_text, media_type, photo_id, btn_type, audience_label, recipient_count, click_count, report_message_id, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)");
        $stmt->execute([
            $admin_id,
            $type,
            $message_text,
            $media_type,
            $photo_id,
            $btn_type,
            $audience_label,
            $recipient_count,
            $status,
            $created_at,
        ]);
    }
    $broadcast_id = intval($pdo->lastInsertId());
    $row = [
        'id' => $broadcast_id,
        'admin_id' => $admin_id,
        'type' => $type,
        'message_text' => $message_text,
        'media_type' => $media_type,
        'photo_id' => $photo_id,
        'btn_type' => $btn_type,
        'audience_label' => $audience_label,
        'recipient_count' => $recipient_count,
        'click_count' => 0,
        'status' => $status,
        'created_at' => $created_at,
        'payload' => $payload,
    ];

    $report_message_id = 0;
    if (!empty($setting['Channel_Report'])) {
        $topic_id = ensure_reportsms_topic();
        $report_text = build_broadcast_report_text($row);
        $send_params = [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $topic_id,
            'text' => $report_text,
            'parse_mode' => 'HTML',
        ];
        $report_keyboard = broadcast_report_keyboard_for_row($row);
        if ($report_keyboard !== null) {
            $send_params['reply_markup'] = $report_keyboard;
        }
        $send = telegram('sendmessage', $send_params);
        if (!empty($send['ok']) && isset($send['result']['message_id'])) {
            $report_message_id = intval($send['result']['message_id']);
            update("broadcast_log", "report_message_id", $report_message_id, "id", $broadcast_id);
        }
        if ($media_type === 'photo' && $photo_id !== '') {
            telegram('sendphoto', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $topic_id,
                'photo' => $photo_id,
                'caption' => "🖼 تصویر پیام همگانی #{$broadcast_id}",
            ]);
        }
    }

    return [
        'id' => $broadcast_id,
        'report_message_id' => $report_message_id,
    ];
}

function refresh_broadcast_report_message($broadcast_id)
{
    global $setting, $pdo;

    $stmt = $pdo->prepare("SELECT * FROM broadcast_log WHERE id = ? LIMIT 1");
    $stmt->execute([intval($broadcast_id)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($setting['Channel_Report']) || intval($row['report_message_id']) <= 0) {
        return false;
    }
    $topic_id = ensure_reportsms_topic();
    $params = [
        'chat_id' => $setting['Channel_Report'],
        'message_id' => intval($row['report_message_id']),
        'text' => build_broadcast_report_text($row),
        'parse_mode' => 'HTML',
    ];
    if (!empty($topic_id) && $topic_id !== '0') {
        $params['message_thread_id'] = $topic_id;
    }
    $report_keyboard = broadcast_report_keyboard_for_row($row);
    if ($report_keyboard !== null) {
        $params['reply_markup'] = $report_keyboard;
    }
    telegram('editMessageText', $params);
    return true;
}

function broadcast_can_resend_type($type): bool
{
    return in_array((string) $type, ['sendmessage', 'forwardmessage', 'xdaynotmessage', 'channelpost'], true);
}

function broadcast_report_resend_keyboard($broadcast_id)
{
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => '🔄 ارسال مجدد', 'callback_data' => 'bresend_' . intval($broadcast_id)],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function broadcast_report_confirm_keyboard($broadcast_id)
{
    $id = intval($broadcast_id);
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => '✅ تایید ارسال مجدد', 'callback_data' => 'bresendok_' . $id],
            ],
            [
                ['text' => '❌ انصراف', 'callback_data' => 'bresendno_' . $id],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function broadcast_report_keyboard_for_row(array $row)
{
    $id = intval($row['id'] ?? 0);
    if ($id <= 0 || !broadcast_can_resend_type($row['type'] ?? '')) {
        return null;
    }
    $payload = $row['payload'] ?? '';
    $decoded = is_array($payload) ? $payload : json_decode((string) $payload, true);
    if (!is_array($decoded) || $decoded === []) {
        return null;
    }
    return broadcast_report_resend_keyboard($id);
}

function broadcast_answer_callback($callback_query_id, $text = '', $show_alert = false)
{
    if (empty($callback_query_id)) {
        return;
    }
    $params = [
        'callback_query_id' => $callback_query_id,
        'cache_time' => 1,
    ];
    if ($text !== '') {
        $params['text'] = $text;
        $params['show_alert'] = $show_alert ? true : false;
    }
    telegram('answerCallbackQuery', $params);
}

function broadcast_callback_chat_id()
{
    global $update, $setting;
    $id = $update['callback_query']['message']['chat']['id'] ?? null;
    if ($id !== null && $id !== '') {
        return $id;
    }
    return $setting['Channel_Report'] ?? 0;
}

function broadcast_queue_busy(): bool
{
    if (is_file(__DIR__ . '/cronbot/gift')) {
        return true;
    }
    $path = __DIR__ . '/cronbot/users.json';
    if (!is_file($path)) {
        return false;
    }
    $userslist = json_decode((string) @file_get_contents($path), true);
    return is_array($userslist) && count($userslist) > 0;
}

function broadcast_decode_payload($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : [];
}

function broadcast_merge_userdata_from_log(array $row): array
{
    $userdata = broadcast_decode_payload($row['payload'] ?? '');
    if (!isset($userdata['typeservice'])) {
        $userdata['typeservice'] = $row['type'] ?? 'sendmessage';
    }
    if (!isset($userdata['typeusermessage']) && ($row['type'] ?? '') === 'channelpost') {
        $userdata['typeusermessage'] = 'channelpost';
    }
    if (!isset($userdata['message'])) {
        $userdata['message'] = $row['message_text'] ?? '';
    }
    if (!isset($userdata['messagemediatype'])) {
        $userdata['messagemediatype'] = $row['media_type'] ?? 'text';
    }
    if (!isset($userdata['photoid'])) {
        $userdata['photoid'] = $row['photo_id'] ?? '';
    }
    if (!isset($userdata['btntypemessage'])) {
        $userdata['btntypemessage'] = $row['btn_type'] ?? 'none';
    }
    return $userdata;
}

function broadcast_query_audience_users(array $userdata, $agent, $typeusermessage): array
{
    global $pdo;
    $agent_filter = ($agent !== 'all' && $agent !== '' && $agent !== null);
    $panel_name = null;
    if ($typeusermessage === 'customer' && !empty($userdata['selectpanel']) && $userdata['selectpanel'] !== 'all') {
        $panel = select("marzban_panel", "*", "code_panel", $userdata['selectpanel'], "select");
        $panel_name = is_array($panel) ? ($panel['name_panel'] ?? null) : null;
    }

    if ($typeusermessage === 'all') {
        if ($agent_filter) {
            return select("user", "id", "agent", $agent, "fetchAll") ?: [];
        }
        return select("user", "id", "User_Status", "Active", "fetchAll") ?: [];
    }

    $sql = "SELECT u.id FROM user u WHERE u.User_Status = 'Active'";
    $params = [];
    if ($agent_filter) {
        $sql .= " AND u.agent = :agent";
        $params[':agent'] = $agent;
    }
    if ($typeusermessage === 'customer') {
        if ($panel_name) {
            $sql .= " AND EXISTS (SELECT 1 FROM invoice i WHERE i.id_user = u.id AND i.Service_location = :panel)";
            $params[':panel'] = $panel_name;
        } else {
            $sql .= " AND EXISTS (SELECT 1 FROM invoice i WHERE i.id_user = u.id)";
        }
    } elseif ($typeusermessage === 'nonecustomer' || $typeusermessage === 'notestnopurchase') {
        $sql .= " AND NOT EXISTS (SELECT 1 FROM invoice i WHERE i.id_user = u.id)";
    } elseif ($typeusermessage === 'testonly') {
        $sql .= " AND EXISTS (SELECT 1 FROM invoice i WHERE i.id_user = u.id AND i.name_product = 'سرویس تست')
                  AND NOT EXISTS (SELECT 1 FROM invoice i WHERE i.id_user = u.id AND i.name_product != 'سرویس تست')";
    } else {
        return [];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function broadcast_query_inactive_users(array $userdata, $agent, $typeusermessage, $timenouser, array $restrict_ids = null): array
{
    global $pdo;
    if (is_array($restrict_ids)) {
        if (count($restrict_ids) === 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($restrict_ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM user WHERE last_message_time < ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$timenouser], $restrict_ids));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $panel_name = null;
    if ($typeusermessage === 'customer' && !empty($userdata['selectpanel']) && $userdata['selectpanel'] !== 'all') {
        $panel = select("marzban_panel", "*", "code_panel", $userdata['selectpanel'], "select");
        $panel_name = is_array($panel) ? ($panel['name_panel'] ?? null) : null;
    }

    $sql = "SELECT u.id FROM user u WHERE u.last_message_time < :time";
    $params = [':time' => $timenouser];
    $agent_filter = ($agent !== 'all' && $agent !== '' && $agent !== null);
    if ($agent_filter) {
        $sql .= " AND u.agent = :agent";
        $params[':agent'] = $agent;
    }
    if ($typeusermessage === 'customer') {
        if ($panel_name) {
            $sql .= " AND EXISTS (SELECT 1 FROM invoice i WHERE i.id_user = u.id AND i.Service_location = :panel)";
            $params[':panel'] = $panel_name;
        } else {
            $sql .= " AND EXISTS (SELECT 1 FROM invoice i WHERE i.id_user = u.id)";
        }
    } elseif ($typeusermessage === 'nonecustomer' || $typeusermessage === 'notestnopurchase') {
        $sql .= " AND NOT EXISTS (SELECT 1 FROM invoice i WHERE i.id_user = u.id)";
    } elseif ($typeusermessage === 'testonly') {
        $sql .= " AND EXISTS (SELECT 1 FROM invoice i WHERE i.id_user = u.id AND i.name_product = 'سرویس تست')
                  AND NOT EXISTS (SELECT 1 FROM invoice i WHERE i.id_user = u.id AND i.name_product != 'سرویس تست')";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function broadcast_collect_recipients(array $userdata): array
{
    $typeservice = $userdata['typeservice'] ?? 'sendmessage';
    $typeusermessage = $userdata['typeusermessage'] ?? 'all';
    $agent = $userdata['agent'] ?? 'all';

    if ($typeservice === 'channelpost' || $typeusermessage === 'channelpost') {
        return ['ok' => true, 'users' => [], 'channelpost' => true];
    }

    $highvolume_users = [];
    if ($typeusermessage === 'highvolume') {
        $highvolume_panel = 'all';
        if (!empty($userdata['selectpanel']) && $userdata['selectpanel'] !== 'all') {
            $panel = select("marzban_panel", "*", "code_panel", $userdata['selectpanel'], "select");
            $highvolume_panel = is_array($panel) ? ($panel['name_panel'] ?? 'all') : 'all';
        }
        @set_time_limit(300);
        $highvolume_users = getUsersHighVolumeUsage(80, $agent, $highvolume_panel);
        if (count($highvolume_users) === 0) {
            return ['ok' => false, 'error' => '❌ کاربری با مصرف بیش از ۸۰٪ حجم سرویس یافت نشد.'];
        }
    }

    if ($typeservice === 'xdaynotmessage') {
        $days = intval($userdata['daynoyuse'] ?? 0);
        $timenouser = time() - ($days * 86400);
        if ($typeusermessage === 'highvolume') {
            $ids = array_column($highvolume_users, 'id');
            $users = broadcast_query_inactive_users($userdata, $agent, $typeusermessage, $timenouser, $ids);
        } else {
            $users = broadcast_query_inactive_users($userdata, $agent, $typeusermessage, $timenouser);
        }
        return ['ok' => true, 'users' => $users];
    }

    if ($typeusermessage === 'highvolume') {
        return ['ok' => true, 'users' => $highvolume_users];
    }

    $users = broadcast_query_audience_users($userdata, $agent, $typeusermessage);
    return ['ok' => true, 'users' => $users];
}

function execute_broadcast_resend($broadcast_id, $admin_id): array
{
    global $pdo, $datatextbot, $usernamebot;

    $stmt = $pdo->prepare("SELECT * FROM broadcast_log WHERE id = ? LIMIT 1");
    $stmt->execute([intval($broadcast_id)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'گزارش پیدا نشد.'];
    }
    if (!broadcast_can_resend_type($row['type'] ?? '')) {
        return ['ok' => false, 'error' => 'این نوع گزارش قابل ارسال مجدد نیست.'];
    }
    if ((string) ($row['payload'] ?? '') === '') {
        return ['ok' => false, 'error' => 'اطلاعات این گزارش برای ارسال مجدد کافی نیست.'];
    }
    $userdata = broadcast_merge_userdata_from_log($row);

    $type = (string) ($row['type'] ?? 'sendmessage');
    if ($type === 'channelpost' || ($userdata['typeusermessage'] ?? '') === 'channelpost') {
        $channel_id = $userdata['channel_id'] ?? '';
        if ($channel_id === '') {
            return ['ok' => false, 'error' => 'کانال این پست مشخص نیست.'];
        }
        $broadcast = log_broadcast_to_report([
            'admin_id' => $admin_id,
            'type' => 'channelpost',
            'message_text' => $userdata['message'] ?? '',
            'media_type' => $userdata['messagemediatype'] ?? 'text',
            'photo_id' => $userdata['photoid'] ?? '',
            'btn_type' => $userdata['btntypemessage'] ?? 'none',
            'audience_label' => broadcast_audience_label($userdata),
            'recipient_count' => 1,
            'status' => 'started',
            'payload' => $userdata,
        ]);
        $btn_type = $userdata['btntypemessage'] ?? 'none';
        $btn_keyboard = null;
        if ($btn_type != 'none') {
            $btn_text = broadcast_resolve_btn_text($btn_type, $userdata['btntextmessage'] ?? '', $datatextbot);
            $start_payload = broadcast_channel_start_payload($btn_type, $broadcast['id']);
            $btn_keyboard = broadcast_inline_keyboard($btn_type, $btn_text, [
                'url' => "https://t.me/{$usernamebot}?start={$start_payload}",
            ]);
        }
        $result = publish_channel_post($channel_id, $userdata, $btn_keyboard);
        if (!isset($result['ok']) || !$result['ok']) {
            update("broadcast_log", "status", "cancelled", "id", intval($broadcast['id']));
            refresh_broadcast_report_message(intval($broadcast['id']));
            $err = $result['description'] ?? 'خطای نامشخص';
            return ['ok' => false, 'error' => 'ارسال پست به کانال ناموفق بود: ' . $err];
        }
        update("broadcast_log", "status", "published", "id", intval($broadcast['id']));
        refresh_broadcast_report_message(intval($broadcast['id']));
        return ['ok' => true, 'mode' => 'channel'];
    }

    if (broadcast_queue_busy()) {
        return ['ok' => false, 'error' => 'سیستم ارسال پیام در حال انجام است. پس از پایان دوباره تلاش کنید.'];
    }

    $collected = broadcast_collect_recipients($userdata);
    if (empty($collected['ok'])) {
        return $collected;
    }
    $users = $collected['users'] ?? [];
    if (!is_array($users) || count($users) === 0) {
        return ['ok' => false, 'error' => 'هیچ مخاطبی برای ارسال یافت نشد.'];
    }

    $cancelmessage = json_encode([
        'inline_keyboard' => [
            [
                ['text' => 'لغو عملیات', 'callback_data' => 'cancel_sendmessage'],
            ],
        ],
    ]);
    $progress = sendmessage($admin_id, "✅ عملیات ارسال مجدد آغاز گردید پس از پایان اطلاع رسانی خواهد شد.", $cancelmessage, 'HTML');
    $progress_mid = intval($progress['result']['message_id'] ?? 0);

    $broadcast = log_broadcast_to_report([
        'admin_id' => $admin_id,
        'type' => $type,
        'message_text' => $userdata['message'] ?? ($row['message_text'] ?? ''),
        'media_type' => $userdata['messagemediatype'] ?? ($row['media_type'] ?? 'text'),
        'photo_id' => $userdata['photoid'] ?? ($row['photo_id'] ?? ''),
        'btn_type' => $userdata['btntypemessage'] ?? ($row['btn_type'] ?? 'none'),
        'audience_label' => broadcast_audience_label($userdata),
        'recipient_count' => count($users),
        'status' => 'started',
        'payload' => $userdata,
    ]);

    $queue_type = $type === 'forwardmessage' ? 'forwardmessage' : ($type === 'xdaynotmessage' ? 'xdaynotmessage' : 'sendmessage');
    $info = [
        'id_admin' => ($queue_type === 'forwardmessage') ? intval($row['admin_id']) : intval($admin_id),
        'progress_admin' => intval($admin_id),
        'type' => $queue_type,
        'id_message' => $progress_mid,
        'message' => $userdata['message'] ?? '',
        'messagemediatype' => $userdata['messagemediatype'] ?? 'text',
        'photoid' => $userdata['photoid'] ?? '',
        'pingmessage' => $userdata['typepinmessage'] ?? 'no',
        'btnmessage' => $userdata['btntypemessage'] ?? 'none',
        'btntextmessage' => $userdata['btntextmessage'] ?? '',
        'broadcast_id' => intval($broadcast['id']),
    ];
    file_put_contents(__DIR__ . '/cronbot/users.json', json_encode($users));
    file_put_contents(__DIR__ . '/cronbot/info', json_encode($info));
    return ['ok' => true, 'mode' => 'queue', 'count' => count($users)];
}

function handle_broadcast_resend_callback($datain, $from_id, $callback_query_id, $message_id, array $admin_ids): bool
{
    $datain = (string) $datain;
    if (!preg_match('/^bresend(ok|no)?_(\d+)$/', $datain, $match)) {
        return false;
    }
    $action = $match[1];
    $broadcast_id = intval($match[2]);
    $is_admin = false;
    foreach ($admin_ids as $aid) {
        if ((string) $aid === (string) $from_id) {
            $is_admin = true;
            break;
        }
    }
    if (!$is_admin) {
        broadcast_answer_callback($callback_query_id, '❌ فقط ادمین‌ها می‌توانند ارسال مجدد کنند.', true);
        return true;
    }

    $chat_id = broadcast_callback_chat_id();
    if ($action === 'no') {
        EditMessageReplyMarkup($chat_id, $message_id, broadcast_report_resend_keyboard($broadcast_id));
        broadcast_answer_callback($callback_query_id, 'ارسال مجدد لغو شد.');
        return true;
    }

    if ($action === '') {
        EditMessageReplyMarkup($chat_id, $message_id, broadcast_report_confirm_keyboard($broadcast_id));
        broadcast_answer_callback($callback_query_id, 'برای ارسال مجدد، تایید کنید.');
        return true;
    }

    broadcast_answer_callback($callback_query_id, 'در حال ارسال مجدد...');
    $result = execute_broadcast_resend($broadcast_id, $from_id);
    EditMessageReplyMarkup($chat_id, $message_id, broadcast_report_resend_keyboard($broadcast_id));
    if (empty($result['ok'])) {
        sendmessage($from_id, "❌ ارسال مجدد ناموفق بود:\n" . ((string) ($result['error'] ?? 'خطای نامشخص')), null, 'HTML');
        return true;
    }
    if (($result['mode'] ?? '') === 'channel') {
        sendmessage($from_id, "✅ پست مجدداً در کانال منتشر شد.", null, 'HTML');
    }
    return true;
}

function track_broadcast_click($broadcast_id, $user_id)
{
    global $pdo;

    ensure_broadcast_schema();
    $broadcast_id = intval($broadcast_id);
    $user_id = intval($user_id);
    if ($broadcast_id <= 0 || $user_id <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO broadcast_click (broadcast_id, user_id, clicked_at) VALUES (?, ?, ?)");
        $stmt->execute([$broadcast_id, $user_id, time()]);
        if ($stmt->rowCount() <= 0) {
            return false;
        }
        $pdo->prepare("UPDATE broadcast_log SET click_count = click_count + 1 WHERE id = ?")->execute([$broadcast_id]);
        refresh_broadcast_report_message($broadcast_id);
        return true;
    } catch (Throwable $e) {
        error_log('track_broadcast_click: ' . $e->getMessage());
        return false;
    }
}

function resolve_broadcast_callback_action($payload)
{
    $payload = (string) $payload;
    $legacy_codes = implode('|', array_map('preg_quote', broadcast_legacy_codes()));
    if ($legacy_codes !== '' && preg_match('/^(' . $legacy_codes . ')__(\d+)$/', $payload, $match)) {
        $legacy = $match[1];
        $btn_type = null;
        foreach (broadcast_attachable_buttons() as $key => $meta) {
            if ($meta['legacy'] === $legacy) {
                $btn_type = $key;
                break;
            }
        }
        if ($btn_type === null) {
            return null;
        }
        $map = broadcast_btn_action_map();
        return [
            'broadcast_id' => intval($match[2]),
            'btn_type' => $btn_type,
            'action' => $map[$btn_type] ?? $btn_type,
            'legacy' => $legacy,
        ];
    }
    if (!preg_match('/^bc_(\d+)_(.+)$/', $payload, $match)) {
        return null;
    }
    $map = broadcast_btn_action_map();
    $btn_type = $match[2];
    $meta = broadcast_attachable_buttons()[$btn_type] ?? null;
    return [
        'broadcast_id' => intval($match[1]),
        'btn_type' => $btn_type,
        'action' => $map[$btn_type] ?? $btn_type,
        'legacy' => $meta['legacy'] ?? null,
    ];
}

function apply_broadcast_start_payload($payload, &$text, &$datain, $from_id)
{
    $resolved = resolve_broadcast_callback_action($payload);
    if ($resolved === null) {
        return false;
    }
    $btn_type = $resolved['btn_type'] ?? null;
    $meta = ($btn_type !== null) ? (broadcast_attachable_buttons()[$btn_type] ?? null) : null;
    if ($meta === null) {
        return false;
    }

    // Remap routing first so a tracking failure never drops the user on /start.
    $text = (string) ($meta['route_text'] ?? '');
    $action = (string) ($meta['route_datain'] ?? $meta['callback']);
    // Channel URL buttons arrive as /start messages, not callback queries.
    // Setting $datain would make handlers try to edit the user's /start message.
    if (function_exists('telegram_is_callback') && telegram_is_callback()) {
        $datain = $action;
    } elseif ($text === '') {
        $datain = $action;
    } else {
        $datain = '';
    }

    try {
        track_broadcast_click($resolved['broadcast_id'], $from_id);
    } catch (Throwable $e) {
        error_log('apply_broadcast_start_payload track: ' . $e->getMessage());
    }
    return true;
}

function broadcast_channel_start_payload($btn_type, $broadcast_id)
{
    $meta = broadcast_attachable_buttons()[$btn_type] ?? null;
    $legacy = $meta['legacy'] ?? null;
    if ($legacy === null || intval($broadcast_id) <= 0) {
        return $legacy ?? 'start';
    }
    return $legacy . '__' . intval($broadcast_id);
}

function ensure_channel_post_setting_column()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'setting' AND COLUMN_NAME = 'Channel_Post'");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE setting ADD Channel_Post VARCHAR(200) NULL DEFAULT ''");
        }
    } catch (Throwable $e) {
        error_log('ensure_channel_post_setting_column: ' . $e->getMessage());
    }
}

function normalize_channel_post_input($channel_input)
{
    $channel_input = trim((string) $channel_input);
    if ($channel_input === '' || $channel_input === '0') {
        return '';
    }
    if (preg_match('/^https?:\/\/t\.me\/([A-Za-z0-9_]+)$/i', $channel_input, $m)) {
        return '@' . $m[1];
    }
    if ($channel_input[0] !== '@' && !preg_match('/^-?\d+$/', $channel_input)) {
        return '@' . ltrim($channel_input, '@');
    }
    return $channel_input;
}

/**
 * Resolve and validate a Telegram channel for bot posting.
 * @return array{ok:bool,channel_id?:string,channel_title?:string,error?:string}
 */
function resolve_channel_post_target($channel_input)
{
    global $APIKEY;
    $channel_input = normalize_channel_post_input($channel_input);
    if ($channel_input === '') {
        return ['ok' => false, 'error' => 'empty'];
    }
    $chat = telegram('getChat', ['chat_id' => $channel_input]);
    if (!isset($chat['ok']) || !$chat['ok']) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    $channel_id = $chat['result']['id'];
    $bot_id = explode(':', (string) $APIKEY)[0];
    $member = telegram('getChatMember', ['chat_id' => $channel_id, 'user_id' => $bot_id]);
    $bot_status = $member['result']['status'] ?? '';
    if (!isset($member['ok']) || !$member['ok'] || !in_array($bot_status, ['administrator', 'creator'], true)) {
        return ['ok' => false, 'error' => 'not_admin'];
    }
    $can_post = true;
    if ($bot_status === 'administrator' && array_key_exists('can_post_messages', $member['result'])) {
        $can_post = (bool) $member['result']['can_post_messages'];
    }
    if (!$can_post) {
        return ['ok' => false, 'error' => 'no_post'];
    }
    return [
        'ok' => true,
        'channel_id' => (string) $channel_id,
        'channel_title' => (string) ($chat['result']['title'] ?? $channel_input),
    ];
}

function channel_post_resolve_error_message($error)
{
    $messages = [
        'empty' => '❌ آیدی یا یوزرنیم کانال را ارسال کنید.',
        'not_found' => '❌ کانال یافت نشد. یوزرنیم یا آیدی را بررسی کنید و مطمئن شوید ربات عضو کانال است.',
        'not_admin' => '❌ ربات ادمین این کانال نیست. ابتدا ربات را ادمین کانال کنید (با دسترسی ارسال پیام).',
        'no_post' => '❌ ربات دسترسی ارسال پیام در کانال را ندارد.',
    ];
    return $messages[$error] ?? '❌ کانال معتبر نیست.';
}

function normalize_outgoing_telegram_entities($entities): array
{
    if (is_string($entities)) {
        $decoded = json_decode($entities, true);
        $entities = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($entities)) {
        return [];
    }
    $out = [];
    foreach ($entities as $entity) {
        if (!is_array($entity)) {
            continue;
        }
        $type = (string) ($entity['type'] ?? '');
        $offset = isset($entity['offset']) ? (int) $entity['offset'] : -1;
        $length = isset($entity['length']) ? (int) $entity['length'] : -1;
        if ($type === '' || $offset < 0 || $length <= 0) {
            continue;
        }
        $item = [
            'type' => $type,
            'offset' => $offset,
            'length' => $length,
        ];
        if ($type === 'custom_emoji') {
            $emojiId = (string) ($entity['custom_emoji_id'] ?? '');
            if ($emojiId === '') {
                continue;
            }
            $item['custom_emoji_id'] = $emojiId;
        } elseif ($type === 'text_link' && !empty($entity['url'])) {
            $item['url'] = (string) $entity['url'];
        } elseif ($type === 'pre' && !empty($entity['language'])) {
            $item['language'] = (string) $entity['language'];
        } elseif ($type === 'text_mention' && !empty($entity['user']) && is_array($entity['user'])) {
            $item['user'] = $entity['user'];
        }
        $out[] = $item;
    }
    return $out;
}

function telegram_entities_have_custom_emoji($entities): bool
{
    foreach (normalize_outgoing_telegram_entities($entities) as $entity) {
        if (($entity['type'] ?? '') === 'custom_emoji') {
            return true;
        }
    }
    return false;
}

function telegram_message_has_custom_emoji($message): bool
{
    if (!is_array($message)) {
        return false;
    }
    return telegram_entities_have_custom_emoji($message['entities'] ?? [])
        || telegram_entities_have_custom_emoji($message['caption_entities'] ?? []);
}

function channel_post_source_text(array $userdata): string
{
    if (isset($userdata['message_raw']) && is_string($userdata['message_raw'])) {
        return $userdata['message_raw'];
    }
    return (string) ($userdata['message'] ?? '');
}

function channel_post_source_entities(array $userdata): array
{
    return normalize_outgoing_telegram_entities($userdata['message_entities'] ?? []);
}

function channel_post_attach_reply_markup($chat_id, $send_result, $btn_keyboard)
{
    if ($btn_keyboard === null || !is_array($send_result) || empty($send_result['ok'])) {
        return $send_result;
    }
    $message_id = intval($send_result['result']['message_id'] ?? 0);
    if ($message_id <= 0) {
        return $send_result;
    }
    $existing = $send_result['result']['reply_markup'] ?? null;
    if (is_array($existing) && !empty($existing['inline_keyboard'])) {
        return $send_result;
    }
    $edit = telegram('editMessageReplyMarkup', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'reply_markup' => $btn_keyboard,
    ]);
    if (is_array($edit) && !empty($edit['ok'])) {
        return $send_result;
    }
    $stripped = broadcast_keyboard_without_premium_fields($btn_keyboard);
    if ($stripped !== $btn_keyboard) {
        telegram('editMessageReplyMarkup', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'reply_markup' => $stripped,
        ]);
    }
    return $send_result;
}

function channel_post_entities_without_custom_emoji(array $entities): array
{
    $out = [];
    foreach ($entities as $entity) {
        if (!is_array($entity)) {
            continue;
        }
        if (($entity['type'] ?? '') === 'custom_emoji') {
            continue;
        }
        $out[] = $entity;
    }
    return $out;
}

/**
 * Compose a native channel post from stored text + Telegram entities (no parse_mode).
 * The inline keyboard is sent with the message so it is not dropped.
 */
function compose_channel_post($chat_id, array $userdata, $btn_keyboard = null)
{
    $is_photo = (($userdata['messagemediatype'] ?? 'text') == 'photo') && !empty($userdata['photoid']);
    $raw = channel_post_source_text($userdata);
    $entities = channel_post_source_entities($userdata);
    $attempts = [$entities];
    if (telegram_entities_have_custom_emoji($entities)) {
        $attempts[] = channel_post_entities_without_custom_emoji($entities);
    }

    $last = ['ok' => false, 'description' => 'message text is empty'];
    foreach ($attempts as $use_entities) {
        $entities_json = $use_entities === [] ? '' : json_encode($use_entities, JSON_UNESCAPED_UNICODE);
        if ($is_photo) {
            $params = [
                'chat_id' => $chat_id,
                'photo' => $userdata['photoid'],
            ];
            if ($raw !== '') {
                $params['caption'] = $raw;
                if ($entities_json !== '') {
                    $params['caption_entities'] = $entities_json;
                }
            }
            $last = telegram_with_optional_premium_markup('sendphoto', $params, $btn_keyboard);
        } else {
            if ($raw === '') {
                return $last;
            }
            $params = [
                'chat_id' => $chat_id,
                'text' => $raw,
            ];
            if ($entities_json !== '') {
                $params['entities'] = $entities_json;
            }
            $last = telegram_with_optional_premium_markup('sendmessage', $params, $btn_keyboard);
        }
        if (is_array($last) && !empty($last['ok'])) {
            return $last;
        }
    }
    return $last;
}

function telegram_with_optional_premium_markup($method, array $params, $btn_keyboard = null)
{
    if ($btn_keyboard !== null) {
        $params['reply_markup'] = $btn_keyboard;
    }
    $result = telegram($method, $params);
    if (is_array($result) && !empty($result['ok'])) {
        return $result;
    }
    if ($btn_keyboard === null) {
        return $result;
    }
    $stripped = broadcast_keyboard_without_premium_fields($btn_keyboard);
    if ($stripped !== $btn_keyboard) {
        $params['reply_markup'] = $stripped;
        $retry = telegram($method, $params);
        if (is_array($retry) && !empty($retry['ok'])) {
            return $retry;
        }
    }
    unset($params['reply_markup']);
    $plain = telegram($method, $params);
    return (is_array($plain) && !empty($plain['ok'])) ? $plain : $result;
}

function compose_channel_post_html($chat_id, array $userdata, $btn_keyboard = null)
{
    $is_photo = (($userdata['messagemediatype'] ?? 'text') == 'photo') && !empty($userdata['photoid']);
    $html = (string) ($userdata['message'] ?? '');
    if ($is_photo) {
        $params = [
            'chat_id' => $chat_id,
            'photo' => $userdata['photoid'],
            'parse_mode' => 'HTML',
        ];
        if ($html !== '') {
            $params['caption'] = $html;
        }
        return telegram_with_optional_premium_markup('sendphoto', $params, $btn_keyboard);
    }
    $params = [
        'chat_id' => $chat_id,
        'text' => $html,
        'parse_mode' => 'HTML',
    ];
    return telegram_with_optional_premium_markup('sendmessage', $params, $btn_keyboard);
}

/**
 * Publish an admin-authored post to a channel as an original post (never forwarded).
 * Forwarding kept Premium emoji but Telegram does not allow inline buttons on forwarded
 * channel posts, so buttons are sent with the native message instead.
 */
function publish_channel_post($channel_id, array $userdata, $btn_keyboard = null)
{
    $source_message_id = intval($userdata['source_message_id'] ?? 0);
    $source_chat_id = $userdata['source_chat_id'] ?? '';
    $has_source = $source_message_id > 0 && $source_chat_id !== '' && $source_chat_id !== null;

    $composed = compose_channel_post($channel_id, $userdata, $btn_keyboard);
    if (is_array($composed) && !empty($composed['ok'])) {
        return channel_post_attach_reply_markup($channel_id, $composed, $btn_keyboard);
    }

    if ($has_source) {
        $copied = telegram_with_optional_premium_markup('copyMessage', [
            'chat_id' => $channel_id,
            'from_chat_id' => $source_chat_id,
            'message_id' => $source_message_id,
        ], $btn_keyboard);
        if (is_array($copied) && !empty($copied['ok'])) {
            return channel_post_attach_reply_markup($channel_id, $copied, $btn_keyboard);
        }
    }

    return compose_channel_post_html($channel_id, $userdata, $btn_keyboard);
}

function send_channel_post_preview($admin_id, array $userdata, $btn_keyboard = null)
{
    $source_message_id = intval($userdata['source_message_id'] ?? 0);
    $source_chat_id = $userdata['source_chat_id'] ?? $admin_id;
    $preview = null;
    if ($source_message_id > 0) {
        $preview = telegram_with_optional_premium_markup('copyMessage', [
            'chat_id' => $admin_id,
            'from_chat_id' => $source_chat_id,
            'message_id' => $source_message_id,
        ], $btn_keyboard);
    }
    if (!is_array($preview) || empty($preview['ok'])) {
        $preview = compose_channel_post($admin_id, $userdata, $btn_keyboard);
    }
    if (!is_array($preview) || empty($preview['ok'])) {
        $preview = compose_channel_post_html($admin_id, $userdata, $btn_keyboard);
    }
    return channel_post_attach_reply_markup($admin_id, $preview, $btn_keyboard);
}

require_once __DIR__ . '/development_mode.php';
mirza_development_mode_boot();