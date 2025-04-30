<?php
session_start();
require_once 'db_config.php';
require_once 'auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => '用户未登录'
    ]);
    exit;
}

try {
    // 查询数据库获取最新的session_expires_at
    $userId = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT session_expires_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // 更新session过期时间，确保用户活跃时持续延长
        $auth->updateSessionExpiresTime($userId);
        
        echo json_encode([
            'success' => true,
            'session_expires_at' => $row['session_expires_at']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '找不到用户'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '获取会话过期时间失败: ' . $e->getMessage()
    ]);
} 