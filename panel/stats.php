<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/users_lib.php';
require_once __DIR__ . '/inc/payments_lib.php';
require_auth();
$pdo = panel_ensure_pdo();

$views = [
    'sales' => 'فروش روزانه',
    'users' => 'کاربران جدید',
    'status' => 'وضعیت سفارش',
    'payments' => 'روش پرداخت',
];

$view = $_GET['view'] ?? 'sales';
if (!isset($views[$view])) {
    $view = 'sales';
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
$statusMap = panel_invoice_status_map();

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
];

$tableRows = [];

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

if ($view === 'sales') {
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
                'label' => $key,
                'count' => $byDay[$key]['count'],
                'extra' => number_format($byDay[$key]['revenue']) . ' ت',
            ];
        }
    }

    $chartPayload['type'] = 'bar';
    $chartPayload['datasets'] = [
        [
            'label' => 'تعداد فروش',
            'data' => $counts,
            'backgroundColor' => 'rgba(6,182,212,0.75)',
            'borderRadius' => 6,
            'yAxisID' => 'y',
            'order' => 2,
        ],
        [
            'label' => 'مبلغ (تومان)',
            'data' => $revenues,
            'type' => 'line',
            'borderColor' => 'rgba(34,197,94,0.95)',
            'backgroundColor' => 'rgba(34,197,94,0.15)',
            'tension' => 0.3,
            'fill' => true,
            'yAxisID' => 'y1',
            'order' => 1,
        ],
    ];
} elseif ($view === 'users') {
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
            $tableRows[] = ['label' => $key, 'count' => $byDay[$key], 'extra' => 'کاربر'];
        }
    }

    $chartPayload['type'] = 'bar';
    $chartPayload['datasets'] = [
        [
            'label' => 'کاربران جدید',
            'data' => $counts,
            'backgroundColor' => 'rgba(167,139,250,0.8)',
            'borderRadius' => 6,
        ],
    ];
} elseif ($view === 'status') {
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

    $datasets = [];
    foreach ($statusKeys as $i => $st) {
        [$tag, $label] = panel_invoice_status_label($st === '—' ? '' : $st);
        if ($st === '—') {
            $label = 'نامشخص';
        }
        $data = [];
        $total = 0;
        foreach ($dayKeys as $key) {
            $val = $byStatus[$st][$key] ?? 0;
            $data[] = $val;
            $total += $val;
        }
        $datasets[] = [
            'label' => $label,
            'data' => $data,
            'backgroundColor' => $palette[$i % count($palette)],
            'stack' => 'status',
            'borderRadius' => 3,
        ];
        if ($total > 0) {
            $tableRows[] = ['label' => $label, 'count' => $total, 'extra' => 'در ماه'];
        }
    }

    $chartPayload['type'] = 'bar';
    $chartPayload['stacked'] = true;
    $chartPayload['datasets'] = $datasets;
} else { // payments
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

    $datasets = [];
    foreach ($methods as $i => $method) {
        $label = panel_payment_method_label($method === '—' ? '' : $method);
        $data = [];
        foreach ($dayKeys as $key) {
            $data[] = $byMethod[$method]['days'][$key] ?? 0;
        }
        $datasets[] = [
            'label' => $label,
            'data' => $data,
            'backgroundColor' => $palette[$i % count($palette)],
            'stack' => 'pay',
            'borderRadius' => 3,
        ];
        $tableRows[] = [
            'label' => $label,
            'count' => $byMethod[$method]['count'],
            'extra' => number_format($byMethod[$method]['sum']) . ' ت',
        ];
    }

    usort($tableRows, static fn($a, $b) => $b['count'] <=> $a['count']);

    $chartPayload['type'] = 'bar';
    $chartPayload['stacked'] = true;
    $chartPayload['datasets'] = $datasets;
}

$monthOptions = [];
for ($i = 0; $i < 18; $i++) {
    $ts = strtotime(date('Y-m-01') . " -$i months");
    $val = date('Y-m', $ts);
    $monthOptions[$val] = date('Y/m', $ts);
}

$pageTitle = 'آمار';
$pageLede = 'نمودار فروش، کاربران، وضعیت سفارش و روش‌های پرداخت به تفکیک روز.';
$activeNav = 'stats';
include __DIR__ . '/inc/layout_head.php';
?>

<style>
  .stats-chart-wrap{position:relative;height:min(420px,58vh);padding:8px 4px 4px}
  .stats-filters{display:flex;gap:4px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:4px;flex-wrap:wrap}
  .stats-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
  .stats-empty{padding:48px 16px;text-align:center;color:var(--mute)}
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
  <div class="stats-filters">
    <?php foreach ($views as $key => $label): ?>
      <a href="stats.php?view=<?= urlencode($key) ?>&month=<?= urlencode($monthParam) ?>"
         class="btn btn-sm <?= $view === $key ? 'btn-primary' : 'btn-ghost' ?>">
        <?= htmlspecialchars($label) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <form method="GET" class="toolbar-end" style="display:flex;gap:8px;align-items:center">
    <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
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
      <div class="card-title"><?= htmlspecialchars($views[$view]) ?></div>
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
          <th><?= $view === 'sales' || $view === 'users' ? 'روز' : 'عنوان' ?></th>
          <th>تعداد</th>
          <th><?= $view === 'sales' || $view === 'payments' ? 'مبلغ' : 'توضیح' ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tableRows as $row): ?>
          <tr>
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
