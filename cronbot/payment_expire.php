<?php
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require __DIR__ . '/../vendor/autoload.php';

// Hourly cron: expire unpaid payment invoices older than 24 hours.
$result = expireStalePaymentInvoices();
if (($result['payments'] ?? 0) > 0 || ($result['invoices'] ?? 0) > 0) {
    error_log(sprintf(
        'payment_expire: expired %d payment(s), %d unpaid invoice(s)',
        (int) ($result['payments'] ?? 0),
        (int) ($result['invoices'] ?? 0)
    ));
}
