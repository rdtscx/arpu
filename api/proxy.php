<?php
$allowed_origins = ['http://localhost', 'http://127.0.0.1', 'https://arpu.151365.cc', 'http://arpu.151365.cc'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 限流：同 IP 每分钟最多 15 次，防被刷/被当代理
function rate_limit() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
    $dir = sys_get_temp_dir() . '/arpu_rate';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = $dir . '/' . md5($ip);
    $now = time();
    $win = $now; $cnt = 0;
    $data = @file_get_contents($file);
    if ($data !== false) {
        $parts = explode('|', $data);
        if (count($parts) === 2) { $win = (int)$parts[0]; $cnt = (int)$parts[1]; }
    }
    if ($now - $win >= 60) { $win = $now; $cnt = 0; }
    $cnt++;
    if ($cnt > 15) {
        http_response_code(429);
        echo json_encode(['errorCode' => 'error', 'errorMsg' => '请求过于频繁，请稍后再试']);
        exit;
    }
    @file_put_contents($file, $win . '|' . $cnt, LOCK_EX);
}
rate_limit();

$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
if (empty($phone) || strlen($phone) !== 11) {
    http_response_code(400);
    echo json_encode(['errorCode' => 'error', 'errorMsg' => '请输入正确的手机号']);
    exit;
}

// 接口分发：src=native 走移动原生(默认)，src=backup 走备用封装接口
$src = isset($_GET['src']) && $_GET['src'] === 'backup' ? 'backup' : 'native';
if ($src === 'backup') {
    $target_url = 'https://arpu.151365.cc/check?phone_no=' . urlencode($phone);
    $headers = [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1',
        'Accept: application/json, text/plain, */*',
    ];
} else {
    $target_url = 'http://ysmapp.bj.10086.cn/app/channel-web/busiCore/customerValidateAndConsumption.action'
        . '?submitPhoneNO=' . urlencode($phone)
        . '&submitWarePackageId=A1000529'
        . '&submitChannel=app_share'
        . '&submitCode=WWFSX001570'
        . '&submitEmpCode=WWFSX001570'
        . '&nbc=ddf05d72a82e5df6698124e53faf3fb9';
    $headers = [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1',
        'Referer: http://ysmapp.bj.10086.cn/',
        'Accept: application/json, text/plain, */*',
    ];
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $target_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    @error_log('[arpu] src=' . $src . ' phone=' . $phone . ' curl=' . $curl_error);
    http_response_code(502);
    echo json_encode(['errorCode' => 'error', 'errorMsg' => '代理请求失败: ' . $curl_error]);
    exit;
}

if ($http_code === 200 && $response) {
    echo $response;
} else {
    @error_log('[arpu] src=' . $src . ' phone=' . $phone . ' http=' . $http_code);
    http_response_code($http_code ?: 500);
    echo json_encode(['errorCode' => 'error', 'errorMsg' => '接口返回异常', 'response' => $response]);
}
