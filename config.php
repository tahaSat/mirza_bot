<?php
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
$request_exec_timeout = null;
$dbhost = '127.0.0.1';
$dbname = 'mirzabot';
$usernamedb = 'root'; 
$passworddb = 'pdqeDna4rayS1J4S21wlkT1qls';
$connect = mysqli_connect($dbhost, $usernamedb, $passworddb, $dbname);
if ($connect->connect_error) { die("error" . $connect->connect_error); }
mysqli_set_charset($connect, "utf8mb4");
$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
$dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
try { $pdo = new PDO($dsn, $usernamedb, $passworddb, $options); } catch (\PDOException $e) { error_log("Database connection failed: " . $e->getMessage()); }
$APIKEY = '8913088647:AAFT3js74c3IKMJr93DDIn11X_FbiWXNHhQ';
$adminnumber = '289943892';
$domainhosts = 'localhost:8080';  // used in links; match PHP server below
$usernamebot = 'pichanet_bot';

// Telegram API only (botapi.php / polling.php). Panel and payment URLs must NOT use this proxy.
$telegram_proxy = '127.0.0.1:10808';
$telegram_proxy_type = 'socks5'; // xray inbound on 10808 is SOCKS; use 'http' only if you run an HTTP proxy there
$telegram_polling_mode = true; // true = polling.php; false = webhook (requires working HTTPS on public URL)
$telegram_polling_async = true; // process each update in a separate PHP worker (users don't block each other)
$telegram_local_bot_url = 'http://127.0.0.1/index.php'; // used only when $telegram_polling_async = false

// Verbose polling / handler logs (logs/polling.log, logs/polling.worker.log)
$telegram_polling_debug = true;
$telegram_polling_log_file = __DIR__ . '/logs/polling.log';
$telegram_polling_worker_log_file = __DIR__ . '/logs/polling.worker.log';
// Log panel HTTP calls slower than this (ms) when debug is on
$telegram_polling_slow_panel_ms = 3000;

?>