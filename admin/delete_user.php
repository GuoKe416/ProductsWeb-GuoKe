<?php
require_once 'auth.php';
require_once 'db_config.php';

// 要求管理员权限
requireAdmin();

header('Content-Type: application/json');

$user_id = $_POST['user_id'];

// 不允许删除自己
if ($user_id == $_SESSION['user_id']) {
    echo json_encode([
        'status' => 'error',
        'message' => "不能删除当前登录的账号"
    ]);
    exit;
}

$stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);

if ($stmt->execute()) {
    echo json_encode([
        'status' => 'success',
        'message' => "用户删除成功"
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => "删除用户失败：" . $conn->error
    ]);
} 