<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';

// Set headers and configuration
header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set('Asia/Tehran');
$topic_id = select("topicid", "*", null, null, "fetchAll");
foreach ($topic_id as $topic) {
    if ($topic['report'] == "reportnight")
        $reportnight = $topic['idreport'];
    if ($topic['report'] == 'reporttest')
        $reporttest = $topic['idreport'];
    if ($topic['report'] == 'errorreport')
        $errorreport = $topic['idreport'];
    if ($topic['report'] == 'porsantreport')
        $porsantreport = $topic['idreport'];
    if ($topic['report'] == 'reportcron')
        $reportcron = $topic['idreport'];
    if ($topic['report'] == 'backupfile')
        $reportbackup = $topic['idreport'];
    if ($topic['report'] == 'buyreport')
        $buyreport = $topic['idreport'];
    if ($topic['report'] == 'otherservice')
        $otherservice = $topic['idreport'];
    if ($topic['report'] == 'paymentreport')
        $paymentreports = $topic['idreport'];

}
ini_set('default_charset', 'UTF-8');
ini_set('error_log', 'error_log');

/**
 * Utility Functions
 */
function sendJsonResponse($status, $message, $data = [], $httpCode = 200)
{
    http_response_code($httpCode);
    echo json_encode([
        'status' => $status,
        'msg' => $message,
        'obj' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendReport($text, $groupid, $topic_id, $reply_markup = null)
{
    if (strlen($groupid) > 0) {
        telegram('sendmessage', [
            'chat_id' => $groupid,
            'message_thread_id' => $topic_id,
            'text' => $text,
            'parse_mode' => "HTML",
            'reply_markup' => $reply_markup
        ]);
    }
}

function validateToken($headers)
{
    global $APIKEY;
    if (!isset($headers['Token'])) {
        return false;
    }
    if (is_file('hash.txt')) {
        $token = file_get_contents('hash.txt');
    } else {
        return false;
    }
    $validTokens = [$token, $APIKEY];
    return in_array($headers['Token'], $validTokens, true);
}

function sanitizeRecursive($data)
{
    if (is_array($data)) {
        return array_map('sanitizeRecursive', $data);
    }
    return is_string($data) ? htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8') : $data;
}

function validateMethod($expected, $actual)
{
    if (strtoupper($expected) !== strtoupper($actual)) {
        sendJsonResponse(false, "method invalid; method must be {$expected}");
    }
}

function logApiRequest($headers, $data, $action)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO logs_api (header, data, time, ip, actions) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            json_encode($headers),
            json_encode($data),
            date('Y/m/d H:i:s'),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $action
        ]);
    } catch (Exception $e) {
        error_log("API logging error: " . $e->getMessage());
    }
}

/**
 * Main API Logic
 */

// Get and validate headers
$headers = getallheaders();
if (!validateToken($headers)) {
    sendJsonResponse(false, "token invalid", [], 403);
}

// Get request method and data
$method = $_SERVER['REQUEST_METHOD'];
$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);

// Validate JSON data
if (!is_array($data)) {
    sendJsonResponse(false, "data invalid", []);
}

// Sanitize input data
$data = sanitizeRecursive($data);

// Log API request
logApiRequest($headers, $data, $data['actions'] ?? 'unknown');

// Get settings
$setting = select("setting", "*");

