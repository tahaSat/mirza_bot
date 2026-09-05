<?php

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

const PANEL_PAYMENT_IMPORT_MAX_BYTES = 5242880;
const PANEL_PAYMENT_IMPORT_MAX_ROWS = 2000;

/**
 * @return array<string,string>
 */
function panel_payment_import_header_aliases(): array
{
    return [
        'شناسه' => 'id',
        'id' => 'id',
        'نوع' => 'type',
        'type' => 'type',
        'تاریخ' => 'date',
        'تاريخ' => 'date',
        'date' => 'date',
        'مقدار' => 'amount',
        'مبلغ' => 'amount',
        'amount' => 'amount',
        'واحد' => 'unit',
        'unit' => 'unit',
        'ارز' => 'unit',
        'توضیحات' => 'note',
        'توضيحات' => 'note',
        'یادداشت' => 'note',
        'note' => 'note',
        'description' => 'note',
        'دسته بندی' => 'category',
        'دسته‌بندی' => 'category',
        'دسته' => 'category',
        'category' => 'category',
    ];
}

function panel_payment_import_norm_text(string $value): string
{
    if (function_exists('tr_num')) {
        $value = (string) tr_num($value, 'en');
    }
    $value = trim($value);
    $value = str_replace(["\u{200c}", "\u{200f}", "\u{200e}", 'ي', 'ك', 'ى', 'ة'], ['', '', '', 'ی', 'ک', 'ی', 'ه'], $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_strtolower($value);
}

function panel_payment_import_cell_string($value): string
{
    if ($value instanceof RichText) {
        $value = $value->getPlainText();
    }
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y/n/j');
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        if (is_float($value) && floor($value) !== $value) {
            $formatted = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
            return $formatted === '' ? '0' : $formatted;
        }
        return (string) $value;
    }
    return trim((string) $value);
}

function panel_payment_import_parse_number($raw): ?float
{
    $value = panel_payment_import_norm_text((string) $raw);
    $value = str_replace(['٬', '،', ',', ' '], '', $value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return (float) $value;
}

/**
 * @return 'expense'|'income'|'investment'|null
 */
function panel_payment_import_parse_kind($raw): ?string
{
    $value = panel_payment_import_norm_text((string) $raw);
    if (in_array($value, ['-1', 'هزینه', 'هزينه', 'cost', 'expense'], true)) {
        return 'expense';
    }
    if (in_array($value, ['1', 'درآمد', 'income'], true)) {
        return 'income';
    }
    if (in_array($value, ['سرمایه', 'سرمایه گذاری', 'سرمایه‌گذاری', 'ورود سرمایه', 'investment', 'capital'], true)) {
        return 'investment';
    }
    return null;
}

function panel_payment_import_is_investment_category(string $raw): bool
{
    $value = panel_payment_import_norm_text($raw);
    return in_array($value, [
        'سرمایه',
        'سرمایه گذاری',
        'سرمایه‌گذاری',
        'ورود سرمایه',
        'investment',
        'capital',
        'capital injection',
        'capital_injection',
    ], true);
}

/**
 * @return 'toman'|'usd'|'unknown'|null
 */
function panel_payment_import_parse_unit($raw): ?string
{
    $value = panel_payment_import_norm_text((string) $raw);
    $value = str_replace(['.', '‌'], '', $value);
    if ($value === '') {
        return null;
    }
    if (in_array($value, ['تومان', 'تومن', 'toman', 'tomans', 'irr', 'rial', 'ریال', 'ريال'], true)) {
        return 'toman';
    }
    if (in_array($value, ['دلار', 'usd', 'dollar', 'dollars', '$', 'us$'], true)) {
        return 'usd';
    }
    return 'unknown';
}

/**
 * @param array<string,string> $map
 */
function panel_payment_import_match_category(string $raw, array $map): ?string
{
    $needle = panel_payment_import_norm_text($raw);
    if ($needle === '') {
        return null;
    }
    foreach ($map as $slug => $label) {
        $slug = (string) $slug;
        if (panel_payment_import_norm_text($slug) === $needle) {
            return $slug;
        }
        if (panel_payment_import_norm_text((string) $label) === $needle) {
            return $slug;
        }
    }
    return null;
}

function panel_payment_import_parse_date($raw): ?int
{
    $value = trim((string) $raw);
    if ($value === '') {
        return null;
    }
    if ($raw instanceof DateTimeInterface) {
        $jy = (int) $raw->format('Y');
        if ($jy >= 1200 && $jy <= 1600) {
            $candidate = $raw->format('Y/n/j H:i');
            if (function_exists('jalali_tehran_parse')) {
                $ts = jalali_tehran_parse($candidate, false);
                if ($ts !== null) {
                    return $ts;
                }
            }
        }
        return $raw->getTimestamp();
    }

    if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000 && class_exists(ExcelDate::class)) {
        try {
            $dt = ExcelDate::excelToDateTimeObject((float) $value, 'Asia/Tehran');
            $jy = (int) $dt->format('Y');
            if ($jy >= 1200 && $jy <= 1600) {
                return panel_payment_import_parse_date($dt);
            }
            return $dt->getTimestamp();
        } catch (Throwable $e) {
            // fall through to string parsing
        }
    }

    $value = panel_payment_import_norm_text($value);
    $value = str_replace(['.', '-'], '/', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    if (function_exists('jalali_tehran_parse')) {
        $ts = jalali_tehran_parse($value, false);
        if ($ts !== null) {
            return $ts;
        }
    }

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}:\d{2}(?::\d{2})?))?$/', $value, $parts)) {
        $month = (int) $parts[1];
        $day = (int) $parts[2];
        $year = (int) $parts[3];
        $time = $parts[4] ?? '';
        if ($year >= 1200 && $year <= 1600 && $month >= 1 && $month <= 12 && function_exists('jalali_tehran_timestamp')) {
            $ymd = sprintf('%04d/%d/%d', $year, $month, $day);
            $ts = jalali_tehran_timestamp($ymd, $time, false);
            if ($ts !== null) {
                return $ts;
            }
        }
    }

    return null;
}

