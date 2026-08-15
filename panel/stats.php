<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/users_lib.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_auth();
$pdo = panel_ensure_pdo();

$metricDefs = [
    'sales' => 'فروش روزانه',
    'users' => 'کاربران جدید',
    'status' => 'وضعیت سفارش',
    'payments' => 'روش پرداخت',
];

$saleTypeDefs = [
    'all' => 'خرید و تمدید',
    'buy' => 'فقط خرید',
    'extend' => 'فقط تمدید',
];
$saleType = (string) ($_GET['sale_type'] ?? 'all');
if (!isset($saleTypeDefs[$saleType])) {
    $saleType = 'all';
}

$rawViews = $_GET['views'] ?? ($_GET['view'] ?? 'sales');
if (is_array($rawViews)) {
    $selected = $rawViews;
} else {
    $selected = preg_split('/[,\s]+/', (string) $rawViews, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
$selected = array_values(array_unique(array_filter(
    $selected,
    static fn($k) => isset($metricDefs[$k])
)));
if ($selected === []) {
    $selected = ['sales'];
}
if (count($selected) > 2) {
    $selected = array_slice($selected, 0, 2);
}

$monthParam = preg_replace('/[^0-9\-]/', '', (string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}

$monthStart = strtotime($monthParam . '-01 00:00:00');
if ($monthStart === false) {
    $monthParam = date('Y-m');
    $monthStart = strtotime($monthParam . '-01 00:00:00');
}
$daysInMonth = (int) date('t', $monthStart);
$monthEnd = strtotime($monthParam . '-' . $daysInMonth . ' 23:59:59');
$monthStartDt = date('Y/m/d', $monthStart) . ' 00:00:00';
$monthEndDt = date('Y/m/d', $monthEnd) . ' 23:59:59';

$dayKeys = [];
$dayLabels = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $key = sprintf('%s-%02d', $monthParam, $d);
    $dayKeys[] = $key;
    $dayLabels[] = (string) $d;
}

$userFilters = panel_user_segment_from_request();
$userFiltersActive = panel_user_segment_active($userFilters);
$userPage = max(1, (int) ($_GET['user_page'] ?? 1));
$userPerPage = 25;
$userOffset = ($userPage - 1) * $userPerPage;

$paidInvoiceSql = panel_invoice_paid_sql('Status');
$extendPaidSql = panel_extend_paid_sql();
$extendEpochSql = panel_datetime_epoch_sql('time');
$extendDaySql = "FROM_UNIXTIME(($extendEpochSql), '%Y-%m-%d')";
$extendRangeSql = "($extendEpochSql) BETWEEN ? AND ?";

$summary = [
    'orders' => 0,
    'revenue' => 0,
    'buys' => 0,
    'buy_revenue' => 0,
    'extends' => 0,
    'extend_revenue' => 0,
    'users' => 0,
    'payments' => 0,
    'payment_sum' => 0,
];

$chartPayload = [
    'labels' => $dayLabels,
    'datasets' => [],
    'type' => 'bar',
    'stacked' => false,
];

$tableRows = [];
$hasStacked = false;
$filteredUsers = [];
$filteredUserTotal = 0;
$filteredUserPages = 1;

try {
    $summary['buys'] = db_count(
        $pdo,
        "SELECT COUNT(*) FROM invoice
         WHERE name_product != 'سرویس تست'
           AND $paidInvoiceSql
           AND time_sell BETWEEN ? AND ?",
        [$monthStart, $monthEnd]
    );
    $summary['buy_revenue'] = (int) db_query(
        $pdo,
        "SELECT COALESCE(SUM(CAST(price_product AS DECIMAL(20,0))),0) FROM invoice
         WHERE name_product != 'سرویس تست'
           AND $paidInvoiceSql
           AND time_sell BETWEEN ? AND ?",
        [$monthStart, $monthEnd]
    )->fetchColumn();
    $summary['extends'] = db_count(
        $pdo,
        "SELECT COUNT(*) FROM service_other
         WHERE $extendPaidSql AND $extendRangeSql",
        [$monthStart, $monthEnd]
    );
    $summary['extend_revenue'] = (int) db_query(
        $pdo,
        "SELECT COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) FROM service_other
         WHERE $extendPaidSql AND $extendRangeSql",
        [$monthStart, $monthEnd]
    )->fetchColumn();
    $summary['users'] = db_count(
        $pdo,
        "SELECT COUNT(*) FROM user
         WHERE register REGEXP '^[0-9]+$'
           AND CAST(register AS UNSIGNED) BETWEEN ? AND ?",
        [$monthStart, $monthEnd]
    );
    $summary['payments'] = db_count(
        $pdo,
        "SELECT COUNT(*) FROM Payment_report
         WHERE payment_Status = 'paid'
           AND (
             (time REGEXP '^[0-9]+$' AND CAST(time AS UNSIGNED) BETWEEN ? AND ?)
             OR (time NOT REGEXP '^[0-9]+$' AND STR_TO_DATE(time, '%Y/%m/%d %H:%i:%s') BETWEEN ? AND ?)
           )",
        [$monthStart, $monthEnd, $monthStartDt, $monthEndDt]
    );
    $summary['payment_sum'] = (int) db_query(
        $pdo,
        "SELECT COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) FROM Payment_report
         WHERE payment_Status = 'paid'
           AND (
             (time REGEXP '^[0-9]+$' AND CAST(time AS UNSIGNED) BETWEEN ? AND ?)
             OR (time NOT REGEXP '^[0-9]+$' AND STR_TO_DATE(time, '%Y/%m/%d %H:%i:%s') BETWEEN ? AND ?)
           )",
        [$monthStart, $monthEnd, $monthStartDt, $monthEndDt]
    )->fetchColumn();
} catch (Exception $e) {
}

if ($saleType === 'buy') {
    $summary['orders'] = $summary['buys'];
    $summary['revenue'] = $summary['buy_revenue'];
} elseif ($saleType === 'extend') {
    $summary['orders'] = $summary['extends'];
    $summary['revenue'] = $summary['extend_revenue'];
} else {
    $summary['orders'] = $summary['buys'] + $summary['extends'];
    $summary['revenue'] = $summary['buy_revenue'] + $summary['extend_revenue'];
}

$palette = [
    'rgba(6,182,212,0.85)',
    'rgba(34,197,94,0.85)',
    'rgba(251,183,64,0.85)',
    'rgba(248,113,113,0.85)',
    'rgba(167,139,250,0.85)',
    'rgba(56,189,248,0.85)',
    'rgba(244,114,182,0.85)',
    'rgba(163,230,53,0.85)',
    'rgba(251,146,60,0.85)',
    'rgba(148,163,184,0.85)',
];

$multi = count($selected) > 1;

if (in_array('sales', $selected, true)) {
    $buyByDay = array_fill_keys($dayKeys, ['count' => 0, 'revenue' => 0]);
    $extendByDay = array_fill_keys($dayKeys, ['count' => 0, 'revenue' => 0]);

    if ($saleType !== 'extend') {
        try {
            $rows = db_fetchAll(
                $pdo,
                "SELECT FROM_UNIXTIME(CAST(time_sell AS UNSIGNED), '%Y-%m-%d') AS day,
                        COUNT(*) AS cnt,
                        COALESCE(SUM(CAST(price_product AS DECIMAL(20,0))),0) AS revenue
                 FROM invoice
                 WHERE name_product != 'سرویس تست'
                   AND $paidInvoiceSql
                   AND time_sell BETWEEN ? AND ?
                 GROUP BY day
                 ORDER BY day",
                [$monthStart, $monthEnd]
            );
            foreach ($rows as $row) {
                $day = $row['day'] ?? '';
                if (isset($buyByDay[$day])) {
                    $buyByDay[$day] = [
                        'count' => (int) $row['cnt'],
                        'revenue' => (int) $row['revenue'],
                    ];
                }
            }
        } catch (Exception $e) {
        }
    }

    if ($saleType !== 'buy') {
        try {
            $rows = db_fetchAll(
                $pdo,
                "SELECT $extendDaySql AS day,
                        COUNT(*) AS cnt,
                        COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) AS revenue
                 FROM service_other
                 WHERE $extendPaidSql AND $extendRangeSql
                 GROUP BY day
                 ORDER BY day",
                [$monthStart, $monthEnd]
            );
            foreach ($rows as $row) {
                $day = $row['day'] ?? '';
                if (isset($extendByDay[$day])) {
                    $extendByDay[$day] = [
                        'count' => (int) $row['cnt'],
                        'revenue' => (int) $row['revenue'],
                    ];
                }
            }
        } catch (Exception $e) {
        }
    }

    $buyCounts = [];
    $extendCounts = [];
    $revenues = [];
    foreach ($dayKeys as $key) {
        $buyCounts[] = $buyByDay[$key]['count'];
        $extendCounts[] = $extendByDay[$key]['count'];
        $dayRevenue = $buyByDay[$key]['revenue'] + $extendByDay[$key]['revenue'];
        $revenues[] = $dayRevenue;
        $dayCount = $buyByDay[$key]['count'] + $extendByDay[$key]['count'];
        if ($dayCount > 0 || $dayRevenue > 0) {
            if ($saleType === 'all') {
                $extra = $buyByDay[$key]['count'] . ' خرید · ' . $extendByDay[$key]['count'] . ' تمدید · ' . number_format($dayRevenue) . ' ت';
            } else {
                $extra = number_format($dayRevenue) . ' ت';
            }
            $tableRows[] = [
                'group' => $metricDefs['sales'],
                'label' => $key,
                'count' => $dayCount,
                'extra' => $extra,
            ];
        }
    }

    if ($saleType !== 'extend') {
        $chartPayload['datasets'][] = [
            'label' => $saleType === 'all' ? 'تعداد خرید' : 'تعداد فروش',
            'data' => $buyCounts,
            'backgroundColor' => 'rgba(6,182,212,0.75)',
            'borderRadius' => 6,
            'stack' => 'sales',
            'yAxisID' => 'y',
            'order' => 2,
        ];
    }
    if ($saleType !== 'buy') {
        $chartPayload['datasets'][] = [
            'label' => $saleType === 'all' ? 'تعداد تمدید' : 'تعداد تمدید',
            'data' => $extendCounts,
            'backgroundColor' => 'rgba(251,146,60,0.8)',
            'borderRadius' => 6,
            'stack' => 'sales',
            'yAxisID' => 'y',
            'order' => 2,
        ];
    }
    $chartPayload['datasets'][] = [
        'label' => 'مبلغ (تومان)',
        'data' => $revenues,
        'type' => 'line',
        'borderColor' => 'rgba(34,197,94,0.95)',
        'backgroundColor' => 'rgba(34,197,94,0.15)',
        'tension' => 0.3,
        'fill' => true,
        'yAxisID' => 'y1',
        'order' => 1,
    ];
    if ($saleType === 'all') {
        $hasStacked = true;
    }
}

if (in_array('users', $selected, true)) {
    $byDay = array_fill_keys($dayKeys, 0);
    try {
        $rows = db_fetchAll(
            $pdo,
            "SELECT FROM_UNIXTIME(CAST(register AS UNSIGNED), '%Y-%m-%d') AS day, COUNT(*) AS cnt
             FROM user
             WHERE register REGEXP '^[0-9]+$'
               AND CAST(register AS UNSIGNED) BETWEEN ? AND ?
             GROUP BY day
             ORDER BY day",
            [$monthStart, $monthEnd]
        );
        foreach ($rows as $row) {
            $day = $row['day'] ?? '';
            if (isset($byDay[$day])) {
                $byDay[$day] = (int) $row['cnt'];
            }
        }
    } catch (Exception $e) {
    }

    $counts = [];
    foreach ($dayKeys as $key) {
        $counts[] = $byDay[$key];
        if ($byDay[$key] > 0) {
            $tableRows[] = [
                'group' => $metricDefs['users'],
                'label' => $key,
                'count' => $byDay[$key],
                'extra' => 'کاربر',
            ];
        }
    }

    $chartPayload['datasets'][] = [
        'label' => 'کاربران جدید',
        'data' => $counts,
        'backgroundColor' => 'rgba(167,139,250,0.8)',
        'borderRadius' => 6,
        'stack' => 'users',
        'yAxisID' => 'y',
        'order' => 2,
    ];
}

if (in_array('status', $selected, true)) {
    $statusKeys = [];
    $byStatus = [];
    try {
        $rows = db_fetchAll(
            $pdo,
            "SELECT FROM_UNIXTIME(CAST(time_sell AS UNSIGNED), '%Y-%m-%d') AS day,
                    COALESCE(Status, '') AS st,
                    COUNT(*) AS cnt
             FROM invoice
             WHERE name_product != 'سرویس تست'
               AND time_sell BETWEEN ? AND ?
             GROUP BY day, st
             ORDER BY day",
            [$monthStart, $monthEnd]
        );
        foreach ($rows as $row) {
            $st = (string) ($row['st'] ?? '');
            if ($st === '') {
                $st = '—';
            }
            if (!isset($byStatus[$st])) {
                $byStatus[$st] = array_fill_keys($dayKeys, 0);
                $statusKeys[] = $st;
            }
            $day = $row['day'] ?? '';
            if (isset($byStatus[$st][$day])) {
                $byStatus[$st][$day] = (int) $row['cnt'];
            }
        }
    } catch (Exception $e) {
    }

    foreach ($statusKeys as $i => $st) {
        [$tag, $label] = panel_invoice_status_label($st === '—' ? '' : $st);
        if ($st === '—') {
            $label = 'نامشخص';
        }
        $prefix = $multi ? 'وضعیت · ' : '';
        $data = [];
        $total = 0;
        foreach ($dayKeys as $key) {
            $val = $byStatus[$st][$key] ?? 0;
            $data[] = $val;
            $total += $val;
        }
        $chartPayload['datasets'][] = [
            'label' => $prefix . $label,
            'data' => $data,
            'backgroundColor' => $palette[$i % count($palette)],
            'stack' => 'status',
            'borderRadius' => 3,
            'yAxisID' => 'y',
            'order' => 3,
        ];
        if ($total > 0) {
            $tableRows[] = [
                'group' => $metricDefs['status'],
                'label' => $label,
                'count' => $total,
                'extra' => 'در ماه',
            ];
        }
    }
    $hasStacked = true;
}

if (in_array('payments', $selected, true)) {
    $methods = [];
    $byMethod = [];
    try {
        $rows = db_fetchAll(
            $pdo,
            "SELECT day, method, SUM(cnt) AS cnt, SUM(total) AS total FROM (
                SELECT DATE_FORMAT(FROM_UNIXTIME(CAST(time AS UNSIGNED)), '%Y-%m-%d') AS day,
                       Payment_Method AS method,
                       COUNT(*) AS cnt,
                       COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) AS total
                FROM Payment_report
                WHERE payment_Status = 'paid'
                  AND time REGEXP '^[0-9]+$'
                  AND CAST(time AS UNSIGNED) BETWEEN ? AND ?
                GROUP BY day, method
                UNION ALL
                SELECT DATE_FORMAT(STR_TO_DATE(time, '%Y/%m/%d %H:%i:%s'), '%Y-%m-%d') AS day,
                       Payment_Method AS method,
                       COUNT(*) AS cnt,
                       COALESCE(SUM(CAST(price AS DECIMAL(20,0))),0) AS total
                FROM Payment_report
                WHERE payment_Status = 'paid'
                  AND time NOT REGEXP '^[0-9]+$'
                  AND STR_TO_DATE(time, '%Y/%m/%d %H:%i:%s') BETWEEN ? AND ?
                GROUP BY day, method
             ) t
             WHERE day IS NOT NULL
             GROUP BY day, method
             ORDER BY day",
            [$monthStart, $monthEnd, $monthStartDt, $monthEndDt]
        );
        foreach ($rows as $row) {
            $method = (string) ($row['method'] ?? '');
            if ($method === '') {
                $method = '—';
            }
            if (!isset($byMethod[$method])) {
                $byMethod[$method] = [
                    'days' => array_fill_keys($dayKeys, 0),
                    'sum' => 0,
                    'count' => 0,
                ];
                $methods[] = $method;
            }
            $day = $row['day'] ?? '';
            if (isset($byMethod[$method]['days'][$day])) {
                $byMethod[$method]['days'][$day] = (int) $row['cnt'];
            }
            $byMethod[$method]['sum'] += (int) $row['total'];
            $byMethod[$method]['count'] += (int) $row['cnt'];
        }
    } catch (Exception $e) {
    }

    $payRows = [];
    foreach ($methods as $i => $method) {
        $label = panel_payment_method_label($method === '—' ? '' : $method);
        $prefix = $multi ? 'پرداخت · ' : '';
        $data = [];
        foreach ($dayKeys as $key) {
            $data[] = $byMethod[$method]['days'][$key] ?? 0;
        }
        $chartPayload['datasets'][] = [
            'label' => $prefix . $label,
            'data' => $data,
            'backgroundColor' => $palette[($i + 3) % count($palette)],
            'stack' => 'pay',
            'borderRadius' => 3,
            'yAxisID' => 'y',
            'order' => 3,
        ];
        $payRows[] = [
            'group' => $metricDefs['payments'],
            'label' => $label,
            'count' => $byMethod[$method]['count'],
            'extra' => number_format($byMethod[$method]['sum']) . ' ت',
        ];
    }

    usort($payRows, static fn($a, $b) => $b['count'] <=> $a['count']);
    foreach ($payRows as $row) {
        $tableRows[] = $row;
    }
    $hasStacked = true;
}

