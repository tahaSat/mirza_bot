<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/panels_lib.php';
require_administrator();

$activeNav = 'reports';
$pageTitle = 'گزارشات';
$pageLede = 'مشاهده لاگ‌های سرور برای عیب‌یابی ربات و پنل.';

$logs = panel_report_log_files();
$selected = (string) ($_GET['log'] ?? '');
if ($selected === '' || !isset($logs[$selected])) {
    $keys = array_keys($logs);
    $selected = $keys[0] ?? '';
}

$tail = null;
$selectedInfo = null;
if ($selected !== '' && isset($logs[$selected])) {
    $selectedInfo = $logs[$selected];
    $tail = panel_read_log_tail($selectedInfo['path']);
}

include __DIR__ . '/inc/layout_head.php';
?>

<div class="card fade-up">
  <div class="card-head" style="flex-wrap:wrap;gap:10px">
    <div>
      <div class="card-title">گزارشات سرور</div>
      <div class="card-subtitle">آخرین خطوط لاگ‌های مهم برنامه</div>
    </div>
    <a href="reports.php?log=<?= urlencode($selected) ?>" class="btn btn-ghost btn-sm">بروزرسانی</a>
  </div>
  <div class="card-body">
    <?php if (empty($logs)): ?>
      <div class="notice notice-warn">هیچ فایل لاگی برای نمایش پیدا نشد.</div>
    <?php else: ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <?php foreach ($logs as $key => $info): ?>
          <a href="reports.php?log=<?= urlencode($key) ?>"
            class="btn <?= $selected === $key ? 'btn-primary' : 'btn-ghost' ?> btn-sm">
            <?= htmlspecialchars($info['label']) ?>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if ($selectedInfo && is_array($tail)): ?>
        <div class="kv-list" style="margin-bottom:12px">
          <div class="kv">
            <span class="kv-key">فایل</span>
            <span class="kv-val cm" style="font-size:.75rem"><?= htmlspecialchars($selectedInfo['path']) ?></span>
          </div>
          <div class="kv">
            <span class="kv-key">حجم</span>
            <span class="kv-val"><?= number_format((int) ($tail['size'] ?? 0)) ?> بایت</span>
          </div>
          <div class="kv">
            <span class="kv-key">آخرین بروزرسانی</span>
            <span class="kv-val"><?= htmlspecialchars(($tail['mtime'] ?? null) ? date('Y/m/d H:i:s', (int) $tail['mtime']) : '—') ?></span>
          </div>
        </div>
        <div style="background:var(--sf2);border:1px solid var(--bd);border-radius:10px;padding:12px;max-height:65vh;overflow:auto">
          <pre style="margin:0;white-space:pre-wrap;word-break:break-word;direction:ltr;text-align:left;font-size:.78rem;line-height:1.6"><?= htmlspecialchars(!empty($tail['lines']) ? implode("\n", $tail['lines']) : 'لاگ خالی است.') ?></pre>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
