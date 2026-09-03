<?php

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const PANEL_FINANCIAL_EXPORT_SHEETS = [
    'Dashboard',
    'Daily Sales',
    'Expenses',
    'Servers',
    'Partner Funding',
    'Partners',
    'Monthly P&L',
    'Daily Rates',
    'Settings',
    'Lists',
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
        $date = panel_financial_export_datetime($timestamp);
        $day = $date->format('Y-m-d');
        $month = $date->format('Y-m-01');
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
                'method' => panel_payment_method_label((string) ($row['Payment_Method'] ?? 'cost')),
                'paid_by' => trim((string) ($row['id_user'] ?? '')),
                'order_id' => trim((string) ($row['id_order'] ?? '')),
            ];
        }
    }

    foreach ($sales as &$sale) {
        $sale['buyers_count'] = count($sale['buyers']);
        unset($sale['buyers']);
        $sale['method_label'] = panel_payment_method_label($sale['method']);
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

function panel_financial_export_date_value(string $date): float
{
    return ExcelDate::PHPToExcel(new DateTime($date . ' 00:00:00', new DateTimeZone('Asia/Tehran')));
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
        ->setTitle('Financial Management Report')
        ->setSubject('Payment-based income and expense report');
    $book->removeSheetByIndex(0);
    foreach (PANEL_FINANCIAL_EXPORT_SHEETS as $title) {
        $book->addSheet(new Worksheet($book, $title));
    }
    $book->setActiveSheetIndex(0);

    $money = panel_financial_export_money_format();

    // Daily Sales
    $sales = $book->getSheetByName('Daily Sales');
    panel_financial_export_base_sheet($sales, 'Daily Sales', 'I');
    panel_financial_export_header($sales, 2, [
        'Date', 'Channel', 'Orders / Buyers', 'Gross Sales (Toman)', 'Commission Rate',
        'Commission (Toman)', 'Net Revenue (Toman)', 'Avg Revenue / Buyer', 'Notes',
    ]);
    $salesRow = 3;
    foreach ($report['sales'] as $item) {
        panel_financial_export_set_row($sales, $salesRow, [
            panel_financial_export_date_value($item['date']),
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
        $sales->setCellValue('A3', 'No paid income in selected period');
    }
    $salesLast = max(3, $salesRow - 1);
    $sales->getStyle("A3:A{$salesLast}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    $sales->getStyle("D3:D{$salesLast}")->getNumberFormat()->setFormatCode($money);
    $sales->getStyle("E3:E{$salesLast}")->getNumberFormat()->setFormatCode('0.0%');
    $sales->getStyle("F3:H{$salesLast}")->getNumberFormat()->setFormatCode($money);
    $sales->setAutoFilter("A2:I{$salesLast}");
    $sales->freezePane('A3');
    panel_financial_export_table_style($sales, "A2:I{$salesLast}");
    foreach (['A' => 13, 'B' => 22, 'C' => 18, 'D' => 20, 'E' => 16, 'F' => 20, 'G' => 20, 'H' => 21, 'I' => 28] as $column => $width) {
        $sales->getColumnDimension($column)->setWidth($width);
    }

    // Expenses
    $expenses = $book->getSheetByName('Expenses');
    panel_financial_export_base_sheet($expenses, 'Expenses', 'K');
    panel_financial_export_header($expenses, 2, [
        'Date', 'Category', 'Description', 'Vendor / Person', 'Original Amount', 'Currency',
        'Daily FX Rate (Auto)', 'Amount (Toman)', 'Payment Method', 'Paid By', 'Notes',
    ]);
    $expenseRow = 3;
    foreach ($report['expenses'] as $item) {
        panel_financial_export_set_row($expenses, $expenseRow, [
            panel_financial_export_date_value($item['date']),
            $item['category'],
            $item['description'],
            $item['vendor'],
            $item['amount'],
            'Toman',
            1,
            "=E{$expenseRow}*G{$expenseRow}",
            $item['method'],
            $item['paid_by'],
            $item['order_id'],
        ]);
        $expenseRow++;
    }
    if ($expenseRow === 3) {
        $expenses->setCellValue('A3', 'No expenses in selected period');
    }
    $expenseLast = max(3, $expenseRow - 1);
    $expenses->getStyle("A3:A{$expenseLast}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    $expenses->getStyle("E3:E{$expenseLast}")->getNumberFormat()->setFormatCode($money);
    $expenses->getStyle("G3:G{$expenseLast}")->getNumberFormat()->setFormatCode('0.0000');
    $expenses->getStyle("H3:H{$expenseLast}")->getNumberFormat()->setFormatCode($money);
    $expenses->setAutoFilter("A2:K{$expenseLast}");
    $expenses->freezePane('A3');
    panel_financial_export_table_style($expenses, "A2:K{$expenseLast}");
    foreach (['A' => 13, 'B' => 20, 'C' => 30, 'D' => 18, 'E' => 18, 'F' => 12, 'G' => 20, 'H' => 18, 'I' => 18, 'J' => 16, 'K' => 22] as $column => $width) {
        $expenses->getColumnDimension($column)->setWidth($width);
    }

    // Monthly P&L
    $pnl = $book->getSheetByName('Monthly P&L');
    panel_financial_export_base_sheet($pnl, 'Monthly Profit & Loss', 'N');
    panel_financial_export_header($pnl, 2, [
        'Month', 'Gross Sales', 'Affiliate Commission', 'Net Revenue', 'Server', 'Marketing',
        'Salary', 'Telegram/Bot', 'Software', 'Refund', 'Office/Admin', 'Other Costs',
        'Total Expenses', 'Net Profit',
    ]);
    $pnlRow = 3;
    foreach ($report['months'] as $month => $item) {
        panel_financial_export_set_row($pnl, $pnlRow, [
            panel_financial_export_date_value($month),
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
        $pnl->setCellValue('A3', panel_financial_export_date_value(date('Y-m-01')));
        panel_financial_export_set_row($pnl, 3, [
            panel_financial_export_date_value(date('Y-m-01')), 0, 0, '=B3-C3',
            0, 0, 0, 0, 0, 0, 0, 0, '=SUM(E3:L3)', '=D3-M3',
        ]);
        $pnlRow = 4;
    }
    $pnlLast = $pnlRow - 1;
    $pnl->getStyle("A3:A{$pnlLast}")->getNumberFormat()->setFormatCode('mmm yyyy');
    $pnl->getStyle("B3:N{$pnlLast}")->getNumberFormat()->setFormatCode($money);
    $pnl->setAutoFilter("A2:N{$pnlLast}");
    $pnl->freezePane('A3');
    panel_financial_export_table_style($pnl, "A2:N{$pnlLast}");
    panel_financial_export_add_profit_condition($pnl, "N3:N{$pnlLast}");
    foreach (range('A', 'N') as $column) {
        $pnl->getColumnDimension($column)->setWidth($column === 'A' ? 15 : 17);
    }

    // Dashboard
    $dashboard = $book->getSheetByName('Dashboard');
    panel_financial_export_base_sheet($dashboard, 'Financial Dashboard', 'H');
    panel_financial_export_header($dashboard, 3, ['KPI', 'Value']);
    panel_financial_export_set_row($dashboard, 4, ['Gross Sales', "=SUM('Daily Sales'!D3:D{$salesLast})"]);
    panel_financial_export_set_row($dashboard, 5, ['Total Expenses', "=SUM(Expenses!H3:H{$expenseLast})"]);
    panel_financial_export_set_row($dashboard, 6, ['Net Profit', '=B4-B5']);
    panel_financial_export_set_row($dashboard, 7, ['Paid Transactions', $report['totals']['payments']]);
    panel_financial_export_set_row($dashboard, 8, ['Unique Buyers', $report['totals']['buyers']]);
    panel_financial_export_set_row($dashboard, 9, ['Skipped Invalid Rows', $report['skipped']]);
    $dashboard->getStyle('B4:B6')->getNumberFormat()->setFormatCode($money);
    panel_financial_export_table_style($dashboard, 'A3:B9');
    panel_financial_export_add_profit_condition($dashboard, 'B6');
    panel_financial_export_header($dashboard, 12, ['Partner Snapshot', 'Value']);
    panel_financial_export_set_row($dashboard, 13, ['Partner data', 'Not connected to Payment_report']);
    panel_financial_export_table_style($dashboard, 'A12:B13');
    $dashboard->getColumnDimension('A')->setWidth(28);
    $dashboard->getColumnDimension('B')->setWidth(24);
    foreach (range('C', 'H') as $column) {
        $dashboard->getColumnDimension($column)->setWidth(15);
    }
    $labels = [new DataSeriesValues('String', "'Monthly P&L'!\$N\$2", null, 1)];
    $categories = [new DataSeriesValues('String', "'Monthly P&L'!\$A\$3:\$A\${$pnlLast}", null, $pnlLast - 2)];
    $values = [new DataSeriesValues('Number', "'Monthly P&L'!\$N\$3:\$N\${$pnlLast}", null, $pnlLast - 2)];
    $series = new DataSeries(DataSeries::TYPE_LINECHART, null, range(0, count($values) - 1), $labels, $categories, $values);
    $chart = new Chart('monthly_performance', new Title('Monthly Performance'), new Legend(Legend::POSITION_BOTTOM), new PlotArea(null, [$series]));
    $chart->setTopLeftPosition('D3');
    $chart->setBottomRightPosition('H18');
    $dashboard->addChart($chart);

    // Empty/reference templates
    $templates = [
        'Servers' => [
            'J',
            ['Server / Service', 'Provider', 'Purpose', 'Billing Cycle', 'Cost', 'Currency', 'Reference FX Rate', 'Cost (Toman)', 'Next Renewal', 'Status'],
        ],
        'Partner Funding' => [
            'K',
            ['Date', 'Partner', 'Transaction Type', 'Description', 'Original Amount', 'Currency', 'Daily FX Rate (Auto)', 'Amount in Toman', 'Cash Impact', 'Partner Balance Impact', 'Notes'],
        ],
        'Partners' => [
            'H',
            ['Partner', 'Profit Share', 'Total Contributions', 'Total Withdrawals / Settlements', 'Net Funding Balance', 'Allocated Profit Share', 'Final Partner Position', 'Notes'],
        ],
    ];
    foreach ($templates as $name => [$lastColumn, $headers]) {
        $sheet = $book->getSheetByName($name);
        panel_financial_export_base_sheet($sheet, $name, $lastColumn);
        panel_financial_export_header($sheet, 2, $headers);
        $sheet->setCellValue('A3', 'Template - no Payment_report data source');
        $sheet->freezePane('A3');
        panel_financial_export_table_style($sheet, "A2:{$lastColumn}3");
        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setWidth(20);
        }
    }

    $rates = $book->getSheetByName('Daily Rates');
    panel_financial_export_base_sheet($rates, 'Daily Rates', 'D');
    $rates->mergeCells('A2:D2');
    $rates->setCellValue('A2', 'All Payment_report amounts are stored in Toman; rates are not used in this export.');
    panel_financial_export_header($rates, 4, ['Date', 'USD → Toman', 'USDT → Toman', 'Notes']);
    $rates->freezePane('A5');
    foreach (['A' => 15, 'B' => 20, 'C' => 20, 'D' => 36] as $column => $width) {
        $rates->getColumnDimension($column)->setWidth($width);
    }

    $settings = $book->getSheetByName('Settings');
    panel_financial_export_base_sheet($settings, 'Settings', 'D');
    panel_financial_export_header($settings, 3, ['Setting', 'Value', 'Source', 'Notes']);
    $from = $filters['from'] ?? '';
    $to = $filters['to'] ?? '';
    $settingsRows = [
        ['Reporting Currency', 'Toman', 'Payment_report.price', 'All amounts are stored in Toman'],
        ['Accounting Basis', 'Cash / Payment based', 'Payment_report', 'Only paid income and recorded costs'],
        ['Wallet Policy', 'Recharge is income', 'Selected policy', 'Later wallet spending is not counted again'],
        ['Affiliate Commission', '0%', 'Not in Payment_report', 'No assumed commission is deducted'],
        ['From', $from !== '' ? $from : 'All time', 'Panel filter', 'Asia/Tehran'],
        ['To', $to !== '' ? $to : 'All time', 'Panel filter', 'Asia/Tehran'],
        ['Generated At', panel_financial_export_datetime(time())->format('Y-m-d H:i:s'), 'System', 'Asia/Tehran'],
    ];
    $row = 4;
    foreach ($settingsRows as $values) {
        panel_financial_export_set_row($settings, $row++, $values);
    }
    panel_financial_export_table_style($settings, 'A3:D10');
    foreach (['A' => 24, 'B' => 26, 'C' => 25, 'D' => 42] as $column => $width) {
        $settings->getColumnDimension($column)->setWidth($width);
    }

    $lists = $book->getSheetByName('Lists');
    panel_financial_export_base_sheet($lists, 'Lists', 'D');
    panel_financial_export_header($lists, 3, ['Sales Channels', 'Expense Categories', 'Currencies', 'Partner Transaction Types']);
    $methodLabels = array_map('panel_payment_method_label', $report['methods']);
    $categoryValues = array_values($report['categories']);
    $currencies = ['Toman', 'IRR', 'USD', 'USDT'];
    $partnerTypes = ['Contribution', 'Withdrawal', 'Settlement'];
    $listRows = max(1, count($methodLabels), count($categoryValues), count($currencies), count($partnerTypes));
    for ($i = 0; $i < $listRows; $i++) {
        panel_financial_export_set_row($lists, $i + 4, [
            $methodLabels[$i] ?? '',
            $categoryValues[$i] ?? '',
            $currencies[$i] ?? '',
            $partnerTypes[$i] ?? '',
        ]);
    }
    panel_financial_export_table_style($lists, 'A3:D' . ($listRows + 3));
    foreach (range('A', 'D') as $column) {
        $lists->getColumnDimension($column)->setWidth(28);
    }

    foreach ($book->getAllSheets() as $sheet) {
        $sheet->getDefaultRowDimension()->setRowHeight(20);
        $sheet->getStyle($sheet->calculateWorksheetDimension())
            ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }
    $book->setActiveSheetIndexByName('Dashboard');
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