if ($userFiltersActive) {
    $seg = panel_user_segment_query_parts($userFilters, true);
    $selectExtra = $seg['select'] ? ', ' . implode(', ', $seg['select']) : '';
    $where = $seg['where'];
    $params = $seg['params'];
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $fromSQL = "FROM user u {$seg['joins']}";
    try {
        $filteredUserTotal = db_count($pdo, "SELECT COUNT(*) $fromSQL $whereSQL", $params);
        $filteredUserPages = max(1, (int) ceil($filteredUserTotal / $userPerPage));
        if ($userPage > $filteredUserPages) {
            $userPage = $filteredUserPages;
            $userOffset = ($userPage - 1) * $userPerPage;
        }
        $filteredUsers = db_fetchAll(
            $pdo,
            "SELECT u.*$selectExtra $fromSQL $whereSQL ORDER BY CAST(u.register AS UNSIGNED) DESC LIMIT $userPerPage OFFSET $userOffset",
            $params
        );
    } catch (Exception $e) {
        $filteredUsers = [];
        $filteredUserTotal = 0;
        error_log('stats.php user filters: ' . $e->getMessage());
    }
}

$chartPayload['stacked'] = $hasStacked;

$monthOptions = [];
for ($i = 0; $i < 18; $i++) {
    $ts = strtotime(date('Y-m-01') . " -$i months");
    $val = date('Y-m', $ts);
    $monthOptions[$val] = date('Y/m', $ts);
}

