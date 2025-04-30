<?php
require_once '../auth.php';
require_once '../db_config.php';

if ($auth->isLoggedIn()) {
    // 检查当前会话ID是否与数据库中的一致
    $stmt = $conn->prepare("SELECT session_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if ($user['session_id'] !== session_id()) {
            // 会话ID不匹配，表示用户被强制下线或在其他设备登录
            $auth->logout();
            header('Content-Type: application/json');
            echo json_encode([
                'valid' => false,
                'message' => '您的账号已被强制下线，请重新登录'
            ]);
            exit;
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['valid' => true]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['valid' => false, 'message' => '会话已过期，请重新登录']);
}
exit;