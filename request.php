<?php
require_once 'config.php';
if (is_file(__DIR__ . '/polling_log.php')) {
    require_once __DIR__ . '/polling_log.php';
}

function mirza_server_log_dir(): string
{
    return __DIR__ . '/storage/logs';
}

function mirza_server_log(string $channel, array $context = []): void
{
    $channel = preg_replace('/[^a-zA-Z0-9._-]/', '_', $channel);
    if ($channel === null || $channel === '') {
        $channel = 'app';
    }

    $dir = mirza_server_log_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log('mirza_server_log: unable to create log dir ' . $dir);
        return;
    }

    $record = [
        'time' => date('c'),
        'channel' => $channel,
        'context' => $context,
    ];
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = json_encode([
            'time' => date('c'),
            'channel' => $channel,
            'context' => ['encode_error' => true],
        ]);
    }
    @file_put_contents($dir . '/' . $channel . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Direct connections only (panels, payments, local URLs). Ignores HTTP_PROXY env vars.
 */
function curl_disable_proxy($ch)
{
    curl_setopt($ch, CURLOPT_PROXY, '');
    if (defined('CURLOPT_NOPROXY')) {
        curl_setopt($ch, CURLOPT_NOPROXY, '*');
    }
}

/**
 * Some x-ui/nginx setups only accept HTTP/1.1 (ALPN h2 causes 403 or handshake issues).
 */
function curl_force_panel_http11($ch): void
{
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    if (defined('CURLOPT_SSL_ENABLE_ALPN')) {
        curl_setopt($ch, CURLOPT_SSL_ENABLE_ALPN, 0);
    }
}

/**
 * Proxy only for api.telegram.org — never use for panel or other outbound URLs.
 */
function apply_telegram_proxy($ch, $url = null)
{
    if ($url !== null && stripos($url, 'api.telegram.org') === false) {
        curl_disable_proxy($ch);
        return;
    }

    global $telegram_proxy, $telegram_proxy_type;

    if (empty($telegram_proxy)) {
        curl_disable_proxy($ch);
        return;
    }

    curl_setopt($ch, CURLOPT_PROXY, $telegram_proxy);
    $proxyType = strtolower((string) ($telegram_proxy_type ?? 'http'));
    if ($proxyType === 'socks5') {
        if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        } else {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
        return;
    }

    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
}

class CurlRequest {
    private $url;
    private $headers = [];
    private $timeout = null;
    private $authToken = null;
    private $api_key = null;
    private $cookie = null;
    public function __construct($url) {
        global $request_exec_timeout;
        $this->url = $url;
        $this->timeout = $request_exec_timeout;
    }

    public function setTimeout($seconds) {
        $this->timeout = $seconds;
    }

    public function setHeaders(array $headers) {
        $this->headers = array_merge($this->headers, $headers);
    }

    public function setBearerToken($token) {
        $this->authToken = $token;
    }
    
    public function api_key($token) {
        $this->api_key = $token;
    }

    public function setCookie($cookieStr) {
        $this->cookie = $cookieStr;
    }

    private function prepareHeaders() {
        $headers = $this->headers;

        if ($this->authToken) {
            $headers[] = "Authorization: Bearer {$this->authToken}";
        }
        if ($this->api_key) {
            $headers[] = $this->authToken;
        }

        return $headers;
    }

    private function execute($method, $data = null) {
        $this->timeout = !$this->timeout  ?  10000 : $this->timeout;
        $startedAt = microtime(true);
        $ch = curl_init();
        curl_disable_proxy($ch);
        curl_force_panel_http11($ch);
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $finalHeaders = $this->prepareHeaders();
        if (!empty($finalHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $finalHeaders);
        }
        if ($this->cookie) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie);   
        }
        if ($data) {
            if (is_array($data)) {
                $data = http_build_query($data);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $response = curl_exec($ch);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            if (function_exists('mirza_polling_log') && mirza_polling_debug_enabled()) {
                mirza_polling_log('panel_http_error', [
                    'method' => strtoupper($method),
                    'url' => mirza_panel_url_for_log($this->url),
                    'timeout_ms' => $this->timeout,
                    'duration_ms' => $durationMs,
                    'error' => $error,
                ]);
            }
            return [
                'status' => null,
                'body' => null,
                'error' => $error,
            ];
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (function_exists('mirza_polling_log') && mirza_polling_debug_enabled()) {
            global $telegram_polling_slow_panel_ms;
            $slowMs = isset($telegram_polling_slow_panel_ms) ? (int) $telegram_polling_slow_panel_ms : 3000;
            if ($durationMs >= $slowMs || ($httpCode !== null && $httpCode >= 400)) {
                mirza_polling_log('panel_http_slow_or_error', [
                    'method' => strtoupper($method),
                    'url' => mirza_panel_url_for_log($this->url),
                    'timeout_ms' => $this->timeout,
                    'duration_ms' => $durationMs,
                    'http_code' => $httpCode,
                ]);
            }
        }

        return [
            'status' => $httpCode,
            'body' => $response
        ];
    }

    public function get() {
        return $this->execute("GET");
    }

    public function post($data) {
        return $this->execute("POST", $data);
    }

    public function put($data) {
        return $this->execute("PUT", $data);
    }

    public function delete($data = null) {
        return $this->execute("DELETE", $data);
    }
    public function PATCH($data = null){
        return $this->execute('PATCH',$data);
    }
}