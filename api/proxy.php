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

$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
if (empty($phone) || strlen($phone) !== 11) {
    http_response_code(400);
    echo json_encode(['errorCode' => 'error', 'errorMsg' => '请输入正确的手机号']);
    exit;
}

$target_url = 'http://ysmapp.bj.10086.cn/app/channel-web/busiCore/customerValidateAndConsumption.action'
    . '?submitPhoneNO=' . urlencode($phone)
    . '&submitWarePackageId=A1000529'
    . '&submitChannel=app_share'
    . '&submitCode=WWFSX001570'
    . '&submitEmpCode=WWFSX001570'
    . '&nbc=ddf05d72a82e5df6698124e53faf3fb9';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $target_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1',
    'Referer: http://ysmapp.bj.10086.cn/',
    'Accept: application/json, text/plain, */*',
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    http_response_code(502);
    echo json_encode(['errorCode' => 'error', 'errorMsg' => '代理请求失败: ' . $curl_error]);
    exit;
}

if ($http_code === 200 && $response) {
    echo $response;
} else {
    http_response_code($http_code ?: 500);
    echo json_encode(['errorCode' => 'error', 'errorMsg' => '接口返回异常', 'response' => $response]);
}
