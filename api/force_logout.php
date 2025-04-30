<?php
require_once '../auth.php';
require_once '../db_config.php';
require_once '../Logger.php';

// 初始化日志记录器
$logger = new Logger();

// 要求管理员权限
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $user_id = intval($_POST['user_id']);
    $admin_id = $_SESSION['user_id'];
    
    // 不能强制退出自己
    if ($user_id === $admin_id) {
        $logger->log('强制下线', '管理员尝试强制下线自己的账号，操作被拒绝', [
            'admin_id' => $admin_id
        ]);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '不能强制退出自己的账号']);
        exit;
    }
    
    // 获取用户的会话ID和用户名
    $stmt = $conn->prepare("SELECT session_id, username FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_data = $result->fetch_assoc();
    
    if (!$user_data || !$user_data['session_id']) {
        $logger->log('强制下线', '管理员尝试强制下线一个未登录或不存在的用户', [
            'admin_id' => $admin_id,
            'target_user_id' => $user_id
        ]);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '用户未登录或不存在']);
        exit;
    }
    
    // 先记录到logout_sessions表
    $stmt = $conn->prepare("INSERT INTO logout_sessions (session_id, user_id, logout_reason) VALUES (?, ?, 'forced_logout')");
    $stmt->bind_param("si", $user_data['session_id'], $user_id);
    $stmt->execute();
    
    // 更新用户状态为强制退出
    $stmt = $conn->prepare("UPDATE users SET session_id = NULL, login_status = 'forced_offline', force_logout_time = NOW(), force_logout_by = ? WHERE id = ?");
    $stmt->bind_param("ii", $admin_id, $user_id);
    $result = $stmt->execute();
    
    header('Content-Type: application/json');
    if ($result) {
        // 获取管理员用户名
        $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $admin_data = $stmt->get_result()->fetch_assoc();
        
        $logger->log('强制下线', '管理员成功强制用户下线', [
            'admin_id' => $admin_id,
            'admin_username' => $admin_data['username'],
            'target_user_id' => $user_id,
            'target_username' => $user_data['username']
        ]);
        echo json_encode(['success' => true, 'message' => '用户已被强制下线']);
    } else {
        $logger->log('强制下线', '管理员强制用户下线失败', [
            'admin_id' => $admin_id,
            'target_user_id' => $user_id,
            'error' => $conn->error
        ]);
        echo json_encode(['success' => false, 'message' => '操作失败']);
    }
    exit;
}

$logger->log('强制下线', '收到无效的强制下线请求', [
    'method' => $_SERVER['REQUEST_METHOD'],
    'has_user_id' => isset($_POST['user_id'])
]);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => '无效的请求']);
exit; 