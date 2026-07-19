<?php
require_once 'vendor/autoload.php';
require 'config.php';
require_once __DIR__ . '/request.php';
require 'vendor/autoload.php';
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

/**
 * Telegram inline button labels are limited to 64 UTF-8 code units.
 */
function mirza_inline_service_button_text(string $username, string $noteSuffix = ''): string
{
    $label = '✨' . $username . $noteSuffix . '✨';
    if (mb_strlen($label, 'UTF-8') > 64) {
        return mb_substr($label, 0, 61, 'UTF-8') . '…';
    }
    return $label;
}

/**
 * Telegram callback_data is limited to 64 bytes.
 */
function mirza_inline_callback_data(string $prefix, $id): string
{
    $data = $prefix . $id;
    return strlen($data) > 64 ? substr($data, 0, 64) : $data;
}

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

#-----------shell helper utilities------------#
function isShellExecAvailable()
{
    static $isAvailable;

    if ($isAvailable !== null) {
        return $isAvailable;
    }

    if (!function_exists('shell_exec')) {
        $isAvailable = false;
        return $isAvailable;
    }

    $disabledFunctions = ini_get('disable_functions');
    if (!empty($disabledFunctions) && stripos($disabledFunctions, 'shell_exec') !== false) {
        $isAvailable = false;
        return $isAvailable;
    }

    $isAvailable = true;
    return $isAvailable;
}

function getCrontabBinary()
{
    static $resolvedPath;

    if ($resolvedPath !== null) {
        return $resolvedPath ?: null;
    }

    $candidateDirectories = [
        '/usr/local/bin',
        '/usr/bin',
        '/bin',
        '/usr/sbin',
        '/sbin',
    ];

    $environmentPath = getenv('PATH');
    if ($environmentPath !== false && $environmentPath !== '') {
        foreach (explode(PATH_SEPARATOR, $environmentPath) as $pathDirectory) {
            $pathDirectory = trim($pathDirectory);
            if ($pathDirectory !== '' && !in_array($pathDirectory, $candidateDirectories, true)) {
                $candidateDirectories[] = $pathDirectory;
            }
        }
    }

    foreach ($candidateDirectories as $directory) {
        $executablePath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'crontab';
        if (@is_file($executablePath) && @is_executable($executablePath)) {
            $resolvedPath = $executablePath;
            return $resolvedPath;
        }
    }

    if (isShellExecAvailable()) {
        $whichOutput = @shell_exec('command -v crontab 2>/dev/null');
        if (is_string($whichOutput)) {
            $whichOutput = trim($whichOutput);
            if ($whichOutput !== '' && @is_executable($whichOutput)) {
                $resolvedPath = $whichOutput;
                return $resolvedPath;
            }
        }
    }

    $resolvedPath = '';
    error_log('Unable to locate the crontab executable on this system.');

    return null;
}

function runShellCommand($command)
{
    if (!isShellExecAvailable()) {
        error_log('shell_exec is not available; unable to run command: ' . $command);
        return null;
    }

    if (getenv('PATH') === false || trim((string) getenv('PATH')) === '') {
        putenv('PATH=/usr/local/bin:/usr/bin:/bin');
    }

    return shell_exec($command);
}

function deleteDirectory($directory)
{
    if (!file_exists($directory)) {
        return true;
    }

    if (!is_dir($directory)) {
        return @unlink($directory);
    }

    $items = scandir($directory);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            if (!deleteDirectory($path)) {
                return false;
            }
        } else {
            if (!@unlink($path)) {
                return false;
            }
        }
    }

    return @rmdir($directory);
}

function ensureTableUtf8mb4($table)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $currentCollation = $stmt->fetchColumn();

        if ($currentCollation === false) {
            error_log("Failed to detect current collation for table {$table}");
            return false;
        }

        if (stripos((string) $currentCollation, 'utf8mb4') === 0) {
            return true;
        }

        $pdo->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return true;
    } catch (PDOException $e) {
        error_log('Failed to convert table to utf8mb4: ' . $e->getMessage());
        return false;
    }
}

function ensureCardNumberTableSupportsUnicode()
{
    global $connect;

    if (!isset($connect) || !($connect instanceof mysqli)) {
        return;
    }

    try {
        if (method_exists($connect, 'character_set_name') && $connect->character_set_name() !== 'utf8mb4') {
            if (!$connect->set_charset('utf8mb4')) {
                error_log('Failed to enforce utf8mb4 charset on mysqli connection: ' . $connect->error);
            }
        }

        if (!$connect->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'")) {
            error_log('Failed to execute SET NAMES utf8mb4 for card_number table: ' . $connect->error);
        }

        $createQuery = "CREATE TABLE IF NOT EXISTS card_number (" .
            "cardnumber varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY," .
            "namecard varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$connect->query($createQuery)) {
            error_log('Failed to create card_number table with utf8mb4 charset: ' . $connect->error);
        }

        ensureTableUtf8mb4('card_number');

        $columnInfo = $connect->query("SHOW FULL COLUMNS FROM card_number WHERE Field IN ('cardnumber', 'namecard')");
        if ($columnInfo instanceof mysqli_result) {
            while ($column = $columnInfo->fetch_assoc()) {
                $collation = $column['Collation'] ?? '';
                if (!is_string($collation) || stripos($collation, 'utf8mb4') === false) {
                    $field = $column['Field'];
                    $type = $field === 'cardnumber' ? 'varchar(500)' : 'varchar(1000)';
                    $alter = sprintf(
                        "ALTER TABLE card_number MODIFY %s %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci%s",
                        $field,
                        $type,
                        $field === 'cardnumber' ? ' PRIMARY KEY' : ' NOT NULL'
                    );
                    if (!$connect->query($alter)) {
                        error_log('Failed to update card_number column collation: ' . $connect->error);
                    }
                }
            }
            $columnInfo->free();
        } else {
            error_log('Unable to inspect card_number column collations: ' . $connect->error);
        }
    } catch (\Throwable $e) {
        error_log('Unexpected error while ensuring card_number utf8mb4 compatibility: ' . $e->getMessage());
    }
}

function normaliseUpdateValue($value)
{
    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return $value;
}

function copyDirectoryContents($source, $destination)
{
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        return false;
    }

    $items = scandir($source);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;

        if (is_dir($sourcePath)) {
            if (!copyDirectoryContents($sourcePath, $destinationPath)) {
                return false;
            }
        } else {
            if (!@copy($sourcePath, $destinationPath)) {
                return false;
            }
        }
    }

    return true;
}