$viewsQuery = implode(',', $selected);
$chartTitle = implode(' + ', array_map(static fn($k) => $metricDefs[$k], $selected));
if (in_array('sales', $selected, true)) {
    $chartTitle .= ' · ' . $saleTypeDefs[$saleType];
}
$showGroupCol = $multi;
$showAmountCol = in_array('sales', $selected, true) || in_array('payments', $selected, true);

$statsUrl = static function (array $overrides = []) use ($selected, $monthParam, $saleType, $userFilters, $userPage): string {
    $q = [
        'views' => implode(',', $selected),
        'month' => $monthParam,
        'sale_type' => $saleType,
        'test' => $userFilters['test'],
        'min_buys' => $userFilters['min_buys'] !== null ? (string) $userFilters['min_buys'] : '',
        'min_extends' => $userFilters['min_extends'] !== null ? (string) $userFilters['min_extends'] : '',
        'user_page' => $userPage,
    ];
    foreach ($overrides as $key => $value) {
        $q[$key] = $value;
    }
    if (($q['sale_type'] ?? 'all') === 'all') {
        unset($q['sale_type']);
    }
    foreach (['test', 'min_buys', 'min_extends'] as $key) {
        if (($q[$key] ?? '') === '' || $q[$key] === null) {
            unset($q[$key]);
        }
    }
    if ((int) ($q['user_page'] ?? 1) <= 1) {
        unset($q['user_page']);
    }
    return 'stats.php?' . http_build_query($q);
};

