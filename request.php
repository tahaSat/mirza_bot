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
function mirza_telegram_proxy_state_path(): string
{
    global $telegram_proxy_state_file;
    if (isset($telegram_proxy_state_file) && is_string($telegram_proxy_state_file) && $telegram_proxy_state_file !== '') {
        return $telegram_proxy_state_file;
    }
    return __DIR__ . '/storage/cache/telegram_proxy_state.json';
}

function mirza_telegram_proxy_state_default(): array
{
    return [
        'active_index' => 0,
        'last_failover_at' => 0,
        'updated_at' => time(),
    ];
}

function mirza_telegram_proxy_list(): array
{
    global $telegram_proxies, $telegram_proxy, $telegram_proxy_type;

    $list = [];
    if (isset($telegram_proxies) && is_array($telegram_proxies)) {
        $list = $telegram_proxies;
    } elseif (!empty($telegram_proxy)) {
        $list = [[
            'name' => 'legacy',
            'proxy' => $telegram_proxy,
            'type' => $telegram_proxy_type ?? 'http',
        ]];
    }

    $normalized = [];
    foreach ($list as $idx => $item) {
        if (!is_array($item) || empty($item['proxy'])) {
            continue;
        }
        $normalized[] = [
            'name' => (string) ($item['name'] ?? ('proxy-' . $idx)),
            'proxy' => (string) $item['proxy'],
            'type' => strtolower((string) ($item['type'] ?? 'http')),
        ];
    }

    if (empty($normalized) && !empty($telegram_proxy)) {
        $normalized[] = [
            'name' => 'legacy',
            'proxy' => (string) $telegram_proxy,
            'type' => strtolower((string) ($telegram_proxy_type ?? 'http')),
        ];
    }

    return $normalized;
}

function mirza_telegram_proxy_is_transport_error($curlError): bool
{
    return is_string($curlError) && trim($curlError) !== '';
}

function mirza_telegram_proxy_with_state_lock(callable $callback): array
{
    $stateFile = mirza_telegram_proxy_state_path();
    $dir = dirname($stateFile);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return mirza_telegram_proxy_state_default();
    }

    $handle = fopen($stateFile, 'c+');
    if ($handle === false) {
        return mirza_telegram_proxy_state_default();
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return mirza_telegram_proxy_state_default();
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($state)) {
            $state = [];
        }
        $state = array_merge(mirza_telegram_proxy_state_default(), $state);
        $newState = $callback($state);
        if (!is_array($newState)) {
            $newState = $state;
        }
        $newState = array_merge(mirza_telegram_proxy_state_default(), $newState);
        $newState['updated_at'] = time();

        $stateToPersist = $newState;
        foreach (['_rotation_from', '_rotation_to', '_rotation_changed'] as $tmpKey) {
            if (array_key_exists($tmpKey, $stateToPersist)) {
                unset($stateToPersist[$tmpKey]);
            }
        }

        $encoded = json_encode($stateToPersist, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded !== false) {
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded);
            fflush($handle);
        }

        flock($handle, LOCK_UN);
        fclose($handle);
        return $newState;
    } catch (Throwable $e) {
        try {
            flock($handle, LOCK_UN);
        } catch (Throwable $ignored) {
        }
        fclose($handle);
        return mirza_telegram_proxy_state_default();
    }
}

function mirza_telegram_proxy_get_state(): array
{
    $list = mirza_telegram_proxy_list();
    $count = count($list);

    $state = mirza_telegram_proxy_with_state_lock(function ($state) use ($count) {
        $index = (int) ($state['active_index'] ?? 0);
        if ($count <= 0 || $index < 0 || $index >= $count) {
            $index = 0;
        }
        $state['active_index'] = $index;
        return $state;
    });

    global $telegram_proxy_prefer_primary_interval_sec;
    $preferPrimaryInterval = isset($telegram_proxy_prefer_primary_interval_sec) ? max(0, (int) $telegram_proxy_prefer_primary_interval_sec) : 0;
    if ($count > 1 && $preferPrimaryInterval > 0 && (int) $state['active_index'] !== 0) {
        $lastFailoverAt = (int) ($state['last_failover_at'] ?? 0);
        if ($lastFailoverAt > 0 && (time() - $lastFailoverAt) >= $preferPrimaryInterval) {
            $state = mirza_telegram_proxy_with_state_lock(function ($lockedState) {
                $lockedState['active_index'] = 0;
                return $lockedState;
            });
        }
    }

    return $state;
}