#-----------function------------#
function step($step, $from_id)
{
    global $pdo;
    $stmt = $pdo->prepare('UPDATE user SET step = ? WHERE id = ?');
    $stmt->execute([$step, $from_id]);
    clearSelectCache('user');
}
function determineColumnTypeFromValue($value)
{
    if (is_bool($value)) {
        return 'TINYINT(1)';
    }

    if (is_int($value)) {
        return 'INT(11)';
    }

    if (is_float($value)) {
        return 'DOUBLE';
    }

    if ($value === null) {
        return 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    if (is_string($value)) {
        if (function_exists('mb_strlen')) {
            $length = mb_strlen($value, 'UTF-8');
        } else {
            $length = strlen($value);
        }

        if ($length <= 191) {
            return 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        if ($length <= 500) {
            return 'VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
}
function ensureColumnExistsForUpdate($tableName, $fieldName, $valueSample = null)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$tableName, $fieldName]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $datatype = determineColumnTypeFromValue($valueSample);

        $defaultValue = null;
        if (is_bool($valueSample)) {
            $defaultValue = $valueSample ? '1' : '0';
        } elseif (is_scalar($valueSample) && $valueSample !== null) {
            $defaultValue = (string) $valueSample;
        }

        addFieldToTable($tableName, $fieldName, $defaultValue, $datatype);
    } catch (PDOException $e) {
        error_log('Failed to ensure column exists: ' . $e->getMessage());
    }
}
function update($table, $field, $newValue, $whereField = null, $whereValue = null)
{
    global $pdo, $user;

    $valueToStore = normaliseUpdateValue($newValue);

    ensureColumnExistsForUpdate($table, $field, $valueToStore);

    $executeUpdate = function ($value) use ($pdo, $table, $field, $whereField, $whereValue) {
        if ($whereField !== null) {
            $stmt = $pdo->prepare("SELECT $field FROM $table WHERE $whereField = ? FOR UPDATE");
            $stmt->execute([$whereValue]);
            $stmt = $pdo->prepare("UPDATE $table SET $field = ? WHERE $whereField = ?");
            $stmt->execute([$value, $whereValue]);
        } else {
            $stmt = $pdo->prepare("UPDATE $table SET $field = ?");
            $stmt->execute([$value]);
        }
    };

    try {
        $executeUpdate($valueToStore);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Incorrect string value') !== false) {
            $tableConverted = ensureTableUtf8mb4($table);
            if ($tableConverted) {
                try {
                    $executeUpdate($valueToStore);
                } catch (PDOException $retryException) {
                    error_log('Retry after charset conversion failed: ' . $retryException->getMessage());
                    throw $retryException;
                }
            } else {
                $fallbackValue = is_string($valueToStore) ? @iconv('UTF-8', 'UTF-8//IGNORE', $valueToStore) : $valueToStore;
                if ($fallbackValue === false) {
                    $fallbackValue = '';
                }
                $executeUpdate($fallbackValue);
            }
        } else {
            throw $e;
        }
    }

    $date = date("Y-m-d H:i:s");
    if (!isset($user['step'])) {
        $user['step'] = '';
    }
    $logValue = is_scalar($valueToStore) ? $valueToStore : json_encode($valueToStore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $logss = "{$table}_{$field}_{$logValue}_{$whereField}_{$whereValue}_{$user['step']}_$date";
    if ($field !== "message_count" && $field !== "last_message_time") {
        $logFile = __DIR__ . '/logs/update.log';
        $logDir = dirname($logFile);
        if (is_dir($logDir) && is_writable($logDir)) {
            @file_put_contents($logFile, "\n" . $logss, FILE_APPEND | LOCK_EX);
        }
    }

    clearSelectCache($table);
}
function &getSelectCacheStore()
{
    static $store = [
    'results' => [],
    'tableIndex' => [],
    ];

    return $store;
}

function clearSelectCache($table = null)
{
    $store = &getSelectCacheStore();

    if ($table === null) {
        $store['results'] = [];
        $store['tableIndex'] = [];
        return;
    }

    if (!isset($store['tableIndex'][$table])) {
        return;
    }

    foreach (array_keys($store['tableIndex'][$table]) as $cacheKey) {
        unset($store['results'][$cacheKey]);
    }

    unset($store['tableIndex'][$table]);
}

function select($table, $field, $whereField = null, $whereValue = null, $type = "select", $options = [])
{
    global $pdo;

    $useCache = true;
    if (is_array($options) && array_key_exists('cache', $options)) {
        $useCache = (bool) $options['cache'];
    }

    $cacheKey = null;
    if ($useCache) {
        $cacheKey = hash('sha256', json_encode([
            $table,
            $field,
            $whereField,
            $whereValue,
            $type,
        ], JSON_UNESCAPED_UNICODE));

        $store = &getSelectCacheStore();
        if (isset($store['results'][$cacheKey])) {
            return $store['results'][$cacheKey];
        }
    }

    $query = "SELECT $field FROM $table";

    if ($whereField !== null) {
        $query .= " WHERE $whereField = :whereValue";
    }

    try {
        $stmt = $pdo->prepare($query);
        if ($whereField !== null) {
            $stmt->bindParam(':whereValue', $whereValue, PDO::PARAM_STR);
        }

        $stmt->execute();
        if ($type == "count") {
            $result = $stmt->rowCount();
        } elseif ($type == "FETCH_COLUMN") {
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($table === 'admin' && $field === 'id_admin') {
                global $adminnumber;
                if (!is_array($results)) {
                    $results = [];
                }

                $results = array_values(array_unique(array_filter($results, function ($value) {
                    return $value !== null && $value !== '';
                })));

                if (empty($results) && isset($adminnumber) && $adminnumber !== '') {
                    $results[] = (string) $adminnumber;
                }
            }
            $result = $results;
        } elseif ($type == "fetchAll") {
            $result = $stmt->fetchAll();
        } else {
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            $result = $fetched === false ? null : $fetched;
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        die("Query failed: " . $e->getMessage());
    }

    if ($useCache && $cacheKey !== null) {
        $store = &getSelectCacheStore();
        $store['results'][$cacheKey] = $result;
        if (!isset($store['tableIndex'][$table])) {
            $store['tableIndex'][$table] = [];
        }
        $store['tableIndex'][$table][$cacheKey] = true;
    }

    return $result;
}

function getPaySettingValue($name, $default = null)
{
    $result = select("PaySetting", "ValuePay", "NamePay", $name, "select");
    if (!is_array($result) || !array_key_exists('ValuePay', $result)) {
        return $default;
    }

    return $result['ValuePay'];
}
function generateUUID()
{
    $data = openssl_random_pseudo_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    return $uuid;
}
function rate_arze()
{
    $arze_rate = [];
    $requests_tron = json_decode(file_get_contents('https://api.diadata.org/v1/assetQuotation/Tron/0x0000000000000000000000000000000000000000'), true);
    $html_read = file_get_contents("https://www.bon-bast.com/");
    preg_match('/<span>\s*([\d,]+)\s*<\/span>/', $html_read, $matches);
    if (!empty($matches[1])) {
        $requestsusd = str_replace(',', '', $matches[1]);
    }
    $arze_rate['USD'] = intval($requestsusd);
    $arze_rate['TRX'] = intval($requests_tron['Price'] * $arze_rate['USD']);

    return $arze_rate;
}
function updatePaymentMessageId($response, $orderId)
{
    if (!is_array($response)) {
        error_log("Failed to send payment message for order {$orderId}: unexpected response");
        return false;
    }

    if (empty($response['ok'])) {
        error_log("Failed to send payment message for order {$orderId}: " . json_encode($response));
        return false;
    }

    if (!isset($response['result']['message_id'])) {
        error_log("Missing message_id for order {$orderId}: " . json_encode($response));
        return false;
    }

    update("Payment_report", "message_id", intval($response['result']['message_id']), "id_order", $orderId);
    return true;
}
function nowPayments($payment, $price_amount, $order_id, $order_description)
{
    global $domainhosts;
    $apinowpayments = select("PaySetting", "*", "NamePay", "marchent_tronseller", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.nowpayments.io/v1/' . $payment,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 7000,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => 1,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apinowpayments,
            'Content-Type: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'price_amount' => $price_amount,
        'price_currency' => 'usd',
        'order_id' => $order_id,
        'order_description' => $order_description,
        'ipn_callback_url' => "https://" . $domainhosts . "/payment/nowpayment.php"
    ]));

    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function StatusPayment($paymentid)
{
    $apinowpayments = select("PaySetting", "*", "NamePay", "marchent_tronseller", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.nowpayments.io/v1/payment/' . $paymentid,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apinowpayments
        ),
    ));
    $response = curl_exec($curl);
    $response = json_decode($response, true);
    curl_close($curl);
    return $response;
}
function channel(array $id_channel)
{
    global $from_id;
    $channel_link = array();
    foreach ($id_channel as $channel) {
        $response = telegram('getChatMember', [
            'chat_id' => $channel,
            'user_id' => $from_id
        ]);
        if ($response['ok']) {
            if (!in_array($response['result']['status'], ['member', 'creator', 'administrator'])) {
                $channel_link[] = $channel;
            }
        }
    }
    if (count($channel_link) == 0) {
        return [];
    } else {
        return $channel_link;
    }
}
function isValidDate($date)
{
    return (strtotime($date) != false);
}
function trnado($order_id, $price)
{
    global $domainhosts;
    $apitronseller = select("PaySetting", "*", "NamePay", "apiternado", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];
    $urlpay = select("PaySetting", "*", "NamePay", "urlpaymenttron", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);
    $data = array(
        "PaymentID" => $order_id,
        "WalletAddress" => $walletaddress,
        "TronAmount" => $price,
        "CallbackUrl" => "https://" . $domainhosts . "/payment/tronado.php"
    );
    $datasend = json_encode($data);
    curl_setopt_array($curl, array(
        CURLOPT_URL => "$urlpay",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apitronseller,
            'Content-Type: application/json',
            'Cookie: ASP.NET_SessionId=spou2s5lo4nnxkjtavscrrlo'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, $datasend);

    $response = curl_exec($curl);

    curl_close($curl);
    return json_decode($response, true);
}
function formatBytes($bytes, $precision = 2): string
{
    $base = log($bytes, 1024);
    $power = $bytes > 0 ? floor($base) : 0;
    $suffixes = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'];
    return round(pow(1024, $base - $power), $precision) . ' ' . $suffixes[$power];
}
function generateUsername($from_id, $Metode, $username, $randomString, $text, $namecustome, $usernamecustom)
{
    $setting = select("setting", "*", null, null, "select");
    $user = select("user", "*", "id", $from_id, "select");
    if ($user == false) {
        $user = array();
        $user = array(
            'number_username' => '',
        );
    }
    if ($Metode == "آیدی عددی + حروف و عدد رندوم") {
        return $from_id . "_" . $randomString;
    } elseif ($Metode == "نام کاربری + عدد به ترتیب") {
        if ($username == "NOT_USERNAME") {
            if (preg_match('/^\w{3,32}$/', $namecustome)) {
                $username = $namecustome;
            }
        }
        return $username . "_" . $user['number_username'];
    } elseif ($Metode == "نام کاربری دلخواه")
        return $text;
    elseif ($Metode == "نام کاربری دلخواه + عدد رندوم") {
        $random_number = rand(1000000, 9999999);
        return $text . "_" . $random_number;
    } elseif ($Metode == "متن دلخواه + عدد رندوم") {
        return $namecustome . "_" . $randomString;
    } elseif ($Metode == "متن دلخواه + عدد ترتیبی") {
        return $namecustome . "_" . $setting['numbercount'];
    } elseif ($Metode == "آیدی عددی+عدد ترتیبی") {
        return $from_id . "_" . $user['number_username'];
    } elseif ($Metode == "متن دلخواه نماینده + عدد ترتیبی") {
        if ($usernamecustom == "none") {
            return $namecustome . "_" . $setting['numbercount'];
        }
        return $usernamecustom . "_" . $user['number_username'];
    }
}
function outputlink($text)
{
    $ch = curl_init();
    curl_disable_proxy($ch);
    curl_setopt($ch, CURLOPT_URL, $text);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 6000);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        return null;
    } else {
        return $response;
    }

    curl_close($ch);
}
function DirectPayment($order_id, $image = 'images.jpg')
{
    global $pdo, $ManagePanel, $textbotlang, $keyboardextendfnished, $keyboard, $Confirm_pay, $from_id, $message_id, $datatextbot;
    $buyreport = select("topicid", "idreport", "report", "buyreport", "select")['idreport'] ?? null;
    $admin_ids = select("admin", "id_admin", null, null, "FETCH_COLUMN") ?: [];
    $otherservice = select("topicid", "idreport", "report", "otherservice", "select")['idreport'] ?? null;
    $otherreport = select("topicid", "idreport", "report", "otherreport", "select")['idreport'] ?? null;
    $errorreport = select("topicid", "idreport", "report", "errorreport", "select")['idreport'] ?? null;
    $porsantreport = select("topicid", "idreport", "report", "porsantreport", "select")['idreport'] ?? null;
    $setting = select("setting", "*");
    if (!is_array($datatextbot)) {
        $datatextbot = [];
    }
    $Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
    if ($Payment_report == false || !is_array($Payment_report)) {
        return;
    }
    $format_price_cart = number_format($Payment_report['price']);
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    if ($Balance_id == false || !is_array($Balance_id)) {
        return;
    }
    $steppay = explode("|", $Payment_report['id_invoice']);
    update("user", "Processing_value", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_one", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_tow", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_four", "0", "id", $Balance_id['id']);
    if ($steppay[0] == "getconfigafterpay") {
        $get_invoice = select("invoice", "*", "username", $steppay[1], "select");
        $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name_product AND (Location = :Service_location  or Location = '/all')");
        $stmt->bindParam(':name_product', $get_invoice['name_product'], PDO::PARAM_STR);
        $stmt->bindParam(':Service_location', $get_invoice['Service_location'], PDO::PARAM_STR);
        $stmt->execute();
        $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($get_invoice['name_product'] == "🛍 حجم دلخواه" || $get_invoice['name_product'] == "⚙️ سرویس دلخواه") {
            $info_product['data_limit_reset'] = "no_reset";
            $info_product['Volume_constraint'] = $get_invoice['Volume'];
            $info_product['name_product'] = $textbotlang['users']['customsellvolume']['title'];
            $info_product['code_product'] = "customvolume";
            $info_product['Service_time'] = $get_invoice['Service_time'];
            $info_product['price_product'] = $get_invoice['price_product'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name_product AND (Location = :Service_location  or Location = '/all')");
            $stmt->bindParam(':name_product', $get_invoice['name_product'], PDO::PARAM_STR);
            $stmt->bindParam(':Service_location', $get_invoice['Service_location'], PDO::PARAM_STR);
            $stmt->execute();
            $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $username_ac = $get_invoice['username'];
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $get_invoice['Service_location'], "select");
        $date = strtotime("+" . $get_invoice['Service_time'] . "days");
        if (intval($get_invoice['Service_time']) == 0) {
            $timestamp = 0;
        } else {
            $timestamp = strtotime(date("Y-m-d H:i:s", $date));
        }
        $datac = array(
            'expire' => $timestamp,
            'data_limit' => $get_invoice['Volume'] * pow(1024, 3),
            'from_id' => $Balance_id['id'],
            'username' => $Balance_id['username'],
            'type' => 'buy'
        );
        $dataoutput = $ManagePanel->createUser($marzban_list_get['name_panel'], $info_product['code_product'], $username_ac, $datac);
        // If panel already has this username (e.g. prior partial confirm), reuse existing account
        if (($dataoutput['username'] ?? null) == null) {
            $msgFail = is_string($dataoutput['msg'] ?? null) ? $dataoutput['msg'] : json_encode($dataoutput['msg'] ?? '');
            if (stripos((string) $msgFail, 'already exists') !== false || stripos((string) $msgFail, 'duplicate') !== false) {
                $existing = $ManagePanel->DataUser($marzban_list_get['name_panel'], $username_ac);
                if (($existing['status'] ?? '') !== 'Unsuccessful') {
                    $dataoutput = [
                        'status' => 'successful',
                        'username' => $existing['username'] ?? $username_ac,
                        'subscription_url' => $existing['subscription_url'] ?? '',
                        'configs' => $existing['configs'] ?? ($existing['links'] ?? []),
                    ];
                }
            }
        }
        if (($dataoutput['username'] ?? null) == null) {
            $dataoutput['msg'] = json_encode($dataoutput['msg'] ?? '');
            $balance = $Balance_id['Balance'] + $Payment_report['price'];
            update("user", "Balance", $balance, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], $textbotlang['users']['sell']['ErrorConfig'], $keyboard, 'HTML');
            sendmessage($Balance_id['id'], "💎  کاربر عزیز بدلیل ساخته نشدن سرویس مبلغ $balance تومان به کیف پول شما اضافه گردید.", $keyboard, 'HTML');
            $texterros = "
⭕️ خطا در ساخت کانفیگ
✍️ دلیل خطا : 
{$dataoutput['msg']}
آیدی کابر : {$Balance_id['id']}
نام کاربری کاربر : @{$Balance_id['username']}
نام پنل : {$marzban_list_get['name_panel']}";
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $texterros,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $Shoppinginfo = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => "📚 مشاهده آموزش استفاده ", 'callback_data' => "helpbtn"],
                ]
            ]
        ]);
        $output_config_link = "";
        $config = "";
        if ($marzban_list_get['config'] == "onconfig" && is_array($dataoutput['configs'])) {
            foreach ($dataoutput['configs'] as $link) {
                $config .= "\n" . $link;
            }
        }
        $output_config_link = $marzban_list_get['sublink'] == "onsublink" ? $dataoutput['subscription_url'] : "";
        $datatextbot['textafterpay'] = $marzban_list_get['type'] == "Manualsale" ? $datatextbot['textmanual'] : $datatextbot['textafterpay'];
        $datatextbot['textafterpay'] = $marzban_list_get['type'] == "WGDashboard" ? $datatextbot['text_wgdashboard'] : $datatextbot['textafterpay'];
        $datatextbot['textafterpay'] = $marzban_list_get['type'] == "ibsng" || $marzban_list_get['type'] == "mikrotik" ? $datatextbot['textafterpayibsng'] : $datatextbot['textafterpay'];
        if (intval($get_invoice['Service_time']) == 0)
            $get_invoice['Service_time'] = $textbotlang['users']['stateus']['Unlimited'];
        $textcreatuser = str_replace('{username}', $dataoutput['username'], $datatextbot['textafterpay']);
        $textcreatuser = str_replace('{name_service}', $get_invoice['name_product'], $textcreatuser);
        $textcreatuser = str_replace('{location}', $marzban_list_get['name_panel'], $textcreatuser);
        $textcreatuser = str_replace('{day}', $get_invoice['Service_time'], $textcreatuser);
        $textcreatuser = str_replace('{volume}', $get_invoice['Volume'], $textcreatuser);
        $textcreatuser = str_replace('{config}', "<code>{$output_config_link}</code>", $textcreatuser);
        $textcreatuser = str_replace('{links}', $config, $textcreatuser);
        $textcreatuser = str_replace('{links2}', "{$output_config_link}", $textcreatuser);
        if ($marzban_list_get['type'] == "Manualsale" || $marzban_list_get['type'] == "ibsng" || $marzban_list_get['type'] == "mikrotik") {
            $textcreatuser = str_replace('{password}', $dataoutput['subscription_url'], $textcreatuser);
            update("invoice", "user_info", $dataoutput['subscription_url'], "id_invoice", $get_invoice['id_invoice']);
        }
        sendMessageService($marzban_list_get, $dataoutput['configs'], $output_config_link, $dataoutput['username'], $Shoppinginfo, $textcreatuser, $get_invoice['id_invoice'], $get_invoice['id_user'], $image);
        $partsdic = explode("_", $Balance_id['Processing_value_four'], $get_invoice['id_user']);
        if ($partsdic[0] == "dis") {
            discount_sell_record_usage([
                'code' => $partsdic[1],
                'id_user' => $Balance_id['id'],
                'type' => 'buy',
                'code_product' => $get_invoice['code_product'] ?? null,
                'name_product' => $get_invoice['name_product'] ?? null,
                'code_panel' => $marzban_list_get['code_panel'] ?? null,
                'name_panel' => $marzban_list_get['name_panel'] ?? ($get_invoice['Service_location'] ?? null),
                'id_invoice' => $get_invoice['id_invoice'] ?? null,
                'price_original' => null,
                'price_final' => $get_invoice['price_product'] ?? ($Payment_report['price'] ?? null),
            ]);
            $text_report = "⭕️ یک کاربر با نام کاربری @{$Balance_id['username']}  و آیدی عددی {$Balance_id['id']} از کد تخفیف {$partsdic[1]} استفاده کرد.";
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $otherreport,
                    'text' => $text_report,
                ]);
            }
        }
        $affiliatescommission = select("affiliates", "*", null, null, "select");
        $marzbanporsant_one_buy = select("affiliates", "*", null, null, "select");
        $stmt = $pdo->prepare("SELECT * FROM invoice WHERE name_product != 'سرویس تست'  AND id_user = :id_user AND Status != 'Unpaid'");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->execute();
        $countinvoice = $stmt->rowCount();
        if ($affiliatescommission['status_commission'] == "oncommission" && ($Balance_id['affiliates'] != null && intval($Balance_id['affiliates']) != 0)) {
            if ($marzbanporsant_one_buy['porsant_one_buy'] == "on_buy_porsant") {
                if ($countinvoice <= 1) {
                    $result = ($Payment_report['price'] * $setting['affiliatespercentage']) / 100;
                    $user_Balance = select("user", "*", "id", $Balance_id['affiliates'], "select");
                    if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['affiliates'], $admin_ids)) {
                        sendmessage($Balance_id['affiliates'], "📌شما 2 امتیاز جدید کسب کردید.", null, 'html');
                        $scorenew = $user_Balance['score'] + 2;
                        update("user", "score", $scorenew, "id", $Balance_id['affiliates']);
                    }
                    $Balance_prim = $user_Balance['Balance'] + $result;
                    $dateacc = date('Y/m/d H:i:s');
                    update("user", "Balance", $Balance_prim, "id", $Balance_id['affiliates']);
                    $result = number_format($result);
                    $textadd = "🎁  پرداخت پورسانت 
        
        مبلغ $result تومان به حساب شما از طرف  زیر مجموعه تان به کیف پول شما واریز گردید";
                    $textreportport = "
مبلغ $result به کاربر {$Balance_id['affiliates']} برای پورسانت از کاربر {$Balance_id['id']} واریز گردید 
تایم : $dateacc";
                    if (strlen($setting['Channel_Report']) > 0) {
                        telegram('sendmessage', [
                            'chat_id' => $setting['Channel_Report'],
                            'message_thread_id' => $porsantreport,
                            'text' => $textreportport,
                            'parse_mode' => "HTML"
                        ]);
                    }
                    sendmessage($Balance_id['affiliates'], $textadd, null, 'HTML');
                }
            } else {

                $result = ($Payment_report['price'] * $setting['affiliatespercentage']) / 100;
                $user_Balance = select("user", "*", "id", $Balance_id['affiliates'], "select");
                if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['affiliates'], $admin_ids)) {
                    sendmessage($Balance_id['affiliates'], "📌شما 2 امتیاز جدید کسب کردید.", null, 'html');
                    $scorenew = $user_Balance['score'] + 2;
                    update("user", "score", $scorenew, "id", $Balance_id['affiliates']);
                }
                $Balance_prim = $user_Balance['Balance'] + $result;
                $dateacc = date('Y/m/d H:i:s');
                update("user", "Balance", $Balance_prim, "id", $Balance_id['affiliates']);
                $result = number_format($result);
                $textadd = "🎁  پرداخت پورسانت 
        
        مبلغ $result تومان به حساب شما از طرف  زیر مجموعه تان به کیف پول شما واریز گردید";
                $textreportport = "
