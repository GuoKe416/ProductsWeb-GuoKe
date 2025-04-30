<?php
require_once 'auth.php';

// 检查用户是否需要重置密码
if ($auth->isLoggedIn() && $auth->needPasswordReset()) {
    // 返回需要重置密码的状态
    header('Content-Type: application/json');
    echo json_encode([
        'need_reset' => true,
        'message' => '您需要修改密码才能继续使用系统'
    ]);
    exit;
} else {
    // 返回不需要重置密码的状态
    header('Content-Type: application/json');
    echo json_encode([
        'need_reset' => false
    ]);
    exit;
} 