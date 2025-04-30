<?php
header('Content-Type: application/json');

require_once '../db_config.php';
require_once '../auth.php';
require_once '../Logger.php';

// 初始化日志记录器
$logger = new Logger();

// 开始会话
session_start();

// 检查用户是否已登录，未登录则拒绝访问
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '未授权访问']);
    exit;
}

// 检查请求来源，防止直接通过URL访问API
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

// 如果是直接通过URL访问API（没有Referer）且不是AJAX请求，则拒绝访问
if (empty($referer) && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    // 直接重定向到首页
    header('Location: ../index.php');
    exit;
}

try {
    $pdo = new PDO("mysql:host={$servername};dbname={$dbname}", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 检查是否是管理员
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => '无权限']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    switch($action) {
        case 'add_user':
            $username = trim($_POST['username']);
            $password = 'zxc123456'; // 设置默认密码
            $remark = trim($_POST['remark'] ?? ''); 
            
            // 限制备注长度为20个字符
            if (mb_strlen($remark, 'UTF-8') > 20) {
                $remark = mb_substr($remark, 0, 20, 'UTF-8');
            }
            
            $role = $_POST['role'] ?? 'user';
            $download_permission = isset($_POST['download_permission']) ? 1 : 0;
            
            // 基本验证
            if (empty($username)) {
                echo json_encode(['success' => false, 'message' => "用户名不能为空"]);
                exit;
            }
            
            // 检查用户名是否已存在
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => "用户名已存在"]);
                exit;
            }
            
            // 添加新用户
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $need_password_reset = 1; // 强制用户首次登录修改密码
            $status = 'active';
            
            $stmt = $pdo->prepare("INSERT INTO users (username, password, remark, role, download_report_permission, need_password_reset, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $hashed_password, $remark, $role, $download_permission, $need_password_reset, $status]);
            $newUserId = $pdo->lastInsertId();
            
            // 记录日志
            $logger->log('user_add', '添加用户', [
                'username' => $username,
                'user_id' => $newUserId,
                'role' => $role,
                'download_permission' => $download_permission
            ]);
            
            echo json_encode([
                'success' => true, 
                'message' => "用户添加成功，初始密码为：zxc123456，该用户首次登录需要修改密码"
            ]);
            break;
            
        case 'edit_user':
            $user_id = $_POST['user_id'] ?? '';
            $password = $_POST['password'] ?? '';
            $remark = trim($_POST['remark'] ?? '');
            $role = $_POST['role'] ?? 'user';
            $download_permission = isset($_POST['download_permission']) ? 1 : 0;
            $need_password_reset = isset($_POST['need_password_reset']) ? 1 : 0;
            $status = $_POST['status'] ?? 'active';
            
            if (empty($user_id)) {
                echo json_encode(['success' => false, 'message' => "用户ID不能为空"]);
                exit;
            }
            
            // 限制备注长度
            if (mb_strlen($remark, 'UTF-8') > 20) {
                $remark = mb_substr($remark, 0, 20, 'UTF-8');
            }
            
            // 获取当前用户信息
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$currentUser) {
                echo json_encode(['success' => false, 'message' => "用户不存在"]);
                exit;
            }
            
            $passwordChanged = false;
            
            // 如果提供了新密码，则更新密码
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ?, remark = ?, role = ?, download_report_permission = ?, need_password_reset = ?, status = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $remark, $role, $download_permission, $need_password_reset, $status, $user_id]);
                $passwordChanged = true;
            } else {
                // 不更新密码
                $stmt = $pdo->prepare("UPDATE users SET remark = ?, role = ?, download_report_permission = ?, need_password_reset = ?, status = ? WHERE id = ?");
                $stmt->execute([$remark, $role, $download_permission, $need_password_reset, $status, $user_id]);
            }
            
            // 记录日志
            $logger->log('user_edit', '编辑用户', [
                'user_id' => $user_id,
                'username' => $currentUser['username'],
                'role_changed' => $currentUser['role'] != $role,
                'old_role' => $currentUser['role'],
                'new_role' => $role,
                'password_changed' => $passwordChanged,
                'status_changed' => $currentUser['status'] != $status,
                'old_status' => $currentUser['status'],
                'new_status' => $status
            ]);
            
            echo json_encode(['success' => true, 'message' => "用户更新成功"]);
            break;
            
        case 'delete_user':
            $user_id = $_POST['user_id'] ?? '';
            
            if (empty($user_id)) {
                echo json_encode(['success' => false, 'message' => "用户ID不能为空"]);
                exit;
            }
            
            // 不允许删除自己
            if ($user_id == $_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => "不能删除当前登录的用户"]);
                exit;
            }
            
            // 获取要删除的用户信息，用于日志记录
            $stmt = $pdo->prepare("SELECT username, role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $userToDelete = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userToDelete) {
                echo json_encode(['success' => false, 'message' => "用户不存在"]);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            
            // 记录日志
            $logger->log('user_delete', '删除用户', [
                'user_id' => $user_id,
                'username' => $userToDelete['username'],
                'role' => $userToDelete['role']
            ]);
            
            echo json_encode(['success' => true, 'message' => "用户已删除"]);
            break;
            
        case 'reset_password':
            $user_id = $_POST['user_id'] ?? '';
            $default_password = 'zxc123456';
            
            if (empty($user_id)) {
                echo json_encode(['success' => false, 'message' => "用户ID不能为空"]);
                exit;
            }
            
            // 获取用户信息，用于日志记录
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$targetUser) {
                echo json_encode(['success' => false, 'message' => "用户不存在"]);
                exit;
            }
            
            $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
            $need_password_reset = 1; // 强制用户下次登录修改密码
            
            $stmt = $pdo->prepare("UPDATE users SET password = ?, need_password_reset = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $need_password_reset, $user_id]);
            
            // 记录日志
            $logger->log('user_reset_password', '重置用户密码', [
                'user_id' => $user_id,
                'username' => $targetUser['username'],
                'default_password_set' => true
            ]);
            
            echo json_encode(['success' => true, 'message' => "密码已重置为: zxc123456"]);
            break;
            
        case 'toggle_status':
            $user_id = $_POST['user_id'] ?? '';
            $status = $_POST['status'] ?? '';
            
            if (empty($user_id) || empty($status)) {
                echo json_encode(['success' => false, 'message' => "参数不完整"]);
                exit;
            }
            
            if ($status !== 'active' && $status !== 'inactive') {
                echo json_encode(['success' => false, 'message' => "状态值无效"]);
                exit;
            }
            
            // 不允许禁用自己
            if ($user_id == $_SESSION['user_id'] && $status == 'inactive') {
                echo json_encode(['success' => false, 'message' => "不能禁用当前登录的用户"]);
                exit;
            }
            
            // 获取用户信息，用于日志记录
            $stmt = $pdo->prepare("SELECT username, status as current_status FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$targetUser) {
                echo json_encode(['success' => false, 'message' => "用户不存在"]);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->execute([$status, $user_id]);
            
            // 记录日志
            $logger->log('user_status_change', '修改用户状态', [
                'user_id' => $user_id,
                'username' => $targetUser['username'],
                'old_status' => $targetUser['current_status'],
                'new_status' => $status
            ]);
            
            $message = $status == 'active' ? "用户已启用" : "用户已禁用";
            echo json_encode(['success' => true, 'message' => $message]);
            break;
            
        case 'force_logout':
            $user_id = $_POST['user_id'] ?? '';
            
            if (empty($user_id)) {
                echo json_encode(['success' => false, 'message' => "用户ID不能为空"]);
                exit;
            }
            
            // 不允许强制下线自己
            if ($user_id == $_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => "不能强制下线当前登录的用户"]);
                exit;
            }
            
            // 获取用户会话信息
            $stmt = $pdo->prepare("SELECT session_id, username FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userInfo || !$userInfo['session_id']) {
                echo json_encode(['success' => false, 'message' => "用户不在线"]);
                exit;
            }
            
            // 更新用户状态为强制下线
            $stmt = $pdo->prepare("UPDATE users SET login_status = 'forced_offline', force_logout_time = NOW(), force_logout_by = ? WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $user_id]);
            
            // 记录会话注销信息
            $stmt = $pdo->prepare("INSERT INTO logout_sessions (session_id, user_id, logout_reason) VALUES (?, ?, 'forced_logout')");
            $stmt->execute([$userInfo['session_id'], $user_id]);
            
            // 记录日志
            $logger->log('user_force_logout', '强制用户下线', [
                'user_id' => $user_id,
                'username' => $userInfo['username'],
                'session_id' => $userInfo['session_id'],
                'force_logout_by' => $_SESSION['user_id']
            ]);
            
            echo json_encode(['success' => true, 'message' => "用户已被强制下线"]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => "未知操作"]);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => "数据库错误: " . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => "系统错误: " . $e->getMessage()]);
} 