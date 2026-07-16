<?php
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
$request_exec_timeout = null;
$dbhost = 'localhost';
$dbname = 'mirza_pr';
$usernamedb = 'mirza_user';
$passworddb = 'f1f712f8a0e2ca7d498a65c99216405b';
$connect = mysqli_connect($dbhost, $usernamedb, $passworddb, $dbname);
if ($connect->connect_error) { die("error" . $connect->connect_error); }
mysqli_set_charset($connect, "utf8mb4");
$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
$dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
try { $pdo = new PDO($dsn, $usernamedb, $passworddb, $options); } catch (\PDOException $e) { error_log("Database connection failed: " . $e->getMessage()); }
$APIKEY = '8913088647:AAFT3js74c3IKMJr93DDIn11X_FbiWXNHhQ';
$adminnumber = '289943892';
$domainhosts = 'bot.theownlypwcha.top';
$usernamebot = 'pichanet_bot';

// Telegram API only (botapi.php / polling.php). Panel and payment URLs must NOT use this proxy.
$telegram_proxy = '';
$telegram_proxy_type = 'socks5'; // xray inbound on 10808 is SOCKS; use 'http' only if you run an HTTP proxy th
$telegram_proxies = [];

    // Add fallback listeners here (example):
    // ['name' => 'fallback-1', 'proxy' => '127.0.0.1:51350', 'type' => 'socks5'],
    // ['name' => 'fallback-2', 'proxy' => '127.0.0.1:51351', 'type' => 'socks5'],
$telegram_proxy_retry_once = true; // Retry one time with next proxy on transport errors.
$telegram_proxy_failover_cooldown_sec = 3; // Minimum seconds between proxy rotations.
$telegram_proxy_healthcheck_timeout_sec = 6; // Timeout used by optional proxy health checks.
$telegram_proxy_prefer_primary_interval_sec = 0; // 0 disables periodic auto-return to primary.
$telegram_proxy_state_file = __DIR__ . '/storage/cache/telegram_proxy_state.json';
$telegram_polling_mode = false;
$telegram_polling_async = true; // process each update in a separate PHP worker (users don't block each other)
$telegram_local_bot_url = 'http://127.0.0.1/index.php'; // used only when $telegram_polling_async = false

$telegram_polling_debug = true;
$telegram_polling_log_file = __DIR__ . '/logs/polling.log';
$telegram_polling_worker_log_file = __DIR__ . '/logs/polling.worker.log';
// Log panel HTTP calls slower than this (ms) when debug is on
$telegram_polling_slow_panel_ms = 3000;


?>
