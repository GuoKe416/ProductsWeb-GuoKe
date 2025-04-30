<?php
header('Content-Type: application/json');

require_once '../db_config.php';

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

try {
    $pdo = new PDO("mysql:host={$servername};dbname={$dbname}", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 获取MySQL版本
    $stmt = $pdo->query("SELECT VERSION() as version");
    $mysqlVersion = $stmt->fetch(PDO::FETCH_ASSOC)['version'];
    
    // 获取最后更新时间
    $lastUpdate = date('Y-m-d H:i:s');
    try {
        // 先尝试从system_logs表获取
        $stmt = $pdo->prepare("SELECT MAX(created_at) as last_update FROM system_logs");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && $result['last_update']) {
            $lastUpdate = $result['last_update'];
        } else {
            // 如果system_logs表没有记录，尝试从其他表获取
            $stmt = $pdo->prepare("SELECT MAX(created_at) as last_update FROM users");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && $result['last_update']) {
                $lastUpdate = $result['last_update'];
            }
        }
    } catch (PDOException $e) {
        // 如果查询出错，使用当前时间
        $lastUpdate = date('Y-m-d H:i:s');
    }
    
    // 获取PHP和服务器信息
    $phpVersion = phpversion();
    $serverOS = php_uname('s') . ' ' . php_uname('r');
    $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    
    // 返回系统信息
    echo json_encode([
        'success' => true,
        'php_version' => $phpVersion,
        'mysql_version' => $mysqlVersion,
        'server_os' => $serverOS,
        'server_software' => $serverSoftware,
        'last_update' => $lastUpdate
    ]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?> 