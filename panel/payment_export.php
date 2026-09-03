<?php

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/panels_lib.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_once __DIR__ . '/inc/financial_export_lib.php';

require_administrator();
csrf_check_get();

$pdo = panel_ensure_pdo();
$fromRaw = trim((string) ($_GET['from'] ?? ''));
$toRaw = trim((string) ($_GET['to'] ?? ''));
$fromFilter = $fromRaw !== '' ? panel_payment_parse_filter_datetime($fromRaw, false) : null;
$toFilter = $toRaw !== '' ? panel_payment_parse_filter_datetime($toRaw, true) : null;

if (($fromRaw !== '' && $fromFilter === null) || ($toRaw !== '' && $toFilter === null)) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('بازه تاریخ معتبر نیست.');
}
if ($fromFilter && $toFilter && $fromFilter['ts'] > $toFilter['ts']) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('زمان شروع باید قبل از زمان پایان باشد.');
}

try {
    $workbook = panel_financial_export_create(
        $pdo,
        $fromFilter,
        $toFilter,
        [
            'from' => $fromFilter['input'] ?? '',
            'to' => $toFilter['input'] ?? '',
        ]
    );
    $writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook);
    $writer->setIncludeCharts(true);

    $jalaliTimestamp = function_exists('jalali_tehran_format')
        ? jalali_tehran_format(time(), 'Y-m-d_H-i', 'en')
        : date('Y-m-d_H-i');
    $filename = 'گزارش_مالی_' . $jalaliTimestamp . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header(
        "Content-Disposition: attachment; filename=\"financial_report.xlsx\"; filename*=UTF-8''"
        . rawurlencode($filename)
    );
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    $writer->save('php://output');
    $workbook->disconnectWorksheets();
    exit;
} catch (Throwable $e) {
    error_log('payment_export: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('ساخت فایل اکسل ناموفق بود. جزئیات در گزارش خطا ثبت شد.');
}
