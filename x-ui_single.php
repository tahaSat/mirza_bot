<?php
require_once 'config.php';
require_once 'request.php';
ini_set('error_log', 'error_log');

function panel_cookie_path(): string
{
    return __DIR__ . '/cookie.txt';
}

function panel_http_timeout_ms(): int
{
    global $request_exec_timeout;
    if ($request_exec_timeout !== null && (int) $request_exec_timeout > 0) {
        return (int) $request_exec_timeout;
    }
    return 30000;
}

function panel_login_base_url(array $panel): string
{
    return rtrim(trim($panel['url_panel'] ?? ''), '/');
}

function panel_login_origin(string $baseUrl): string
{
    $parts = parse_url($baseUrl);
    $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    if (!empty($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }
    return $origin;
}

function panel_login_headers(string $baseUrl, ?string $csrfToken = null): array
{
    $headers = [
        'Accept: application/json, text/plain, */*',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Referer: ' . $baseUrl . '/',
        'Origin: ' . panel_login_origin($baseUrl),
        'X-Requested-With: XMLHttpRequest',
    ];
    if ($csrfToken !== null && $csrfToken !== '') {
        $headers[] = 'X-CSRF-Token: ' . $csrfToken;
    }
    return $headers;
}

function panel_extract_csrf_from_html(string $html): ?string
{
    if (preg_match('/<meta\s+name=["\']csrf-token["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
        return $m[1];
    }
    return null;
}

function panel_stored_csrf(array $panel): ?string
{
    if (empty($panel['datelogin'])) {
        return null;
    }
    $date = json_decode($panel['datelogin'], true);
    return is_array($date) ? ($date['csrf_token'] ?? null) : null;
}

function panel_xui_api_headers(array $panel): array
{
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    $csrf = panel_stored_csrf($panel);
    if ($csrf !== null && $csrf !== '') {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    return $headers;
}

function panel_xui_api_base(string $url): string
{
    $base = rtrim(trim($url), '/');
    if (substr($base, -6) === '/panel') {
        $base = substr($base, 0, -6);
    }
    return $base;
}

/**
 * @param mixed $raw
 * @return int[]
 */
function panel_xui_normalize_inbound_ids($raw): array
{
    if (is_array($raw)) {
        $ids = $raw;
    } elseif (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $ids = $decoded;
        } else {
            $ids = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
    } elseif ($raw === null) {
        $ids = [];
    } else {
        $ids = [$raw];
    }

    $out = [];
    foreach ($ids as $id) {
        if (is_numeric($id) && (int) $id > 0) {
            $out[] = (int) $id;
        }
    }
    return array_values(array_unique($out));
}

function panel_xui_decode_json_body(array $response): array
{
    $body = $response['body'] ?? '';
    if (!is_string($body) || $body === '') {
        return [];
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

function panel_xui_extract_client_from_response(array $decoded): array
{
    if (isset($decoded['client']) && is_array($decoded['client'])) {
        return $decoded['client'];
    }
    if (isset($decoded['obj']) && is_array($decoded['obj'])) {
        return $decoded['obj'];
    }
    if (isset($decoded['data']) && is_array($decoded['data'])) {
        if (isset($decoded['data']['client']) && is_array($decoded['data']['client'])) {
            return $decoded['data']['client'];
        }
        return $decoded['data'];
    }
    return [];
}

function panel_xui_extract_sub_id(array $decoded, array $client): string
{
    $candidates = [
        $client['subId'] ?? null,
        $client['subid'] ?? null,
        $client['subID'] ?? null,
        $decoded['subId'] ?? null,
        $decoded['subid'] ?? null,
        $decoded['subID'] ?? null,
        $decoded['data']['subId'] ?? null,
        $decoded['data']['subid'] ?? null,
        $decoded['obj']['subId'] ?? null,
        $decoded['obj']['subid'] ?? null,
    ];
    foreach ($candidates as $v) {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }
    return '';
}

function panel_xui_extract_subscription_url(array $decoded, array $client): string
{
    $candidates = [
        $client['subscription_url'] ?? null,
        $client['subscriptionUrl'] ?? null,
        $client['subUrl'] ?? null,
        $client['subURL'] ?? null,
        $decoded['subscription_url'] ?? null,
        $decoded['subscriptionUrl'] ?? null,
        $decoded['subUrl'] ?? null,
        $decoded['subURL'] ?? null,
        $decoded['data']['subscription_url'] ?? null,
        $decoded['data']['subscriptionUrl'] ?? null,
    ];
    foreach ($candidates as $v) {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }
    return '';
}

function panel_xui_extract_expiry_ms(array $decoded, array $client): int
{
    $candidates = [
        $client['expiryTime'] ?? null,
        $client['expiry_time'] ?? null,
        $client['expire'] ?? null,
        $client['expiredAt'] ?? null,
        $client['expired_at'] ?? null,
        $decoded['expiryTime'] ?? null,
        $decoded['expire'] ?? null,
        $decoded['expiredAt'] ?? null,
        $decoded['data']['expiryTime'] ?? null,
        $decoded['data']['expire'] ?? null,
        $decoded['obj']['expiryTime'] ?? null,
        $decoded['obj']['expire'] ?? null,
    ];

    foreach ($candidates as $value) {
        if (is_string($value) && trim($value) !== '' && !is_numeric($value)) {
            $ts = strtotime($value);
            if ($ts !== false && $ts > 0) {
                return $ts * 1000;
            }
            continue;
        }
        if (is_numeric($value)) {
            $num = (int) $value;
            if ($num <= 0) {
                continue;
            }
            // New/old panels may return seconds or milliseconds; normalize to ms.
            if ($num < 100000000000) {
                $num *= 1000;
            }
            return $num;
        }
    }
    return 0;
}

/**
 * @param mixed[] $values
 */
function panel_xui_pick_numeric(array $values, float $default = 0): float
{
    foreach ($values as $value) {
        if (is_numeric($value)) {
            return (float) $value;
        }
    }
    return $default;
}

function panel_curl_common_opts(string $cookieFile): array
{
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT_MS => panel_http_timeout_ms(),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ];
}

function panel_fetch_csrf_token(string $baseUrl, string $cookieFile): ?string
{
    $opts = panel_curl_common_opts($cookieFile);

    $csrfUrl = curl_init();
    curl_disable_proxy($csrfUrl);
    curl_force_panel_http11($csrfUrl);
    curl_setopt_array($csrfUrl, $opts + [
        CURLOPT_URL => $baseUrl . '/csrf-token',
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => panel_login_headers($baseUrl),
    ]);
    $csrfBody = curl_exec($csrfUrl);
    curl_close($csrfUrl);
    if (is_string($csrfBody) && $csrfBody !== '') {
        $decoded = json_decode($csrfBody, true);
        if (is_array($decoded) && !empty($decoded['token'])) {
            return (string) $decoded['token'];
        }
        $trimmed = trim($csrfBody);
        if ($trimmed !== '' && strlen($trimmed) < 256 && preg_match('/^[A-Za-z0-9_\-]+$/', $trimmed)) {
            return $trimmed;
        }
    }

    $warmup = curl_init();
    curl_disable_proxy($warmup);
    curl_force_panel_http11($warmup);
    curl_setopt_array($warmup, $opts + [
        CURLOPT_URL => $baseUrl . '/',
        CURLOPT_HTTPGET => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => panel_login_headers($baseUrl),
    ]);
    $html = curl_exec($warmup);
    curl_close($warmup);

    return is_string($html) ? panel_extract_csrf_from_html($html) : null;
}

function panel_login_cookie($code_panel)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    $baseUrl = panel_login_base_url($panel);
    $cookieFile = panel_cookie_path();
    if (is_file($cookieFile)) {
        @unlink($cookieFile);
    }

    $csrfToken = panel_fetch_csrf_token($baseUrl, $cookieFile);
    if ($csrfToken === null || $csrfToken === '') {
        return [
            'success' => false,
            'msg' => 'Could not obtain CSRF token from panel (3x-ui v3 requires X-CSRF-Token on login).',
        ];
    }

    $opts = panel_curl_common_opts($cookieFile);
    $curl = curl_init();
    curl_disable_proxy($curl);
    curl_force_panel_http11($curl);
    curl_setopt_array($curl, $opts + [
        CURLOPT_URL => $baseUrl . '/login',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'username' => $panel['username_panel'],
            'password' => $panel['password_panel'],
        ]),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => panel_login_headers($baseUrl, $csrfToken),
    ]);
    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false || $curlError !== '') {
        return [
            'success' => false,
            'msg' => $curlError !== '' ? $curlError : 'Panel login request failed.',
        ];
    }

    $decoded = json_decode((string) $response, true);
    if (is_array($decoded)) {
        if (empty($decoded['success'])) {
            $decoded['msg'] = $decoded['msg'] ?? 'Login rejected by panel.';
        } else {
            $decoded['csrf_token'] = $csrfToken;
        }
        return $decoded;
    }

    $snippet = trim(preg_replace('/\s+/', ' ', substr(strip_tags((string) $response), 0, 200)));
    $hint = $httpCode === 403
        ? ' HTTP 403 on 3x-ui v3 usually means missing/invalid X-CSRF-Token or blocked IP.'
        : '';

    return [
        'success' => false,
        'msg' => 'Invalid panel response (HTTP ' . $httpCode . ').' . $hint
            . ($snippet !== '' ? ' Body: ' . $snippet : '')
            . ' Check url_panel, username, and password.',
    ];
}