مبلغ $result به کاربر {$Balance_id['affiliates']} برای پورسانت از کاربر {$Balance_id['id']} واریز گردید 
تایم : $dateacc";
                if (strlen($setting['Channel_Report']) > 0) {
                    telegram('sendmessage', [
                        'chat_id' => $setting['Channel_Report'],
                        'message_thread_id' => $porsantreport,
                        'text' => $textreportport,
                        'parse_mode' => "HTML"
                    ]);
                }
                sendmessage($Balance_id['affiliates'], $textadd, null, 'HTML');
            }
        }
        if ($marzban_list_get['MethodUsername'] == "متن دلخواه + عدد ترتیبی" || $marzban_list_get['MethodUsername'] == "نام کاربری + عدد به ترتیب" || $marzban_list_get['MethodUsername'] == "آیدی عددی+عدد ترتیبی" || $marzban_list_get['MethodUsername'] == "متن دلخواه نماینده + عدد ترتیبی") {
            $value = intval($Balance_id['number_username']) + 1;
            update("user", "number_username", $value, "id", $Balance_id['id']);
            if ($marzban_list_get['MethodUsername'] == "متن دلخواه + عدد ترتیبی" || $marzban_list_get['MethodUsername'] == "متن دلخواه نماینده + عدد ترتیبی") {
                $value = intval($setting['numbercount']) + 1;
                update("setting", "numbercount", $value);
            }
        }
        $Balance_prims = $Balance_id['Balance'] - $get_invoice['price_product'];
        if ($Balance_prims <= 0)
            $Balance_prims = 0;
        update("user", "Balance", $Balance_prims, "id", $Balance_id['id']);
        $balanceformatsell = select("user", "Balance", "id", $get_invoice['id_user'], "select")['Balance'];
        $balanceformatsell = number_format($balanceformatsell, 0);
        $balancebefore = number_format($Balance_id['Balance'], 0);
        $timejalali = jdate('Y/m/d H:i:s');
        $textonebuy = "";
        if ($countinvoice == 1) {
            $textonebuy = "📌 خرید اول کاربر";
        }
        $Response = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['Admin']['ManageUser']['mangebtnuser'], 'callback_data' => 'manageuser_' . $Balance_id['id']],
                ],
            ]
        ]);
        $text_report = "📣 جزئیات ساخت اکانت در ربات بعد پرداخت ثبت شد .

$textonebuy
▫️آیدی عددی کاربر : <code>{$Balance_id['id']}</code>
▫️نام کاربری کاربر :@{$Balance_id['username']}
▫️نام کاربری کانفیگ :$username_ac
▫️لوکیشن سرویس : {$get_invoice['Service_location']}
▫️زمان خریداری شده :{$get_invoice['Service_time']} روز
▫️نام محصول خریداری شده :{$get_invoice['name_product']}
▫️حجم خریداری شده : {$get_invoice['Volume']} GB
▫️موجودی قبل خرید : $balancebefore تومان
▫️موجودی بعد خرید : $balanceformatsell تومان
▫️کد پیگیری: {$get_invoice['id_invoice']}
▫️نوع کاربر : {$Balance_id['agent']}
▫️شماره تلفن کاربر : {$Balance_id['number']}
▫️قیمت محصول : {$get_invoice['price_product']} تومان
▫️قیمت نهایی : {$Payment_report['price']} تومان
▫️زمان خرید : $timejalali";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $buyreport,
                'text' => $text_report,
                'parse_mode' => "HTML",
                'reply_markup' => $Response
            ]);
        }
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], "📌شما 1 امتیاز جدید کسب کردید.", null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        update("invoice", "Status", "active", "username", $get_invoice['username']);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            update("invoice", "Status", "active", "id_invoice", $get_invoice['id_invoice']);
            $textconfrom = "✅ پرداخت تایید شده
 🛍خرید سرویس 
 ▫️نام کاربری کانفیگ :$username_ac
▫️لوکیشن سرویس : {$get_invoice['Service_location']}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💎 موجودی قبل خرید  : {$Balance_id['Balance']}
💸 مبلغ پرداختی: $format_price_cart تومان
✍️ توضیحات : {$Payment_report['dec_not_confirmed']}

";
            if (!empty($from_id) && !empty($message_id)) {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
    } elseif ($steppay[0] == "getextenduser") {
        $balanceformatsell = number_format(select("user", "Balance", "id", $Balance_id['id'], "select")['Balance'], 0);
        $partsdic = explode("%", $steppay[1]);
        $usernamepanel = $partsdic[0];
        $sql = "SELECT * FROM service_other WHERE username = :username  AND value  LIKE CONCAT('%', :value, '%') AND id_user = :id_user ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':username', $usernamepanel, PDO::PARAM_STR);
        $stmt->bindParam(':value', $partsdic[1], PDO::PARAM_STR);
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->execute();
        $data_order = $stmt->fetch(PDO::FETCH_ASSOC);
        $service_other = $data_order;
        if ($service_other == false) {
            sendmessage($Balance_id['id'], '❌ خطایی در هنگام تمدید رخ داده با پشتیبانی در ارتباط باشید', $keyboard, 'HTML');
            return;
        }
        $service_other = json_decode($service_other['value'], true);
        $codeproduct = $service_other['code_product'];
        $nameloc = select("invoice", "*", "username", $usernamepanel, "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        if ($codeproduct == "custom_volume") {
            $prodcut['code_product'] = "custom_volume";
            $prodcut['name_product'] = $nameloc['name_product'];
            $prodcut['price_product'] = $data_order['price'];
            $prodcut['Service_time'] = $service_other['Service_time'];
            $prodcut['Volume_constraint'] = $service_other['volumebuy'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE (Location = '{$nameloc['Service_location']}' OR Location = '/all') AND agent= '{$Balance_id['agent']}' AND code_product = '$codeproduct'");
            $stmt->execute();
            $prodcut = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($nameloc['name_product'] == "سرویس تست") {
            update("invoice", "name_product", $prodcut['name_product'], "id_invoice", $nameloc['id_invoice']);
            update("invoice", "price_product", $prodcut['price_product'], "id_invoice", $nameloc['id_invoice']);
        }
        $dateacc = date('Y/m/d H:i:s');
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $nameloc['username']);
        $Balance_Low_user = 0;
        update("user", "Balance", $Balance_Low_user, "id", $Balance_id['id']);
        $extend = $ManagePanel->extend($marzban_list_get['Methodextend'], $prodcut['Volume_constraint'], $prodcut['Service_time'], $nameloc['username'], $prodcut['code_product'], $marzban_list_get['code_panel']);
        if ($extend['status'] == false) {
            $balance = $Balance_id['Balance'] + $Payment_report['price'];
            update("user", "Balance", $balance, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], $textbotlang['users']['sell']['ErrorConfig'], $keyboard, 'HTML');
            sendmessage($Balance_id['id'], "💎  کاربر عزیز بدلیل تمدید نشدن سرویس مبلغ $balance تومان به کیف پول شما اضافه گردید.", $keyboard, 'HTML');
            $extend['msg'] = json_encode($extend['msg']);
            $textreports = "
        خطای تمدید سرویس
نام پنل : {$marzban_list_get['name_panel']}
نام کاربری سرویس : {$nameloc['username']}
دلیل خطا : {$extend['msg']}";
            sendmessage($nameloc['id_user'], "❌خطایی در تمدید سرویس رخ داده با پشتیبانی در ارتباط باشید", null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }

        update("service_other", "output", json_encode($extend), "id", $data_order['id']);
        update("service_other", "status", "paid", "id", $data_order['id']);
        $partsdic = explode("_", $Balance_id['Processing_value_four']);
        if ($partsdic[0] == "dis") {
            discount_sell_record_usage([
                'code' => $partsdic[1],
                'id_user' => $Balance_id['id'],
                'type' => 'extend',
                'code_product' => $prodcut['code_product'] ?? null,
                'name_product' => $prodcut['name_product'] ?? ($nameloc['name_product'] ?? null),
                'code_panel' => $marzban_list_get['code_panel'] ?? null,
                'name_panel' => $marzban_list_get['name_panel'] ?? ($nameloc['Service_location'] ?? null),
                'id_invoice' => $nameloc['id_invoice'] ?? null,
                'price_original' => $prodcut['price_product'] ?? null,
                'price_final' => $Payment_report['price'] ?? null,
            ]);
            $text_report = "⭕️ یک کاربر با نام کاربری @{$Balance_id['username']}  و آیدی عددی {$Balance_id['id']} از کد تخفیف {$partsdic[1]} استفاده کرد.";
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $otherreport,
                    'text' => $text_report,
                ]);
            }
        }
        $keyboardextendfnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['stateus']['backlist'], 'callback_data' => "backorder"],
                ],
                [
                    ['text' => $textbotlang['users']['stateus']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        if ($Balance_id['agent'] == "f") {
            $valurcashbackextend = select("shopSetting", "*", "Namevalue", "chashbackextend", "select")['value'];
        } else {
            $valurcashbackextend = json_decode(select("shopSetting", "*", "Namevalue", "chashbackextend_agent", "select")['value'], true)[$Balance_id['agenr']];
        }
        if (intval($valurcashbackextend) != 0) {
            $result = ($prodcut['price_product'] * $valurcashbackextend) / 100;
            $pricelastextend = $result;
            update("user", "Balance", $pricelastextend, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], "تبریک 🎉
📌 به عنوان هدیه تمدید مبلغ $result تومان حساب شما شارژ گردید", null, 'HTML');
        }
        $priceproductformat = number_format($prodcut['price_product']);
        $textextend = "✅ تمدید برای سرویس شما با موفقیت صورت گرفت
 
▫️نام سرویس : $usernamepanel
▫️نام محصول : {$prodcut['name_product']}
▫️مبلغ تمدید $priceproductformat تومان
";
        sendmessage($Balance_id['id'], $textextend, $keyboardextendfnished, 'HTML');
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], "📌شما 2 امتیاز جدید کسب کردید.", null, 'html');
            $scorenew = $Balance_id['score'] + 2;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $timejalali = jdate('Y/m/d H:i:s');
        $text_report = "📣 جزئیات تمدید اکانت در ربات شما ثبت شد .
    
▫️آیدی عددی کاربر : <code>{$Balance_id['id']}</code>
▫️نام کاربری کاربر : @{$Balance_id['username']}
▫️نام کاربری کانفیگ :$usernamepanel
▫️موقعیت سرویس سرویس : {$nameloc['Service_location']}
▫️نام محصول : {$prodcut['name_product']}
▫️حجم محصول : {$prodcut['Volume_constraint']}
▫️زمان محصول : {$prodcut['Service_time']}
▫️مبلغ تمدید : $priceproductformat تومان
▫️موجودی قبل از خرید : $balanceformatsell تومان
▫️زمان خرید : $timejalali";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
                'parse_mode' => "HTML"
            ]);
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {

            $textconfrom = "✅ پرداخت تایید شده
🔋 تمدید سرویس
🪪 نام کاربری کانفیگ : $usernamepanel
🛍 نام محصول : {$prodcut['name_product']}
🌏 نام لوکیشن : {$nameloc['Service_location']}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💎 موجودی قبل تمدید  : {$Balance_id['Balance']}
💸 مبلغ پرداختی: $format_price_cart تومان
✍️ توضیحات : {$Payment_report['dec_not_confirmed']}

";
            if (!empty($from_id) && !empty($message_id)) {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
    } elseif ($steppay[0] == "getextravolumeuser") {
        $steppay = explode("%", $steppay[1]);
        $volume = $steppay[1];
        $nameloc = select("invoice", "*", "username", $steppay[0], "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        $Balance_Low_user = 0;
        $inboundid = $marzban_list_get['inboundid'];
        if ($nameloc['inboundid'] != null) {
            $inboundid = $nameloc['inboundid'];
        }
        update("user", "Balance", $Balance_Low_user, "id", $Balance_id['id']);
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $steppay[0]);
        $data_for_database = json_encode(array(
            'volume_value' => $volume,
            'old_volume' => $DataUserOut['data_limit'],
            'expire_old' => $DataUserOut['expire']
        ));
        $dateacc = date('Y/m/d H:i:s');
        $type = "extra_user";
        $extra_volume = $ManagePanel->extra_volume($nameloc['username'], $marzban_list_get['code_panel'], $volume);
        if ($extra_volume['status'] == false) {
            $extra_volume['msg'] = json_encode($extra_volume['msg']);
            $textreports = "خطای خرید حجم اضافه
نام پنل : {$marzban_list_get['name_panel']}
نام کاربری سرویس : {$nameloc['username']}
دلیل خطا : {$extra_volume['msg']}";
            sendmessage($nameloc['id_user'], "❌خطایی در خرید حجم اضافه سرویس رخ داده با پشتیبانی در ارتباط باشید", null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO service_other (id_user, username,value,type,time,price,output) VALUES (:id_user,:username,:value,:type,:time,:price,:output)");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':username', $steppay[0]);
        $stmt->bindParam(':value', $data_for_database);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':time', $dateacc);
        $stmt->bindParam(':price', $Payment_report['price']);
        $stmt->bindValue(':output', json_encode($extra_volume));
        $stmt->execute();
        $keyboardextrafnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['stateus']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        $volumesformat = number_format($Payment_report['price'], 0);
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], "📌شما 1 امتیاز جدید کسب کردید.", null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $textvolume = "✅ افزایش حجم برای سرویس شما با موفقیت صورت گرفت
 
▫️نام سرویس  : {$steppay[0]}
▫️حجم اضافه : $volume گیگ

▫️مبلغ افزایش حجم : $volumesformat تومان";
        sendmessage($Balance_id['id'], $textvolume, $keyboardextrafnished, 'HTML');
        $volumes = $volume;
        if ($Payment_report['Payment_Method'] == "cart to cart") {
            $textconfrom = "✅ پرداخت تایید شده
🔋 خرید حجم اضافه
🛍 حجم خریداری شده  : $volumes گیگ
👤 نام کاربری کانفیگ {$steppay[0]}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💎 موجودی قبل ازافزایش موجودی : {$Balance_id['Balance']}
💸 مبلغ پرداختی: $format_price_cart تومان
";
            if (!empty($from_id) && !empty($message_id)) {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        $text_report = "⭕️ یک کاربر حجم اضافه خریده است
        
اطلاعات کاربر : 
🪪 آیدی عددی : {$Balance_id['id']}
🛍 حجم خریداری شده  : $volumes گیگ
💰 مبلغ پرداختی : {$Payment_report['price']} تومان
👤 نام کاربری کانفیگ {$steppay[0]}
موجودی کاربر قبل خرید : {$Balance_id['Balance']}
";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
                'parse_mode' => "HTML"
            ]);
        }
    } elseif ($steppay[0] == "getextratimeuser") {
        $steppay = explode("%", $steppay[1]);
        $tmieextra = $steppay[1];
        $nameloc = select("invoice", "*", "username", $steppay[0], "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        $Balance_Low_user = 0;
        $inboundid = $marzban_list_get['inboundid'];
        if ($nameloc['inboundid'] != false) {
            $inboundid = $nameloc['inboundid'];
        }
        update("user", "Balance", $Balance_Low_user, "id", $nameloc['id_user']);
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $steppay[0]);
        $data_for_database = json_encode(array(
            'day' => $tmieextra,
            'old_volume' => $DataUserOut['data_limit'],
            'expire_old' => $DataUserOut['expire']
        ));
        $dateacc = date('Y/m/d H:i:s');
        $type = "extra_time_user";
        $timeservice = $DataUserOut['expire'] - time();
        $day = floor($timeservice / 86400);
        $extra_time = $ManagePanel->extra_time($nameloc['username'], $marzban_list_get['code_panel'], $tmieextra);
        if ($extra_time['status'] == false) {
            $extra_time['msg'] = json_encode($extra_time['msg']);
            $textreports = "خطای خرید حجم اضافه
نام پنل : {$marzban_list_get['name_panel']}
نام کاربری سرویس : {$nameloc['username']}
دلیل خطا : {$extra_time['msg']}";
            sendmessage($from_id, "❌خطایی در خرید حجم اضافه سرویس رخ داده با پشتیبانی در ارتباط باشید", null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO service_other (id_user, username,value,type,time,price,output) VALUES (:id_user,:username,:value,:type,:time,:price,:output)");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':username', $steppay[0]);
        $stmt->bindParam(':value', $data_for_database);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':time', $dateacc);
        $stmt->bindParam(':price', $Payment_report['price']);
        $stmt->bindValue(':output', json_encode($extra_time));
        $stmt->execute();
        $keyboardextrafnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['stateus']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        $volumesformat = number_format($Payment_report['price']);
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], "📌شما 1 امتیاز جدید کسب کردید.", null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $textextratime = "✅ افزایش زمان برای سرویس شما با موفقیت صورت گرفت
 
▫️نام سرویس : {$steppay[0]}
▫️زمان اضافه : $tmieextra روز

▫️مبلغ افزایش زمان : $volumesformat تومان";
        sendmessage($Balance_id['id'], $textextratime, $keyboardextrafnished, 'HTML');
        if ($Payment_report['Payment_Method'] == "cart to cart") {
            $volumes = $tmieextra;
            $textconfrom = "✅ پرداخت تایید شده
🔋 خرید زمان اضافه
🛍 زمان خریداری شده  : $volumes روز
👤 نام کاربری کانفیگ {$steppay[0]}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💎 موجودی قبل ازافزایش موجودی : {$Balance_id['Balance']}
💸 مبلغ پرداختی: $format_price_cart تومان
";
            if (!empty($from_id) && !empty($message_id)) {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        $text_report = "⭕️ یک کاربر زمان اضافه خریده است
        
اطلاعات کاربر : 
🪪 آیدی عددی : {$Balance_id['id']}
🛍 زمان خریداری شده  : $volumes روز
💰 مبلغ پرداختی : {$Payment_report['price']} تومان
👤 نام کاربری کانفیگ {$steppay[0]}";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
            ]);
        }
    } else {
        $Balance_confrim = intval($Balance_id['Balance']) + intval($Payment_report['price']);
        update("user", "Balance", $Balance_confrim, "id", $Payment_report['id_user']);
        update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);
        $Payment_report['price'] = number_format($Payment_report['price'], 0);
        $format_price_cart = $Payment_report['price'];
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            $textconfrom = "⭕️ یک پرداخت جدید انجام شده است
        افزایش موجودی.
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💸 مبلغ پرداختی: $format_price_cart تومان
💎 موجودی قبل ازافزایش موجودی : {$Balance_id['Balance']}
✍️ توضیحات : {$Payment_report['dec_not_confirmed']}";
            if (!empty($from_id) && !empty($message_id)) {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
        sendmessage($Payment_report['id_user'], "💎 کاربر گرامی مبلغ {$Payment_report['price']} تومان به کیف پول شما واریز گردید با تشکراز پرداخت شما.
                
🛒 کد پیگیری شما: {$Payment_report['id_order']}", null, 'HTML');
    }
}
function plisio($order_id, $price)
{
    $apinowpayments = select("PaySetting", "ValuePay", "NamePay", "apinowpayment", "select")['ValuePay'];
    $api_key = $apinowpayments;

    $url = 'https://api.plisio.net/api/v1/invoices/new';
    $url .= '?source_currency=USD';
    $url .= '&source_amount=' . urlencode($price);
    $url .= '&order_number=' . urlencode($order_id);
    $url .= '&email=customer@plisio.net';
    $url .= '&order_name=plisio';
    $url .= '&language=fa';
    $url .= '&api_key=' . urlencode($api_key);
    $ch = curl_init($url);
    curl_disable_proxy($ch);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = json_decode(curl_exec($ch), true);
    return $response['data'];
    curl_close($ch);
}
function checkConnection($address, $port)
{
    $socket = @stream_socket_client("tcp://$address:$port", $errno, $errstr, 5);
    if ($socket) {
        fclose($socket);
        return true;
    } else {
        return false;
    }
}
function savedata($type, $namefiled, $valuefiled)
{
    global $from_id;
    if ($type == "clear") {
        $datauser = [];
        $datauser[$namefiled] = $valuefiled;
        $data = json_encode($datauser);
        update("user", "Processing_value", $data, "id", $from_id);
    } elseif ($type == "save") {
        $userdata = select("user", "*", "id", $from_id, "select");
        $dataperevieos = json_decode($userdata['Processing_value'], true);
        $dataperevieos[$namefiled] = $valuefiled;
        update("user", "Processing_value", json_encode($dataperevieos), "id", $from_id);
    }
}
function addFieldToTable($tableName, $fieldName, $defaultValue = null, $datatype = "VARCHAR(500)")
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_name = :tableName");
    $stmt->bindParam(':tableName', $tableName);
    $stmt->execute();
    $tableExists = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tableExists['count'] == 0)
        return;
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$pdo->query("SELECT DATABASE()")->fetchColumn(), $tableName, $fieldName]);
    $filedExists = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($filedExists['count'] != 0)
        return;
    $query = "ALTER TABLE $tableName ADD $fieldName $datatype";
    $statement = $pdo->prepare($query);
    $statement->execute();
    if ($defaultValue != null) {
        $stmt = $pdo->prepare("UPDATE $tableName SET $fieldName= ?");
        $stmt->bindParam(1, $defaultValue);
        $stmt->execute();
    }
    echo "The $fieldName field was added ✅";
}

