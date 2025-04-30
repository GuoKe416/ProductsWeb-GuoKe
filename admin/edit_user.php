<?php
require_once 'auth.php';
require_once 'db_config.php';

// 要求管理员权限
requireAdmin();

header('Content-Type: application/json');

$user_id = $_POST['user_id'];
$remark = trim($_POST['remark']);
// 限制备注长度为20个字符
if (mb_strlen($remark, 'UTF-8') > 20) {
    $remark = mb_substr($remark, 0, 20, 'UTF-8');
}
$role = $_POST['role'];
$download_permission = isset($_POST['download_permission']) ? 1 : 0;
$status = $_POST['status'];
$need_password_reset = isset($_POST['need_password_reset']) ? 1 : 0;

// 如果提供了新密码则更新密码
if (!empty($_POST['password'])) {
    $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET remark = ?, role = ?, download_report_permission = ?, status = ?, need_password_reset = ?, password = ? WHERE id = ?");
    $stmt->bind_param("ssisssi", $remark, $role, $download_permission, $status, $need_password_reset, $hashed_password, $user_id);
} else {
    $stmt = $conn->prepare("UPDATE users SET remark = ?, role = ?, download_report_permission = ?, status = ?, need_password_reset = ? WHERE id = ?");
    $stmt->bind_param("ssisii", $remark, $role, $download_permission, $status, $need_password_reset, $user_id);
}

if ($stmt->execute()) {
    echo json_encode([
        'status' => 'success',
        'message' => "用户信息更新成功"
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => "更新用户信息失败：" . $conn->error
    ]);
} 