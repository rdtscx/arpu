<?php
/**
 * 北京移动资费/ARPU 查询 · 跨域代理
 *
 * 上游 ysmapp.bj.10086.cn 的 Access-Control-Allow-Origin 固定为它自己的域名，
 * 且 OPTIONS 预检返回 403，浏览器无法直连，因此由本文件转发并补 CORS 头。
 *
 * 端点：
 *   GET  /_proxy.php?phone=13xxxxxxxxx   前端主通道，内部拼上游参数
 *   GET  /_proxy.php?url=<encoded>       透传通道，仅允许白名单主机
 *   HEAD /_proxy.php?ping=1              前端连通性探测
 *   OPTIONS 任意                          预检，返回 204
 *
 * 缓存：按手机号落盘，10 分钟内命中直接返回；6 小时内可返回过期值并后台刷新。
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Max-Age: 86400');

const UPSTREAM = 'https://ysmapp.bj.10086.cn/app/channel-web/busiCore/customerValidateAndConsumption.action';
const API_PARAMS = '&submitWarePackageId=A1000529&submitChannel=app_share'
    . '&submitCode=WWFSX001570&submitEmpCode=WWFSX001570'
    . '&nbc=ddf05d72a82e5df6698124e53faf3fb9';
const ALLOWED_HOSTS = array('ysmapp.bj.10086.cn');
const CACHE_TTL = 600;
const STALE_TTL = 21600;
const CONNECT_TIMEOUT = 3;
const TOTAL_TIMEOUT = 8;
const UA = 'Mozilla/5.0 (Linux; Android 13; Pixel 6) AppleWebKit/537.36'
    . ' (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

function respond($code, $body, array $headers = array()) {
    http_response_code($code);
    foreach ($headers as $k => $v) {
        header($k . ': ' . $v);
    }
    echo $body;
    exit;
}

function fail($code, $msg) {
    respond($code, json_encode(array(
        'errorCode' => 'error',
        'errorMsg' => $msg,
        'entity' => null,
    ), JSON_UNESCAPED_UNICODE));
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET' && $method !== 'HEAD') {
    fail(405, '只支持 GET');
}

// 前端用 HEAD ?ping=1 判断代理在线，不能走号码校验
if (isset($_GET['ping'])) {
    respond(200, $method === 'HEAD' ? '' : '{"ok":true}');
}

$phone = '';
$target = '';

if (isset($_GET['url']) && $_GET['url'] !== '') {
    $parsed = parse_url(trim($_GET['url']));
    if (!$parsed || empty($parsed['host'])) {
        fail(400, 'url 参数不合法');
    }
    if (($parsed['scheme'] ?? '') !== 'https'
        || !in_array($parsed['host'], ALLOWED_HOSTS, true)) {
        fail(403, '不允许的主机');
    }
    $target = $_GET['url'];
} else {
    $phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
    if (!preg_match('/^1[0-9]{10}$/', $phone)) {
        fail(400, '手机号格式不正确');
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
    respond(200, $cached, array(
        'X-Cache' => 'HIT',
        'X-Cache-Age' => $cachedAge,
    ));
}

$serveStale = false;
$lock = null;
if ($cached !== null && $cachedAge < STALE_TTL) {
    // 先返回过期值，再后台刷新，避免上游变慢时页面一直转圈
    header('X-Cache: STALE');
    header('X-Cache-Age: ' . $cachedAge);
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
    fail(502, '转发失败');
}
$decoded = json_decode($body, true);
if (!is_array($decoded) || !isset($decoded['errorCode'])) {
    if ($serveStale) {
        exit;
    }
    fail(502, '上游返回异常');
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

if (!$serveStale) {
    respond($status === 200 ? 200 : 502, $body, array('X-Cache' => 'MISS'));
}