$pageTitle = 'آمار';
$pageLede = 'فروش پرداخت‌شده (خرید و تمدید)، کاربران و روش پرداخت. فیلترها را می‌توان با هم ترکیب کرد.';
$activeNav = 'stats';
include __DIR__ . '/inc/layout_head.php';

$toggleMetricUrl = static function (string $key) use ($selected, $metricDefs, $statsUrl): string {
    $next = $selected;
    $idx = array_search($key, $next, true);
    if ($idx !== false) {
        if (count($next) <= 1) {
            return $statsUrl(['views' => $key, 'user_page' => 1]);
        }
        array_splice($next, $idx, 1);
    } else {
        if (count($next) >= 2) {
            array_shift($next);
        }
        $next[] = $key;
    }
    $next = array_values(array_filter($next, static fn($k) => isset($metricDefs[$k])));
    if ($next === []) {
        $next = ['sales'];
    }
    return $statsUrl(['views' => implode(',', $next), 'user_page' => 1]);
};

$saleMeta = number_format($summary['buys']) . ' خرید · ' . number_format($summary['extends']) . ' تمدید';
if ($saleType === 'buy') {
    $saleMeta = 'فقط خریدهای پرداخت‌شده';
} elseif ($saleType === 'extend') {
    $saleMeta = 'فقط تمدیدهای پرداخت‌شده';
}

