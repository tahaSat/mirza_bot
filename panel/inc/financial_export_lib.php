<?php

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const PANEL_FINANCIAL_EXPORT_SHEETS = [
    'داشبورد',
    'فروش روزانه',
    'هزینه‌ها',
    'سود و زیان ماهانه',
];

/**
 * Return only cash income and recorded expenses. Wallet spending is not a second
 * cash event; administrative balance adjustments and capital are excluded by
 * bot_payment_paid_income_sql().
 */
function panel_financial_export_fetch_rows(
    PDO $pdo,
    ?array $fromFilter = null,
    ?array $toFilter = null
): array {
    panel_payment_ensure_schema($pdo);
    $incomeSql = function_exists('bot_payment_paid_income_sql')
        ? bot_payment_paid_income_sql()
        : "payment_Status = 'paid'
            AND COALESCE(Payment_Method,'') NOT IN (
                'add balance by admin','low balance by admin','capital_injection','cost'
            )
            AND COALESCE(id_invoice,'') != 'cost'";
    $expenseSql = "(tx_type = 'expense'
        OR payment_Status = 'cost'
        OR Payment_Method = 'cost'
        OR id_invoice = 'cost')";

    $where = ["(($incomeSql) OR $expenseSql)"];
    $params = [];
    panel_payment_append_time_range($where, $params, $fromFilter, $toFilter);
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $orderSql = panel_payment_time_sort_sql();

    return db_fetchAll(
        $pdo,
        "SELECT id, id_user, id_order, time, price, payment_Status, Payment_Method,
                id_invoice, tx_type, expense_category, note
         FROM Payment_report
         $whereSql
         ORDER BY ($orderSql) ASC, id ASC",
        $params
    );
}

function panel_financial_export_timestamp($raw): ?int
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    if (ctype_digit($raw) && strlen($raw) >= 9) {
        return (int) $raw;
    }
    $tz = new DateTimeZone('Asia/Tehran');
    foreach (['Y-m-d H:i:s', 'Y/m/d H:i:s', 'Y-m-d', 'Y/m/d'] as $format) {
        $date = DateTime::createFromFormat('!' . $format, $raw, $tz);
        if ($date instanceof DateTime) {
            return $date->getTimestamp();
        }
    }
    return null;
}

function panel_financial_export_datetime(int $timestamp): DateTime
{
    return (new DateTime('@' . $timestamp))->setTimezone(new DateTimeZone('Asia/Tehran'));
}

function panel_financial_export_jalali(int $timestamp, string $format = 'Y/m/d'): string
{
    return function_exists('jalali_tehran_format')
        ? jalali_tehran_format($timestamp, $format, 'en')
        : panel_financial_export_datetime($timestamp)->format($format);
}

function panel_financial_export_is_income(array $row): bool
{
    if (panel_payment_is_cost($row) || ($row['payment_Status'] ?? '') !== 'paid') {
        return false;
    }
    return !in_array(
        (string) ($row['Payment_Method'] ?? ''),
        ['add balance by admin', 'low balance by admin', 'capital_injection', 'cost'],
        true
    ) && (string) ($row['id_invoice'] ?? '') !== 'cost';
}

function panel_financial_export_expense_bucket(string $slug, string $label = ''): string
{
    $value = mb_strtolower(trim($slug . ' ' . $label), 'UTF-8');
    $rules = [
        'server' => ['server', 'host', 'سرور', 'هاست'],
        'marketing' => ['ads', 'advert', 'marketing', 'تبلیغ', 'بازاریابی'],
        'salary' => ['salary', 'payroll', 'حقوق', 'دستمزد'],
        'telegram' => ['telegram', 'bot', 'تلگرام', 'ربات'],
        'software' => ['software', 'license', 'نرم افزار', 'نرم‌افزار', 'لایسنس'],
        'refund' => ['refund', 'cashback', 'بازپرداخت', 'مرجوع'],
        'office' => ['office', 'admin', 'دفتر', 'اداری'],
    ];
    foreach ($rules as $bucket => $needles) {
        foreach ($needles as $needle) {
            if (mb_strpos($value, $needle, 0, 'UTF-8') !== false) {
                return $bucket;
            }
        }
    }
    return 'other';
}