function panel_payment_import_format_jalali(?int $ts): string
{
    if ($ts === null || $ts < 1) {
        return '';
    }
    if (function_exists('jalali_tehran_format')) {
        return jalali_tehran_format($ts, 'Y/m/d H:i', 'en');
    }
    return date('Y/m/d H:i', $ts);
}

/**
 * @param list<mixed> $row
 */
function panel_payment_import_row_empty(array $row): bool
{
    foreach ($row as $cell) {
        if (trim(panel_payment_import_cell_string($cell)) !== '') {
            return false;
        }
    }
    return true;
}

/**
 * @param list<mixed> $row
 * @return array<string,int>
 */
function panel_payment_import_map_headers(array $row): array
{
    $aliases = panel_payment_import_header_aliases();
    $map = [];
    foreach ($row as $idx => $cell) {
        $label = panel_payment_import_norm_text(panel_payment_import_cell_string($cell));
        if ($label === '' || !isset($aliases[$label])) {
            continue;
        }
        $key = $aliases[$label];
        if (!isset($map[$key])) {
            $map[$key] = (int) $idx;
        }
    }
    return $map;
}

/**
 * @param list<mixed> $row
 */
function panel_payment_import_looks_like_header(array $row): bool
{
    $map = panel_payment_import_map_headers($row);
    return isset($map['type'], $map['date'], $map['amount']);
}

/**
 * @return list<list<mixed>>
 */
function panel_payment_import_read_csv(string $path): array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('خواندن فایل CSV ناموفق بود.');
    }
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    $rows = [];
    try {
        while (($row = fgetcsv($fh)) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row;
            if (count($rows) > PANEL_PAYMENT_IMPORT_MAX_ROWS + 5) {
                break;
            }
        }
    } finally {
        fclose($fh);
    }
    return $rows;
}

function panel_payment_import_xlsx_cell($sheet, int $row, int $col): string
{
    $cell = $sheet->getCell([$col, $row]);
    if (!$cell instanceof Cell) {
        return '';
    }
    $value = $cell->getValue();
    if ($value instanceof RichText) {
        return trim($value->getPlainText());
    }
    if (ExcelDate::isDateTime($cell)) {
        $numeric = ExcelDate::excelToDateTimeObject((float) $cell->getCalculatedValue(), 'Asia/Tehran');
        return $numeric->format('Y/n/j');
    }
    return panel_payment_import_cell_string($cell->getCalculatedValue());
}

/**
 * @return list<list<mixed>>
 */