/**
 * Decode category/panel style agent JSON: {"f":"...","n":"...","n2":"..."}.
 */
function category_decode_agent_json(?string $json, string $default = '0'): array
{
    if (!$json) {
        return ['f' => $default, 'n' => $default, 'n2' => $default];
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return ['f' => $default, 'n' => $default, 'n2' => $default];
    }
    return [
        'f' => (string) ($d['f'] ?? $default),
        'n' => (string) ($d['n'] ?? $default),
        'n2' => (string) ($d['n2'] ?? $default),
    ];
}

function category_encode_agent_json(array $values): string
{
    return json_encode([
        'f' => (string) ($values['f'] ?? '0'),
        'n' => (string) ($values['n'] ?? '0'),
        'n2' => (string) ($values['n2'] ?? '0'),
    ], JSON_UNESCAPED_UNICODE);
}

function category_agent_field($category, string $field, string $agent, string $default = '0'): string
{
    if (!is_array($category) || !array_key_exists($field, $category) || $category[$field] === null) {
        return $default;
    }
    $decoded = category_decode_agent_json((string) $category[$field], $default);
    return (string) ($decoded[$agent] ?? $default);
}

/** Custom volume/time sell is enabled on this category for the given agent. */
function category_custom_enabled($category, string $agent, $panelType = null): bool
{
    if (!is_array($category)) {
        return false;
    }
    if ($panelType === 'Manualsale') {
        return false;
    }
    return category_agent_field($category, 'customvolume', $agent, '0') === '1';
}

/** Load category saved during buy flow (categorynames_*). */
function category_from_processing($userdate)
{
    if (!is_array($userdate) || empty($userdate['category_id'])) {
        return null;
    }
    $category = select("category", "*", "id", $userdate['category_id'], "select");
    return $category ?: null;
}
function outtypepanel($typepanel, $message)
{
    global $from_id, $optionMarzban, $optionX_ui_single, $optionhiddfy, $optionalireza, $optionalireza_single, $optionmarzneshin, $option_mikrotik, $optionwg, $options_ui, $optioneylanpanel, $optionibsng;
    if ($typepanel == "marzban") {
        sendmessage($from_id, $message, $optionMarzban, 'HTML');
    } elseif ($typepanel == "x-ui_single") {
        sendmessage($from_id, $message, $optionX_ui_single, 'HTML');
    } elseif ($typepanel == "hiddify") {
        sendmessage($from_id, $message, $optionhiddfy, 'HTML');
    } elseif ($typepanel == "alireza_single") {
        sendmessage($from_id, $message, $optionalireza_single, 'HTML');
    } elseif ($typepanel == "marzneshin") {
        sendmessage($from_id, $message, $optionmarzneshin, 'HTML');
    } elseif ($typepanel == "WGDashboard") {
        sendmessage($from_id, $message, $optionwg, 'HTML');
    } elseif ($typepanel == "s_ui") {
        sendmessage($from_id, $message, $options_ui, 'HTML');
    } elseif ($typepanel == "ibsng") {
        sendmessage($from_id, $message, $optionibsng, 'HTML');
    } elseif ($typepanel == "mikrotik") {
        sendmessage($from_id, $message, $option_mikrotik, 'HTML');
    }
}

function addBackgroundImage($urlimage, $qrCodeResult, $backgroundPath)
{
    if (!file_exists($backgroundPath)) {
        error_log("addBackgroundImage: File not found at $backgroundPath");
        file_put_contents($urlimage, $qrCodeResult->getString());
        return;
    }

    $qrString = $qrCodeResult->getString();
    $qrCodeImage = imagecreatefromstring($qrString);
    if (!$qrCodeImage) {
        error_log("addBackgroundImage: Failed to create QR Code resource");
        return;
    }

    $backgroundImage = null;

    try {
        $backgroundImage = imagecreatefromjpeg($backgroundPath);
    } catch (Throwable $t) {
        error_log("addBackgroundImage::EXCEPTION loading image: " . $t->getMessage());
    }

    if (!$backgroundImage) {
        $lastError = error_get_last();
        error_log("addBackgroundImage::System Error: " . $lastError['message']);

        imagepng($qrCodeImage, $urlimage);
        imagedestroy($qrCodeImage);
        return;
    }

    $qrCodeWidth = imagesx($qrCodeImage);
    $qrCodeHeight = imagesy($qrCodeImage);
    $backgroundWidth = imagesx($backgroundImage);
    $backgroundHeight = imagesy($backgroundImage);

    $x = ($backgroundWidth - $qrCodeWidth) / 2;
    $y = ($backgroundHeight - $qrCodeHeight) / 2;

    imagecopy($backgroundImage, $qrCodeImage, $x, $y, 0, 0, $qrCodeWidth, $qrCodeHeight);

    imagepng($backgroundImage, $urlimage);

    imagedestroy($qrCodeImage);
    imagedestroy($backgroundImage);
}

