<?php
ob_clean(); // 清除之前的输出缓冲
header('Content-Type: application/json; charset=utf-8');

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 引入数据库连接和Auth类
require_once 'db_config.php';
require_once 'auth.php';

// 初始化认证对象
$auth = new Auth($conn);

// 初始化响应数组
$response = [
    'authenticated' => false,
    'session_expired' => false,
    'message' => '',
    'reason' => '',
    'username' => ''
];

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 检查请求来源，防止直接通过URL访问API
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

// 如果是直接通过URL访问API（没有Referer）且不是AJAX请求，则拒绝访问
if (empty($referer) && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    // 直接重定向到首页
    header('Location: index.php');
    exit;
}

// 检查会话状态
$isAuthenticated = isset($_SESSION['user_id']);
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$isSessionExpired = false;
$reason = '';
$message = '';

// 检查用户会话是否仍有效
if ($isAuthenticated) {
    // 检查数据库中的会话状态
    $stmt = $conn->prepare("SELECT session_id, login_status, session_expires_at, DATE_FORMAT(session_expires_at, '%Y-%m-%d %H:%i:%s') as expires_formatted FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $currentTime = date('Y-m-d H:i:s');
        
        // 检查会话是否过期（数据库中的时间）
        if ($currentTime > $user['expires_formatted']) {
            $isAuthenticated = false;
            $isSessionExpired = true;
            $reason = 'expired';
            $message = '您的会话已过期，请重新登录。';
        } 
        // 检查会话是否有效（PHP会话中的时间）
        else if (isset($_SESSION['expires_time']) && $_SESSION['expires_time'] < time()) {
            $isAuthenticated = false;
            $isSessionExpired = true;
            $reason = 'expired';
            $message = '您的会话已过期，请重新登录。';
        }
        // 检查是否被强制下线
        else if ($user['login_status'] === 'forced_offline' || $user['login_status'] === 'offline') {
            $isAuthenticated = false;
            $isSessionExpired = true;
            $reason = 'forced_logout';
            $message = '您的账号已被管理员强制下线。';
        }
        // 检查是否被其他设备登录（会话ID不匹配）
        else if ($user['session_id'] !== session_id() && $user['session_id'] !== null) {
            $isAuthenticated = false;
            $isSessionExpired = true;
            $reason = 'other_device';
            $message = '您的账号已在其他设备登录，当前会话已失效。';
        }
    } else {
        // 用户不存在
        $isAuthenticated = false;
        $isSessionExpired = true;
        $reason = 'user_not_found';
        $message = '用户账号不存在或已被删除。';
    }
    
    $stmt->close();
}

// 组装响应数据
$response = array(
    'authenticated' => $isAuthenticated,
    'username' => $username,
    'session_expired' => $isSessionExpired,
    'message' => $message,
    'reason' => $reason
);

// 如果会话已过期，清理会话
if ($isSessionExpired && isset($_SESSION['user_id'])) {
    // 执行登出
    $auth->logout();
}

// 确保输出干净的JSON
ob_end_clean(); // 清除所有输出缓冲
echo json_encode($response);
exit;