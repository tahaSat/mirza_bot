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
    $url = $marzban_list_get['url_panel'] . "/panel/api/inbounds/getClientTraffics/$username";
    $req = new CurlRequest($url);
    $req->setHeaders(panel_xui_api_headers($marzban_list_get));
    $req->setCookie(panel_cookie_path());
    $response = $req->get();

    if (isset($response['body'])) {
        $decodedBody = json_decode($response['body'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedBody)) {
            if (isset($decodedBody['success']) && $decodedBody['success'] === false) {
                $response['error'] = $decodedBody['msg'] ?? 'Unknown panel error';
            }
        }
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
    $config = array(
        "id" => intval($inboundid),
        'settings' => json_encode(array(
            'clients' => array(
                array(
                    "id" => $Uuid,
                    "flow" => $Flow,
                    "email" => $usernameac,
                    "totalGB" => $Total,
                    "expiryTime" => $timeservice,
                    "enable" => true,
                    "tgId" => "",
                    "subId" => $subid,
                    "reset" => 0,
                    "comment" => $note
                )
            ),
            'decryption' => 'none',
            'fallbacks' => array(),
        ))
    );
    if (!isset($usernameac))
        return array(
            'status' => 500,
            'msg' => 'username is null'
        );
    $configpanel = json_encode($config, true);
    $url = $marzban_list_get['url_panel'] . '/panel/api/inbounds/addClient';
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
    $configpanel = json_encode($config, true);
    $url = $marzban_list_get['url_panel'] . '/panel/api/inbounds/updateClient/' . $uuid;
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
    $data_user = get_clinets($usernamepanel, $namepanel);
    $data_user = json_decode($data_user['body'], true)['obj'];
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    $url = $marzban_list_get['url_panel'] . "/panel/api/inbounds/{$data_user['inboundId']}/resetClientTraffic/" . $usernamepanel;
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
    $url = $marzban_list_get['url_panel'] . "/panel/api/inbounds/{$marzban_list_get['inboundid']}/delClientByEmail/" . $username;
    $req = new CurlRequest($url);
    $req->setHeaders(panel_xui_api_headers($marzban_list_get));
    $req->setCookie(panel_cookie_path());
    $response = $req->post(array());
    if (is_file(panel_cookie_path())) {
        @unlink(panel_cookie_path());
    }
    return $response;
}