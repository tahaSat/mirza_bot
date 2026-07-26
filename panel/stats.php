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

$paidStatuses = panel_invoice_active_statuses();

$summary = [
    'orders' => 0,
    'revenue' => 0,
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

try {
    $summary['orders'] = db_count(
        $pdo,
        "SELECT COUNT(*) FROM invoice
         WHERE name_product != 'سرویس تست'
           AND time_sell REGEXP '^[0-9]+$'
           AND CAST(time_sell AS UNSIGNED) BETWEEN ? AND ?",
        [$monthStart, $monthEnd]
    );
    $summary['revenue'] = (int) db_query(
        $pdo,
        "SELECT COALESCE(SUM(CAST(price_product AS DECIMAL(20,0))),0) FROM invoice
         WHERE name_product != 'سرویس تست'
           AND Status IN ('" . implode("','", $paidStatuses) . "')
           AND time_sell REGEXP '^[0-9]+$'
           AND CAST(time_sell AS UNSIGNED) BETWEEN ? AND ?",
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
    $byDay = array_fill_keys($dayKeys, ['count' => 0, 'revenue' => 0]);
    try {
        $rows = db_fetchAll(
            $pdo,
            "SELECT FROM_UNIXTIME(CAST(time_sell AS UNSIGNED), '%Y-%m-%d') AS day,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(CAST(price_product AS DECIMAL(20,0))),0) AS revenue
             FROM invoice
             WHERE name_product != 'سرویس تست'
               AND Status IN ('" . implode("','", $paidStatuses) . "')
               AND time_sell REGEXP '^[0-9]+$'
               AND CAST(time_sell AS UNSIGNED) BETWEEN ? AND ?
             GROUP BY day
             ORDER BY day",
            [$monthStart, $monthEnd]
        );
        foreach ($rows as $row) {
            $day = $row['day'] ?? '';
            if (isset($byDay[$day])) {
                $byDay[$day] = [
                    'count' => (int) $row['cnt'],
                    'revenue' => (int) $row['revenue'],
                ];
            }
        }
    } catch (Exception $e) {
    }

    $counts = [];
    $revenues = [];
    foreach ($dayKeys as $key) {
        $counts[] = $byDay[$key]['count'];
        $revenues[] = $byDay[$key]['revenue'];
        if ($byDay[$key]['count'] > 0 || $byDay[$key]['revenue'] > 0) {
            $tableRows[] = [
                'group' => $metricDefs['sales'],
                'label' => $key,
                'count' => $byDay[$key]['count'],
                'extra' => number_format($byDay[$key]['revenue']) . ' ت',
            ];
        }
    }

    $chartPayload['datasets'][] = [
        'label' => 'تعداد فروش',
        'data' => $counts,
        'backgroundColor' => 'rgba(6,182,212,0.75)',
        'borderRadius' => 6,
        'stack' => 'sales',
        'yAxisID' => 'y',
        'order' => 2,
    ];
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
               AND time_sell REGEXP '^[0-9]+$'
               AND CAST(time_sell AS UNSIGNED) BETWEEN ? AND ?
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

$chartPayload['stacked'] = $hasStacked;

$monthOptions = [];
for ($i = 0; $i < 18; $i++) {
    $ts = strtotime(date('Y-m-01') . " -$i months");
    $val = date('Y-m', $ts);
    $monthOptions[$val] = date('Y/m', $ts);
}

$viewsQuery = implode(',', $selected);
$chartTitle = implode(' + ', array_map(static fn($k) => $metricDefs[$k], $selected));
$showGroupCol = $multi;
$showAmountCol = in_array('sales', $selected, true) || in_array('payments', $selected, true);

$pageTitle = 'آمار';
$pageLede = 'نمودار فروش، کاربران، وضعیت سفارش و روش‌های پرداخت به تفکیک روز. تا دو معیار را هم‌زمان انتخاب کنید.';
$activeNav = 'stats';
include __DIR__ . '/inc/layout_head.php';

$toggleMetricUrl = static function (string $key) use ($selected, $monthParam, $metricDefs): string {
    $next = $selected;
    $idx = array_search($key, $next, true);
    if ($idx !== false) {
        if (count($next) <= 1) {
            return 'stats.php?views=' . urlencode($key) . '&month=' . urlencode($monthParam);
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
    return 'stats.php?views=' . urlencode(implode(',', $next)) . '&month=' . urlencode($monthParam);
};
?>

<style>
  .stats-chart-wrap{position:relative;height:min(420px,58vh);padding:8px 4px 4px}
  .stats-filters{display:flex;gap:4px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:4px;flex-wrap:wrap}
  .stats-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
  .stats-empty{padding:48px 16px;text-align:center;color:var(--mute)}
  .stats-hint{font-size:12px;color:var(--mute);margin-top:6px}
</style>

<div class="stats fade-up" style="margin-bottom:18px">
  <div class="stat ok">
    <div class="stat-label">فروش ماه</div>
    <div class="stat-num">
      <?= $summary['revenue'] >= 1_000_000
          ? number_format($summary['revenue'] / 1_000_000, 1) . '<small>M ت</small>'
          : number_format($summary['revenue']) . '<small>ت</small>' ?>
    </div>
    <div class="stat-meta">سفارش‌های فعال</div>
  </div>
  <div class="stat">
    <div class="stat-label">تعداد سفارش</div>
    <div class="stat-num"><?= number_format($summary['orders']) ?></div>
    <div class="stat-meta">کل ثبت‌شده در ماه</div>
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
    <div class="stats-hint">یک یا دو معیار را انتخاب کنید تا روی یک نمودار مقایسه شوند.</div>
  </div>
  <form method="GET" class="toolbar-end" style="display:flex;gap:8px;align-items:center">
    <input type="hidden" name="views" value="<?= htmlspecialchars($viewsQuery) ?>">
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
