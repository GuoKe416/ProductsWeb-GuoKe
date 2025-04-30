<?php
// 检查是否已安装
require_once 'install_check.php';
checkInstallation();

require_once 'auth.php';
require_once 'Logger.php';
require_once 'db_config.php'; // 添加数据库连接

// 初始化认证对象
$auth = new Auth($conn);

// 初始化日志记录器
$logger = new Logger();

// 记录用户登出日志
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
    $logger->log('登出', '用户主动登出系统', ['username' => $username], $userId);
}

// 执行登出
$auth->logout();

// 清除 session_id
$stmt = $conn->prepare("UPDATE users SET session_id = NULL WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->close();

// 重定向到登录页面
header("Location: login.php");
exit;
?>