function panel_payment_import_read_xlsx(string $path): array
{
    $reader = IOFactory::createReaderForFile($path);
    if (method_exists($reader, 'setReadDataOnly')) {
        $reader->setReadDataOnly(true);
    }
    $book = $reader->load($path);
    try {
        $sheet = $book->getSheet(0);
        $highestRow = (int) $sheet->getHighestDataRow();
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $limit = min($highestRow, PANEL_PAYMENT_IMPORT_MAX_ROWS + 5);
        $rows = [];
        for ($r = 1; $r <= $limit; $r++) {
            $row = [];
            $empty = true;
            for ($c = 1; $c <= $highestCol; $c++) {
                $val = panel_payment_import_xlsx_cell($sheet, $r, $c);
                $row[] = $val;
                if ($val !== '') {
                    $empty = false;
                }
            }
            if (!$empty) {
                $rows[] = $row;
            }
        }
        return $rows;
    } finally {
        $book->disconnectWorksheets();
        unset($book);
    }
}

/**
 * @return array{ok:bool,msg?:string,ext?:string}
 */
function panel_payment_import_validate_upload(?array $file): array
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'msg' => 'فایل را انتخاب کنید.'];
    }
    $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => 'آپلود فایل ناموفق بود.'];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1) {
        return ['ok' => false, 'msg' => 'فایل خالی است.'];
    }
    if ($size > PANEL_PAYMENT_IMPORT_MAX_BYTES) {
        return ['ok' => false, 'msg' => 'حجم فایل نباید بیشتر از ۵ مگابایت باشد.'];
    }
    $name = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx'], true)) {
        return ['ok' => false, 'msg' => 'فقط فایل CSV یا XLSX پذیرفته می‌شود.'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'msg' => 'فایل آپلود معتبر نیست.'];
    }
    return ['ok' => true, 'ext' => $ext];
}

/**
 * @param list<list<mixed>> $matrix
 * @param array<string,string> $expenseMap
 * @param array<string,string> $incomeMap
 * @return array{ok:bool,msg?:string,rows?:list<array<string,mixed>>,stats?:array<string,int>}
 */
