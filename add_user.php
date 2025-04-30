<?php
require_once 'auth.php';
require_once 'db_config.php';

// 要求管理员权限
requireAdmin();

header('Content-Type: application/json');

$username = trim($_POST['username']);
$password = $_POST['password'];
$remark = trim($_POST['remark']); 
// 限制备注长度为20个字符
if (mb_strlen($remark, 'UTF-8') > 20) {
    $remark = mb_substr($remark, 0, 20, 'UTF-8');
}
$role = $_POST['role'];
$download_permission = isset($_POST['download_permission']) ? 1 : 0;

// 基本验证
if (empty($username) || empty($password)) {
    echo json_encode([
        'status' => 'error',
        'message' => "用户名和密码不能为空"
    ]);
    exit;
}

// 检查用户名是否已存在
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo json_encode([
        'status' => 'error',
        'message' => "用户名已存在"
    ]);
    exit;
}

// 密码哈希
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// 插入新用户
$stmt = $conn->prepare("INSERT INTO users (username, password, remark, role, download_report_permission) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("ssssi", $username, $hashed_password, $remark, $role, $download_permission);

if ($stmt->execute()) {
    echo json_encode([
        'status' => 'success',
        'message' => "用户添加成功"
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => "添加用户失败：" . $conn->error
    ]);
} 