/**
 * Pure aggregation layer, intentionally usable with fixtures in tests.
 *
 * @return array{
 *   sales:array,expenses:array,months:array,totals:array,methods:array,
 *   categories:array,skipped:int,min_ts:?int,max_ts:?int
 * }
 */
function panel_financial_export_prepare(array $rows, array $categoryLabels = []): array
{
    $sales = [];
    $expenses = [];
    $months = [];
    $methods = [];
    $categories = [];
    $buyers = [];
    $skipped = 0;
    $minTs = null;
    $maxTs = null;

    foreach ($rows as $row) {
        $timestamp = panel_financial_export_timestamp($row['time'] ?? '');
        if ($timestamp === null) {
            $skipped++;
            continue;
        }
        $amount = max(0, (int) preg_replace('/[^\d-]/', '', (string) ($row['price'] ?? '0')));
        if ($amount < 1) {
            continue;
        }
        $day = panel_financial_export_jalali($timestamp, 'Y/m/d');
        $month = panel_financial_export_jalali($timestamp, 'Y/m');
        $minTs = $minTs === null ? $timestamp : min($minTs, $timestamp);
        $maxTs = $maxTs === null ? $timestamp : max($maxTs, $timestamp);

        if (!isset($months[$month])) {
            $months[$month] = [
                'gross' => 0,
                'commission' => 0,
                'server' => 0,
                'marketing' => 0,
                'salary' => 0,
                'telegram' => 0,
                'software' => 0,
                'refund' => 0,
                'office' => 0,
                'other' => 0,
            ];
        }

        if (panel_financial_export_is_income($row)) {
            $method = trim((string) ($row['Payment_Method'] ?? ''));
            $method = $method !== '' ? $method : 'unknown';
            $key = $day . '|' . $method;
            if (!isset($sales[$key])) {
                $sales[$key] = [
                    'date' => $day,
                    'method' => $method,
                    'orders' => 0,
                    'gross' => 0,
                    'buyers' => [],
                ];
            }
            $sales[$key]['orders']++;
            $sales[$key]['gross'] += $amount;
            $userId = trim((string) ($row['id_user'] ?? ''));
            if ($userId !== '' && $userId !== '0') {
                $sales[$key]['buyers'][$userId] = true;
                $buyers[$userId] = true;
            }
            $methods[$method] = true;
            $months[$month]['gross'] += $amount;
            continue;
        }

        if (panel_payment_is_cost($row)) {
            $slug = trim((string) ($row['expense_category'] ?? '')) ?: panel_expense_default_slug();
            $label = $categoryLabels[$slug] ?? $slug;
            $bucket = panel_financial_export_expense_bucket($slug, $label);
            $months[$month][$bucket] += $amount;
            $categories[$slug] = $label;
            $expenses[] = [
                'date' => $day,
                'category' => $label,
                'description' => trim((string) ($row['note'] ?? '')),
                'vendor' => trim((string) ($row['id_user'] ?? '')),
                'amount' => $amount,
                'method' => (string) ($row['Payment_Method'] ?? '') === 'cost'
                    ? 'هزینه ثبت‌شده'
                    : panel_payment_method_label((string) ($row['Payment_Method'] ?? '')),
                'paid_by' => trim((string) ($row['id_user'] ?? '')),
                'order_id' => trim((string) ($row['id_order'] ?? '')),
            ];
        }
    }

    foreach ($sales as &$sale) {
        $sale['buyers_count'] = count($sale['buyers']);
        unset($sale['buyers']);
        $sale['method_label'] = $sale['method'] === 'unknown'
            ? 'نامشخص'
            : panel_payment_method_label($sale['method']);
    }
    unset($sale);
    uasort($sales, static fn(array $a, array $b): int => [$a['date'], $a['method']] <=> [$b['date'], $b['method']]);
    usort($expenses, static fn(array $a, array $b): int => [$a['date'], $a['order_id']] <=> [$b['date'], $b['order_id']]);
    ksort($months);
    ksort($methods);
    asort($categories, SORT_STRING);

    $income = array_sum(array_column($sales, 'gross'));
    $cost = array_sum(array_column($expenses, 'amount'));
    return [
        'sales' => array_values($sales),
        'expenses' => $expenses,
        'months' => $months,
        'totals' => [
            'income' => $income,
            'cost' => $cost,
            'net' => $income - $cost,
            'payments' => array_sum(array_column($sales, 'orders')),
            'buyers' => count($buyers),
        ],
        'methods' => array_keys($methods),
        'categories' => $categories,
        'skipped' => $skipped,
        'min_ts' => $minTs,
        'max_ts' => $maxTs,
    ];
}