function panel_payment_import_transform(
    array $matrix,
    array $expenseMap,
    array $incomeMap,
    float $usdRate,
    bool $rateProvided
): array {
    $headerIdx = null;
    $columns = [];
    foreach ($matrix as $idx => $row) {
        if (!is_array($row) || panel_payment_import_row_empty($row)) {
            continue;
        }
        if (panel_payment_import_looks_like_header($row)) {
            $headerIdx = $idx;
            $columns = panel_payment_import_map_headers($row);
            break;
        }
    }
    if ($headerIdx === null) {
        return ['ok' => false, 'msg' => 'سطر عنوان فایل پیدا نشد. ستون‌های نوع، تاریخ و مقدار الزامی است.'];
    }
    if (!isset($columns['type'], $columns['date'], $columns['amount'])) {
        return ['ok' => false, 'msg' => 'ستون‌های نوع، تاریخ و مقدار در فایل موجود نیست.'];
    }

    $out = [];
    $dollarCount = 0;
    $unmatched = 0;
    $dataCount = 0;
    for ($i = $headerIdx + 1, $n = count($matrix); $i < $n; $i++) {
        $row = $matrix[$i];
        if (!is_array($row) || panel_payment_import_row_empty($row)) {
            continue;
        }
        $dataCount++;
        if ($dataCount > PANEL_PAYMENT_IMPORT_MAX_ROWS) {
            return ['ok' => false, 'msg' => 'تعداد سطرها بیشتر از ۲۰۰۰ مورد است.'];
        }
        $get = static function (string $key) use ($row, $columns): string {
            if (!isset($columns[$key])) {
                return '';
            }
            $idx = $columns[$key];
            return panel_payment_import_cell_string($row[$idx] ?? '');
        };

        $warnings = [];
        $kind = panel_payment_import_parse_kind($get('type'));
        if ($kind === null) {
            $warnings[] = 'type';
            $kind = 'expense';
        }
        $dateRaw = $get('date');
        $ts = panel_payment_import_parse_date($dateRaw);
        if ($ts === null) {
            $warnings[] = 'date';
        }
        $amountRaw = panel_payment_import_parse_number($get('amount'));
        $unitRaw = $get('unit');
        $unit = isset($columns['unit']) ? panel_payment_import_parse_unit($unitRaw) : 'toman';
        if ($unit === null) {
            $unit = 'toman';
        }
        $amountToman = 0;
        if ($amountRaw === null) {
            $warnings[] = 'amount';
        } elseif ($unit === 'usd') {
            $dollarCount++;
            if (!$rateProvided || $usdRate <= 0) {
                $warnings[] = 'rate';
                $amountToman = 0;
            } else {
                $amountToman = (int) round($amountRaw * $usdRate);
            }
        } elseif ($unit === 'toman') {
            $amountToman = (int) round($amountRaw);
        } else {
            $warnings[] = 'unit';
            $amountToman = 0;
            $warnings[] = 'amount';
        }
        if ($amountToman < 1 && !in_array('amount', $warnings, true) && !in_array('rate', $warnings, true)) {
            $warnings[] = 'amount';
        }

        $note = $get('note');
        $categoryRaw = $get('category');
        if ($kind === 'investment' || panel_payment_import_is_investment_category($categoryRaw)) {
            $kind = 'investment';
            $map = [panel_payment_investment_method() => panel_payment_investment_label()];
            $slug = panel_payment_investment_method();
        } else {
            $map = $kind === 'income' ? $incomeMap : $expenseMap;
            $slug = panel_payment_import_match_category($categoryRaw, $map);
            if ($slug === null) {
                $warnings[] = 'category';
                $unmatched++;
            }
        }

        $out[] = [
            'source_row' => $i + 1,
            'kind' => $kind,
            'time' => panel_payment_import_format_jalali($ts),
            'amount' => $amountToman,
            'note' => $note,
            'category' => $slug ?? '',
            'category_label' => $slug !== null ? (string) ($map[$slug] ?? $slug) : 'یافت نشده',
            'source_amount' => $amountRaw,
            'source_unit' => $unitRaw,
            'source_category' => $categoryRaw,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    if ($out === []) {
        return ['ok' => false, 'msg' => 'سطر داده‌ای در فایل پیدا نشد.'];
    }
    if ($dollarCount > 0 && (!$rateProvided || $usdRate <= 0)) {
        return ['ok' => false, 'msg' => 'فایل شامل مبلغ دلاری است. نرخ تبدیل دلار به تومان را وارد کنید.'];
    }

    return [
        'ok' => true,
        'rows' => $out,
        'stats' => [
            'total' => count($out),
            'dollar' => $dollarCount,
            'unmatched' => $unmatched,
        ],
    ];
}

/**
 * @return array{ok:bool,msg?:string,rows?:list<array<string,mixed>>,stats?:array<string,int>,expense_categories?:array<string,string>,income_categories?:array<string,string>}
 */
function panel_payment_import_parse_file(PDO $pdo, ?array $file, $usdRateRaw): array
{
    $check = panel_payment_import_validate_upload($file);
    if (empty($check['ok'])) {
        return $check;
    }
    $rateProvided = trim((string) $usdRateRaw) !== '';
    $usdRate = $rateProvided ? (panel_payment_import_parse_number($usdRateRaw) ?? 0.0) : 0.0;
    if ($rateProvided && $usdRate <= 0) {
        return ['ok' => false, 'msg' => 'نرخ تبدیل دلار باید عدد مثبت باشد.'];
    }

    $path = (string) $file['tmp_name'];
    $ext = (string) ($check['ext'] ?? '');
    try {
        $matrix = $ext === 'xlsx'
            ? panel_payment_import_read_xlsx($path)
            : panel_payment_import_read_csv($path);
    } catch (Throwable $e) {
        error_log('payment import read: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'خواندن فایل ناموفق بود.'];
    }

    $expenseMap = panel_expense_category_map($pdo);
    $incomeMap = panel_income_category_map($pdo);
    $parsed = panel_payment_import_transform($matrix, $expenseMap, $incomeMap, $usdRate, $rateProvided);
    if (empty($parsed['ok'])) {
        return $parsed;
    }
    $parsed['expense_categories'] = $expenseMap;
    $parsed['income_categories'] = $incomeMap;
    $parsed['investment_label'] = panel_payment_investment_label();
    $parsed['usd_rate'] = $usdRate;
    return $parsed;
}

/**
 * @param array<string,mixed> $row
 * @return array{ok:bool,msg?:string,kind?:string,amount?:int,time?:string,note?:string,category?:string}
 */
function panel_payment_import_validate_row(PDO $pdo, array $row, int $index): array
{
    $line = $index + 1;
    $kind = (string) ($row['kind'] ?? '');
    if (!in_array($kind, ['income', 'expense', 'investment'], true)) {
        return ['ok' => false, 'msg' => 'سطر ' . $line . ': نوع تراکنش نامعتبر است.'];
    }
    $amount = (int) round(panel_payment_import_parse_number((string) ($row['amount'] ?? '')) ?? 0);
    if ($amount < 1) {
        return ['ok' => false, 'msg' => 'سطر ' . $line . ': مبلغ باید عدد مثبت تومان باشد.'];
    }
    $time = trim((string) ($row['time'] ?? ''));
    $ts = panel_payment_import_parse_date($time);
    if ($ts === null) {
        return ['ok' => false, 'msg' => 'سطر ' . $line . ': تاریخ نامعتبر است.'];
    }
    $category = trim((string) ($row['category'] ?? ''));
    if ($kind === 'investment') {
        $category = panel_payment_investment_method();
    } elseif ($category === '') {
        return ['ok' => false, 'msg' => 'سطر ' . $line . ': دسته را انتخاب کنید.'];
    } elseif ($kind === 'expense') {
        $map = panel_expense_category_map($pdo);
        if (!isset($map[$category])) {
            return ['ok' => false, 'msg' => 'سطر ' . $line . ': دسته هزینه نامعتبر است.'];
        }
    } else {
        $map = panel_income_category_map($pdo);
        if (!isset($map[$category])) {
            return ['ok' => false, 'msg' => 'سطر ' . $line . ': دسته درآمد نامعتبر است.'];
        }
    }
    return [
        'ok' => true,
        'kind' => $kind,
        'amount' => $amount,
        'time' => panel_payment_import_format_jalali($ts),
        'note' => trim((string) ($row['note'] ?? '')),
        'category' => $category,
    ];
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array{ok:bool,msg:string,inserted?:int}
 */
function panel_payment_import_commit(PDO $pdo, array $rows): array
{
    if ($rows === []) {
        return ['ok' => false, 'msg' => 'سطری برای ورود وجود ندارد.'];
    }
    if (count($rows) > PANEL_PAYMENT_IMPORT_MAX_ROWS) {
        return ['ok' => false, 'msg' => 'تعداد سطرها بیشتر از ۲۰۰۰ مورد است.'];
    }

    $prepared = [];
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            return ['ok' => false, 'msg' => 'سطر ' . ($i + 1) . ' نامعتبر است.'];
        }
        $valid = panel_payment_import_validate_row($pdo, $row, $i);
        if (empty($valid['ok'])) {
            return $valid;
        }
        $prepared[] = $valid;
    }

    $pdo->beginTransaction();
    try {
        foreach ($prepared as $item) {
            if ($item['kind'] === 'expense') {
                $r = panel_payment_add_cost($pdo, [
                    'amount' => $item['amount'],
                    'time' => $item['time'],
                    'note' => $item['note'],
                    'expense_category' => $item['category'],
                ]);
            } elseif ($item['kind'] === 'investment') {
                $r = panel_payment_add_investment($pdo, [
                    'amount' => $item['amount'],
                    'time' => $item['time'],
                    'note' => $item['note'],
                ]);
            } else {
                $r = panel_payment_add_manual($pdo, [
                    'amount' => $item['amount'],
                    'time' => $item['time'],
                    'note' => $item['note'],
                    'method' => $item['category'],
                    'status' => 'paid',
                    'credit_wallet' => false,
                ]);
            }
            if (empty($r['ok'])) {
                throw new RuntimeException((string) ($r['msg'] ?? 'ثبت سطر ناموفق بود.'));
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('payment import commit: ' . $e->getMessage());
        return ['ok' => false, 'msg' => $e->getMessage() !== '' ? $e->getMessage() : 'ورود داده‌ها ناموفق بود.'];
    }

    $count = count($prepared);
    return [
        'ok' => true,
        'msg' => $count . ' سطر با موفقیت وارد شد.',
        'inserted' => $count,
    ];
}