$userFilterLabels = [];
if ($userFilters['test'] === 'yes') {
    $userFilterLabels[] = 'دارای اکانت تست';
} elseif ($userFilters['test'] === 'no') {
    $userFilterLabels[] = 'بدون اکانت تست';
}
if ($userFilters['min_buys'] !== null) {
    $userFilterLabels[] = 'حداقل ' . number_format($userFilters['min_buys']) . ' خرید';
}
if ($userFilters['min_extends'] !== null) {
    $userFilterLabels[] = 'حداقل ' . number_format($userFilters['min_extends']) . ' تمدید';
}
?>

<style>
  .stats-chart-wrap{position:relative;height:min(420px,58vh);padding:8px 4px 4px}
  .stats-filters{display:flex;gap:4px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:4px;flex-wrap:wrap}
  .stats-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
  .stats-empty{padding:48px 16px;text-align:center;color:var(--mute)}
  .stats-hint{font-size:12px;color:var(--mute);margin-top:6px}
  .stats-user-filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end}
  .stats-user-filters .field input,.stats-user-filters .field select{width:100%}
  @media (max-width:900px){.stats-user-filters{grid-template-columns:1fr 1fr}}
  @media (max-width:560px){.stats-user-filters{grid-template-columns:1fr}}
</style>