function panel_financial_export_set_row(Worksheet $sheet, int $row, array $values): void
{
    foreach (array_values($values) as $index => $value) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . $row, $value);
    }
}

function panel_financial_export_base_sheet(Worksheet $sheet, string $title, string $lastColumn): void
{
    $sheet->setRightToLeft(true);
    $sheet->setShowGridlines(false);
    $sheet->mergeCells("A1:{$lastColumn}1");
    $sheet->setCellValue('A1', $title);
    $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 15],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '17324D']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(30);
}

function panel_financial_export_header(Worksheet $sheet, int $row, array $headers): void
{
    panel_financial_export_set_row($sheet, $row, $headers);
    $last = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '244A6B']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D5DEE7']]],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(28);
}

function panel_financial_export_table_style(Worksheet $sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'D5DEE7']]],
    ]);
}

function panel_financial_export_money_format(): string
{
    return '#,##0;[Red]-#,##0;-';
}

function panel_financial_export_add_profit_condition(Worksheet $sheet, string $range): void
{
    $positive = new Conditional();
    $positive->setConditionType(Conditional::CONDITION_CELLIS)
        ->setOperatorType(Conditional::OPERATOR_GREATERTHANOREQUAL)
        ->addCondition('0');
    $positive->getStyle()->getFont()->getColor()->setRGB('18794E');
    $negative = new Conditional();
    $negative->setConditionType(Conditional::CONDITION_CELLIS)
        ->setOperatorType(Conditional::OPERATOR_LESSTHAN)
        ->addCondition('0');
    $negative->getStyle()->getFont()->getColor()->setRGB('C0392B');
    $sheet->getStyle($range)->setConditionalStyles([$positive, $negative]);
}