function login($code_panel, $verify = true)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    $cookieFile = panel_cookie_path();
    if ($panel['datelogin'] != null && $verify) {
        $date = json_decode($panel['datelogin'], true);
        if (isset($date['time'])) {
            $timecurrent = time();
            $start_date = time() - strtotime($date['time']);
            if ($start_date <= 3000 && !empty($date['access_token'])) {
                file_put_contents($cookieFile, $date['access_token']);
                return [
                    'success' => true,
                    'csrf_token' => $date['csrf_token'] ?? null,
                ];
            }
        }
    }
    $result = panel_login_cookie($panel['code_panel']);
    if (!is_array($result)) {
        return ['success' => false, 'msg' => 'Invalid panel response.'];
    }
    if (empty($result['success'])) {
        return $result;
    }
    if (!is_file($cookieFile)) {
        return ['success' => false, 'msg' => 'Login succeeded but session cookie was not saved.'];
    }
    $time = date('Y/m/d H:i:s');
    $data = json_encode(array(
        'time' => $time,
        'access_token' => file_get_contents($cookieFile),
        'csrf_token' => $result['csrf_token'] ?? null,
    ));
    update("marzban_panel", "datelogin", $data, 'name_panel', $panel['name_panel']);
    return $result;
}

function get_clinets($username, $namepanel)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    $panelFresh = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (is_array($panelFresh)) {
        $marzban_list_get = $panelFresh;
    }
    $base = panel_xui_api_base($marzban_list_get['url_panel']);
    $url = $base . "/panel/api/clients/get/" . rawurlencode($username);
    $req = new CurlRequest($url);
    $req->setHeaders(panel_xui_api_headers($marzban_list_get));
    $req->setCookie(panel_cookie_path());
    $response = $req->get();
    $decodedBody = panel_xui_decode_json_body($response);
    if (isset($decodedBody['success']) && $decodedBody['success'] === false) {
        $response['error'] = $decodedBody['msg'] ?? 'Unknown panel error';
    } elseif (($response['status'] ?? 0) === 200 && !empty($decodedBody)) {
        $client = panel_xui_extract_client_from_response($decodedBody);
        $subId = panel_xui_extract_sub_id($decodedBody, $client);
        $subscriptionUrl = panel_xui_extract_subscription_url($decodedBody, $client);
        $expiryMs = panel_xui_extract_expiry_ms($decodedBody, $client);
        $inboundIds = panel_xui_normalize_inbound_ids($decodedBody['inboundIds'] ?? ($client['inboundIds'] ?? []));
        $firstInbound = $inboundIds[0] ?? null;
        $traffic = $decodedBody['traffic'] ?? ($client['traffic'] ?? []);
        $up = panel_xui_pick_numeric([
            $traffic['up'] ?? null,
            $traffic['upload'] ?? null,
            $traffic['uploaded'] ?? null,
            $traffic['uplink'] ?? null,
            $client['up'] ?? null,
            $client['upload'] ?? null,
            $client['uploaded'] ?? null,
        ]);
        $down = panel_xui_pick_numeric([
            $traffic['down'] ?? null,
            $traffic['download'] ?? null,
            $traffic['downloaded'] ?? null,
            $traffic['downlink'] ?? null,
            $client['down'] ?? null,
            $client['download'] ?? null,
            $client['downloaded'] ?? null,
        ]);
        $total = panel_xui_pick_numeric([
            $client['totalGB'] ?? null,
            $client['total'] ?? null,
            $client['data_limit'] ?? null,
            $client['limitBytes'] ?? null,
            $client['limit'] ?? null,
            $decodedBody['totalGB'] ?? null,
            $decodedBody['total'] ?? null,
            $decodedBody['data']['totalGB'] ?? null,
            $decodedBody['data']['total'] ?? null,
        ]);
        $enableRaw = $client['enable'] ?? ($decodedBody['enable'] ?? true);
        $enable = is_bool($enableRaw)
            ? $enableRaw
            : in_array(strtolower((string) $enableRaw), ['1', 'true', 'on', 'enabled', 'active'], true);
        $lastOnline = panel_xui_pick_numeric([
            $client['lastOnline'] ?? null,
            $client['last_online'] ?? null,
            $decodedBody['lastOnline'] ?? null,
            $decodedBody['data']['lastOnline'] ?? null,
        ]);
        $normalizedObj = [
            'inboundId' => $firstInbound,
            'email' => $client['email'] ?? $username,
            'total' => $total,
            'up' => $up,
            'down' => $down,
            'expiryTime' => $expiryMs,
            'enable' => $enable,
            'subId' => $subId,
            'subscription_url' => $subscriptionUrl,
            'id' => $client['id'] ?? ($client['uuid'] ?? ''),
            'uuid' => $client['id'] ?? ($client['uuid'] ?? ''),
            'lastOnline' => (int) $lastOnline,
        ];
        $response['body'] = json_encode([
            'success' => true,
            'obj' => $normalizedObj,
            'msg' => $decodedBody['msg'] ?? 'ok',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    if (!empty($response['error'])) {
        error_log(json_encode($response));
    }

    if (is_file(panel_cookie_path())) {
        @unlink(panel_cookie_path());
    }

    return $response;
}
function addClient($namepanel, $usernameac, $Expire, $Total, $Uuid, $Flow, $subid, $inboundid, $name_product, $note = "")
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    $panelFresh = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (is_array($panelFresh)) {
        $marzban_list_get = $panelFresh;
    }
    if ($name_product == "usertest") {
        if ($marzban_list_get['on_hold_test'] == "1") {
            if ($Expire == 0) {
                $timeservice = 0;
            } else {
                $timelast = $Expire - time();
                $timeservice = -intval(($timelast / 86400) * 86400000);
            }
        } else {
            $timeservice = $Expire * 1000;
        }
    } else {
        if ($marzban_list_get['conecton'] == "onconecton") {
            if ($Expire == 0) {
                $timeservice = 0;
            } else {
                $timelast = $Expire - time();
                $timeservice = -intval(($timelast / 86400) * 86400000);
            }
        } else {
            $timeservice = $Expire * 1000;
        }
    }
    $inboundIds = panel_xui_normalize_inbound_ids($inboundid);
    if (empty($inboundIds)) {
        return [
            'status' => 400,
            'body' => json_encode(['success' => false, 'msg' => 'inboundIds is empty'], JSON_UNESCAPED_UNICODE),
        ];
    }
    $config = [
        'client' => [
            'email' => $usernameac,
            'totalGB' => (float) $Total,
            'expiryTime' => (int) $timeservice,
            'tgId' => 0,
            'limitIp' => 0,
            'enable' => true,
            'id' => $Uuid,
            'subId' => $subid,
            'comment' => $note,
        ],
        'inboundIds' => $inboundIds,
    ];
    if ($Flow !== '') {
        $config['client']['flow'] = $Flow;
    }
    if (!isset($usernameac))
        return array(
            'status' => 500,
            'msg' => 'username is null'
        );
    $configpanel = json_encode($config, true);
    $base = panel_xui_api_base($marzban_list_get['url_panel']);
    $url = $base . '/panel/api/clients/add';
    $req = new CurlRequest($url);
    $req->setHeaders(panel_xui_api_headers($marzban_list_get));
    $req->setCookie(panel_cookie_path());
    $response = $req->post($configpanel);
    if (is_file(panel_cookie_path())) {
        @unlink(panel_cookie_path());
    }
    return $response;
}
function updateClient($namepanel, $uuid, array $config)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    $panelFresh = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (is_array($panelFresh)) {
        $marzban_list_get = $panelFresh;
    }
    $settings = json_decode($config['settings'] ?? '{}', true);
    $clientPatch = $settings['clients'][0] ?? [];
    $email = $clientPatch['email'] ?? '';
    if ($email === '') {
        return [
            'status' => 400,
            'body' => json_encode(['success' => false, 'msg' => 'email is required for update'], JSON_UNESCAPED_UNICODE),
        ];
    }
    $base = panel_xui_api_base($marzban_list_get['url_panel']);
    $existingReq = new CurlRequest($base . '/panel/api/clients/get/' . rawurlencode($email));
    $existingReq->setHeaders(panel_xui_api_headers($marzban_list_get));
    $existingReq->setCookie(panel_cookie_path());
    $existingResp = $existingReq->get();
    $existingDecoded = panel_xui_decode_json_body($existingResp);
    $existingClient = panel_xui_extract_client_from_response($existingDecoded);
    $existingInboundIds = panel_xui_normalize_inbound_ids($existingDecoded['inboundIds'] ?? ($existingClient['inboundIds'] ?? []));
    $inboundIds = isset($config['id']) ? panel_xui_normalize_inbound_ids([$config['id']]) : $existingInboundIds;
    if (empty($inboundIds)) {
        $inboundIds = $existingInboundIds;
    }
    $mergedClient = array_merge($existingClient, [
        'email' => $email,
    ]);
    if (array_key_exists('totalGB', $clientPatch)) {
        $mergedClient['totalGB'] = (float) $clientPatch['totalGB'];
    }
    if (array_key_exists('expiryTime', $clientPatch)) {
        $mergedClient['expiryTime'] = (int) $clientPatch['expiryTime'];
    }
    if (array_key_exists('enable', $clientPatch)) {
        $mergedClient['enable'] = (bool) $clientPatch['enable'];
    }
    if (array_key_exists('id', $clientPatch)) {
        $mergedClient['id'] = $clientPatch['id'];
    }
    if (array_key_exists('subId', $clientPatch)) {
        $mergedClient['subId'] = $clientPatch['subId'];
    }
    if (array_key_exists('flow', $clientPatch)) {
        $mergedClient['flow'] = $clientPatch['flow'];
    }
    $configpanel = json_encode([
        'client' => $mergedClient,
        'inboundIds' => $inboundIds,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $url = $base . '/panel/api/clients/update/' . rawurlencode($email);
    $req = new CurlRequest($url);
    $req->setHeaders(panel_xui_api_headers($marzban_list_get));
    $req->setCookie(panel_cookie_path());
    $response = $req->post($configpanel);
    if (is_file(panel_cookie_path())) {
        @unlink(panel_cookie_path());
    }
    return $response;
}
function ResetUserDataUsagex_uisin($usernamepanel, $namepanel)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    $panelFresh = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (is_array($panelFresh)) {
        $marzban_list_get = $panelFresh;
    }
    $base = panel_xui_api_base($marzban_list_get['url_panel']);
    $url = $base . "/panel/api/clients/resetTraffic/" . rawurlencode($usernamepanel);
    $req = new CurlRequest($url);
    $req->setHeaders(panel_xui_api_headers($marzban_list_get));
    $req->setCookie(panel_cookie_path());
    $response = $req->post(array());
    if (is_file(panel_cookie_path())) {
        @unlink(panel_cookie_path());
    }
    return $response;
}
function removeClient($location, $username)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $location, "select");
    login($marzban_list_get['code_panel']);
    $panelFresh = select("marzban_panel", "*", "name_panel", $location, "select");
    if (is_array($panelFresh)) {
        $marzban_list_get = $panelFresh;
    }
    $base = panel_xui_api_base($marzban_list_get['url_panel']);
    $url = $base . "/panel/api/clients/del/" . rawurlencode($username);
    $req = new CurlRequest($url);
    $req->setHeaders(panel_xui_api_headers($marzban_list_get));
    $req->setCookie(panel_cookie_path());
    $response = $req->post(array());
    if (is_file(panel_cookie_path())) {
        @unlink(panel_cookie_path());
    }
    return $response;
}