<div class="stats fade-up" style="margin-bottom:18px">
  <div class="stat ok">
    <div class="stat-label">فروش ماه</div>
    <div class="stat-num">
      <?= $summary['revenue'] >= 1_000_000
          ? number_format($summary['revenue'] / 1_000_000, 1) . '<small>M ت</small>'
          : number_format($summary['revenue']) . '<small>ت</small>' ?>
    </div>
    <div class="stat-meta"><?= htmlspecialchars($saleMeta) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">تعداد سفارش</div>
    <div class="stat-num"><?= number_format($summary['orders']) ?></div>
    <div class="stat-meta">پرداخت‌شده در ماه</div>
  </div>
  <div class="stat">
    <div class="stat-label">کاربران جدید</div>
    <div class="stat-num"><?= number_format($summary['users']) ?></div>
    <div class="stat-meta">ثبت‌نام در ماه</div>
  </div>
  <div class="stat warn">
    <div class="stat-label">پرداخت موفق</div>
    <div class="stat-num"><?= number_format($summary['payments']) ?></div>
    <div class="stat-meta"><?= number_format($summary['payment_sum']) ?> ت</div>
  </div>
</div>

<div class="stats-toolbar fade-up">
  <div>
    <div class="stats-filters">
      <?php foreach ($metricDefs as $key => $label): ?>
        <?php $active = in_array($key, $selected, true); ?>
        <a href="<?= htmlspecialchars($toggleMetricUrl($key)) ?>"
           class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-ghost' ?>"
           title="<?= $active ? 'حذف از نمودار' : (count($selected) >= 2 ? 'جایگزین معیار اول' : 'افزودن به نمودار') ?>">
          <?= htmlspecialchars($label) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="stats-filters" style="margin-top:8px">
      <?php foreach ($saleTypeDefs as $key => $label): ?>
        <a href="<?= htmlspecialchars($statsUrl(['sale_type' => $key, 'user_page' => 1])) ?>"
           class="btn btn-sm <?= $saleType === $key ? 'btn-primary' : 'btn-ghost' ?>">
          <?= htmlspecialchars($label) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="stats-hint">یک یا دو معیار را انتخاب کنید. فروش شامل همه فاکتورهای پرداخت‌شده است و با فیلتر خرید/تمدید جدا می‌شود.</div>
  </div>
  <form method="GET" class="toolbar-end" style="display:flex;gap:8px;align-items:center">
    <input type="hidden" name="views" value="<?= htmlspecialchars($viewsQuery) ?>">
    <input type="hidden" name="sale_type" value="<?= htmlspecialchars($saleType) ?>">
    <input type="hidden" name="test" value="<?= htmlspecialchars($userFilters['test']) ?>">
    <input type="hidden" name="min_buys" value="<?= $userFilters['min_buys'] !== null ? (int) $userFilters['min_buys'] : '' ?>">
    <input type="hidden" name="min_extends" value="<?= $userFilters['min_extends'] !== null ? (int) $userFilters['min_extends'] : '' ?>">
    <select name="month" class="select" style="width:auto" onchange="this.form.submit()">
      <?php foreach ($monthOptions as $val => $lbl): ?>
        <option value="<?= htmlspecialchars($val) ?>" <?= $val === $monthParam ? 'selected' : '' ?>>
          <?= htmlspecialchars($lbl) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<div class="card fade-up">
  <div class="card-head">
    <div>
      <div class="card-title"><?= htmlspecialchars($chartTitle) ?></div>
      <div class="card-subtitle">ماه <?= htmlspecialchars(date('Y/m', $monthStart)) ?> — به تفکیک روز</div>
    </div>
  </div>
  <?php if (empty($chartPayload['datasets'])): ?>
    <div class="stats-empty">داده‌ای برای این بازه ثبت نشده است.</div>
  <?php else: ?>
    <div class="stats-chart-wrap">
      <canvas id="statsChart"></canvas>
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($tableRows)): ?>
<div class="card fade-up" style="margin-top:16px">
  <div class="card-head">
    <div class="card-title">جزئیات</div>
  </div>
  <div class="tbl-wrap">
    <table class="tbl-md">
      <thead>
        <tr>
          <?php if ($showGroupCol): ?><th>معیار</th><?php endif; ?>
          <th>عنوان</th>
          <th>تعداد</th>
          <th><?= $showAmountCol ? 'مبلغ / توضیح' : 'توضیح' ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tableRows as $row): ?>
          <tr>
            <?php if ($showGroupCol): ?>
              <td><?= htmlspecialchars($row['group']) ?></td>
            <?php endif; ?>
            <td class="cm"><?= htmlspecialchars($row['label']) ?></td>
            <td><?= number_format((int) $row['count']) ?></td>
            <td><?= htmlspecialchars($row['extra']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card fade-up" style="margin-top:16px">
  <div class="card-head">
    <div>
      <div class="card-title">فیلتر کاربران</div>
      <div class="card-subtitle">اکانت تست، تعداد خرید غیرتست و تعداد تمدید را می‌توان با هم ترکیب کرد. شمارش‌ها بر اساس کل سابقه است.</div>
    </div>
  </div>
  <form method="GET" class="card-body">
    <input type="hidden" name="views" value="<?= htmlspecialchars($viewsQuery) ?>">
    <input type="hidden" name="month" value="<?= htmlspecialchars($monthParam) ?>">
    <input type="hidden" name="sale_type" value="<?= htmlspecialchars($saleType) ?>">
    <div class="stats-user-filters">
      <div class="field">
        <label>اکانت تست</label>
        <select name="test" class="select">
          <option value="" <?= $userFilters['test'] === '' ? 'selected' : '' ?>>همه</option>
          <option value="yes" <?= $userFilters['test'] === 'yes' ? 'selected' : '' ?>>دارای اکانت تست</option>
          <option value="no" <?= $userFilters['test'] === 'no' ? 'selected' : '' ?>>بدون اکانت تست</option>
        </select>
      </div>
      <div class="field">
        <label>حداقل خرید غیرتست</label>
        <input class="input" type="number" name="min_buys" min="0" step="1" inputmode="numeric"
               placeholder="مثلاً ۲"
               value="<?= $userFilters['min_buys'] !== null ? (int) $userFilters['min_buys'] : '' ?>">
      </div>
      <div class="field">
        <label>حداقل تمدید</label>
        <input class="input" type="number" name="min_extends" min="0" step="1" inputmode="numeric"
               placeholder="مثلاً ۱"
               value="<?= $userFilters['min_extends'] !== null ? (int) $userFilters['min_extends'] : '' ?>">
      </div>
      <div class="field" style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary" style="flex:1">اعمال فیلتر</button>
        <?php if ($userFiltersActive): ?>
          <a href="<?= htmlspecialchars($statsUrl(['test' => '', 'min_buys' => '', 'min_extends' => '', 'user_page' => 1])) ?>" class="btn btn-ghost">پاک کردن</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if (!$userFiltersActive): ?>
    <div class="stats-empty" style="padding-top:0">برای دیدن فهرست، حداقل یک فیلتر را انتخاب کنید.</div>
  <?php elseif ($filteredUserTotal === 0): ?>
    <div class="empty"><p>کاربری با این ترکیب فیلتر پیدا نشد.</p></div>
  <?php else: ?>
    <div class="toolbar" style="border-top:1px solid var(--bd)">
      <div class="toolbar-title">
        فهرست کاربران
        <small>(<?= number_format($filteredUserTotal) ?>)</small>
      </div>
      <div class="toolbar-end">
        <?php foreach ($userFilterLabels as $lbl): ?>
          <span class="tag tag-info"><?= htmlspecialchars($lbl) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="data-list">
      <?php
      $i = $userOffset + 1;
      foreach ($filteredUsers as $u):
          $agent = $u['agent'] ?? 'f';
          $isBlocked = panel_user_is_blocked($u);
          $displayName = panel_user_display_name($u);
          $uname = $u['username'] ?? '';
          if ($uname === 'none') {
              $uname = '';
          }
          ?>
        <div class="data-row user-data-row" role="link" tabindex="0"
             data-user-url="user.php?id=<?= htmlspecialchars((string) $u['id']) ?>"
             onclick="if (!event.target.closest('a,button')) window.location.href = this.dataset.userUrl"
             onkeydown="if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('a,button')) { event.preventDefault(); window.location.href = this.dataset.userUrl; }">
          <div class="data-row-body">
            <div class="data-row-head">
              <div class="data-row-title">
                <span class="data-row-index"><?= $i++ ?></span>
                <a href="user.php?id=<?= htmlspecialchars((string) $u['id']) ?>"><?= htmlspecialchars($displayName) ?></a>
              </div>
              <?php if ($isBlocked): ?>
                <span class="tag tag-no">مسدود</span>
              <?php else: ?>
                <span class="tag <?= user_role_tag($agent) ?>"><?= user_role_label($agent) ?></span>
              <?php endif; ?>
            </div>
            <div class="data-row-fields">
              <div class="data-field">
                <span class="data-field-label">آیدی</span>
                <span class="data-field-val cm"><?= htmlspecialchars((string) $u['id']) ?></span>
              </div>
              <?php if ($uname): ?>
                <div class="data-field">
                  <span class="data-field-label">یوزرنیم</span>
                  <span class="data-field-val cm" style="color:var(--ac)">@<?= htmlspecialchars($uname) ?></span>
                </div>
              <?php endif; ?>
              <div class="data-field">
                <span class="data-field-label">خرید</span>
                <span class="data-field-val cn"><?= number_format((int) ($u['buy_count'] ?? 0)) ?></span>
              </div>
              <div class="data-field">
                <span class="data-field-label">تمدید</span>
                <span class="data-field-val cn"><?= number_format((int) ($u['extend_count'] ?? 0)) ?></span>
              </div>
              <div class="data-field">
                <span class="data-field-label">اکانت تست</span>
                <span class="data-field-val"><?= ((int) ($u['test_count'] ?? 0)) > 0 ? 'دارد (' . number_format((int) $u['test_count']) . ')' : 'ندارد' ?></span>
              </div>
              <div class="data-field">
                <span class="data-field-label">ثبت‌نام</span>
                <span class="data-field-val"><?= safe_date($u['register'] ?? null) ?></span>
              </div>
            </div>
          </div>
          <div class="data-row-actions">
            <a href="user.php?id=<?= htmlspecialchars((string) $u['id']) ?>" class="btn btn-ghost btn-sm btn-icon" title="مدیریت کاربر"><?= icon('eye', 14) ?></a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="tbl-foot">
      <span><?= number_format($filteredUserTotal) ?> کاربر · صفحه <?= $userPage ?> از <?= $filteredUserPages ?></span>
      <div class="pager">
        <a class="<?= $userPage <= 1 ? 'dis' : '' ?>" href="<?= htmlspecialchars($statsUrl(['user_page' => max(1, $userPage - 1)])) ?>">‹</a>
        <?php for ($p = max(1, $userPage - 2); $p <= min($filteredUserPages, $userPage + 2); $p++): ?>
          <a class="<?= $p === $userPage ? 'cur' : '' ?>" href="<?= htmlspecialchars($statsUrl(['user_page' => $p])) ?>"><?= $p ?></a>
        <?php endfor; ?>
        <a class="<?= $userPage >= $filteredUserPages ? 'dis' : '' ?>" href="<?= htmlspecialchars($statsUrl(['user_page' => min($filteredUserPages, $userPage + 1)])) ?>">›</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($chartPayload['datasets'])): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
