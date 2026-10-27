<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(array('errorCode' => 'error', 'errorMsg' => $msg, 'entity' => null), JSON_UNESCAPED_UNICODE);
    exit;
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
if (!preg_match('/^1[0-9]{10}$/', $phone)) {
    fail(400, '手机号格式不正确');
}

$cacheDir = rtrim(sys_get_temp_dir(), '/') . '/arpu_cache';
$cacheFile = $cacheDir . '/arpu_v1_' . md5($phone) . '.json';
$cacheTtl = 600;
$staleTtl = 86400;

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

if ($cached !== null && $cachedAge < $cacheTtl) {
    header('X-Cache: HIT');
    echo $cached;
    exit;
}

$serveStale = false;
$lock = null;
if ($cached !== null && $cachedAge < $staleTtl) {
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

$params = array(
    'submitPhoneNO' => $phone,
    'submitWarePackageId' => 'A1000529',
    'submitChannel' => 'app_share',
    'submitCode' => 'WWFSX001570',
    'submitEmpCode' => 'WWFSX001570',
    'nbc' => 'ddf05d72a82e5df6698124e53faf3fb9'
);
$url = 'https://ysmapp.bj.10086.cn/app/channel-web/busiCore/customerValidateAndConsumption.action?' . http_build_query($params);

$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_DNS_CACHE_TIMEOUT => 120,
    CURLOPT_ENCODING => '',
    CURLOPT_FOLLOWLOCATION => false
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
    http_response_code($status === 200 ? 200 : 502);
    echo $body;
}
