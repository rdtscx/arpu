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
if (!function_exists('curl_init')) {
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
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => false
));
$body = curl_exec($ch);
$errno = curl_errno($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $errno !== 0) {
    fail(502, '转发失败');
}
$decoded = json_decode($body, true);
if (!is_array($decoded) || !isset($decoded['errorCode'])) {
    fail(502, '上游返回异常');
}
http_response_code($status === 200 ? 200 : 502);
echo $body;
