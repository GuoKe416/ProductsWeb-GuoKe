<?php
require_once 'auth.php';
require_once 'Logger.php';

// 初始化日志记录器
$logger = new Logger();

// 要求用户已登录
requireLogin();

$response = ['success' => false, 'message' => ''];

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // 基本验证
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $response['message'] = '所有字段都不能为空';
        $logger->log('修改密码', '密码修改失败：字段为空');
    } elseif (strlen($new_password) < 6) {
        $response['message'] = '新密码长度不能少于6个字符';
        $logger->log('修改密码', '密码修改失败：新密码长度不足');
    } elseif ($new_password !== $confirm_password) {
        $response['message'] = '两次输入的新密码不一致';
        $logger->log('修改密码', '密码修改失败：两次输入的新密码不一致');
    } else {
        // 验证原密码
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($current_password, $user['password'])) {
                // 更新密码
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed_password, $_SESSION['user_id']);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = '密码修改成功，请重新登录';
                    $logger->log('修改密码', '用户成功修改自己的密码');
                } else {
                    $response['message'] = '密码更新失败: ' . $conn->error;
                    $logger->log('修改密码', '密码更新失败：数据库错误', ['error' => $conn->error]);
                }
            } else {
                $response['message'] = '原密码不正确';
                $logger->log('修改密码', '密码修改失败：原密码不正确');
            }
        } else {
            $response['message'] = '用户信息获取失败';
            $logger->log('修改密码', '密码修改失败：用户信息获取失败');
        }
    }
}

// 返回JSON响应
header('Content-Type: application/json');
echo json_encode($response);