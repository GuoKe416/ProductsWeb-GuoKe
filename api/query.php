<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 开始会话
session_start();

// 检查用户是否已登录，未登录则拒绝访问
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '未授权访问']);
    exit;
}

// 检查请求来源，防止直接通过URL访问API
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

// 如果是直接通过URL访问API（没有Referer）且不是AJAX请求，则拒绝访问
if (empty($referer) && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    // 直接重定向到首页
    header('Location: ../index.php');
    exit;
}

require_once '../db_config.php';

// 处理跨域预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // 获取SQL和参数
    $sql = isset($_GET['sql']) ? urldecode($_GET['sql']) : '';
    $params = isset($_GET['params']) ? json_decode(urldecode($_GET['params']), true) : [];
    
    // 处理规格数据查询
    if (isset($_GET['action']) && $_GET['action'] === 'get_specs') {
        $product_id = isset($_GET['product_id']) ? $_GET['product_id'] : '';
        if (empty($product_id)) {
            throw new Exception('产品ID不能为空');
        }
        $sql = "SELECT * FROM product_specs WHERE product_id = ?";
        $params = [$product_id];
    }

    if (empty($sql)) {
        throw new Exception('SQL语句不能为空');
    }

    // 设置字符集
    $conn->set_charset('utf8mb4');

    // 准备SQL语句
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('SQL准备失败: ' . $conn->error);
    }

    // 绑定参数
    if (!empty($params)) {
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
    }

    // 执行查询
    if (!$stmt->execute()) {
        throw new Exception('SQL执行失败: ' . $stmt->error);
    }

    // 获取结果
    $result = $stmt->get_result();
    $data = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $result->free();
    }

    // 关闭连接
    $stmt->close();

    // 返回结果
    echo json_encode([
        'success' => true,
        'data' => $data
    ]);

} catch (Exception $e) {
    // 返回错误信息
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}