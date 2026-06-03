<?php
/**
 * Process a single Telegram update outside Apache (async polling worker).
 * Usage: php cli_update.php /path/to/update.json
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/polling_log.php';

ini_set('error_log', __DIR__ . '/logs/php_errors.log');
mirza_polling_ensure_log_dir(ini_get('error_log') ?: __DIR__ . '/logs/php_errors.log');

$startedAt = microtime(true);
$workerLog = mirza_polling_worker_log_path();

if ($argc < 2 || !is_readable($argv[1])) {
  fwrite(STDERR, "Usage: php cli_update.php <update.json>\n");
  mirza_polling_log('worker_bad_args', ['argc' => $argc], $workerLog);
  exit(1);
}

$payload = file_get_contents($argv[1]);
@unlink($argv[1]);

$update = json_decode($payload, true);
if (!is_array($update)) {
  fwrite(STDERR, "Invalid update JSON\n");
  mirza_polling_log('worker_invalid_json', [], $workerLog);
  exit(1);
}

$summary = mirza_update_summary($update);
$updateId = $update['update_id'] ?? null;

mirza_polling_log('worker_start', [
  'summary' => $summary,
  'update_id' => $updateId,
], $workerLog);

register_shutdown_function(static function () use ($startedAt, $summary, $updateId, $workerLog): void {
  $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
  $lastError = error_get_last();
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

  if (is_array($lastError) && in_array($lastError['type'], $fatalTypes, true)) {
    mirza_polling_log('worker_fatal', [
      'summary' => $summary,
      'update_id' => $updateId,
      'duration_ms' => $durationMs,
      'error' => $lastError['message'] ?? '',
      'file' => $lastError['file'] ?? '',
      'line' => $lastError['line'] ?? 0,
    ], $workerLog);
    return;
  }

  mirza_polling_log('worker_done', [
    'summary' => $summary,
    'update_id' => $updateId,
    'duration_ms' => $durationMs,
    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
  ], $workerLog);
});

$GLOBALS['_mirza_telegram_update'] = $update;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Apache uses the project root as cwd; CLI workers must match for relative paths.
chdir(__DIR__);

try {
  require __DIR__ . '/index.php';
} catch (Throwable $e) {
  mirza_polling_log('worker_exception', [
    'summary' => $summary,
    'update_id' => $updateId,
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    'exception' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
  ], $workerLog);
  throw $e;
}
