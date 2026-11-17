<?php
/**
 * 北京移动资费/ARPU 查询 · 跨域代理
 *
 * 上游 ysmapp.bj.10086.cn 的 Access-Control-Allow-Origin 固定为它自己的域名，
 * 且 OPTIONS 预检返回 403，浏览器无法直连，因此由本文件转发并补 CORS 头。
 *
 * 端点：
 *   POST /_proxy.php   {"phone":"13xxxxxxxxx"}   主通道（号码放请求体，不进访问日志、不进 Referer）
 *   GET  /_proxy.php?phone=13xxxxxxxxx            兼容旧页面（号码会出现在 URL 里）
 *   GET  /_proxy.php?url=<encoded>                透传通道，仅允许上游查询接口本身及其白名单参数
 *   HEAD /_proxy.php?ping=1                       连通性探测（不计数、不查上游）
 *   OPTIONS 任意                                   预检，返回 204
 *
 * 安全：
 *   - 仅允许白名单 Origin（浏览器跨域）；无 Origin 的命令行请求放行但同样受限流约束
 *   - 按 IP 固定窗口限流，超限返回 429 + Retry-After
 *   - 透传只接受「同一主机 + 同一路径 + 白名单参数」，不做出通用 SSRF 代理
 *
 * 缓存：按目标 URL 落盘，10 分钟内命中直接返回；6 小时内可返回过期值并后台刷新。
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

const UPSTREAM = 'https://ysmapp.bj.10086.cn/app/channel-web/busiCore/customerValidateAndConsumption.action';
const API_PARAMS = '&submitWarePackageId=A1000529&submitChannel=app_share'
    . '&submitCode=WWFSX001570&submitEmpCode=WWFSX001570'
    . '&nbc=ddf05d72a82e5df6698124e53faf3fb9';
const ALLOWED_ORIGINS = array(
    'https://rdtscx.github.io',
    'https://a781927.xqryfh.cn',
);
const ALLOWED_PARAMS = array(
    'submitPhoneNO', 'submitWarePackageId', 'submitChannel',
    'submitCode', 'submitEmpCode', 'nbc',
);
const CACHE_TTL = 600;
const STALE_TTL = 21600;
const CONNECT_TIMEOUT = 3;
const TOTAL_TIMEOUT = 8;
const RATE_LIMIT = 60;          // 每个 IP 每分钟最多请求次数（前端双通道并发，一次查询占 2 次；ping 不计）
const RATE_WINDOW = 60;
const UA = 'Mozilla/5.0 (Linux; Android 13; Pixel 6) AppleWebKit/537.36'
    . ' (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

/* ── CORS：只对白名单来源回显 ACAO ── */
$origin = isset($_SERVER['HTTP_ORIGIN']) ? trim($_SERVER['HTTP_ORIGIN']) : '';
$originAllowed = in_array($origin, ALLOWED_ORIGINS, true);
if ($origin !== '' && !$originAllowed) {
    http_response_code(403);
    header('Vary: Origin');
    echo json_encode(array('errorCode' => 'error', 'errorMsg' => '来源不在白名单', 'entity' => null),
        JSON_UNESCAPED_UNICODE);
    exit;
}
if ($originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: false');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, HEAD, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Access-Control-Expose-Headers: X-Cache, X-Cache-Age, X-RateLimit-Remaining');

function respond($code, $body, array $headers = array())
{
    http_response_code($code);
    foreach ($headers as $k => $v) {
        header($k . ': ' . $v);
    }
    echo $body;
    exit;
}

function fail($code, $msg, array $headers = array())
{
    respond($code, json_encode(array(
        'errorCode' => 'error',
        'errorMsg' => $msg,
        'entity' => null,
    ), JSON_UNESCAPED_UNICODE), $headers);
}

/** 按 IP 固定窗口限流。返回 [是否放行, 剩余次数]。拿不到锁时放行，避免把服务弄挂。 */
function rate_limit($ip)
{
    $dir = rtrim(sys_get_temp_dir(), '/') . '/arpu_rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/rl_' . md5($ip) . '.json';
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return array(true, RATE_LIMIT);
    }
    flock($fp, LOCK_EX);
    $now = time();
    $state = json_decode((string) stream_get_contents($fp), true);
    if (!is_array($state) || !isset($state['ts']) || ($now - (int) $state['ts']) >= RATE_WINDOW) {
        $state = array('ts' => $now, 'n' => 0);
    }
    $state['n'] = (int) $state['n'] + 1;
    $remaining = max(0, RATE_LIMIT - (int) $state['n']);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return array((int) $state['n'] <= RATE_LIMIT, $remaining);
}

/** 从请求体（JSON / 表单）或查询串里取号码 */
function input_phone()
{
    $ctype = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $j = json_decode((string) $raw, true);
        return isset($j['phone']) ? trim((string) $j['phone']) : '';
    }
    if (isset($_POST['phone'])) {
        return trim((string) $_POST['phone']);
    }
    return isset($_GET['phone']) ? trim((string) $_GET['phone']) : '';
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
    fail(405, '只支持 GET / POST');
}

/* 连通性探测：不计数、不查上游 */
if (isset($_GET['ping'])) {
    $body = ($method === 'HEAD') ? '' : '{"ok":true}';
    respond(200, $body);
}

$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
list($allowed, $remaining) = rate_limit($ip);
$rateHeaders = array('X-RateLimit-Remaining' => (string) $remaining);
if (!$allowed) {
    fail(429, '请求过于频繁，请稍后再试',
        $rateHeaders + array('Retry-After' => (string) RATE_WINDOW));
}