function mirza_telegram_proxy_active_index(): int
{
    $state = mirza_telegram_proxy_get_state();
    return (int) ($state['active_index'] ?? 0);
}

function mirza_telegram_proxy_get_active(): ?array
{
    $list = mirza_telegram_proxy_list();
    if (empty($list)) {
        return null;
    }

    $index = mirza_telegram_proxy_active_index();
    if (!isset($list[$index])) {
        $index = 0;
    }
    $active = $list[$index];
    $active['index'] = $index;
    return $active;
}

function mirza_telegram_proxy_rotate_on_failure(string $reason = ''): array
{
    $list = mirza_telegram_proxy_list();
    $count = count($list);
    $result = [
        'rotated' => false,
        'from' => 0,
        'to' => 0,
        'proxy' => null,
        'name' => null,
        'reason' => $reason,
    ];

    if ($count <= 1) {
        $active = mirza_telegram_proxy_get_active();
        if (is_array($active)) {
            $result['proxy'] = $active['proxy'];
            $result['name'] = $active['name'];
        }
        return $result;
    }

    global $telegram_proxy_failover_cooldown_sec;
    $cooldown = isset($telegram_proxy_failover_cooldown_sec) ? max(0, (int) $telegram_proxy_failover_cooldown_sec) : 0;

    $state = mirza_telegram_proxy_with_state_lock(function ($state) use ($count, $cooldown) {
        $now = time();
        $from = (int) ($state['active_index'] ?? 0);
        if ($from < 0 || $from >= $count) {
            $from = 0;
        }
        $lastFailoverAt = (int) ($state['last_failover_at'] ?? 0);
        if ($cooldown > 0 && $lastFailoverAt > 0 && ($now - $lastFailoverAt) < $cooldown) {
            $state['_rotation_from'] = $from;
            $state['_rotation_to'] = $from;
            $state['_rotation_changed'] = false;
            return $state;
        }

        $to = ($from + 1) % $count;
        $state['active_index'] = $to;
        $state['last_failover_at'] = $now;
        $state['_rotation_from'] = $from;
        $state['_rotation_to'] = $to;
        $state['_rotation_changed'] = true;
        return $state;
    });

    $from = (int) ($state['_rotation_from'] ?? 0);
    $to = (int) ($state['_rotation_to'] ?? $from);
    $changed = !empty($state['_rotation_changed']);

    $result['rotated'] = $changed;
    $result['from'] = $from;
    $result['to'] = $to;
    if (isset($list[$to])) {
        $result['proxy'] = $list[$to]['proxy'];
        $result['name'] = $list[$to]['name'];
    }
    return $result;
}

function mirza_telegram_proxy_label($index = null): string
{
    $list = mirza_telegram_proxy_list();
    if (empty($list)) {
        return 'none';
    }

    if ($index === null) {
        $index = mirza_telegram_proxy_active_index();
    }
    $index = (int) $index;
    if (!isset($list[$index])) {
        $index = 0;
    }
    $item = $list[$index];
    return '#' . $index . ' ' . ($item['name'] ?? 'proxy') . ' (' . ($item['proxy'] ?? 'unknown') . ', ' . ($item['type'] ?? 'http') . ')';
}

/**
 * Proxy only for api.telegram.org — never use for panel or other outbound URLs.
 */
function apply_telegram_proxy($ch, $url = null, $forcedIndex = null)
{
    if ($url !== null && stripos($url, 'api.telegram.org') === false) {
        curl_disable_proxy($ch);
        return;
    }

    $list = mirza_telegram_proxy_list();
    if (empty($list)) {
        curl_disable_proxy($ch);
        return;
    }

    $index = $forcedIndex === null ? mirza_telegram_proxy_active_index() : (int) $forcedIndex;
    if (!isset($list[$index])) {
        $index = 0;
    }
    $proxyConfig = $list[$index];
    $proxy = (string) ($proxyConfig['proxy'] ?? '');
    if ($proxy === '') {
        curl_disable_proxy($ch);
        return;
    }

    curl_setopt($ch, CURLOPT_PROXY, $proxy);
    $proxyType = strtolower((string) ($proxyConfig['type'] ?? 'http'));
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