function panel_financial_export_build_workbook(array $report, array $filters = []): Spreadsheet
{
    $book = new Spreadsheet();
    $book->getProperties()
        ->setCreator('Mirzabot')
        ->setTitle('گزارش مدیریت مالی')
        ->setSubject('گزارش درآمد و هزینه بر مبنای پرداخت‌ها');
    $book->removeSheetByIndex(0);
    foreach (PANEL_FINANCIAL_EXPORT_SHEETS as $title) {
        $book->addSheet(new Worksheet($book, $title));
    }
    $book->setActiveSheetIndex(0);

    $money = panel_financial_export_money_format();

    // فروش روزانه
    $sales = $book->getSheetByName('فروش روزانه');
    panel_financial_export_base_sheet($sales, 'فروش روزانه', 'I');
    panel_financial_export_header($sales, 2, [
        'تاریخ', 'کانال / روش پرداخت', 'تراکنش / خریدار', 'فروش ناخالص (تومان)', 'نرخ کمیسیون',
        'کمیسیون (تومان)', 'درآمد خالص (تومان)', 'میانگین درآمد هر خریدار', 'یادداشت',
    ]);
    $salesRow = 3;
    foreach ($report['sales'] as $item) {
        panel_financial_export_set_row($sales, $salesRow, [
            $item['date'],
            $item['method_label'],
            $item['orders'] . ' / ' . $item['buyers_count'],
            $item['gross'],
            0,
            "=D{$salesRow}*E{$salesRow}",
            "=D{$salesRow}-F{$salesRow}",
            $item['buyers_count'] > 0 ? "=G{$salesRow}/{$item['buyers_count']}" : 0,
            '',
        ]);
        $salesRow++;
    }
    if ($salesRow === 3) {
        $sales->setCellValue('A3', 'در بازه انتخاب‌شده درآمد قطعی ثبت نشده است.');
    }
    $salesLast = max(3, $salesRow - 1);
    $sales->getStyle("A3:A{$salesLast}")->getNumberFormat()->setFormatCode('@');
    $sales->getStyle("D3:D{$salesLast}")->getNumberFormat()->setFormatCode($money);
    $sales->getStyle("E3:E{$salesLast}")->getNumberFormat()->setFormatCode('0.0%');
    $sales->getStyle("F3:H{$salesLast}")->getNumberFormat()->setFormatCode($money);
    $sales->setAutoFilter("A2:I{$salesLast}");
    $sales->freezePane('A3');
    panel_financial_export_table_style($sales, "A2:I{$salesLast}");
    foreach (['A' => 13, 'B' => 22, 'C' => 18, 'D' => 20, 'E' => 16, 'F' => 20, 'G' => 20, 'H' => 21, 'I' => 28] as $column => $width) {
        $sales->getColumnDimension($column)->setWidth($width);
    }

    // هزینه‌ها
    $expenses = $book->getSheetByName('هزینه‌ها');
    panel_financial_export_base_sheet($expenses, 'هزینه‌ها', 'K');
    panel_financial_export_header($expenses, 2, [
        'تاریخ', 'دسته هزینه', 'شرح', 'فروشنده / شخص', 'مبلغ اصلی', 'ارز',
        'نرخ تبدیل', 'مبلغ (تومان)', 'روش پرداخت', 'پرداخت‌کننده', 'شناسه تراکنش',
    ]);
    $expenseRow = 3;
    foreach ($report['expenses'] as $item) {
        panel_financial_export_set_row($expenses, $expenseRow, [
            $item['date'],
            $item['category'],
            $item['description'],
            $item['vendor'],
            $item['amount'],
            'تومان',
            1,
            "=E{$expenseRow}*G{$expenseRow}",
            $item['method'],
            $item['paid_by'],
            $item['order_id'],
        ]);
        $expenseRow++;
    }
    if ($expenseRow === 3) {
        $expenses->setCellValue('A3', 'در بازه انتخاب‌شده هزینه‌ای ثبت نشده است.');
    }
    $expenseLast = max(3, $expenseRow - 1);
    $expenses->getStyle("A3:A{$expenseLast}")->getNumberFormat()->setFormatCode('@');
    $expenses->getStyle("E3:E{$expenseLast}")->getNumberFormat()->setFormatCode($money);
    $expenses->getStyle("G3:G{$expenseLast}")->getNumberFormat()->setFormatCode('0.0000');
    $expenses->getStyle("H3:H{$expenseLast}")->getNumberFormat()->setFormatCode($money);
    $expenses->setAutoFilter("A2:K{$expenseLast}");
    $expenses->freezePane('A3');
    panel_financial_export_table_style($expenses, "A2:K{$expenseLast}");
    foreach (['A' => 13, 'B' => 20, 'C' => 30, 'D' => 18, 'E' => 18, 'F' => 12, 'G' => 20, 'H' => 18, 'I' => 18, 'J' => 16, 'K' => 22] as $column => $width) {
        $expenses->getColumnDimension($column)->setWidth($width);
    }

    // سود و زیان ماهانه
    $pnl = $book->getSheetByName('سود و زیان ماهانه');
    panel_financial_export_base_sheet($pnl, 'گزارش سود و زیان ماهانه', 'N');
    panel_financial_export_header($pnl, 2, [
        'ماه', 'فروش ناخالص', 'کمیسیون همکاری', 'درآمد خالص', 'سرور', 'تبلیغات',
        'حقوق', 'تلگرام / ربات', 'نرم‌افزار', 'بازپرداخت', 'دفتر / اداری', 'سایر هزینه‌ها',
        'مجموع هزینه‌ها', 'سود خالص',
    ]);
    $pnlRow = 3;
    foreach ($report['months'] as $month => $item) {
        panel_financial_export_set_row($pnl, $pnlRow, [
            $month,
            $item['gross'],
            $item['commission'],
            "=B{$pnlRow}-C{$pnlRow}",
            $item['server'],
            $item['marketing'],
            $item['salary'],
            $item['telegram'],
            $item['software'],
            $item['refund'],
            $item['office'],
            $item['other'],
            "=SUM(E{$pnlRow}:L{$pnlRow})",
            "=D{$pnlRow}-M{$pnlRow}",
        ]);
        $pnlRow++;
    }
    if ($pnlRow === 3) {
        panel_financial_export_set_row($pnl, 3, [
            panel_financial_export_jalali(time(), 'Y/m'), 0, 0, '=B3-C3',
            0, 0, 0, 0, 0, 0, 0, 0, '=SUM(E3:L3)', '=D3-M3',
        ]);
        $pnlRow = 4;
    }
    $pnlLast = $pnlRow - 1;
    $pnl->getStyle("A3:A{$pnlLast}")->getNumberFormat()->setFormatCode('@');
    $pnl->getStyle("B3:N{$pnlLast}")->getNumberFormat()->setFormatCode($money);
    $pnl->setAutoFilter("A2:N{$pnlLast}");
    $pnl->freezePane('A3');
    panel_financial_export_table_style($pnl, "A2:N{$pnlLast}");
    panel_financial_export_add_profit_condition($pnl, "N3:N{$pnlLast}");
    foreach (range('A', 'N') as $column) {
        $pnl->getColumnDimension($column)->setWidth($column === 'A' ? 15 : 17);
    }

    // داشبورد
    $dashboard = $book->getSheetByName('داشبورد');
    panel_financial_export_base_sheet($dashboard, 'داشبورد مدیریت مالی', 'H');
    panel_financial_export_header($dashboard, 3, ['شاخص', 'مقدار']);
    panel_financial_export_set_row($dashboard, 4, ['فروش ناخالص', "=SUM('فروش روزانه'!D3:D{$salesLast})"]);
    panel_financial_export_set_row($dashboard, 5, ['مجموع هزینه‌ها', "=SUM('هزینه‌ها'!H3:H{$expenseLast})"]);
    panel_financial_export_set_row($dashboard, 6, ['سود خالص', '=B4-B5']);
    panel_financial_export_set_row($dashboard, 7, ['تعداد تراکنش‌های موفق', $report['totals']['payments']]);
    panel_financial_export_set_row($dashboard, 8, ['تعداد خریداران یکتا', $report['totals']['buyers']]);
    panel_financial_export_set_row($dashboard, 9, ['ردیف‌های دارای تاریخ نامعتبر', $report['skipped']]);
    $dashboard->getStyle('B4:B6')->getNumberFormat()->setFormatCode($money);
    panel_financial_export_table_style($dashboard, 'A3:B9');
    panel_financial_export_add_profit_condition($dashboard, 'B6');
    $dashboard->getColumnDimension('A')->setWidth(28);
    $dashboard->getColumnDimension('B')->setWidth(24);
    foreach (range('C', 'H') as $column) {
        $dashboard->getColumnDimension($column)->setWidth(15);
    }
    $labels = [new DataSeriesValues('String', "'سود و زیان ماهانه'!\$N\$2", null, 1)];
    $categories = [new DataSeriesValues('String', "'سود و زیان ماهانه'!\$A\$3:\$A\${$pnlLast}", null, $pnlLast - 2)];
    $values = [new DataSeriesValues('Number', "'سود و زیان ماهانه'!\$N\$3:\$N\${$pnlLast}", null, $pnlLast - 2)];
    $series = new DataSeries(DataSeries::TYPE_LINECHART, null, range(0, count($values) - 1), $labels, $categories, $values);
    $chart = new Chart('monthly_performance', new Title('روند سود خالص ماهانه'), new Legend(Legend::POSITION_BOTTOM), new PlotArea(null, [$series]));
    $chart->setTopLeftPosition('D3');
    $chart->setBottomRightPosition('H18');
    $dashboard->addChart($chart);

    foreach ($book->getAllSheets() as $sheet) {
        $sheet->getDefaultRowDimension()->setRowHeight(20);
        $sheet->getStyle($sheet->calculateWorksheetDimension())
            ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }
    $book->setActiveSheetIndexByName('داشبورد');
    return $book;
}

function panel_financial_export_create(
    PDO $pdo,
    ?array $fromFilter = null,
    ?array $toFilter = null,
    array $filterLabels = []
): Spreadsheet {
    $rows = panel_financial_export_fetch_rows($pdo, $fromFilter, $toFilter);
    $report = panel_financial_export_prepare($rows, panel_expense_category_map($pdo));
    return panel_financial_export_build_workbook($report, $filterLabels);
}