(() => {
  const payload = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE) ?>;
  const el = document.getElementById('statsChart');
  if (!el || typeof Chart === 'undefined') return;

  const styles = getComputedStyle(document.documentElement);
  const textColor = styles.getPropertyValue('--mute').trim() || '#94A3B8';
  const gridColor = styles.getPropertyValue('--bd').trim() || '#2A3A55';
  const stacked = !!payload.stacked;
  const hasDual = payload.datasets.some(d => d.yAxisID === 'y1');

  new Chart(el, {
    type: payload.type || 'bar',
    data: {
      labels: payload.labels,
      datasets: payload.datasets,
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          position: 'top',
          align: 'end',
          labels: { color: textColor, boxWidth: 12, font: { family: 'Vazirmatn', size: 11 } },
        },
        tooltip: {
          titleFont: { family: 'Vazirmatn' },
          bodyFont: { family: 'Vazirmatn' },
          callbacks: {
            label(ctx) {
              const v = ctx.parsed.y ?? 0;
              const name = ctx.dataset.label || '';
              if (ctx.dataset.yAxisID === 'y1' || /تومان|مبلغ/.test(name)) {
                return name + ': ' + Number(v).toLocaleString('en-US') + ' ت';
              }
              return name + ': ' + Number(v).toLocaleString('en-US');
            }
          }
        }
      },
      scales: {
        x: {
          stacked,
          ticks: { color: textColor, font: { family: 'Vazirmatn', size: 10 }, maxRotation: 0 },
          grid: { color: 'transparent' },
        },
        y: {
          stacked,
          beginAtZero: true,
          ticks: { color: textColor, font: { family: 'Vazirmatn', size: 10 }, precision: 0 },
          grid: { color: gridColor },
        },
        ...(hasDual ? {
          y1: {
            position: 'right',
            beginAtZero: true,
            ticks: {
              color: textColor,
              font: { family: 'Vazirmatn', size: 10 },
              callback: (v) => Number(v).toLocaleString('en-US'),
            },
            grid: { drawOnChartArea: false },
          }
        } : {}),
      },
    },
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