function resolveTelegramClientIp()
{
    $candidates = [];

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidates[] = $_SERVER['HTTP_X_REAL_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidates[] = trim($parts[0]);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = $_SERVER['REMOTE_ADDR'];
    }

    foreach ($candidates as $ip) {
        $ip = trim((string) $ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

function checktelegramip()
{
    $clientIp = resolveTelegramClientIp();
    if ($clientIp === '') {
        return false;
    }

    global $telegram_polling_mode;
    if (!empty($telegram_polling_mode) && in_array($clientIp, ['127.0.0.1', '::1'], true)) {
        return true;
    }

    $telegramIpRanges = [
        ['lower' => '149.154.160.0', 'upper' => '149.154.175.255'],
        ['lower' => '91.108.4.0', 'upper' => '91.108.7.255'],
        ['lower' => '2001:67c:4e8::', 'upper' => '2001:67c:4e8:ffff:ffff:ffff:ffff:ffff']
    ];

    foreach ($telegramIpRanges as $range) {
        if (isClientIpInRange($clientIp, $range['lower'], $range['upper'])) {
            return true;
        }
    }

    return false;
}

function isClientIpInRange($clientIp, $lowerBound, $upperBound)
{
    $clientPacked = inet_pton($clientIp);
    $lowerPacked = inet_pton($lowerBound);
    $upperPacked = inet_pton($upperBound);

    if ($clientPacked === false || $lowerPacked === false || $upperPacked === false) {
        return false;
    }

    $length = strlen($clientPacked);
    if ($length !== strlen($lowerPacked) || $length !== strlen($upperPacked)) {
        return false;
    }

    return strcmp($clientPacked, $lowerPacked) >= 0 && strcmp($clientPacked, $upperPacked) <= 0;
}
function addCronIfNotExists($cronCommand)
{
    $commands = is_array($cronCommand) ? $cronCommand : [$cronCommand];
    $commands = array_values(array_filter(array_map('trim', $commands), static function ($command) {
        return $command !== '';
    }));

    if (empty($commands)) {
        return true;
    }

    $logContext = implode('; ', $commands);

    if (!isShellExecAvailable()) {
        error_log('shell_exec is not available; unable to register cron job(s): ' . $logContext);
        return false;
    }

    $crontabBinary = getCrontabBinary();
    if ($crontabBinary === null) {
        error_log('crontab executable not found; unable to register cron job(s): ' . $logContext);
        return false;
    }

    $existingCronJobs = runShellCommand(sprintf('%s -l 2>/dev/null', escapeshellarg($crontabBinary)));
    $existingCronJobs = trim((string) $existingCronJobs);
    $cronLines = $existingCronJobs === '' ? [] : preg_split('/\r?\n/', $existingCronJobs);
    $cronLines = array_values(array_filter(array_map('trim', $cronLines), static function ($line) {
        return $line !== '' && strpos($line, '#') !== 0;
    }));

    $newLineAdded = false;
    foreach ($commands as $command) {
        if (!in_array($command, $cronLines, true)) {
            $cronLines[] = $command;
            $newLineAdded = true;
        }
    }

    if (!$newLineAdded) {
        return true;
    }

    $cronLines = array_values(array_unique($cronLines));
    $cronContent = implode(PHP_EOL, $cronLines) . PHP_EOL;

    $temporaryFile = tempnam(sys_get_temp_dir(), 'cron');
    if ($temporaryFile === false) {
        error_log('Unable to create temporary file for cron job registration.');
        return false;
    }

    if (file_put_contents($temporaryFile, $cronContent) === false) {
        error_log('Unable to write cron configuration to temporary file: ' . $temporaryFile);
        unlink($temporaryFile);
        return false;
    }

    runShellCommand(sprintf('%s %s', escapeshellarg($crontabBinary), escapeshellarg($temporaryFile)));
    unlink($temporaryFile);

    return true;
}

function activecron()
{
    global $domainhosts;

    $cronCommands = [
        "*/15 * * * * curl https://$domainhosts/cronbot/statusday.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/croncard.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/NoticationsService.php",
        "*/5 * * * * curl https://$domainhosts/cronbot/payment_expire.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/sendmessage.php",
        "*/3 * * * * curl https://$domainhosts/cronbot/plisio.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/activeconfig.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/disableconfig.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/iranpay1.php",
        "0 */5 * * * curl https://$domainhosts/cronbot/backupbot.php",
        "*/2 * * * * curl https://$domainhosts/cronbot/gift.php",
        "*/30 * * * * curl https://$domainhosts/cronbot/expireagent.php",
        "*/15 * * * * curl https://$domainhosts/cronbot/on_hold.php",
        "*/2 * * * * curl https://$domainhosts/cronbot/configtest.php",
        "*/15 * * * * curl https://$domainhosts/cronbot/uptime_node.php",
        "*/15 * * * * curl https://$domainhosts/cronbot/uptime_panel.php",
    ];

    addCronIfNotExists($cronCommands);
}
function createInvoice($amount)
{
    global $from_id, $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "apiiranpay", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];

    $curl = curl_init();
    curl_disable_proxy($curl);

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://pay.melorinabeauty.com/api/factor/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array('amount' => $amount, 'address' => $walletaddress, 'base' => 'trx'),
        CURLOPT_HTTPHEADER => array(
            'Authorization: Token ' . $PaySetting
        ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    return json_decode($response, true);
}
function verifpay($id)
{
    global $from_id, $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "apiiranpay", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://pay.melorinabeauty.ir/api/factor/status?id=' . $id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Token ' . $PaySetting
        ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    return $response;
}
function createInvoiceiranpay1($amount, $id_invoice)
{
    global $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "marchent_floypay", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);
    $amount = intval($amount);
    $data = [
        "ApiKey" => $PaySetting,
        "Hash_id" => $id_invoice,
        "Amount" => $amount . "0",
        "CallbackURL" => "https://$domainhosts/payment/iranpay1.php"
    ];
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://tetra98.com/api/create_order",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => array(
            'accept: application/json',
            'Content-Type: application/json'
        ),
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function createInvoiceTetraminator($price, $order_id)
{
    global $domainhosts, $tetraminator_api_key;
    $curl = curl_init();
    curl_disable_proxy($curl);
    $price = intval($price);
    $data = [
        "price" => $price,
        "callback_url" => "https://$domainhosts/payment/tetraminator.php?order_id=" . urlencode($order_id)
    ];
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.tetraminator.com/v1/invoice/create",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: ' . $tetraminator_api_key
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function inquireTetraminatorPayment($pay_id)
{
    global $tetraminator_api_key;
    $pay_id = rawurlencode($pay_id);
    $curl = curl_init();
    curl_disable_proxy($curl);
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.tetraminator.com/v1/payment/inquiry/" . $pay_id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'X-API-KEY: ' . $tetraminator_api_key
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function sanitizeUserName($userName)
{
    $forbiddenCharacters = [
        "'",
        "\"",
        "<",
        ">",
        "--",
        "#",
        ";",
        "\\",
        "%",
        "(",
        ")"
    ];

    foreach ($forbiddenCharacters as $char) {
        $userName = str_replace($char, "", $userName);
    }

    return $userName;
}
function publickey()
{
    $privateKey = sodium_crypto_box_keypair();
    $privateKeyEncoded = base64_encode(sodium_crypto_box_secretkey($privateKey));
    $publicKey = sodium_crypto_box_publickey($privateKey);
    $publicKeyEncoded = base64_encode($publicKey);
    $presharedKey = base64_encode(random_bytes(32));
    return [
        'private_key' => $privateKeyEncoded,
        'public_key' => $publicKeyEncoded,
        'preshared_key' => $presharedKey
    ];
}
function languagechange($path_dir)
{
    if (!is_string($path_dir) || $path_dir === '') {
        return [];
    }
    if ($path_dir[0] !== '/' && !preg_match('#^[A-Za-z]:[/\\\\]#', $path_dir)) {
        $path_dir = __DIR__ . '/' . ltrim($path_dir, './');
    }

    $raw = @file_get_contents($path_dir);
    if ($raw === false) {
        error_log('languagechange: cannot read ' . $path_dir);
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_log('languagechange: invalid JSON in ' . $path_dir);
        return [];
    }

    $setting = select("setting", "*");
    if (intval($setting['languageen'] ?? 0) === 1 && isset($decoded['en'])) {
        return $decoded['en'];
    }
    if (intval($setting['languageru'] ?? 0) === 1 && isset($decoded['ru'])) {
        return $decoded['ru'];
    }
    return $decoded['fa'] ?? $decoded['en'] ?? [];
}
function generateAuthStr($length = 10)
{
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    return substr(str_shuffle(str_repeat($characters, ceil($length / strlen($characters)))), 0, $length);
}
function createqrcode($contents)
{
    $builder = new Builder(
        writer: new PngWriter(),
        writerOptions: [],
        data: $contents,
        encoding: new Encoding('UTF-8'),
        errorCorrectionLevel: ErrorCorrectionLevel::High,
        size: 500,
        margin: 10,
    );

    $result = $builder->build();
    return $result;
}
function sanitize_recursive(array $data): array
{
    $sanitized_data = [];
    foreach ($data as $key => $value) {
        $sanitized_key = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        if (is_array($value)) {
            $sanitized_data[$sanitized_key] = sanitize_recursive($value);
        } elseif (is_string($value)) {
            $sanitized_data[$sanitized_key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        } elseif (is_int($value)) {
            $sanitized_data[$sanitized_key] = filter_var($value, FILTER_SANITIZE_NUMBER_INT);
        } elseif (is_float($value)) {
            $sanitized_data[$sanitized_key] = filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        } elseif (is_bool($value) || is_null($value)) {
            $sanitized_data[$sanitized_key] = $value;
        } else {
            $sanitized_data[$sanitized_key] = $value;
        }
    }
    return $sanitized_data;
}

function get_main_keyboard_button_ids()
{
    $ids = [];
    foreach (get_default_main_keyboard_layout() as $row) {
        $ids = array_merge($ids, $row);
    }
    return array_values(array_unique($ids));
}

function keyboardmain_label_to_id_map($datatextbot = null)
{
    if (!is_array($datatextbot)) {
        $datatextbot = [];
        foreach (select("textbot", "*", null, null, "fetchAll") as $row) {
            $datatextbot[$row['id_text']] = $row['text'];
        }
    }
    $map = [];
    foreach (get_main_keyboard_button_ids() as $id) {
        $map[$id] = $id;
        if (!empty($datatextbot[$id])) {
            $map[$datatextbot[$id]] = $id;
        }
    }
    return $map;
}

function resolve_main_keyboard_button_id($text, $datatextbot = null)
{
    if ($text === '' || $text === null) {
        return null;
    }
    $map = keyboardmain_label_to_id_map($datatextbot);
    return $map[$text] ?? null;
}

function normalize_keyboardmain_to_ids($keyboardmain_json, $datatextbot = null)
{
    $layout = json_decode($keyboardmain_json, true);
    if (!is_array($layout) || empty($layout['keyboard']) || !is_array($layout['keyboard'])) {
        return get_default_main_keyboard_json();
    }
    $active = [];
    foreach ($layout['keyboard'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($row as $btn) {
            $id = resolve_main_keyboard_button_id($btn['text'] ?? '', $datatextbot);
            if ($id !== null) {
                $active[] = $id;
            }
        }
    }
    $active = array_values(array_unique($active));
    if ($active === []) {
        return get_default_main_keyboard_json();
    }
    return build_keyboardmain_from_active_buttons($active);
}

function check_active_btn($keyboard, $text_var, $datatextbot = null)
{
    $active = get_active_main_keyboard_buttons($keyboard, $datatextbot);
    return in_array($text_var, $active, true);
}

function get_default_main_keyboard_layout()
{
    return [
        ['text_sell', 'text_extend'],
        ['text_usertest', 'text_wheel_luck'],
        ['text_Purchased_services', 'accountwallet'],
        ['text_affiliates', 'text_Tariff_list'],
        ['text_referral', 'text_support'],
        ['text_help'],
    ];
}

function get_default_main_keyboard_json()
{
    $keyboard = ['keyboard' => []];
    foreach (get_default_main_keyboard_layout() as $row) {
        $row_buttons = [];
        foreach ($row as $btn) {
            $row_buttons[] = ['text' => $btn];
        }
        $keyboard['keyboard'][] = $row_buttons;
    }
    return json_encode($keyboard);
}

function get_active_main_keyboard_buttons($keyboardmain_json, $datatextbot = null)
{
    $layout = json_decode($keyboardmain_json, true);
    $active = [];
    if (!empty($layout['keyboard']) && is_array($layout['keyboard'])) {
        foreach ($layout['keyboard'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $btn) {
                $id = resolve_main_keyboard_button_id($btn['text'] ?? '', $datatextbot);
                if ($id !== null) {
                    $active[] = $id;
                }
            }
        }
    }
    return array_values(array_unique($active));
}

function build_keyboardmain_from_active_buttons($active_buttons)
{
    $keyboard = ['keyboard' => []];
    foreach (get_default_main_keyboard_layout() as $row) {
        $row_buttons = [];
        foreach ($row as $btn) {
            if (in_array($btn, $active_buttons, true)) {
                $row_buttons[] = ['text' => $btn];
            }
        }
        if (!empty($row_buttons)) {
            $keyboard['keyboard'][] = $row_buttons;
        }
    }
    return json_encode($keyboard);
}

function get_main_keyboard_button_fallback_labels()
{
    return [
        'text_sell' => '🔐 خرید اشتراک',
        'text_extend' => '♻️ تمدید سرویس',
        'text_usertest' => '🔑 اکانت تست',
        'text_wheel_luck' => '🎲 گردونه شانس',
        'text_Purchased_services' => '🛍 سرویس های من',
        'accountwallet' => '🏦 کیف پول + شارژ',
        'text_affiliates' => '👥 زیر مجموعه گیری',
        'text_referral' => '🎁 دعوت دوستان',
        'text_Tariff_list' => '💵 تعرفه اشتراک ها',
        'text_support' => '☎️ پشتیبانی',
        'text_help' => '📚 آموزش',
    ];
}

function is_main_keyboard_internal_id($text)
{
    return in_array($text, get_main_keyboard_button_ids(), true);
}

function get_main_keyboard_button_label($button_id, $datatextbot)
{
    $fallbacks = get_main_keyboard_button_fallback_labels();
    $fallback = $fallbacks[$button_id] ?? $button_id;

    if (!is_array($datatextbot) || empty($datatextbot[$button_id])) {
        return $fallback;
    }

    $label = trim((string) $datatextbot[$button_id]);
    if ($label === '' || is_main_keyboard_internal_id($label)) {
        return $fallback;
    }

    // Menu buttons must stay short; long/multi-line textbot entries are message copy, not labels.
    if (str_contains($label, "\n") || mb_strlen($label) > 32) {
        return $fallback;
    }

    return $label;
}

function user_text_matches_main_button($text, $button_id, $datatextbot)
{
    if ($text === '' || $text === null) {
        return false;
    }

    $candidates = [
        $button_id,
        get_main_keyboard_button_label($button_id, $datatextbot),
        get_main_keyboard_button_fallback_labels()[$button_id] ?? '',
    ];

    if (is_array($datatextbot) && !empty($datatextbot[$button_id])) {
        $raw = trim((string) $datatextbot[$button_id]);
        if ($raw !== '') {
            $candidates[] = $raw;
            $first_line = trim(strtok($raw, "\n"));
            if ($first_line !== '') {
                $candidates[] = $first_line;
            }
        }
    }

    $candidates = array_values(array_unique(array_filter($candidates, static function ($value) {
        return $value !== '';
    })));

    return in_array($text, $candidates, true);
}

function attach_main_keyboard_inline_callbacks($keyboard_rows)
{
    $callback_map = [
        'text_sell' => 'buy',
        'accountwallet' => 'account',
        'text_Tariff_list' => 'Tariff_list',
        'text_wheel_luck' => 'wheel_luck',
        'text_affiliates' => 'affiliatesbtn',
        'text_referral' => 'referralbtn',
        'text_extend' => 'extendbtn',
        'text_support' => 'supportbtns',
        'text_Purchased_services' => 'backorder',
        'text_help' => 'helpbtns',
        'text_usertest' => 'usertestbtn',
    ];
    $rows = [];
    foreach ($keyboard_rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $new_row = [];
        foreach ($row as $button) {
            if (!is_array($button)) {
                continue;
            }
            $new_button = $button;
            $button_id = $button['text'] ?? '';
            if (isset($callback_map[$button_id])) {
                $new_button['callback_data'] = $callback_map[$button_id];
            }
            $new_row[] = $new_button;
        }
        if ($new_row !== []) {
            $rows[] = $new_row;
        }
    }
    return $rows;
}

function apply_main_keyboard_button_labels($keyboard_rows, $datatextbot)
{
    $labeled = [];
    foreach ($keyboard_rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $labeled_row = [];
        foreach ($row as $button) {
            if (!is_array($button)) {
                continue;
            }
            $button_id = resolve_main_keyboard_button_id($button['text'] ?? '', $datatextbot);
            if ($button_id === null) {
                continue;
            }
            $label = get_main_keyboard_button_label($button_id, $datatextbot);
            if ($label === '') {
                continue;
            }
            $new_button = $button;
            $new_button['text'] = $label;
            $labeled_row[] = $new_button;
        }
        if ($labeled_row !== []) {
            $labeled[] = $labeled_row;
        }
    }
    return $labeled;
}

function build_user_main_keyboard_markup($setting, $datatextbot, $textbotlang, $from_id, array $options = [])
{
    $persist = $options['persist'] ?? true;
    $users = $options['users'] ?? select('user', '*', 'id', $from_id, 'select');
    if ($users === false || $users === null) {
        $users = [
            'agent' => '',
            'step' => '',
        ];
    }
    $admin_idss = $options['admin_idss'] ?? select('admin', '*', 'id_admin', $from_id, 'count');

    $keyboardmain = normalize_keyboardmain_to_ids($setting['keyboardmain'] ?? '', $datatextbot);
    if ($persist && $keyboardmain !== ($setting['keyboardmain'] ?? '')) {
        update('setting', 'keyboardmain', $keyboardmain, null, null);
        $setting['keyboardmain'] = $keyboardmain;
    }

    $layout = json_decode($keyboardmain, true);
    $keyboard_rows = [];
    if (is_array($layout) && !empty($layout['keyboard']) && is_array($layout['keyboard'])) {
        $keyboard_rows = $layout['keyboard'];
    }
    if ($keyboard_rows === []) {
        $keyboard_rows = json_decode(get_default_main_keyboard_json(), true)['keyboard'] ?? [];
    }

    $inline = ($setting['inlinebtnmain'] ?? '') === 'oninline';
    $extra_row = [];
    if (intval($admin_idss) !== 0) {
        $extra_button = ['text' => $textbotlang['Admin']['textpaneladmin']];
        if ($inline) {
            $extra_button['callback_data'] = 'admin';
        }
        $extra_row[] = $extra_button;
    }
    if (($users['agent'] ?? '') !== 'f') {
        $extra_button = ['text' => $datatextbot['textpanelagent'] ?? 'نمایندگی'];
        if ($inline) {
            $extra_button['callback_data'] = 'agentpanel';
        }
        $extra_row[] = $extra_button;
    }
    if (($users['agent'] ?? '') === 'f' && ($setting['statusagentrequest'] ?? '') === 'onrequestagent') {
        $extra_button = ['text' => $datatextbot['textrequestagent'] ?? 'درخواست نمایندگی'];
        if ($inline) {
            $extra_button['callback_data'] = 'requestagent';
        }
        $extra_row[] = $extra_button;
    }

    if ($inline) {
        $keyboard_rows = attach_main_keyboard_inline_callbacks($keyboard_rows);
        $keyboardcustom = apply_main_keyboard_button_labels($keyboard_rows, $datatextbot);
        if ($keyboardcustom === []) {
            $keyboard_rows = json_decode(get_default_main_keyboard_json(), true)['keyboard'] ?? [];
            $keyboard_rows = attach_main_keyboard_inline_callbacks($keyboard_rows);
            $keyboardcustom = apply_main_keyboard_button_labels($keyboard_rows, $datatextbot);
        }
        if ($extra_row !== []) {
            $keyboardcustom[] = $extra_row;
        }
        return json_encode(['inline_keyboard' => $keyboardcustom], JSON_UNESCAPED_UNICODE);
    }

    $keyboardcustom = apply_main_keyboard_button_labels($keyboard_rows, $datatextbot);
    if ($keyboardcustom === []) {
        $keyboard_rows = json_decode(get_default_main_keyboard_json(), true)['keyboard'] ?? [];
        $keyboardcustom = apply_main_keyboard_button_labels($keyboard_rows, $datatextbot);
    }
    if ($extra_row !== []) {
        $keyboardcustom[] = $extra_row;
    }

    return json_encode([
        'keyboard' => $keyboardcustom,
        'resize_keyboard' => true,
    ], JSON_UNESCAPED_UNICODE);
}

function toggle_main_keyboard_button($keyboardmain_json, $button_id, $datatextbot = null)
{
    $allowed = get_main_keyboard_button_ids();
    if (!in_array($button_id, $allowed, true)) {
        return $keyboardmain_json;
    }
    $keyboardmain_json = normalize_keyboardmain_to_ids($keyboardmain_json, $datatextbot);
    $active = get_active_main_keyboard_buttons($keyboardmain_json, $datatextbot);
    if (in_array($button_id, $active, true)) {
        $active = array_values(array_diff($active, [$button_id]));
    } else {
        $active[] = $button_id;
    }
    return build_keyboardmain_from_active_buttons($active);
}

function build_main_keyboard_admin_markup($datatextbot, $keyboardmain_json)
{
    global $textbotlang;
    $rows = [];
    foreach (get_default_main_keyboard_layout() as $row) {
        $inline_row = [];
        foreach ($row as $btn_id) {
            $label = $datatextbot[$btn_id] ?? $btn_id;
            $status = check_active_btn($keyboardmain_json, $btn_id, $datatextbot)
                ? $textbotlang['Admin']['Status']['statuson']
                : $textbotlang['Admin']['Status']['statusoff'];
            $inline_row[] = [
                'text' => "$status $label",
                'callback_data' => "togglemainbtn-$btn_id",
            ];
        }
        if (!empty($inline_row)) {
            $rows[] = $inline_row;
        }
    }
    $rows[] = [
        ['text' => "♻️ بازنشانی پیش‌فرض", 'callback_data' => 'resetmainbtn'],
    ];
    return json_encode(['inline_keyboard' => $rows]);
}
function deleteFolder($folderPath)
{
    if (!is_dir($folderPath))
        return false;

    $files = array_diff(scandir($folderPath), ['.', '..']);

    foreach ($files as $file) {
        $filePath = $folderPath . DIRECTORY_SEPARATOR . $file;
        if (is_dir($filePath)) {
            deleteFolder($filePath);
        } else {
            unlink($filePath);
        }
    }

    return rmdir($folderPath);
}
function isBase64($string)
{
    if (base64_encode(base64_decode($string, true)) === $string) {
        return true;
    }
    return false;
}
function sendMessageService($panel_info, $config, $sub_link, $username_service, $reply_markup, $caption, $invoice_id, $user_id = null, $image = 'images.jpg')
{
    global $setting, $from_id;
    if (!check_active_btn($setting['keyboardmain'], "text_help"))
        $reply_markup = null;
    $user_id = $user_id == null ? $from_id : $user_id;
    $STATUS_SEND_MESSAGE_PHOTO = $panel_info['config'] == "onconfig" && count($config) != 1 ? false : true;
    $out_put_qrcode = "";
    if ($panel_info['type'] == "Manualsale" || $panel_info['type'] == "ibsng" || $panel_info['type'] == "mikrotik") {
    }
    if ($panel_info['sublink'] == "onsublink" && $panel_info['config']) {
        $out_put_qrcode = $sub_link;
    } elseif ($panel_info['sublink'] == "onsublink") {
        $out_put_qrcode = $sub_link;
    } elseif ($panel_info['config'] == "onconfig") {
        $out_put_qrcode = $config[0];
    }
    if ($STATUS_SEND_MESSAGE_PHOTO) {
        if ($panel_info['type'] == "WGDashboard") {
            $urlimage = "{$panel_info['inboundid']}_{$invoice_id}.conf";
            file_put_contents($urlimage, $sub_link);
            telegram('senddocument', [
                'chat_id' => $user_id,
                'document' => new CURLFile($urlimage),
                'reply_markup' => $reply_markup,
                'caption' => $caption,
                'parse_mode' => "HTML",
            ]);
            unlink($urlimage);
        } else {
            $urlimage = "$user_id$invoice_id.png";
            $qrCode = createqrcode($out_put_qrcode);
            file_put_contents($urlimage, $qrCode->getString());
            addBackgroundImage($urlimage, $qrCode, $image);
            telegram('sendphoto', [
                'chat_id' => $user_id,
                'photo' => new CURLFile($urlimage),
                'reply_markup' => $reply_markup,
                'caption' => $caption,
                'parse_mode' => "HTML",
            ]);
            unlink($urlimage);
        }
    } else {
        sendmessage($user_id, $caption, $reply_markup, 'HTML');
    }
    if ($panel_info['config'] == "onconfig" && $setting['status_keyboard_config'] == "1") {
        if (is_array($config)) {
            sendmessage($user_id, "📌 جهت دریافت کانفیگ روی دکمه دریافت کانفیگ کلیک کنید", keyboard_config($config, $invoice_id, false), 'HTML');
        }
    }
    // Keep the latest delivered sub link so "subscription link" button can fallback reliably.
    if (is_string($sub_link) && trim($sub_link) !== '') {
        update("invoice", "user_info", trim($sub_link), "id_invoice", $invoice_id);
    }
}
function isValidInvitationCode($setting, $fromId, $verfy_status)
{

    if ($setting['verifybucodeuser'] == "onverify" && $verfy_status != 1) {
        sendmessage($fromId, "حساب کاربری شما با موفقیت احرازهویت گردید", null, 'html');
        update("user", "verify", "1", "id", $fromId);
        update("user", "cardpayment", "1", "id", $fromId);
    }
}
function createPayZarinpal($price, $order_id)
{
    global $domainhosts;
    $marchent_zarinpal = select("PaySetting", "ValuePay", "NamePay", "merchant_zarinpal", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.zarinpal.com/pg/v4/payment/request.json',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        "merchant_id" => $marchent_zarinpal,
        "currency" => "IRT",
        "amount" => $price,
        "callback_url" => "https://$domainhosts/payment/zarinpal.php",
        "description" => $order_id,
        "metadata" => array(
            "order_id" => $order_id
        )
    ]));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function createPayaqayepardakht($price, $order_id)
{
    global $domainhosts;
    $merchant_aqayepardakht = select("PaySetting", "ValuePay", "NamePay", "merchant_id_aqayepardakht", "select")['ValuePay'];
    $curl = curl_init();
    curl_disable_proxy($curl);
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://panel.aqayepardakht.ir/api/v2/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'pin' => $merchant_aqayepardakht,
        'amount' => $price,
        'callback' => $domainhosts . "/payment/aqayepardakht.php",
        'invoice_id' => $order_id,
    ]));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function parseConfigs($input)
{
    $lines = explode("\n", $input);
    $configs = [];

    $currentName = null;
    $currentData = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if (strpos($line, '#') === 0) {
            if ($currentName && $currentData) {
                $configs[] = [
                    'name' => $currentName,
                    'config' => implode("\n", $currentData)
                ];
            }
            $currentName = trim(substr($line, 1));
            $currentData = [];
        } else {
            if ($line !== '') {
                $currentData[] = $line;
            }
        }
    }
    if ($currentName && $currentData) {
        $configs[] = [
            'name' => $currentName,
            'config' => implode("\n", $currentData)
        ];
    }

    return $configs;
}

function referral_ensure_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    global $pdo;

    if (!($pdo instanceof PDO)) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_campaign (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(32) NOT NULL,
        title VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        code_product VARCHAR(100) NOT NULL,
        panel_name VARCHAR(255) NOT NULL,
        required_invites INT UNSIGNED NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'inactive',
        new_users_only TINYINT(1) NOT NULL DEFAULT 1,
        created_at VARCHAR(50) NOT NULL,
        UNIQUE KEY uniq_referral_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_invite (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT UNSIGNED NOT NULL,
        referrer_id BIGINT NOT NULL,
        invited_user_id BIGINT NOT NULL,
        created_at VARCHAR(50) NOT NULL,
        UNIQUE KEY uniq_campaign_invited (campaign_id, invited_user_id),
        KEY idx_campaign_referrer (campaign_id, referrer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_reward (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT UNSIGNED NOT NULL,
        user_id BIGINT NOT NULL,
        id_invoice VARCHAR(100) NOT NULL,
        granted_at VARCHAR(50) NOT NULL,
        UNIQUE KEY uniq_campaign_user_reward (campaign_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

    addFieldToTable('setting', 'referralstatus', 'offreferral', 'VARCHAR(200)');

    $stmt = $pdo->prepare("INSERT IGNORE INTO textbot (id_text, text) VALUES ('text_referral', ?)");
    $stmt->execute(['🎁 دعوت دوستان']);

    $ready = true;
}

function referral_get_campaign_by_code($code)
{
    referral_ensure_schema();
    return select("referral_campaign", "*", "code", $code, "select");
}

function referral_get_campaign_by_id($id)
{
    referral_ensure_schema();
    return select("referral_campaign", "*", "id", $id, "select");
}

function referral_get_active_campaigns()
{
    referral_ensure_schema();
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM referral_campaign WHERE status = 'active' ORDER BY id DESC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function referral_count_invites($campaign_id, $referrer_id)
{
    referral_ensure_schema();
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referral_invite WHERE campaign_id = ? AND referrer_id = ?");
    $stmt->execute([(int) $campaign_id, (string) $referrer_id]);
    return (int) $stmt->fetchColumn();
}

function referral_has_reward($campaign_id, $user_id)
{
    referral_ensure_schema();
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM referral_reward WHERE campaign_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([(int) $campaign_id, (string) $user_id]);
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

/** Each user's referral identifier is their Telegram numeric ID (auto, no manual code). */
function referral_get_user_code($user_id)
{
    return (string) $user_id;
}

function referral_resolve_campaign($campaign_key)
{
    if (ctype_digit((string) $campaign_key)) {
        return referral_get_campaign_by_id((int) $campaign_key);
    }
    return referral_get_campaign_by_code($campaign_key);
}

function referral_build_link($campaign_id, $referrer_id)
{
    global $usernamebot;
    $campaign_id = (int) $campaign_id;
    $referrer_id = referral_get_user_code($referrer_id);
    return "https://t.me/$usernamebot?start=ref_{$campaign_id}_{$referrer_id}";
}

function referral_validate_campaign_code($code)
{
    return (bool) preg_match('/^[A-Za-z0-9]{2,20}$/', (string) $code);
}

function referral_auto_campaign_code($campaign_id)
{
    return 'REF' . (int) $campaign_id;
}

function provision_free_service($user_id, $product, $panel, $note = 'referral_reward')
{
    global $pdo, $connect, $textbotlang, $setting, $admin_ids, $errorreport, $datatextbot;

    if (!is_array($product) || !is_array($panel)) {
        return ['ok' => false, 'invoice_id' => null, 'msg' => 'invalid product or panel'];
    }

    if (!class_exists('ManagePanel')) {
        require_once __DIR__ . '/panels.php';
    }
    $ManagePanel = new ManagePanel();

    $user_info = select("user", "*", "id", $user_id, "select");
    if (!$user_info) {
        return ['ok' => false, 'invoice_id' => null, 'msg' => 'user not found'];
    }

    $usernameinvoice = select("invoice", "username", null, null, "FETCH_COLUMN");
    if (!is_array($usernameinvoice)) {
        $usernameinvoice = [];
    }

    $randomString = bin2hex(random_bytes(4));
    $username_ac = generateUsername(
        $user_info['id'],
        $panel['MethodUsername'],
        $user_info['username'],
        $randomString,
        '',
        $panel['namecustom'],
        $user_info['namecustom']
    );
    $username_ac = strtolower((string) $username_ac);

    $DataUserOut = $ManagePanel->DataUser($panel['name_panel'], $username_ac);
    if (isset($DataUserOut['username']) || in_array($username_ac, $usernameinvoice, true)) {
        return ['ok' => false, 'invoice_id' => null, 'msg' => 'username exists'];
    }

    $notifctions = json_encode(['volume' => false, 'time' => false]);
    $date = time();
    $Status = "active";
    $price = 0;
    $stmt = $connect->prepare("INSERT IGNORE INTO invoice (id_user, id_invoice, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status, note, refral, notifctions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $refral = '0';
    $stmt->bind_param(
        "sssssssssssss",
        $user_id,
        $randomString,
        $username_ac,
        $date,
        $panel['name_panel'],
        $product['name_product'],
        $price,
        $product['Volume_constraint'],
        $product['Service_time'],
        $Status,
        $note,
        $refral,
        $notifctions
    );
    $stmt->execute();
    $stmt->close();

    $datetimestep = strtotime("+" . $product['Service_time'] . "days");
    if (intval($product['Service_time']) == 0) {
        $datetimestep = 0;
    } else {
        $datetimestep = strtotime(date("Y-m-d H:i:s", $datetimestep));
    }

    $datac = [
        'expire' => $datetimestep,
        'data_limit' => intval($product['Volume_constraint']) * pow(1024, 3),
        'from_id' => $user_id,
        'username' => $user_info['username'],
        'type' => 'buy',
    ];

    $dataoutput = $ManagePanel->createUser($panel['name_panel'], $product['code_product'], $username_ac, $datac);
    if (!isset($dataoutput['username']) || $dataoutput['username'] === null || $dataoutput['username'] === '') {
        $errorMessage = $dataoutput['msg'] ?? 'unknown error';
        if (is_array($errorMessage) || is_object($errorMessage)) {
            $errorMessage = json_encode($errorMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (strlen($setting['Channel_Report'] ?? '') > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $errorreport ?? 0,
                'text' => "⭕️ خطای ساخت هدیه دعوت\n{$errorMessage}\nکاربر: {$user_id}",
                'parse_mode' => "HTML",
            ]);
        }
        return ['ok' => false, 'invoice_id' => $randomString, 'msg' => (string) $errorMessage];
    }

    update("invoice", "Status", "active", "username", $username_ac);

    $output_config_link = $panel['sublink'] == "onsublink" ? ($dataoutput['subscription_url'] ?? '') : "";
    $config = "";
    if ($panel['config'] == "onconfig" && is_array($dataoutput['configs'] ?? null)) {
        foreach ($dataoutput['configs'] as $link) {
            $config .= "\n" . $link;
        }
    }

    if (!is_array($datatextbot)) {
        $datatextbot = $pdo->query("SELECT id_text, text FROM textbot")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    $textafterpay = $datatextbot['textafterpay'] ?? '';
    $textafterpay = $panel['type'] == "Manualsale" ? ($datatextbot['textmanual'] ?? $textafterpay) : $textafterpay;
    $textafterpay = $panel['type'] == "WGDashboard" ? ($datatextbot['text_wgdashboard'] ?? $textafterpay) : $textafterpay;
    $textafterpay = ($panel['type'] == "ibsng" || $panel['type'] == "mikrotik") ? ($datatextbot['textafterpayibsng'] ?? $textafterpay) : $textafterpay;

    $service_time = $product['Service_time'];
    $volume = $product['Volume_constraint'];
    if (intval($service_time) == 0) {
        $service_time = $textbotlang['users']['stateus']['Unlimited'] ?? 'نامحدود';
    }
    if (intval($volume) == 0) {
        $volume = $textbotlang['users']['stateus']['Unlimited'] ?? 'نامحدود';
    }

    $textcreatuser = str_replace('{username}', "<code>{$dataoutput['username']}</code>", $textafterpay);
    $textcreatuser = str_replace('{name_service}', $product['name_product'], $textcreatuser);
    $textcreatuser = str_replace('{location}', $panel['name_panel'], $textcreatuser);
    $textcreatuser = str_replace('{day}', $service_time, $textcreatuser);
    $textcreatuser = str_replace('{volume}', $volume, $textcreatuser);
    $textcreatuser = str_replace('{config}', "<code>{$output_config_link}</code>", $textcreatuser);
    $textcreatuser = str_replace('{links}', $config, $textcreatuser);
    $textcreatuser = str_replace('{links2}', $output_config_link, $textcreatuser);
    if (intval($product['Volume_constraint']) == 0) {
        $textcreatuser = str_replace('گیگابایت', "", $textcreatuser);
    }
    if (in_array($panel['type'], ['Manualsale', 'ibsng', 'mikrotik'], true)) {
        $textcreatuser = str_replace('{password}', $dataoutput['subscription_url'] ?? '', $textcreatuser);
        update("invoice", "user_info", $dataoutput['subscription_url'] ?? '', "id_invoice", $randomString);
    }

    $Shoppinginfo = json_encode([
        'inline_keyboard' => [
            [['text' => $textbotlang['users']['help']['btninlinebuy'] ?? 'راهنما', 'callback_data' => "helpbtn"]],
        ],
    ]);

    sendMessageService($panel, $dataoutput['configs'] ?? [], $output_config_link, $dataoutput['username'], $Shoppinginfo, $textcreatuser, $randomString, $user_id);

    if ($panel['MethodUsername'] == "متن دلخواه + عدد ترتیبی" || $panel['MethodUsername'] == "نام کاربری + عدد به ترتیب" || $panel['MethodUsername'] == "آیدی عددی+عدد ترتیبی" || $panel['MethodUsername'] == "متن دلخواه نماینده + عدد ترتیبی") {
        $value = intval($user_info['number_username']) + 1;
        update("user", "number_username", $value, "id", $user_id);
        if ($panel['MethodUsername'] == "متن دلخواه + عدد ترتیبی" || $panel['MethodUsername'] == "متن دلخواه نماینده + عدد ترتیبی") {
            $value = intval($setting['numbercount']) + 1;
            update("setting", "numbercount", $value);
        }
    }

    return ['ok' => true, 'invoice_id' => $randomString, 'msg' => 'ok', 'username' => $dataoutput['username']];
}

function referral_check_and_grant_reward($campaign, $referrer_id)
{
    global $setting, $pdo, $buyreport, $usernamebot;

    if (!is_array($campaign)) {
        return false;
    }

    $campaign_id = (int) $campaign['id'];
    if (referral_has_reward($campaign_id, $referrer_id)) {
        return false;
    }

    $invite_count = referral_count_invites($campaign_id, $referrer_id);
    if ($invite_count < (int) $campaign['required_invites']) {
        return false;
    }

    $product = select("product", "*", "code_product", $campaign['code_product'], "select");
    $panel = select("marzban_panel", "*", "name_panel", $campaign['panel_name'], "select");
    if (!$product || !$panel) {
        return false;
    }

    $result = provision_free_service($referrer_id, $product, $panel, 'referral_reward_' . $campaign['code']);
    if (!$result['ok']) {
        return false;
    }

    $granted_at = date('Y/m/d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO referral_reward (campaign_id, user_id, id_invoice, granted_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$campaign_id, (string) $referrer_id, $result['invoice_id'], $granted_at]);

    $reward_text = "<b>🎉 تبریک! هدیه دعوت دریافت شد</b>\n\n";
    $reward_text .= "کمپین: <b>{$campaign['title']}</b>\n";
    $reward_text .= "سرویس: <b>{$product['name_product']}</b>\n";
    $reward_text .= "نام کاربری: <code>{$result['username']}</code>";
    sendmessage($referrer_id, $reward_text, null, 'HTML');

    if (strlen($setting['Channel_Report'] ?? '') > 0) {
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $buyreport ?? 0,
            'text' => "🎁 هدیه دعوت\nکمپین: {$campaign['title']}\nکاربر: {$referrer_id}\nسرویس: {$product['name_product']}",
            'parse_mode' => "HTML",
        ]);
    }

    return true;
}

function handle_referral_start($campaign_key, $referrer_id, $invited_user_id, $was_new_user, $invited_username = '')
{
    global $setting, $pdo, $users_ids, $keyboard;

    $referrer_id = referral_get_user_code($referrer_id);
    $campaign = referral_resolve_campaign($campaign_key);
    if (!$campaign || ($campaign['status'] ?? '') !== 'active') {
        return ['ok' => false, 'reason' => 'inactive'];
    }
    if (($setting['referralstatus'] ?? 'offreferral') !== 'onreferral') {
        return ['ok' => false, 'reason' => 'disabled'];
    }
    if ((string) $referrer_id === (string) $invited_user_id) {
        sendmessage($invited_user_id, "❌ نمی‌توانید از لینک دعوت خودتان استفاده کنید.", null, 'HTML');
        return ['ok' => false, 'reason' => 'self'];
    }
    if (!in_array((string) $referrer_id, array_map('strval', $users_ids), true)) {
        return ['ok' => false, 'reason' => 'invalid_referrer'];
    }
    if (intval($campaign['new_users_only'] ?? 1) === 1 && !$was_new_user) {
        sendmessage($invited_user_id, "❌ این لینک دعوت فقط برای کاربران جدید معتبر است.", $keyboard, 'HTML');
        return ['ok' => false, 'reason' => 'not_new_user'];
    }

    $stmt = $pdo->prepare("SELECT id FROM referral_invite WHERE campaign_id = ? AND invited_user_id = ? LIMIT 1");
    $stmt->execute([(int) $campaign['id'], (string) $invited_user_id]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return ['ok' => false, 'reason' => 'already_invited'];
    }

    $created_at = date('Y/m/d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO referral_invite (campaign_id, referrer_id, invited_user_id, created_at) VALUES (?, ?, ?, ?)");
    try {
        $stmt->execute([(int) $campaign['id'], (string) $referrer_id, (string) $invited_user_id, $created_at]);
    } catch (Exception $e) {
        return ['ok' => false, 'reason' => 'duplicate'];
    }

    $referrer = select("user", "*", "id", $referrer_id, "select");
    $referrer_name = '';
    $chat = telegram('getChat', ['chat_id' => $referrer_id]);
    if (!empty($chat['ok']) && !empty($chat['result']['first_name'])) {
        $referrer_name = sanitizeUserName($chat['result']['first_name']);
        if (!empty($chat['result']['last_name'])) {
            $referrer_name .= ' ' . sanitizeUserName($chat['result']['last_name']);
        }
    }
    if ($referrer_name === '') {
        $referrer_name = $referrer['namecustom'] ?? '';
        if ($referrer_name === '' || $referrer_name === 'none') {
            $referrer_name = 'یک کاربر';
        }
    }
    sendmessage($invited_user_id, "<b>🎉 خوش آمدید!</b>\n\nشما با دعوت <b>{$referrer_name}</b> وارد ربات شدید.", $keyboard, 'HTML');

    $invite_count = referral_count_invites($campaign['id'], $referrer_id);
    $required = (int) $campaign['required_invites'];
    sendmessage($referrer_id, "✅ یک دعوت جدید ثبت شد!\n\nکمپین: <b>{$campaign['title']}</b>\nپیشرفت: {$invite_count} / {$required}", null, 'HTML');

    referral_check_and_grant_reward($campaign, $referrer_id);

    return ['ok' => true, 'reason' => 'registered', 'count' => $invite_count];
}

function referral_render_user_message($campaign, $user_id)
{
    global $usernamebot;

    if (!is_array($campaign)) {
        return '';
    }

    $user_id = referral_get_user_code($user_id);
    $link = referral_build_link($campaign['id'], $user_id);
    $invite_count = referral_count_invites($campaign['id'], $user_id);
    $required = (int) $campaign['required_invites'];
    $rewarded = referral_has_reward($campaign['id'], $user_id);

    $product = select("product", "*", "code_product", $campaign['code_product'], "select");
    $product_name = $product['name_product'] ?? $campaign['code_product'];

    $text = "<b>🎁 {$campaign['title']}</b>\n\n";
    if (!empty($campaign['description']) && $campaign['description'] !== 'none') {
        $text .= $campaign['description'] . "\n\n";
    }
    $text .= "🎯 هدف: <b>{$required}</b> دعوت\n";
    $text .= "🏆 جایزه: <b>{$product_name}</b>\n";
    $text .= "📊 پیشرفت شما: <b>{$invite_count} / {$required}</b>\n\n";
    $text .= "🆔 کد دعوت شما: <code>{$user_id}</code>\n";
    $text .= "🔗 لینک اختصاصی:\n<code>{$link}</code>\n";

    if ($rewarded) {
        $text .= "\n✅ جایزه این کمپین قبلاً برای شما فعال شده است.";
    } elseif ($invite_count >= $required) {
        $text .= "\n⏳ جایزه در حال آماده‌سازی است...";
    }

    $keyboard_rows = [
        [['text' => "🔗 اشتراک‌گذاری لینک", 'url' => "https://t.me/share/url?url=" . urlencode($link)]],
    ];

    return [
        'text' => $text,
        'keyboard' => json_encode(['inline_keyboard' => $keyboard_rows], JSON_UNESCAPED_UNICODE),
    ];
}

function get_support_admin_ids()
{
    $admin_ids = select("admin", "id_admin", null, null, "FETCH_COLUMN");
    if (!is_array($admin_ids)) {
        return [];
    }

    $support_admins = [];
    foreach ($admin_ids as $id_admin) {
        $admin = select("admin", "*", "id_admin", $id_admin, "select");
        if (!$admin || $admin['rule'] === 'Seller') {
            continue;
        }
        $support_admins[] = $id_admin;
    }

    return $support_admins;
}

function notify_support_admins($text, $keyboard, $photo = false, $video = false, $photoid = null, $videoid = null)
{
    foreach (get_support_admin_ids() as $id_admin) {
        if ($photo && $photoid) {
            sendphoto($id_admin, $photoid, null);
        }
        if ($video && $videoid) {
            sendvideo($id_admin, $videoid, null);
        }
        sendmessage($id_admin, $text, $keyboard, 'HTML');
    }
}

function product_ensure_sort_order_column(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'sort_order'");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE product ADD sort_order INT NOT NULL DEFAULT 0');
            $pdo->exec('UPDATE product SET sort_order = id WHERE sort_order = 0');
        }
    } catch (Throwable $e) {
        error_log('product_ensure_sort_order_column: ' . $e->getMessage());
    }
    $ensured = true;
}

function product_sort_value(array $product): int
{
    $sort = (int) ($product['sort_order'] ?? 0);
    if ($sort > 0) {
        return $sort;
    }
    return (int) ($product['id'] ?? 0);
}

function sortProductsByOrder(array $products): array
{
    usort($products, function ($a, $b) {
        $cmp = product_sort_value($a) <=> product_sort_value($b);
        if ($cmp !== 0) {
            return $cmp;
        }
        return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
    });
    return $products;
}

function product_category_where(string $category, string $column = 'category'): array
{
    if ($category === '') {
        return ['sql' => "({$column} IS NULL OR {$column} = '')", 'params' => []];
    }
    return ['sql' => "{$column} = ?", 'params' => [$category]];
}

function product_renormalize_category_sort_orders(PDO $pdo, string $category): void
{
    $where = product_category_where($category);
    $stmt = $pdo->prepare("SELECT id FROM product WHERE {$where['sql']} ORDER BY sort_order ASC, id ASC");
    $stmt->execute($where['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $i => $row) {
        $update = $pdo->prepare('UPDATE product SET sort_order = ? WHERE id = ?');
        $update->execute([$i + 1, (int) $row['id']]);
    }
}

function product_apply_category_sort_order(PDO $pdo, string $category, array $orderedIds): void
{
    $where = product_category_where($category);
    $stmt = $pdo->prepare("SELECT id FROM product WHERE {$where['sql']}");
    $stmt->execute($where['params']);
    $existingIds = array_map(static fn($row) => (int) $row['id'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    sort($existingIds);

    $ids = array_values(array_unique(array_map('intval', $orderedIds)));
    sort($ids);
    if ($ids !== $existingIds) {
        throw new InvalidArgumentException('ترتیب محصولات نامعتبر است.');
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE product SET sort_order = ? WHERE id = ?');
        foreach ($orderedIds as $i => $id) {
            $update->execute([$i + 1, (int) $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function product_next_sort_order(string $category = ''): int
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return 1;
    }
    try {
        $where = product_category_where($category);
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM product WHERE {$where['sql']}");
        $stmt->execute($where['params']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return max(1, (int) ($row['n'] ?? 1));
    } catch (Throwable $e) {
        return 1;
    }
}

function product_ensure_hwid_limit_column(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return;
    }
    addFieldToTable('product', 'hwid_limit', null, 'INT');
    $ensured = true;
}

product_ensure_hwid_limit_column();
product_ensure_sort_order_column();

#----------- agent volume / sell bot ------------#

function agent_ensure_volume_columns(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    addFieldToTable('user', 'agent_volume_remaining', '0', 'VARCHAR(100)');
    addFieldToTable('user', 'agent_price_per_gb', '0', 'VARCHAR(100)');
    $ensured = true;
}

function agent_is_reseller($agent): bool
{
    return in_array((string) $agent, ['n', 'n2'], true);
}

/**
 * Pre-check whether an agent can create the given GB volume.
 * Returns ['ok' => bool, 'msg' => string, 'cost' => int, 'user' => array|null]
 */
function agent_check_volume_quota($agentUserId, $volumeGb): array
{
    agent_ensure_volume_columns();
    $volumeGb = (int) $volumeGb;
    $user = select('user', '*', 'id', $agentUserId, 'select');
    if (!$user || !agent_is_reseller($user['agent'] ?? 'f')) {
        return ['ok' => true, 'msg' => '', 'cost' => 0, 'user' => $user ?: null, 'skipped' => true];
    }
    if ($volumeGb <= 0) {
        return [
            'ok' => false,
            'msg' => '❌ نمایندگان نمی‌توانند سرویس با حجم نامحدود (۰ گیگ) بسازند. حجم باید بیشتر از صفر باشد.',
            'cost' => 0,
            'user' => $user,
            'skipped' => false,
        ];
    }
    $remaining = (int) ($user['agent_volume_remaining'] ?? 0);
    if ($remaining < $volumeGb) {
        return [
            'ok' => false,
            'msg' => "❌ سهمیه حجم نمایندگی کافی نیست.\nباقیمانده: {$remaining} گیگ | درخواستی: {$volumeGb} گیگ",
            'cost' => 0,
            'user' => $user,
            'skipped' => false,
        ];
    }
    $pricePerGb = (int) ($user['agent_price_per_gb'] ?? 0);
    $cost = $volumeGb * $pricePerGb;
    $balance = (int) ($user['Balance'] ?? 0);
    $balanceAfter = $balance - $cost;
    if (($user['agent'] ?? '') === 'n2') {
        $maxBuy = (int) ($user['maxbuyagent'] ?? 0);
        if ($maxBuy != 0 && $balanceAfter < intval('-' . $maxBuy)) {
            return [
                'ok' => false,
                'msg' => '❌ سقف خرید نماینده برای این مقدار حجم کافی نیست.',
                'cost' => $cost,
                'user' => $user,
                'skipped' => false,
            ];
        }
    } elseif ($cost > $balance) {
        return [
            'ok' => false,
            'msg' => '❌ موجودی نمایندگی برای ساخت این حجم کافی نیست. هزینه: ' . number_format($cost) . ' تومان',
            'cost' => $cost,
            'user' => $user,
            'skipped' => false,
        ];
    }
    return ['ok' => true, 'msg' => '', 'cost' => $cost, 'user' => $user, 'skipped' => false];
}

/**
 * Deduct GB quota and wholesale cost from agent after a successful create.
 * Call agent_check_volume_quota first; this re-checks and updates.
 */
function agent_consume_volume($agentUserId, $volumeGb): array
{
    $check = agent_check_volume_quota($agentUserId, $volumeGb);
    if (!empty($check['skipped'])) {
        return $check;
    }
    if (!$check['ok']) {
        return $check;
    }
    $user = $check['user'];
    $volumeGb = (int) $volumeGb;
    $cost = (int) $check['cost'];
    $remaining = (int) ($user['agent_volume_remaining'] ?? 0) - $volumeGb;
    $balance = (int) ($user['Balance'] ?? 0) - $cost;
    update('user', 'agent_volume_remaining', (string) $remaining, 'id', $agentUserId);
    update('user', 'Balance', $balance, 'id', $agentUserId);
    $check['remaining'] = $remaining;
    $check['balance'] = $balance;
    return $check;
}

/**
 * Create an agent sell bot. $rootPath is project root (contains vpnbot/).
 * Returns ['ok' => bool, 'msg' => string, 'username' => string|null]
 */
function agent_create_sell_bot($agentUserId, $token, $rootPath = null): array
{
    global $pdo, $domainhosts;

    $agentUserId = (string) $agentUserId;
    $token = trim((string) $token);
    if ($token === '') {
        return ['ok' => false, 'msg' => 'توکن خالی است.', 'username' => null];
    }

    $existing = select('botsaz', '*', 'id_user', $agentUserId, 'count');
    $totalBots = select('botsaz', '*', null, null, 'count');
    if ((int) $totalBots >= 15) {
        return ['ok' => false, 'msg' => 'حداکثر ۱۵ ربات نماینده مجاز است.', 'username' => null];
    }
    if ((int) $existing !== 0) {
        return ['ok' => false, 'msg' => 'این نماینده از قبل ربات فروش دارد.', 'username' => null];
    }

    $getInfoToken = json_decode(@file_get_contents("https://api.telegram.org/bot{$token}/getme"), true);
    if ($getInfoToken == false || empty($getInfoToken['ok'])) {
        return ['ok' => false, 'msg' => 'توکن نامعتبر است.', 'username' => null];
    }
    $botUsername = $getInfoToken['result']['username'] ?? '';
    if ($botUsername === '') {
        return ['ok' => false, 'msg' => 'نام کاربری ربات دریافت نشد.', 'username' => null];
    }
    if ((int) select('botsaz', '*', 'bot_token', $token, 'count') !== 0) {
        return ['ok' => false, 'msg' => 'این توکن از قبل ثبت شده است.', 'username' => null];
    }

    if ($rootPath === null) {
        $rootPath = defined('__DIR__') ? dirname(__DIR__) : getcwd();
        // When called from project root (admin.php), getcwd is correct; from panel use parent.
        if (!is_dir($rootPath . '/vpnbot') && is_dir(getcwd() . '/vpnbot')) {
            $rootPath = getcwd();
        }
        if (!is_dir($rootPath . '/vpnbot') && is_dir(dirname(getcwd()) . '/vpnbot')) {
            $rootPath = dirname(getcwd());
        }
    }
    $rootPath = rtrim($rootPath, '/\\');
    $dirsource = $rootPath . '/vpnbot/' . $agentUserId . $botUsername;
    if (is_dir($dirsource) && !deleteDirectory($dirsource)) {
        error_log('Failed to remove existing bot directory: ' . $dirsource);
    }
    if (!copyDirectoryContents($rootPath . '/vpnbot/Default', $dirsource)) {
        return ['ok' => false, 'msg' => 'کپی فایل‌های ربات ناموفق بود.', 'username' => null];
    }
    $contentconfig = file_get_contents($dirsource . '/config.php');
    file_put_contents($dirsource . '/config.php', str_replace('BotTokenNew', $token, $contentconfig));
    @file_get_contents("https://api.telegram.org/bot{$token}/setwebhook?url=https://{$domainhosts}/vpnbot/{$agentUserId}{$botUsername}/index.php");
    @file_get_contents("https://api.telegram.org/bot{$token}/sendmessage?chat_id={$agentUserId}&text=" . urlencode('✅ کاربر عزیز ربات شما با موفقیت نصب گردید.'));

    $admin_ids = json_encode([$agentUserId]);
    $datasetting = json_encode([
        'minpricetime' => 4000,
        'pricetime' => 4000,
        'minpricevolume' => 4000,
        'pricevolume' => 4000,
        'support_username' => '@support',
        'Channel_Report' => 0,
        'cart_info' => 'جهت پرداخت مبلغ را به شماره کارت زیر واریز نمایید',
        'show_product' => true,
    ]);
    $hide = '{}';
    $time = date('Y/m/d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO botsaz (id_user,bot_token,admin_ids,username,time,setting,hide_panel) VALUES (:id_user,:bot_token,:admin_ids,:username,:time,:setting,:hide_panel)');
    $stmt->execute([
        ':id_user' => $agentUserId,
        ':bot_token' => $token,
        ':admin_ids' => $admin_ids,
        ':username' => $botUsername,
        ':time' => $time,
        ':setting' => $datasetting,
        ':hide_panel' => $hide,
    ]);

    return ['ok' => true, 'msg' => 'ربات با موفقیت ساخته شد.', 'username' => $botUsername, 'token' => $token];
}

/**
 * Remove agent sell bot (filesystem + webhook + botsaz row).
 */
function agent_remove_sell_bot($agentUserId, $rootPath = null): array
{
    global $pdo;

    $agentUserId = (string) $agentUserId;
    $contentbot = select('botsaz', '*', 'id_user', $agentUserId, 'select');
    if (!$contentbot) {
        return ['ok' => false, 'msg' => 'ربات فروشی برای این نماینده یافت نشد.'];
    }

    if ($rootPath === null) {
        $rootPath = getcwd();
        if (!is_dir($rootPath . '/vpnbot') && is_dir(dirname($rootPath) . '/vpnbot')) {
            $rootPath = dirname($rootPath);
        }
    }
    $rootPath = rtrim($rootPath, '/\\');
    $dirsource = $rootPath . '/vpnbot/' . $agentUserId . $contentbot['username'];
    if (is_dir($dirsource) && !deleteDirectory($dirsource)) {
        error_log('Failed to remove bot directory: ' . $dirsource);
    }
    if (!empty($contentbot['bot_token'])) {
        @file_get_contents('https://api.telegram.org/bot' . $contentbot['bot_token'] . '/deletewebhook');
    }
    $stmt = $pdo->prepare('DELETE FROM botsaz WHERE id_user = :id_user');
    $stmt->execute([':id_user' => $agentUserId]);
    return ['ok' => true, 'msg' => 'ربات فروش حذف شد.'];
}

#-----------DiscountSell scope (multi product/panel/category)------------#
function discount_sell_ensure_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    global $pdo, $connect;
    try {
        if ($pdo instanceof PDO) {
            $cols = $pdo->query("SHOW COLUMNS FROM DiscountSell")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('code_category', $cols, true)) {
                $pdo->exec("ALTER TABLE DiscountSell ADD code_category TEXT NULL");
                $pdo->exec("UPDATE DiscountSell SET code_category = 'all' WHERE code_category IS NULL OR code_category = ''");
            }
            foreach (['code_product', 'code_panel', 'code_category'] as $col) {
                $pdo->exec("ALTER TABLE DiscountSell MODIFY `$col` TEXT NULL");
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS DiscountSellUsage (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(255) NOT NULL,
                id_user VARCHAR(64) NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'buy',
                code_product VARCHAR(100) NULL,
                name_product VARCHAR(255) NULL,
                code_panel VARCHAR(100) NULL,
                name_panel VARCHAR(255) NULL,
                id_invoice VARCHAR(100) NULL,
                price_original VARCHAR(50) NULL,
                price_final VARCHAR(50) NULL,
                created_at INT UNSIGNED NOT NULL,
                KEY idx_discount_usage_code (code),
                KEY idx_discount_usage_user (id_user)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } elseif (isset($connect) && $connect) {
            $check = $connect->query("SHOW COLUMNS FROM DiscountSell LIKE 'code_category'");
            if ($check && mysqli_num_rows($check) != 1) {
                $connect->query("ALTER TABLE DiscountSell ADD code_category TEXT NULL");
                $connect->query("UPDATE DiscountSell SET code_category = 'all' WHERE code_category IS NULL OR code_category = ''");
            }
            $connect->query("ALTER TABLE DiscountSell MODIFY code_product TEXT NULL");
            $connect->query("ALTER TABLE DiscountSell MODIFY code_panel TEXT NULL");
            $connect->query("ALTER TABLE DiscountSell MODIFY code_category TEXT NULL");
            $connect->query("CREATE TABLE IF NOT EXISTS DiscountSellUsage (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(255) NOT NULL,
                id_user VARCHAR(64) NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'buy',
                code_product VARCHAR(100) NULL,
                name_product VARCHAR(255) NULL,
                code_panel VARCHAR(100) NULL,
                name_panel VARCHAR(255) NULL,
                id_invoice VARCHAR(100) NULL,
                price_original VARCHAR(50) NULL,
                price_final VARCHAR(50) NULL,
                created_at INT UNSIGNED NOT NULL,
                KEY idx_discount_usage_code (code),
                KEY idx_discount_usage_user (id_user)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    } catch (Throwable $e) {
        error_log('discount_sell_ensure_schema: ' . $e->getMessage());
    }
    $ready = true;
}

function discount_scope_values(?string $raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return ['all'];
    }
    if (isset($raw[0]) && $raw[0] === '[') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $out[] = $v;
                }
            }
            return $out !== [] ? array_values(array_unique($out)) : ['all'];
        }
    }
    if (strpos($raw, ',') !== false) {
        $out = [];
        foreach (explode(',', $raw) as $v) {
            $v = trim($v);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return $out !== [] ? array_values(array_unique($out)) : ['all'];
    }
    return [$raw];
}

function discount_scope_is_all(array $values, array $allTokens = ['all', '/all']): bool
{
    if ($values === []) {
        return true;
    }
    foreach ($values as $v) {
        if (in_array($v, $allTokens, true)) {
            return true;
        }
    }
    return false;
}

function discount_scope_allows(?string $raw, string $needle, array $allTokens = ['all', '/all']): bool
{
    $values = discount_scope_values($raw);
    if (discount_scope_is_all($values, $allTokens)) {
        return true;
    }
    return in_array($needle, $values, true);
}

function discount_sell_encode_scope($values, string $allToken = 'all'): string
{
    if (!is_array($values)) {
        $values = [$values];
    }
    $clean = [];
    foreach ($values as $v) {
        $v = trim((string) $v);
        if ($v !== '') {
            $clean[] = $v;
        }
    }
    $clean = array_values(array_unique($clean));
    if ($clean === [] || discount_scope_is_all($clean, ['all', '/all'])) {
        return $allToken;
    }
    if (count($clean) === 1) {
        return $clean[0];
    }
    return json_encode($clean, JSON_UNESCAPED_UNICODE);
}

function discount_sell_applies(array $discount, string $codeProduct, string $codePanel, ?string $category = null, ?string $type = null, ?string $agent = null): bool
{
    if ($agent !== null) {
        $dAgent = (string) ($discount['agent'] ?? 'allusers');
        if ($dAgent !== 'allusers' && $dAgent !== $agent) {
            return false;
        }
    }
    if ($type !== null) {
        $dType = (string) ($discount['type'] ?? 'all');
        if ($dType !== 'all' && $dType !== $type) {
            return false;
        }
    }
    if (!discount_scope_allows($discount['code_panel'] ?? '/all', $codePanel, ['all', '/all'])) {
        return false;
    }
    if (!discount_scope_allows($discount['code_product'] ?? 'all', $codeProduct, ['all'])) {
        return false;
    }
    if (!discount_scope_allows($discount['code_category'] ?? 'all', (string) ($category ?? ''), ['all'])) {
        return false;
    }
    return true;
}

function discount_scope_label(array $values, array $nameMap, string $allLabel, int $max = 2): string
{
    if (discount_scope_is_all($values, ['all', '/all'])) {
        return $allLabel;
    }
    $labels = [];
    foreach ($values as $v) {
        $labels[] = $nameMap[$v] ?? $v;
    }
    if (count($labels) <= $max) {
        return implode('، ', $labels);
    }
    return implode('، ', array_slice($labels, 0, $max)) . ' +' . (count($labels) - $max);
}

/**
 * Record a successful DiscountSell redemption: bump usedDiscount, Giftcodeconsumed, and detailed usage log.
 *
 * @param array{
 *   code: string,
 *   id_user: string|int,
 *   type?: string,
 *   code_product?: string|null,
 *   name_product?: string|null,
 *   code_panel?: string|null,
 *   name_panel?: string|null,
 *   id_invoice?: string|null,
 *   price_original?: string|int|null,
 *   price_final?: string|int|null
 * } $data
 */
function discount_sell_record_usage(array $data): bool
{
    global $pdo, $connect;

    $code = trim((string) ($data['code'] ?? ''));
    $idUser = (string) ($data['id_user'] ?? '');
    if ($code === '' || $idUser === '') {
        return false;
    }

    discount_sell_ensure_schema();

    $type = trim((string) ($data['type'] ?? 'buy'));
    if (!in_array($type, ['buy', 'extend'], true)) {
        $type = 'buy';
    }

    try {
        $discount = select('DiscountSell', '*', 'codeDiscount', $code, 'select');
        if ($discount) {
            $value = (int) ($discount['usedDiscount'] ?? 0) + 1;
            update('DiscountSell', 'usedDiscount', $value, 'codeDiscount', $code);
        }

        if ($pdo instanceof PDO) {
            $stmt = $pdo->prepare('INSERT INTO Giftcodeconsumed (id_user, code) VALUES (:id_user, :code)');
            $stmt->execute([':id_user' => $idUser, ':code' => $code]);
            $stmt = $pdo->prepare(
                'INSERT INTO DiscountSellUsage
                (code, id_user, type, code_product, name_product, code_panel, name_panel, id_invoice, price_original, price_final, created_at)
                VALUES
                (:code, :id_user, :type, :code_product, :name_product, :code_panel, :name_panel, :id_invoice, :price_original, :price_final, :created_at)'
            );
            $stmt->execute([
                ':code' => $code,
                ':id_user' => $idUser,
                ':type' => $type,
                ':code_product' => $data['code_product'] ?? null,
                ':name_product' => $data['name_product'] ?? null,
                ':code_panel' => $data['code_panel'] ?? null,
                ':name_panel' => $data['name_panel'] ?? null,
                ':id_invoice' => $data['id_invoice'] ?? null,
                ':price_original' => isset($data['price_original']) ? (string) $data['price_original'] : null,
                ':price_final' => isset($data['price_final']) ? (string) $data['price_final'] : null,
                ':created_at' => time(),
            ]);
        } elseif (isset($connect) && $connect) {
            $stmt = $connect->prepare('INSERT INTO Giftcodeconsumed (id_user, code) VALUES (?, ?)');
            $stmt->bind_param('ss', $idUser, $code);
            $stmt->execute();
            $stmt->close();
            $stmt = $connect->prepare(
                'INSERT INTO DiscountSellUsage
                (code, id_user, type, code_product, name_product, code_panel, name_panel, id_invoice, price_original, price_final, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $codeProduct = $data['code_product'] ?? null;
            $nameProduct = $data['name_product'] ?? null;
            $codePanel = $data['code_panel'] ?? null;
            $namePanel = $data['name_panel'] ?? null;
            $idInvoice = $data['id_invoice'] ?? null;
            $priceOriginal = isset($data['price_original']) ? (string) $data['price_original'] : null;
            $priceFinal = isset($data['price_final']) ? (string) $data['price_final'] : null;
            $createdAt = time();
            $stmt->bind_param(
                'ssssssssssi',
                $code,
                $idUser,
                $type,
                $codeProduct,
                $nameProduct,
                $codePanel,
                $namePanel,
                $idInvoice,
                $priceOriginal,
                $priceFinal,
                $createdAt
            );
            $stmt->execute();
            $stmt->close();
        }
        return true;
    } catch (Throwable $e) {
        error_log('discount_sell_record_usage: ' . $e->getMessage());
        return false;
    }
}