<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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

try {
    $pdo = new PDO("mysql:host={$servername};dbname={$dbname}", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 获取所有产品
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
    $total = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT * FROM products ORDER BY code");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['total' => $total, 'products' => $products]);
} catch(PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>