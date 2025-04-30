<?php
// 设置响应头为 JSON 格式
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 初始化返回数据
$response = [];

// 处理百度API token请求
if (isset($_GET['action']) && $_GET['action'] === 'get_baidu_token') {
    // 检查是否启用百度API
    if (getConfig('enable_baidu_api', '0') !== '1') {
        echo json_encode(['error' => 'Baidu API is not enabled']);
        exit;
    }
    
    $apiKey = getConfig('baidu_api_key', '');
    $apiSecret = getConfig('baidu_api_secret', '');
    
    if (empty($apiKey) || empty($apiSecret)) {
        echo json_encode(['error' => 'Baidu API credentials are not configured']);
        exit;
    }
    
    $url = "https://aip.baidubce.com/oauth/2.0/token?grant_type=client_credentials&client_id={$apiKey}&client_secret={$apiSecret}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    echo $result;
    exit;
}

// 从 URL 查询参数中获取商品编码
$codeFromUrl = isset($_GET['code']) ? trim($_GET['code']) : '';

// 从请求头中获取商品编码
$codeFromHeader = '';
if (isset($_SERVER['HTTP_CODE'])) {
    $codeFromHeader = trim($_SERVER['HTTP_CODE']);
}

// 从请求体中获取商品编码
$codeFromBody = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $bodyData = json_decode($rawBody, true);
    if (is_array($bodyData) && isset($bodyData['code'])) {
        $codeFromBody = trim($bodyData['code']);
    }
}

// 合并所有可能的商品编码来源
$code = $codeFromUrl ?: ($codeFromHeader ?: $codeFromBody);

// 验证商品编码是否为空
if (empty($code)) {
    $response['link'] = '商品编码不能为空，请检查输入是否正确';
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 引入数据库配置文件
require 'db_config.php';

// 查询数据库
$sql = "SELECT link FROM products WHERE code = ?";
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    $response['link'] = 'SQL准备失败，请稍后重试或联系管理员';
    echo json_encode($response, JSON_UNESCAPED_UNICODE); // 确保中文正常显示
    exit;
}

// 绑定参数并执行查询
$stmt->bind_param("s", $code); // "s" 表示字符串类型
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // 获取查询结果
    $row = $result->fetch_assoc();
    
    // 如果链接字段为空，视为未找到
    if (empty($row['link'])) {
        $response['link'] = "未找到商品货号为{$code}的文件，可能暂未记录该商品数据，或您输入的编码有误";
    } else {
        // 输出实际链接
        $response['link'] = $row['link'];
    }
} else {
    // 如果未找到对应的商品链接，返回提示信息
    $response['link'] = "未找到编码为{$code}的文件，可能暂未记录该商品数据，或您输入的编码有误";
}

// 关闭语句和连接
$stmt->close();
$conn->close();

// 返回 JSON 格式的响应，并确保中文正常显示
echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>