// Route based on action
switch ($data['actions'] ?? '') {

    case 'discounts':
        validateMethod('GET', $method);

        // Validate and set limit
        $limit = 50;
        if (isset($data['limit']) && is_numeric($data['limit']))
            $limit = min(max((int) $data['limit'], 1), 1000);

        // Validate and set page
        $page = isset($data['page']) && is_numeric($data['page']) ? max((int) $data['page'], 1) : 1;
        $offset = ($page - 1) * $limit;
        $q = isset($data['q']) ? $data['q'] : '';

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM Discount WHERE (code LIKE :code_discount)");
            $search = "%$q%";
            $stmt->bindParam(':code_discount', $search, PDO::PARAM_STR);
            $stmt->execute();
            $totalDiscount = (int) $stmt->fetchColumn();
            $totalPages = ceil($totalDiscount / $limit);
            $query = "SELECT * FROM Discount WHERE (code  LIKE CONCAT('%', :code_discount, '%')) ORDER BY id LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($query);
            $stmt->bindValue(':code_discount', $q, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $discount = $stmt->fetchAll(PDO::FETCH_ASSOC);
            sendJsonResponse(true, "Successful", [
                'discount' => $discount,
                'pagination' => [
                    'total_discount' => $totalDiscount,
                    'total_pages' => $totalPages,
                    'current_page' => $page,
                    'per_page' => $limit
                ]
            ]);

        } catch (Exception $e) {
            error_log("Database error in discount: " . $e->getMessage());
            sendJsonResponse(false, "Database error occurred", [], 500);
        }
        break;

    case 'discount':
        validateMethod('GET', $method);

        // Validate id discount
        if (!isset($data['id']) || empty($data['id'])) {
            sendJsonResponse(false, "id empty", []);
        }

        try {
            $discount = select("Discount", "*", "id", $data['id'], "select");
            if (!$discount) {
                sendJsonResponse(true, "Successful", [
                    'discount' => [],
                ]);
            }
            sendJsonResponse(true, "Successful", [
                'discount' => $discount,
            ]);
        } catch (Exception $e) {
            error_log("Database error in discount: " . $e->getMessage());
            sendJsonResponse(false, "Database error occurred", [], 500);
        }
        break;

    case 'discount_add':
        validateMethod('POST', $method);
        $required_fields = ['code', 'price', 'limit_use'];
        $missing_fields = array_diff($required_fields, array_keys($data));
        if (!empty($missing_fields)) {
            sendJsonResponse(false, "Missing required fields: " . implode(', ', $missing_fields), []);
        }
        $discount = select("Discount", "*", "code", $data['code'], "count");
        if ($discount != 0) {
            sendJsonResponse(false, "Discount code exits", [], 200);
        }
        if (!preg_match('/^[A-Za-z\d]+$/', $data['code'])) {
            sendJsonResponse(false, "invalid code", [], 200);
        }
        try {
            // Prepare Discount data
            $productData = [
                'code' => $data['code'],
                'price' => $data['price'],
                'limituse' => $data['limit_use'],
                'limitused' => 0
            ];
            // Insert Discount into database
            $columns = implode(',', array_keys($productData));
            $placeholders = ':' . implode(', :', array_keys($productData));
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO Discount ({$columns}) VALUES ({$placeholders})"
            );

            foreach ($productData as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            $stmt->execute();
            sendJsonResponse(true, "Successful");

        } catch (Exception $e) {
            error_log("Error in Discount add: " . $e->getMessage());
            sendJsonResponse(false, "An error occurred while editing Discount");
        }
        break;

    case 'discount_delete':
        validateMethod('POST', $method);
        $required_fields = ['id'];
        $missing_fields = array_diff($required_fields, array_keys($data));
        if (!empty($missing_fields)) {
            sendJsonResponse(false, "Missing required fields: " . implode(', ', $missing_fields), []);
        }
        $product = select("Discount", "*", "id", $data['id'], "select");
        if (!$product) {
            sendJsonResponse(false, "Discount not found", [], 200);
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM Discount  WHERE id = :id");
            $stmt->bindValue(":id", $data['id'], PDO::PARAM_INT);
            $stmt->execute();

            sendJsonResponse(true, "Discount delete successfully", [], 200);

        } catch (Exception $e) {
            error_log("Error in Discount delete : " . $e->getMessage());
            sendJsonResponse(false, "An error occurred while delete Discount");
        }
        break;
    case 'discount_sell_lists':
        validateMethod('GET', $method);

        // Validate and set limit
        $limit = 50;
        if (isset($data['limit']) && is_numeric($data['limit']))
            $limit = min(max((int) $data['limit'], 1), 1000);

        // Validate and set page
        $page = isset($data['page']) && is_numeric($data['page']) ? max((int) $data['page'], 1) : 1;
        $offset = ($page - 1) * $limit;
        $q = isset($data['q']) ? $data['q'] : '';

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM DiscountSell WHERE (codeDiscount LIKE :code_discount)");
            $search = "%$q%";
            $stmt->bindParam(':code_discount', $search, PDO::PARAM_STR);
            $stmt->execute();
            $totalDiscount = (int) $stmt->fetchColumn();
            $totalPages = ceil($totalDiscount / $limit);
            $query = "SELECT * FROM DiscountSell WHERE (codeDiscount  LIKE CONCAT('%', :code_discount, '%')) ORDER BY id LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($query);
            $stmt->bindValue(':code_discount', $q, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $discount = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $product = select("product", "code_product as id,name_product", null, null, "fetchAll");
            $panel = select("marzban_panel", "code_panel,name_panel", "status", "active", "fetchAll");
            $category = select("category", "remark", null, null, "fetchAll");
            sendJsonResponse(true, "Successful", [
                'discount' => $discount,
                'product' => $product,
                'panel' => $panel,
                'category' => $category,
                'pagination' => [
                    'total_discount' => $totalDiscount,
                    'total_pages' => $totalPages,
                    'current_page' => $page,
                    'per_page' => $limit
                ]
            ]);

        } catch (Exception $e) {
            error_log("Database error in discount: " . $e->getMessage());
            sendJsonResponse(false, "Database error occurred", [], 500);
        }
        break;
    case 'discount_sell':
        validateMethod('GET', $method);

        // Validate id discount sell
        if (!isset($data['id']) || empty($data['id'])) {
            sendJsonResponse(false, "id empty", []);
        }

        try {
            $discount = select("DiscountSell", "*", "id", $data['id'], "select");
            if ($discount['code_product'] != "all")
                $discount['code_product'] = select('product', "*", "code_product", $discount['code_product'], "select")['name_product'];
            if ($discount['code_panel'] != "/all")
                $discount['code_panel'] = select('marzban_panel', "*", "code_panel", $discount['code_panel'], "select")['name_panel'];
            if (!$discount) {
                sendJsonResponse(true, "Successful", [
                    'discount' => [],
                ]);
            }
            sendJsonResponse(true, "Successful", [
                'discount' => $discount,
            ]);
        } catch (Exception $e) {
            error_log("Database error in discount: " . $e->getMessage());
            sendJsonResponse(false, "Database error occurred", [], 500);
        }
        break;

    case 'discount_sell_delete':
        validateMethod('POST', $method);
        $required_fields = ['id'];
        $missing_fields = array_diff($required_fields, array_keys($data));
        if (!empty($missing_fields)) {
            sendJsonResponse(false, "Missing required fields: " . implode(', ', $missing_fields), []);
        }
        $product = select("DiscountSell", "*", "id", $data['id'], "select");
        if (!$product) {
            sendJsonResponse(false, "DiscountSell not found", [], 200);
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM Giftcodeconsumed WHERE code = :code");
            $stmt->bindValue(":code", $product['codeDiscount'], PDO::PARAM_STR);
            $stmt->execute();
            $stmt = $pdo->prepare("DELETE FROM DiscountSell  WHERE id = :id");
            $stmt->bindValue(":id", $data['id'], PDO::PARAM_INT);
            $stmt->execute();

            sendJsonResponse(true, "DiscountSell delete successfully", [], 200);

        } catch (Exception $e) {
            error_log("Error in DiscountSell delete : " . $e->getMessage());
            sendJsonResponse(false, "An error occurred while delete DiscountSell");
        }
        break;
    case 'discount_sell_add':
        validateMethod('POST', $method);
        discount_sell_ensure_schema();
        $required_fields = ['code', 'percent', 'limit_use'];
        $missing_fields = array_diff($required_fields, array_keys($data));
        if (!empty($missing_fields)) {
            sendJsonResponse(false, "Missing required fields: " . implode(', ', $missing_fields), []);
        }
        $discount = select("DiscountSell", "*", "codeDiscount", $data['code'], "count");
        if ($discount != 0) {
            sendJsonResponse(false, "Discount code exits", [], 200);
        }
        if (!preg_match('/^[A-Za-z\d]+$/', $data['code'])) {
            sendJsonResponse(false, "invalid code", [], 200);
        }
        try {
            $codeProduct = $data['code_product'] ?? 'all';
            $codePanel = $data['code_panel'] ?? '/all';
            $codeCategory = $data['code_category'] ?? 'all';
            if (is_array($codeProduct)) {
                $codeProduct = discount_sell_encode_scope($codeProduct, 'all');
            }
            if (is_array($codePanel)) {
                $codePanel = discount_sell_encode_scope($codePanel, '/all');
            }
            if (is_array($codeCategory)) {
                $codeCategory = discount_sell_encode_scope($codeCategory, 'all');
            }
            $productData = [
                'codeDiscount' => $data['code'],
                'price' => $data['percent'],
                'limitDiscount' => $data['limit_use'],
                'usedDiscount' => 0,
                'agent' => empty($data['agent']) ? "allusers" : $data['agent'],
                'usefirst' => empty($data['usefirst']) ? "0" : $data['usefirst'],
                'useuser' => array_key_exists('useuser', $data) ? $data['useuser'] : '1',
                'code_product' => $codeProduct === '' || $codeProduct === null ? "all" : $codeProduct,
                'code_panel' => $codePanel === '' || $codePanel === null ? "/all" : $codePanel,
                'code_category' => $codeCategory === '' || $codeCategory === null ? "all" : $codeCategory,
                'time' => empty($data['time']) ? '0' : $data['time'],
                'type' => empty($data['type']) ? "all" : $data['type'],
            ];
            $columns = implode(',', array_keys($productData));
            $placeholders = ':' . implode(', :', array_keys($productData));
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO DiscountSell ({$columns}) VALUES ({$placeholders})"
            );

            foreach ($productData as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            $stmt->execute();
            sendJsonResponse(true, "Successful");

        } catch (Exception $e) {
            error_log("Error in Discount add: " . $e->getMessage());
            sendJsonResponse(false, "An error occurred while editing Discount");
        }
        break;

    case 'discount_sell_edit':
        validateMethod('POST', $method);
        discount_sell_ensure_schema();
        $required_fields = ['id', 'code', 'percent', 'limit_use'];
        $missing_fields = array_diff($required_fields, array_keys($data));
        if (!empty($missing_fields)) {
            sendJsonResponse(false, "Missing required fields: " . implode(', ', $missing_fields), []);
        }
        if (!preg_match('/^[A-Za-z\d]+$/', $data['code'])) {
            sendJsonResponse(false, "invalid code", [], 200);
        }
        $existing = select("DiscountSell", "*", "id", $data['id'], "select");
        if (!$existing) {
            sendJsonResponse(false, "DiscountSell not found", [], 200);
        }
        $dup = select("DiscountSell", "*", "codeDiscount", $data['code'], "select");
        if ($dup && (int) $dup['id'] !== (int) $data['id']) {
            sendJsonResponse(false, "Discount code exits", [], 200);
        }
        try {
            $usefirst = empty($data['usefirst']) ? ($existing['usefirst'] ?? '0') : $data['usefirst'];
            $type = empty($data['type']) ? ($existing['type'] ?? 'all') : $data['type'];
            if ((string) $usefirst === '1') {
                $type = 'all';
            }
            $codeProduct = array_key_exists('code_product', $data) ? $data['code_product'] : ($existing['code_product'] ?? 'all');
            $codePanel = array_key_exists('code_panel', $data) ? $data['code_panel'] : ($existing['code_panel'] ?? '/all');
            $codeCategory = array_key_exists('code_category', $data) ? $data['code_category'] : ($existing['code_category'] ?? 'all');
            if (is_array($codeProduct)) {
                $codeProduct = discount_sell_encode_scope($codeProduct, 'all');
            }
            if (is_array($codePanel)) {
                $codePanel = discount_sell_encode_scope($codePanel, '/all');
            }
            if (is_array($codeCategory)) {
                $codeCategory = discount_sell_encode_scope($codeCategory, 'all');
            }
            $stmt = $pdo->prepare(
                "UPDATE DiscountSell SET
                    codeDiscount = :codeDiscount,
                    price = :price,
                    limitDiscount = :limitDiscount,
                    agent = :agent,
                    usefirst = :usefirst,
                    useuser = :useuser,
                    code_product = :code_product,
                    code_panel = :code_panel,
                    code_category = :code_category,
                    time = :time,
                    type = :type
                 WHERE id = :id"
            );
            $stmt->execute([
                ':codeDiscount' => $data['code'],
                ':price' => $data['percent'],
                ':limitDiscount' => $data['limit_use'],
                ':agent' => empty($data['agent']) ? ($existing['agent'] ?? 'allusers') : $data['agent'],
                ':usefirst' => $usefirst,
                ':useuser' => array_key_exists('useuser', $data) ? $data['useuser'] : ($existing['useuser'] ?? '1'),
                ':code_product' => $codeProduct === '' || $codeProduct === null ? 'all' : $codeProduct,
                ':code_panel' => $codePanel === '' || $codePanel === null ? '/all' : $codePanel,
                ':code_category' => $codeCategory === '' || $codeCategory === null ? 'all' : $codeCategory,
                ':time' => array_key_exists('time', $data) ? $data['time'] : ($existing['time'] ?? '0'),
                ':type' => $type,
                ':id' => $data['id'],
            ]);
            if (strcasecmp((string) $existing['codeDiscount'], (string) $data['code']) !== 0) {
                $stmt = $pdo->prepare("UPDATE Giftcodeconsumed SET code = :newcode WHERE code = :oldcode");
                $stmt->execute([
                    ':newcode' => $data['code'],
                    ':oldcode' => $existing['codeDiscount'],
                ]);
                try {
                    $stmt = $pdo->prepare("UPDATE DiscountSellUsage SET code = :newcode WHERE code = :oldcode");
                    $stmt->execute([
                        ':newcode' => $data['code'],
                        ':oldcode' => $existing['codeDiscount'],
                    ]);
                } catch (Exception $e) {
                }
            }
            sendJsonResponse(true, "Successful");
        } catch (Exception $e) {
            error_log("Error in DiscountSell edit: " . $e->getMessage());
            sendJsonResponse(false, "An error occurred while editing DiscountSell");
        }
        break;

    default:
        sendJsonResponse(false, "Action Invalid");
        break;
}

?>