$phone = '';
$target = '';

if (isset($_GET['url']) && $_GET['url'] !== '') {
    /* 透传通道：只允许上游查询接口本身 + 白名单参数，避免变成通用 SSRF 代理 */
    $u = trim((string) $_GET['url']);
    $p = parse_url($u);
    $up = parse_url(UPSTREAM);
    if (!$p || empty($p['host']) || empty($p['path'])) {
        fail(400, 'url 参数不合法');
    }
    if (($p['scheme'] ?? '') !== 'https'
        || strcasecmp($p['host'], $up['host']) !== 0
        || $p['path'] !== $up['path']) {
        fail(403, '只允许转发上游查询接口');
    }
    parse_str(isset($p['query']) ? $p['query'] : '', $q);
    foreach ($q as $k => $v) {
        if (!in_array($k, ALLOWED_PARAMS, true)) {
            fail(400, '不支持的参数：' . $k);
        }
    }
    if (!empty($q['submitPhoneNO']) && !preg_match('/^1[0-9]{10}$/', (string) $q['submitPhoneNO'])) {
        fail(400, '手机号格式不正确');
    }
    $target = $u;
} else {
    $phone = input_phone();
    if (!preg_match('/^1[0-9]{10}$/', $phone)) {
        fail(400, '手机号格式不正确', $rateHeaders);
    }
    $target = UPSTREAM . '?submitPhoneNO=' . urlencode($phone) . API_PARAMS;
}

$cacheDir = rtrim(sys_get_temp_dir(), '/') . '/arpu_cache';
$cacheFile = $cacheDir . '/arpu_v1_' . md5($target) . '.json';

$cached = null;
$cachedAge = 0;
if (is_file($cacheFile)) {
    $rawCache = @file_get_contents($cacheFile);
    $decodedCache = $rawCache === false ? null : json_decode($rawCache, true);
    if (is_array($decodedCache) && isset($decodedCache['errorCode'])) {
        $cached = $rawCache;
        $cachedAge = time() - (int) @filemtime($cacheFile);
    }
}

if ($cached !== null && $cachedAge < CACHE_TTL) {
    respond(200, $cached, $rateHeaders + array(
        'X-Cache' => 'HIT',
        'X-Cache-Age' => (string) $cachedAge,
    ));
}

$serveStale = false;
$lock = null;
if ($cached !== null && $cachedAge < STALE_TTL) {
    /* 先回过期值，再后台刷新，避免上游变慢时页面一直转圈 */
    header('X-Cache: STALE');
    header('X-Cache-Age: ' . $cachedAge);
    foreach ($rateHeaders as $k => $v) {
        header($k . ': ' . $v);
    }
    echo $cached;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ignore_user_abort(true);
        if (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
    }
    $serveStale = true;
    $lock = @fopen($cacheFile . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) {
            fclose($lock);
        }
        exit;
    }
} else {
    header('X-Cache: MISS');
}

if (!function_exists('curl_init')) {
    if ($serveStale) {
        exit;
    }
    fail(502, '服务器未启用 curl');
}

/* 单飞（singleflight）：前端并发两路请求时，只让一路回源，其余等它的结果。
 * 没有这层保护，双通道会把上游请求量直接翻倍（源站本来就容易限流）。 */
$sfLock = null;
if (!$serveStale) {
    $sfLock = @fopen($cacheFile . '.sf', 'c');
    $owned = $sfLock && flock($sfLock, LOCK_EX | LOCK_NB);
    if (!$owned) {
        if ($sfLock) {
            fclose($sfLock);
            $sfLock = null;
        }
        $waitUntil = microtime(true) + 6.0;
        while (microtime(true) < $waitUntil) {
            usleep(100000);
            clearstatcache(true, $cacheFile);
            if (is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < CACHE_TTL) {
                $fresh = @file_get_contents($cacheFile);
                if ($fresh !== false) {
                    respond(200, $fresh, $rateHeaders + array('X-Cache' => 'HIT', 'X-Cache-Age' => '0'));
                }
            }
        }
        // 等不到就自己回源，避免请求一直挂在那里
    }
}

function release_sf($fp)
{
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

$ch = curl_init($target);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
    CURLOPT_TIMEOUT => TOTAL_TIMEOUT,
    CURLOPT_DNS_CACHE_TIMEOUT => 120,
    CURLOPT_ENCODING => '',
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_USERAGENT => UA,
    CURLOPT_HTTPHEADER => array(
        'Accept: application/json, text/plain, */*',
        'Referer: https://ysmapp.bj.10086.cn/app/channel-web/',
    ),
));
$body = curl_exec($ch);
$errno = curl_errno($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $errno !== 0) {
    if ($serveStale) {
        exit;
    }
    release_sf($sfLock);
    fail(502, '转发失败', $rateHeaders);
}
$decoded = json_decode($body, true);
if (!is_array($decoded) || !isset($decoded['errorCode'])) {
    if ($serveStale) {
        exit;
    }
    release_sf($sfLock);
    fail(502, '上游返回异常', $rateHeaders);
}

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0700, true);
}
@file_put_contents($cacheFile, $body, LOCK_EX);
@chmod($cacheFile, 0600);
if ($lock) {
    flock($lock, LOCK_UN);
    fclose($lock);
}
release_sf($sfLock);

if (!$serveStale) {
    respond($status === 200 ? 200 : 502, $body, $rateHeaders + array('X-Cache' => 'MISS'));
}
