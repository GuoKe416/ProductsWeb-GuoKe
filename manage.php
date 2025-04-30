<?php
// 检查是否已安装
require_once 'install_check.php';
checkInstallation();

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 0); // 不直接显示错误
ini_set('log_errors', 1); // 记录错误到日志

require_once 'auth.php';
require_once 'db_config.php';
require_once 'Logger.php';

// 初始化日志记录器
$logger = new Logger();

// 处理API请求，确保始终返回JSON
if (isset($_GET['action']) && $_GET['action'] == 'list_users') {
    header('Content-Type: application/json');
    try {
        $username = isset($_GET['username']) ? trim($_GET['username']) : '';
        $role = isset($_GET['role']) ? trim($_GET['role']) : '';
        $permission = isset($_GET['permission']) ? trim($_GET['permission']) : '';
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        $loginStatus = isset($_GET['login_status']) ? trim($_GET['login_status']) : '';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $pageSize = isset($_GET['pageSize']) ? (int)$_GET['pageSize'] : 10;
        $offset = ($page - 1) * $pageSize;

        // 先获取总数
        $countQuery = "SELECT COUNT(*) as total FROM users u WHERE 1=1";
        $whereConditions = [];
        $params = [];
        $types = '';
        
        if ($username) {
            $whereConditions[] = "u.username LIKE ?";
            $params[] = "%$username%";
            $types .= 's';
        }
        if ($role) {
            $whereConditions[] = "u.role = ?";
            $params[] = $role;
            $types .= 's';
        }
        if ($permission !== '') {
            $whereConditions[] = "u.download_report_permission = ?";
            $params[] = (int)$permission;
            $types .= 'i';  // 使用整数类型
        }
        if ($status) {
            $whereConditions[] = "u.status = ?";
            $params[] = $status;
            $types .= 's';
        }
        if ($loginStatus) {
            $whereConditions[] = "u.login_status = ?";
            $params[] = $loginStatus;
            $types .= 's';
        }
        
        if (!empty($whereConditions)) {
            $countQuery .= " AND " . implode(" AND ", $whereConditions);
        }
        
        $stmt = $conn->prepare($countQuery);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $totalResult = $stmt->get_result()->fetch_assoc();
        $total = $totalResult['total'];
        
        // 获取分页数据
        $query = "SELECT u.id, u.username, u.remark, u.role, u.download_report_permission, 
                  u.created_at, u.last_login, u.status, u.need_password_reset, u.last_login_ip, 
                  u.login_status, u.force_logout_time, u.session_expires_at, a.username as forced_by_username
                  FROM users u 
                  LEFT JOIN users a ON u.force_logout_by = a.id 
                  WHERE 1=1";
        
        if (!empty($whereConditions)) {
            $query .= " AND " . implode(" AND ", $whereConditions);
        }
        
        // 按登录状态排序，在线用户优先显示
        $query .= " ORDER BY FIELD(u.login_status, 'online', 'offline', 'forced_offline'), u.id DESC LIMIT ? OFFSET ?";
        $types .= 'ii';
        $params[] = $pageSize;
        $params[] = $offset;
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // 返回JSON响应
        echo json_encode([
            'success' => true,
            'data' => $users,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// 添加日志查看API
if (isset($_GET['action']) && $_GET['action'] == 'get_logs') {
    header('Content-Type: application/json');
    try {
        require_once 'Logger.php';
        $logger = new Logger();
        
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        
        // 获取指定日期的日志
        $logs = $logger->getLogs($date);
        
        // 获取可用的日志日期
        $dates = $logger->getAvailableDates();
        
        // 返回结果
        echo json_encode([
            'success' => true,
            'data' => $logs,
            'dates' => $dates,
            'current_date' => $date
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// 记录下载文件日志的API
if (isset($_GET['action']) && $_GET['action'] == 'log_download_report') {
    header('Content-Type: application/json');
    
    // 验证用户身份
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '用户未登录']);
        exit;
    }
    
    // 验证必要参数
    if (!isset($_GET['code']) || empty($_GET['code'])) {
        echo json_encode(['success' => false, 'message' => '缺少商品编码参数']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    $productCode = $_GET['code'];
    
    // 获取商品信息
    $stmt = $conn->prepare("SELECT id, code, info FROM products WHERE code = ?");
    $stmt->bind_param("s", $productCode);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();
    
    if (!$product) {
        echo json_encode(['success' => false, 'message' => '商品不存在']);
        exit;
    }
    
    require_once 'Logger.php';
    $logger = new Logger();
    
    // 记录下载日志
    $logger->log(
        '下载文件',
        '用户下载了商品 ' . $productCode . ' 的文件',
        [
            'product_id' => $product['id'],
            'product_code' => $product['code'],
            'product_info' => $product['info'],
            'download_time' => date('Y-m-d H:i:s'),
            'download_ip' => $_SERVER['REMOTE_ADDR']
        ],
        $userId
    );
    
    echo json_encode(['success' => true, 'message' => '日志记录成功']);
    exit;
}

// 要求管理员权限
requireAdmin();

// 处理用户操作
$message = '';
$messageType = '';

if (isset($_POST['action']) && $_POST['action'] == 'add_user') {
    $username = trim($_POST['username']);
    $password = 'zxc123456'; // 设置默认密码
    $remark = trim($_POST['remark']); 
    // 限制备注长度为20个字符
    if (mb_strlen($remark, 'UTF-8') > 20) {
        $remark = mb_substr($remark, 0, 20, 'UTF-8');
    }
    $role = $_POST['role'];
    $download_permission = isset($_POST['download_permission']) ? 1 : 0;
    
    // 基本验证
    if (empty($username)) {
        $message = "用户名不能为空";
        $messageType = "error";
        $logger->log('用户管理', '添加用户失败，用户名不能为空', ['username' => $username]);
    } else {
        // 检查用户名是否已存在
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $message = "用户名已存在";
            $messageType = "error";
            $logger->log('用户管理', '添加用户失败，用户名已存在', ['username' => $username]);
        } else {
            // 添加新用户
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $need_password_reset = 1; // 强制用户首次登录修改密码
            $status = 'active';
            
            $stmt = $conn->prepare("INSERT INTO users (username, password, remark, role, download_report_permission, need_password_reset, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssiis", $username, $hashed_password, $remark, $role, $download_permission, $need_password_reset, $status);
            
            if ($stmt->execute()) {
                $message = "用户添加成功，初始密码为：zxc123456，该用户首次登录需要修改密码";
                $messageType = "success";
                $logger->log('用户管理', '成功添加新用户', [
                    'username' => $username,
                    'role' => $role,
                    'download_permission' => $download_permission
                ]);
            } else {
                $message = "添加用户失败: " . $conn->error;
                $messageType = "error";
                $logger->log('用户管理', '添加用户失败：数据库错误', [
                    'username' => $username,
                    'error' => $conn->error
                ]);
            }
        }
    }
    
    // 使用POST-Redirect-GET模式，避免表单重新提交
    $_SESSION['message'] = $message;
    $_SESSION['messageType'] = $messageType;
    header("Location: manage.php");
    exit;
}

// 更新用户
if (isset($_POST['action']) && $_POST['action'] == 'edit_user') {
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
    
    // 获取用户原始信息，用于日志记录
    $stmt = $conn->prepare("SELECT username, role, download_report_permission, status, need_password_reset FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $oldUser = $stmt->get_result()->fetch_assoc();
    
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
        $message = "用户更新成功";
        $messageType = "success";
        
        // 记录用户更新日志
        $logger->log('用户管理', '更新用户信息', [
            'user_id' => $user_id,
            'username' => $oldUser['username'],
            'changes' => [
                'role' => ['from' => $oldUser['role'], 'to' => $role],
                'download_permission' => ['from' => $oldUser['download_report_permission'], 'to' => $download_permission],
                'status' => ['from' => $oldUser['status'], 'to' => $status],
                'need_password_reset' => ['from' => $oldUser['need_password_reset'], 'to' => $need_password_reset],
                'password_changed' => !empty($_POST['password'])
            ]
        ]);
    } else {
        $message = "更新用户失败: " . $conn->error;
        $messageType = "error";
        $logger->log('用户管理', '更新用户失败', [
            'user_id' => $user_id,
            'username' => $oldUser['username'],
            'error' => $conn->error
        ]);
    }
    
    // 使用POST-Redirect-GET模式，避免表单重新提交
    $_SESSION['message'] = $message;
    $_SESSION['messageType'] = $messageType;
    header("Location: manage.php");
    exit;
}

// 删除用户
if (isset($_POST['action']) && $_POST['action'] == 'delete_user') {
    $user_id = $_POST['user_id'];
    
    // 获取用户信息用于日志
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    // 不允许删除自己
    if ($user_id == $_SESSION['user_id']) {
        $message = "不能删除当前登录的账号";
        $messageType = "error";
        $logger->log('用户管理', '删除用户失败：尝试删除自己的账号', [
            'user_id' => $user_id, 
            'username' => $user['username']
        ]);
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $message = "用户删除成功";
            $messageType = "success";
            $logger->log('用户管理', '成功删除用户', [
                'user_id' => $user_id, 
                'username' => $user['username']
            ]);
        } else {
            $message = "删除用户失败: " . $conn->error;
            $messageType = "error";
            $logger->log('用户管理', '删除用户失败：数据库错误', [
                'user_id' => $user_id, 
                'username' => $user['username'],
                'error' => $conn->error
            ]);
        }
    }
    
    // 使用POST-Redirect-GET模式，避免表单重新提交
    $_SESSION['message'] = $message;
    $_SESSION['messageType'] = $messageType;
    header("Location: manage.php");
    exit;
}

// 获取所有用户
$stmt = $conn->prepare("SELECT u.id, u.username, u.remark, u.role, u.download_report_permission, 
                       u.created_at, u.last_login, u.status, u.need_password_reset, u.last_login_ip, 
                       u.login_status, u.force_logout_time, u.session_expires_at, a.username as forced_by_username
                       FROM users u 
                       LEFT JOIN users a ON u.force_logout_by = a.id 
                       ORDER BY u.id");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>后台管理</title>
    <link href="css/googleapis.css" rel="stylesheet">
    <link href="./layui/css/layui.css" rel="stylesheet">
    <script src="./layui/layui.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Noto Sans SC', sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #2c3e50;
            padding: 20px;
        }

        .container {
            max-width: 85%;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }

        .search-box {
            flex: 1;
            min-width: 200px;
        }

        .controls select {
            min-width: 120px;
        }

        .controls .layui-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .controls .layui-btn i {
            font-size: 14px;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #1E9FFF;
            color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .btn.delete {
            background: #ff4d4f;
        }

        .btn.delete:hover {
            background: #ff7875;
        }

        .edit-btn {
            background: #1E9FFF !important;
        }

        .delete-btn {
            background: #ff4d4f !important;
        }

        .btn-cancel {
            background: #909399 !important;
            color: white;
        }

        .btn-primary {
            background: #1E9FFF !important;
            color: white;
        }

        table {
            width: 100%;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-collapse: collapse;
            overflow: hidden;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #f8fafc;
            font-weight: 500;
        }

        .product-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 4px;
        }

        /* 规格表格样式 */
        .spec-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .spec-table th,
        .spec-table td {
            padding: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .spec-table th {
            background-color: #f8fafc;
            text-align: left;
            font-weight: 500;
        }
        
        .spec-table input.layui-input {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            box-sizing: border-box;
        }
        
        .spec-controls {
            margin-bottom: 10px;
        }
        
        .readonly-input {
            background-color: #f5f5f5;
            cursor: not-allowed;
            color: #666;
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            overflow: hidden;
        }

        .modal-content {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            width: 90%;
            max-width: 700px;
            max-height: 85vh;
            position: relative;
            display: flex;
            flex-direction: column;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        
        .modal-title {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 20px;
            font-weight: 600;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }
        
        .modal-body {
            overflow-y: auto;
            padding-right: 10px;
            flex: 1;
            margin-bottom: 0;
            max-height: calc(85vh - 140px);
            scrollbar-width: thin;
        }
        
        .modal-body::-webkit-scrollbar {
            width: 8px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        
        .modal-body::-webkit-scrollbar-thumb:hover {
            background: #666;
        }
        
        .modal-footer {
            padding-top: 10px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 5px;
            background: #fff;
            z-index: 10;
            margin-top: 0px;
        }
        
        .btn-cancel {
            background-color: #f5f5f5;
            color: #333;
            border: 1px solid #ddd;
        }
        
        .btn-cancel:hover {
            background-color: #e0e0e0;
        }
        
        .btn-primary {
            background-color: #1E9FFF;
            color: #fff;
        }
        
        .btn-primary:hover {
            background-color: #1a90e8;
        }
        
        .modal .form-buttons {
            display: none; /* 隐藏原有的按钮 */
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: flex;
            margin-bottom: 8px;
            color: #4a5568;
        }

        #edit_download_permission,#download_permission_a,#edit_need_password_reset{
            width:20px;
            right:10px !important;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            outline: none;
        }

        .image-upload {
            border: 2px dashed #ddd;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            margin-bottom: 15px;
            border-radius: 8px;
            position: relative;
            background-color: #f9f9f9;
        }

        .image-upload img {
            max-width: 100%;
            max-height: 300px;
            pointer-events: none; /* 防止图片鼠标事件影响滚动 */
        }

        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        
        
        /* 导航菜单样式 */
        .nav-menu {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
        }
        
        .nav-menu a {
            padding: 15px 25px;
            text-decoration: none;
            color: #4a5568;
            font-weight: 500;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .nav-menu a:hover {
            color: #3182ce;
        }
        
        .nav-menu a.active {
            color: #3182ce;
            border-bottom-color: #3182ce;
        }
        
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .success {
            background-color: #def7ec;
            color: #0c532d;
        }

        .error {
            background-color: #fde8e8;
            color: #c81e1e;
        }

        /* 视图显示控制 */
        .view {
            display: none;
        }
        
        .view.active {
            display: block;
        }

        /* 美化表格按钮 */
        .edit-btn, .delete-btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            margin-right: 5px;
        }

        .edit-btn {
            background: #4299e1;
        }

        .delete-btn {
            background: #e53e3e;
        }

        .manage-header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .search-filters {
            margin-bottom: 20px;
        }
        
        .filters-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.3s;
            min-width: 120px;
            flex: 1;
        }
        
        .search-input:focus {
            border-color: #1E9FFF;
            box-shadow: 0 0 0 2px rgba(30,159,255,0.2);
            outline: none;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-primary {
            background: #1E9FFF;
            color: white;
        }
        
        .btn-success {
            background: #52c41a;
            color: white;
        }
        
        .btn-warning {
            background: #faad14;
            color: white;
        }
        
        .btn-danger {
            background: #ff4d4f;
            color: white;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        .btn i {
            font-size: 16px;
        }
        
        .table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        
        th {
            background: #fafafa;
            font-weight: 500;
            color: #333;
        }
        
        tr:hover {
            background: #fafafa;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .status-active {
            background-color: #e6f7ff;
            color: #1890ff;
        }
        
        .status-inactive {
            background-color: #fff1f0;
            color: #ff4d4f;
        }
        
        .status-warning {
            background-color: #fffbe6;
            color: #fa8c16;
        }
        
        .status-error {
            background-color: #fff2f0;
            color: #f5222d;
        }
        
        @media (max-width: 768px) {
            .search-filters {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
            
            .btn {
                flex: 0 1 auto;
            }
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            justify-content: flex-end;
        }

        .action-buttons .layui-btn {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .action-buttons .layui-btn i {
            font-size: 14px;
        }

        .user-operation-btn {
            padding: 5px 10px;
            font-size: 12px;
            border-radius: 4px;
            margin-right: 5px;
            height: 28px;
            line-height: 18px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .user-operation-btn i {
            font-size: 12px;
            margin-right: 2px;
        }

        td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .user-pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .user-pagination button {
            min-width: 32px;
            height: 32px;
            padding: 0 10px;
        }

        .user-pagination button.active {
            background-color: #1E9FFF;
            color: white;
        }

        .search-reset-btn {
            margin-right: 10px;
        }

        /* 商品列表样式 */
        #products-view table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        #products-view th,
        #products-view td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        #products-view th:nth-child(1) { width: 150px; } /* 商品编码 */
        #products-view th:nth-child(2) { width: 150px; } /* 图片 */
        #products-view th:nth-child(3) { width: 600px; } /* 信息 */
        #products-view th:nth-child(4) { width: auto; } /* 文件 */
        #products-view th:nth-child(5) { width: 200px; } /* 操作 */

        #products-view td img {
            max-width: 100px;
            max-height: 100px;
            object-fit: contain;
        }

        .product-info {
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* 添加按钮悬浮提示样式 */
        .layui-btn[title] {
            position: relative;
        }
        
        .layui-btn[title]:hover:after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 5px 10px;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 5px;
        }
        
        .layui-btn-disabled {
            cursor: not-allowed !important;
            opacity: 0.6 !important;
        }
        
        .layui-btn-disabled:hover {
            opacity: 0.6 !important;
            transform: none !important;
        }

        /* 日志管理样式 */
        .log-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .log-date-select {
            min-width: 200px;
        }
        
        .log-table {
            width: 100%;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-collapse: collapse;
            overflow: hidden;
        }
        
        .log-entry {
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .log-entry:hover {
            background-color: #f3f4f6;
        }
        
        .log-entry.expanded {
            background-color: #eef2ff;
        }
        
        .log-data {
            display: none;
            padding: 10px 20px;
            background-color: #f8fafc;
            border-radius: 8px;
            margin: 10px 0;
            font-family: monospace;
            white-space: pre-wrap;
        }
        
        .log-data.show {
            display: block;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-login {
            background-color: #c6f6d5;
            color: #22543d;
        }
        
        .badge-logout {
            background-color: #feebc8;
            color: #7b341e;
        }
        
        .badge-visit {
            background-color: #e9d8fd;
            color: #553c9a;
        }
        
        .badge-modify {
            background-color: #bee3f8;
            color: #2a4365;
        }
        
        .badge-error {
            background-color: #fed7d7;
            color: #822727;
        }
        
        .badge-admin {
            background-color: #a4adbf;
            color: #264c71;
        }
        
        .badge-download {
            background-color: #e6fffb;
            color: #13c2c2;
        }
        
        .empty-logs {
            text-align: center;
            padding: 50px 0;
            color: #a0aec0;
        }

        .site-config-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .site-config-container .section-title {
            font-size: 1.2em;
            color: #2d3748;
            margin: 30px 0 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #edf2f7;
        }

        .site-config-container .form-group {
            margin-bottom: 20px;
        }

        .site-config-container .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .site-config-container input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 0;
        }

        .site-config-container .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            user-select: none;
        }

        .site-config-container .help-text {
            font-size: 0.9em;
            color: #718096;
            margin-top: 4px;
            margin-left: 26px;
        }

        .site-config-container .form-buttons {
            margin-top: 30px;
            text-align: right;
        }

        /* 日志高亮 */
        .log-entry.highlight {
            background-color: #ffffcc;
        }
        
        /* 数据看板样式 */
        .dashboard-container {
            padding: 20px;
        }
        
        .dashboard-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .dashboard-card {
            flex: 1;
            min-width: 200px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            padding: 20px;
            align-items: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
        }
        
        .card-icon {
            background: #f2f7ff;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .card-icon i {
            font-size: 24px;
            color: #009688;
        }
        
        .card-content {
            flex: 1;
        }
        
        .card-value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        
        .card-title {
            font-size: 14px;
            color: #666;
        }
        
        .system-info {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        /* 关于页面样式 */
        .about-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .about-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .about-logo {
            width: 120px;
            height: auto;
            margin-bottom: 15px;
        }
        
        .about-content {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .about-features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .feature-item {
            display: flex;
            align-items: flex-start;
            padding: 15px;
            border-radius: 8px;
            background: #f9f9f9;
            transition: transform 0.3s;
        }
        
        .feature-item:hover {
            transform: translateY(-5px);
        }
        
        .feature-icon {
            background: #e1f5fe;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .feature-icon i {
            font-size: 20px;
            color: #0288d1;
        }
        
        .feature-content h4 {
            margin: 0 0 10px 0;
            color: #333;
        }
        
        .feature-content p {
            margin: 0;
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }

        /* 用户信息和头像样式 */
        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .user-controls {
            position: relative;
            margin-left: auto;
        }
        .user-info-trigger {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 8px;
            transition: background-color 0.2s;
        }
        .user-info-trigger:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: #1E9FFF;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 600;
        }
        .user-brief {
            display: flex;
            flex-direction: column;
        }
        .user-name {
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        .user-role {
            color: #888;
            font-size: 12px;
        }
        .dropdown-arrow {
            font-size: 12px;
            color: #888;
        }
        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 220px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: none;
            z-index: 1000;
            overflow: hidden;
        }
        .dropdown-menu.show {
            display: block;
        }
        .menu-item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: background-color 0.2s;
            color: #333;
            text-decoration: none;
        }
        .menu-item:hover {
            background-color: #f5f5f5;
        }
        .menu-item i {
            font-size: 16px;
            color: #666;
            width: 20px;
            text-align: center;
        }
        .menu-divider {
            height: 1px;
            background-color: #eee;
            margin: 6px 0;
        }
        .badge-permission {
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 500;
        }
        .has-permission {
            background-color: #e6f7ff;
            color: #1890ff;
        }
        .no-permission {
            background-color: #fff2f0;
            color: #ff4d4f;
        }
        .login-expiry {
            color: #666;
            font-size: 14px;
        }
        #login-countdown {
            color: #1E9FFF;
            font-weight: 500;
        }
        /* 深色主题支持 */
        body.dark-theme {
            background-color: #1e1e1e;
            color: #f0f0f0;
        }
        body.dark-theme .container {
            background-color: #2d2d2d;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        body.dark-theme .section-title h2,
        body.dark-theme .header h1 {
            color: #f0f0f0;
        }
        body.dark-theme table th {
            background-color: #333;
            color: #f0f0f0;
        }
        body.dark-theme table td {
            color: #e0e0e0;
            border-bottom: 1px solid #444;
        }
        body.dark-theme .tab-button {
            background-color: #333;
            color: #ccc;
        }
        body.dark-theme .tab-button.active {
            background-color: #1E9FFF;
            color: white;
        }
        body.dark-theme .dropdown-menu {
            background-color: #333;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        body.dark-theme .menu-item {
            color: #e0e0e0;
        }
        body.dark-theme .menu-item:hover {
            background-color: #444;
        }
        body.dark-theme .menu-divider {
            background-color: #444;
        }
        body.dark-theme .user-name {
            color: #e0e0e0;
        }
        body.dark-theme .modal-content, 
        body.dark-theme input, 
        body.dark-theme select, 
        body.dark-theme textarea {
            background-color: #333;
            color: #e0e0e0;
            border-color: #555;
        }
        body.dark-theme ::placeholder {
            color: #888;
        }
        body.dark-theme .log-entry:hover {
            background-color: #3a3a3a;
        }
        body.dark-theme .log-entry.expanded {
            background-color: #304050;
        }
        body.dark-theme .success-message {
            background: #143322;
            color: #82d993;
        }
    </style>
</head>
<body class="<?php echo isset($_COOKIE['dark_theme']) && $_COOKIE['dark_theme'] === 'true' ? 'dark-theme' : ''; ?>">
    <?php
    // 显示会话中的消息（POST操作后的反馈）
    if (isset($_SESSION['message']) && isset($_SESSION['messageType'])) {
        echo '<div id="session-message" 
                 data-message="' . htmlspecialchars($_SESSION['message']) . '" 
                 data-type="' . htmlspecialchars($_SESSION['messageType']) . '"></div>';
        // 清除会话消息，避免刷新时重复显示
        unset($_SESSION['message']);
        unset($_SESSION['messageType']);
    }
    ?>
    <div class="container">
        <div class="header">
            <h1>后台管理系统</h1>
            <button class="layui-btn layui-btn-primary">
                <a href="index.php">
                    <i>🏠 返回首页</i>
                </a>
            </button>
        </div>
        
        <div class="nav-menu">
            <a href="#dashboard" data-view="dashboard" id="menu-dashboard"><i class="layui-icon layui-icon-console"> </i> 数据看板</a>
            <a href="#products" data-view="products" id="menu-products"><i class="layui-icon layui-icon-cart-simple"> </i> 商品管理</a>
            <a href="#users" data-view="users" id="menu-users"><i class="layui-icon layui-icon-user"> </i>用户管理</a>
            <a href="#ipban" data-view="ipban" id="menu-ipban"><i class="layui-icon layui-icon-vercode"> </i>IP封禁管理</a>
            <a href="#logs" data-view="logs" id="menu-logs"><i class="layui-icon layui-icon-log"> </i>系统日志</a>
            <a href="#site" data-view="site" id="menu-site"><i class="layui-icon layui-icon-set"> </i>站点设置</a>
            <a href="#about" data-view="about" id="menu-about"><i class="layui-icon layui-icon-about"> </i>关于</a>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- 视图容器 -->
        <div class="content-container">
            <!-- 数据看板视图 -->
            <div id="dashboard-view" class="view active">
                <h2>系统数据看板</h2>
                <div class="dashboard-container">
                    <div class="dashboard-row">
                        <div class="dashboard-card">
                            <div class="card-icon"><i class="layui-icon layui-icon-app"></i></div>
                            <div class="card-content">
                                <div class="card-value" id="total-products">0</div>
                                <div class="card-title">商品总数</div>
                            </div>
                        </div>
                        <div class="dashboard-card">
                            <div class="card-icon"><i class="layui-icon layui-icon-user"></i></div>
                            <div class="card-content">
                                <div class="card-value" id="total-users">0</div>
                                <div class="card-title">用户总数</div>
                            </div>
                        </div>
                        <div class="dashboard-card">
                            <div class="card-icon"><i class="layui-icon layui-icon-circle"></i></div>
                            <div class="card-content">
                                <div class="card-value" id="online-users">0</div>
                                <div class="card-title">在线用户</div>
                            </div>
                        </div>
                    </div>
                    
                    <h3>系统信息</h3>
                    <div class="system-info">
                        <table class="layui-table">
                            <tbody>
                                <tr>
                                    <td>系统版本</td>
                                    <td id="system-version">1.0.0</td>
                                </tr>
                                <tr>
                                    <td>PHP 版本</td>
                                    <td id="php-version"></td>
                                </tr>
                                <tr>
                                    <td>MySQL 版本</td>
                                    <td id="mysql-version"></td>
                                </tr>
                                <tr>
                                    <td>服务器系统</td>
                                    <td id="server-os"></td>
                                </tr>
                                <tr>
                                    <td>服务器软件</td>
                                    <td id="server-software"></td>
                                </tr>
                                <tr>
                                    <td>系统安装时间</td>
                                    <td id="last-update-time"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="products-view" class="view">
                <div class="controls">
                    <input type="text" class="search-box layui-input" placeholder="输入商品编码搜索...">
                    <select class="layui-input" style="width: auto;">
                        <option value="30">30条/页</option>
                        <option value="60">60条/页</option>
                        <option value="120">120条/页</option>
                    </select>
                    <button class="layui-btn" onclick="showModal()">
                        <i class="layui-icon layui-icon-add-1"></i>添加商品
                    </button>
                    <button class="layui-btn layui-btn-normal" onclick="exportData('products')">
                        <i class="layui-icon layui-icon-export"></i>导出商品
                    </button>
                </div>

                <div class="pagination pagination-top" style="margin: 15px 0;"></div>

                <table>
                    <thead>
                        <tr>
                            <th>商品编码</th>
                            <th>图片</th>
                            <th>信息</th>
                            <th>文件</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- 商品列表将通过 JavaScript 动态插入 -->
                    </tbody>
                </table>
            </div>
            
            <!-- 用户管理视图 -->
            <div id="users-view" class="view">
                <div class="manage-header">
                    <h2>用户管理</h2>
                    <div class="search-filters">
                        <div class="filters-row">
                            <input type="text" class="search-input" placeholder="搜索用户名..." id="username-search">
                            <select class="search-input" id="role-filter">
                                <option value="">所有角色</option>
                                <option value="admin">管理员</option>
                                <option value="user">普通用户</option>
                            </select>
                            <select class="search-input" id="permission-filter">
                                <option value="">所有权限</option>
                                <option value="1">可下载</option>
                                <option value="0">不可下载</option>
                            </select>
                            <select class="search-input" id="status-filter">
                                <option value="">所有状态</option>
                                <option value="active">正常</option>
                                <option value="inactive">禁用</option>
                            </select>
                            <select class="search-input" id="login-status-filter">
                                <option value="">所有登录状态</option>
                                <option value="online">在线</option>
                                <option value="offline">离线</option>
                                <option value="forced_offline">被强制下线</option>
                            </select>
                            <select class="search-input" id="user-page-size">
                                <option value="10">10条/页</option>
                                <option value="20">20条/页</option>
                                <option value="30">30条/页</option>
                                <option value="50">50条/页</option>
                                <option value="100">100条/页</option>
                            </select>
                            <button class="layui-btn layui-btn-primary" onclick="resetUserSearch()">
                                <i class="layui-icon layui-icon-refresh"></i>重置搜索
                            </button>
                            <button class="layui-btn" onclick="showAddUserModal()">
                                <i class="layui-icon layui-icon-add-1"></i>添加用户
                            </button>
                            <button class="layui-btn layui-btn-normal" onclick="exportData('users')">
                                <i class="layui-icon layui-icon-export"></i>导出用户
                            </button>
                        </div>
                    </div>
                </div>
                
                <div id="user-pagination" class="pagination pagination-top" style="margin: 15px 0;"></div>
                
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>用户名</th>
                                <th>备注</th>
                                <th>角色</th>
                                <th>下载权限</th>
                                <th>账号状态</th>
                                <th>最后登录IP</th>
                                <th>登录状态</th>
                                <th>剩余有效期</th>
                                <th>创建时间</th>
                                <th>最后登录</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="user-list">
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- IP封禁管理视图 -->
            <div id="ipban-view" class="view">
                <div class="manage-header">
                    <h2>IP封禁管理</h2>
                    <div class="search-filters">
                        <div class="filters-row">
                            <input type="text" class="search-input" placeholder="搜索IP地址..." id="ip-search">
                            <button class="layui-btn layui-btn-primary" onclick="loadIpBanList()">
                                <i class="layui-icon layui-icon-refresh"></i>重置刷新
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>IP地址</th>
                                <th>失败次数</th>
                                <th>最后尝试时间</th>
                                <th>剩余封禁时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="ip-list">
                            <!-- IP封禁列表将通过JavaScript动态插入 -->
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- 日志管理视图 -->
            <div id="logs-view" class="view">
                <div class="section-title">
                    <h2>系统日志</h2>
                    <div class="filters-container" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <div class="filters-row">
                            <select id="log-date-select" class="search-input">
                                <!-- 日期选项将通过JavaScript动态添加 -->
                            </select>
                            <input type="text" id="log-username-filter" class="search-input" placeholder="搜索用户名">
                            <input type="text" id="log-ip-filter" class="search-input" placeholder="搜索IP地址">
                            <select id="log-action-filter" class="search-input">
                                <option value="">全部操作类型</option>
                                <option value="登录">登录</option>
                                <option value="登出">登出</option>
                                <option value="查看">查看商品</option>
                                <option value="下载">下载报告</option>
                                <option value="修改">修改数据</option>
                                <option value="添加">添加数据</option>
                                <option value="删除">删除数据</option>
                                <option value="错误">错误/失败</option>
                                <option value="管理">管理操作</option>
                            </select>
                            <button class="layui-btn" onclick="refreshLogs()">
                                <i class="layui-icon layui-icon-refresh"></i> 刷新
                            </button>
                            <button class="layui-btn" onclick="resetLogFilters()">
                                <i class="layui-icon layui-icon-refresh-1"></i> 重置筛选
                            </button>
                        </div>
                    </div>
                </div>
                
                <div id="log-pagination" class="pagination pagination-top" style="margin-bottom: 15px;"></div>
                
                <div id="logs-container"></div>
            </div>

            <!-- 站点设置视图 -->
            <div id="site-view" class="view">
                <div class="manage-header">
                    <h2>站点设置</h2>
                </div>
                
                <div class="site-config-container">
                    <form id="siteConfigForm">
                        <div class="section-title">基本设置</div>
                        
                        <div class="form-group">
                            <label for="site_title">网站标题</label>
                            <input type="text" id="site_title" name="site_title" class="layui-input">
                            <div class="help-text">显示在浏览器标签页的标题</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="site_h1">首页标题</label>
                            <input type="text" id="site_h1" name="site_h1" class="layui-input">
                            <div class="help-text">显示在首页顶部的大标题</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="tip_text">提示文本</label>
                            <input type="text" id="tip_text" name="tip_text" class="layui-input">
                            <div class="help-text">显示在首页搜索框下方的提示文字</div>
                        </div>

                        <div class="form-group">
                            <label for="icp_number">备案号</label>
                            <input type="text" id="icp_number" name="icp_number" class="layui-input">
                            <div class="help-text">网站ICP备案号，留空则不显示</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="contact_info">联系信息</label>
                            <input type="text" id="contact_info" name="contact_info" class="layui-input">
                            <div class="help-text">显示在首页底部的联系管理员信息</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="error_notice">异常通知内容</label>
                            <input type="text" id="error_notice" name="error_notice" class="layui-input">
                            <div class="help-text">网站出现错误时显示的提示内容</div>
                        </div>
                        
                        <div class="section-title">功能设置</div>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="enable_ip_ban" name="enable_ip_ban" value="1">
                                <span>启用IP封禁功能</span>
                            </label>
                            <div class="help-text">开启后，登录失败次数过多的IP将被临时封禁24小时，可在IP封禁管理视图查看</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="session_lifetime">会话有效期（天）</label>
                            <input type="number" id="session_lifetime" name="session_lifetime" class="layui-input" min="0">
                            <div class="help-text">登录用户会话有效期，到期提示会话过期需重新登录。默认为7天，设置为0表示不限制有效期</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="enable_baidu_api" name="enable_baidu_api" value="1" onchange="toggleBaiduApiFields()">
                                <span>启用百度API商品识别</span>
                            </label>
                            <div class="help-text">启用后，将使用百度API进行商品图片识别，支持直接在搜索框粘贴图片识别，支持实物图</div>
                        </div>
                        
                        <div id="baidu_api_settings" style="display: none;">
                            <div class="alert alert-info">
                                请先前往<a href="https://console.bce.baidu.com/" target="_blank">百度API控制台</a>注册账号并创建应用，
                                获取API Key和Secret。同时需要开通图像搜索服务，并上传商品图片建立图库。
                            </div>
                            
                            <div class="form-group">
                                <label for="baidu_api_key">百度API Key</label>
                                <input type="text" id="baidu_api_key" name="baidu_api_key" class="layui-input">
                            </div>
                            
                            <div class="form-group">
                                <label for="baidu_api_secret">百度API Secret</label>
                                <input type="text" id="baidu_api_secret" name="baidu_api_secret" class="layui-input">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="enable_watermark" name="enable_watermark" value="1">
                                <span>启用页面水印</span>
                            </label>
                            <div class="help-text">开启后，页面将显示IP和时间水印</div>
                        </div>
                        
                        <div class="form-buttons">
                            <button type="submit" class="layui-btn">保存设置</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 添加关于视图 -->
        <div id="about-view" class="view">
            <div class="about-container">
                <div class="about-header">
                    <img src="logo.png" alt="系统Logo" class="about-logo">
                    <h3>商品文件库</h3>
                    <p>版本 1.0.0</p>
                </div>
                
                <div class="about-content">
                    <h3>系统介绍</h3>
                    <p>本系统是一个功能强大的商品管理平台，专为组织提供高效的商品信息管理服务。系统支持商品编码、图文信息、规格参数、商品相关文件下载等内容的管理，帮助组织实现数字化转型...好好好，夸大了,打住</p>
                    
                    <div class="about-features">
                        <div class="feature-item">
                            <div class="feature-icon"><i class="layui-icon layui-icon-app"></i></div>
                            <div class="feature-content">
                                <h4>商品管理</h4>
                                <p>支持商品的添加、编辑、删除和查询，商品相关文件下载，可管理商品编码、图片、信息和规格等属性。</p>
                            </div>
                        </div>
                        
                        <div class="feature-item">
                            <div class="feature-icon"><i class="layui-icon layui-icon-user"></i></div>
                            <div class="feature-content">
                                <h4>用户管理</h4>
                                <p>提供完善的用户管理功能，包括用户添加、编辑、权限设置和在线状态监控。</p>
                            </div>
                        </div>
                        
                        <div class="feature-item">
                            <div class="feature-icon"><i class="layui-icon layui-icon-set"></i></div>
                            <div class="feature-content">
                                <h4>系统设置</h4>
                                <p>灵活的系统配置，支持站点名称、会话时间、IP封禁等多种设置选项。</p>
                            </div>
                        </div>
                        
                        <div class="feature-item">
                            <div class="feature-icon"><i class="layui-icon layui-icon-log"></i></div>
                            <div class="feature-content">
                                <h4>日志管理</h4>
                                <p>详细记录系统操作日志，方便管理员追踪用户行为和系统事件。</p>
                            </div>
                        </div>
                    </div>
                    
                    <h3>技术支持</h3>
                    <p>如有任何问题或建议，请联系开发者</p>
                    <p><a href="https://hyk416.cn" target="_blank" rel="noopener noreferrer">www.hyk416.cn</a></p>
                    <p>微信：hyk416-</p>
                    <br>
                    <button class="layui-btn layui-btn-sm" onclick="copyToClipboard()">复制并打开微信</button>
                    <script>
                        function copyToClipboard() {
                            navigator.clipboard.writeText('hyk416-');
                            showMessage('已复制微信号到剪贴板，即将打开微信客户端');
                            setTimeout(() => {
                                window.location.href = 'weixin://';
                            }, 2000);
                        }
                    </script>
                </div>
            </div>
        </div>
    </div>

    <!-- 商品模态框 -->
    <div id="modal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">添加商品</h2>
            <form id="productForm">
                <div class="modal-body">
                <input type="hidden" id="productId">
                <div class="form-group">
                    <label for="code">商品编码</label>
                    <input type="text" id="code" required>
                </div>
                <div class="form-group">
                    <label>商品图片</label>
                    <div class="image-upload" id="imageUpload">
                        <p>点击或拖拽图片到这里上传</p>
                        <p>支持粘贴上传</p>
                        <img id="previewImage" style="display: none; max-width: 100%; margin-top: 10px;">
                    </div>
                    <input type="hidden" id="image" required>
                </div>
                <div class="form-group">
                    <label for="info">商品信息</label>
                    <textarea id="info" required></textarea>
                </div>
                <div class="form-group">
                    <label for="link">文件链接</label>
                    <input type="text" id="link">
                </div>
                    
                    <!-- 商品规格 -->
                    <div class="form-group">
                        <label>商品规格</label>
                        <div class="spec-controls">
                            <button type="button" class="layui-btn layui-btn-sm" onclick="addSpecRow()">
                                <i class="layui-icon layui-icon-add-1"></i> 添加规格
                            </button>
                        </div>
                        <div class="spec-table-container" style="margin-top: 10px; overflow-x: auto;">
                            <table class="spec-table" style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr>
                                        <th style="width: 30%;">规格</th>
                                        <th style="width: 30%;">备注1</th>
                                        <th style="width: 30%;">备注2</th>
                                        <th style="width: 10%;">操作</th>
                                    </tr>
                                </thead>
                                <tbody id="specs-container">
                                    <!-- 规格行将通过JavaScript动态添加 -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="form-buttons">
                    <button type="submit" class="btn" id="submit-btn-manage">保存</button>
                    <button type="button" class="btn" onclick="hideModal()">取消</button>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="hideModal()">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 添加用户模态框 -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <h2>添加用户</h2>
            <form id="addUserForm">
                <input type="hidden" name="action" value="add_user">
                
                <div class="modal-body">
                <div class="form-group">
                    <label for="username">用户名 *</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <div class="layui-alert layui-alert-info">
                        <p>默认密码为：zxc123456</p>
                        <p>用户首次登录时将被要求修改密码</p>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="remark">备注</label>
                    <input type="text" id="remark" name="remark" maxlength="20">
                </div>
                
                <div class="form-group">
                    <label for="role">角色</label>
                    <select id="role" name="role">
                        <option value="user">普通用户</option>
                        <option value="admin">管理员</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="download_permission" id="download_permission_a" value="1"> 
                        允许下载文件
                    </label>
                    </div>
                </div>
                
                <div class="form-buttons">
                    <button type="button" class="btn btn-cancel" onclick="hideUserModal('addUserModal')">取消</button>
                    <button type="submit" class="btn btn-primary">添加</button>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="hideUserModal('addUserModal')">取消</button>
                    <button type="submit" class="btn btn-primary">添加</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 编辑用户模态框 -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <h2>编辑用户</h2>
            <form id="editUserForm">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" id="edit_user_id" name="user_id">
                
                <div class="modal-body">
                <div class="form-group">
                    <label for="edit_username">用户名</label>
                    <input type="text" id="edit_username" disabled>
                </div>
                
                <div class="form-group">
                    <label for="edit_password">新密码 (留空表示不修改)</label>
                    <input type="password" id="edit_password" name="password" autocomplete="new-password">
                </div>
                
                <div class="form-group">
                    <label for="edit_remark">备注</label>
                    <input type="text" id="edit_remark" name="remark" maxlength="20">
                </div>
                
                <div class="form-group">
                    <label for="edit_role">角色</label>
                    <select id="edit_role" name="role">
                        <option value="user">普通用户</option>
                        <option value="admin">管理员</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="edit_download_permission" name="download_permission" value="1"> 
                        允许下载文件
                    </label>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="edit_need_password_reset" name="need_password_reset" value="1"> 
                        下次登录需要重置密码
                    </label>
                </div>
                
                <div class="form-group">
                    <label for="edit_status">状态</label>
                    <select id="edit_status" name="status">
                        <option value="active">正常</option>
                        <option value="inactive">禁用</option>
                    </select>
                    </div>
                </div>
                
                <div class="form-buttons">
                    <button type="button" class="btn btn-cancel" onclick="hideUserModal('editUserModal')">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="hideUserModal('editUserModal')">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 删除用户表单 -->
    <form id="deleteUserForm" style="display: none;">
        <input type="hidden" name="action" value="delete_user">
        <input type="hidden" id="delete_user_id" name="user_id">
    </form>

    <script>
// 全局变量声明
let currentPage = 1;
let pageSize = 30;
let totalItems = 0;
const imageUpload = document.getElementById('imageUpload');
let currentUploadedFile = null; // 保存当前上传的文件名
    let productSpecs = []; // 存储商品规格数据
    
    // 用户管理变量
    let userCurrentPage = 1;
    let userPageSize = 10;
    let userTotalItems = 0;
    
    // 日志管理变量
    let logCurrentPage = 1;
    let logPageSize = 15;
    let logTotalItems = 0;
    let allLogs = []; // 存储当前日期所有日志
    
    // 重置日志筛选
    function resetLogFilters() {
        document.getElementById('log-username-filter').value = '';
        document.getElementById('log-ip-filter').value = '';
        document.getElementById('log-action-filter').value = '';
        logCurrentPage = 1;
        applyLogFilters();
    }
    
    // 页面切换函数
    function goToLogPage(page) {
        logCurrentPage = page;
        renderFilteredLogs();
    }
    
    // 应用筛选并重新加载日志
    function applyLogFilters() {
        logCurrentPage = 1;
        renderFilteredLogs();
    }

// 显示对应视图
function showView(viewName) {
        try {
            // 隐藏所有视图
            document.querySelectorAll('.view').forEach(view => {
                view.classList.remove('active');
            });
            
            // 显示指定视图
            const viewElement = document.getElementById(viewName + '-view');
            if (viewElement) {
                viewElement.classList.add('active');
            } else {
                console.error('视图元素未找到:', viewName + '-view');
            }
            
            // 高亮对应菜单
            const menuElement = document.getElementById('menu-' + viewName);
            if (menuElement) {
                menuElement.classList.add('active');
            } else {
                console.error('菜单元素未找到:', 'menu-' + viewName);
            }
            
            // 根据视图类型加载不同的数据
            if (viewName === 'products') {
                loadProducts();
            } else if (viewName === 'users') {
                loadUsers();
            } else if (viewName === 'logs') {
                refreshLogs();
            } else if (viewName === 'ipban') {
                loadIpBanList();
            } else if (viewName === 'site') {
                loadSiteConfig();
            } else if (viewName === 'dashboard') {
                loadDashboardData();
            } else if (viewName === 'about') {
                // 关于页面不需要加载数据
            }
        } catch (error) {
            console.error('切换视图错误:', error);
        }
    }

// 页面加载完成后执行
document.addEventListener('DOMContentLoaded', function() {
        // 初始化layui模块
        layui.use(['layer', 'form'], function(){
            window.layer = layui.layer;
        });
        
        // 绑定表单提交事件
        const productForm = document.getElementById('productForm');
        if (productForm) {
            productForm.addEventListener('submit', saveProduct);
        }
        
        // 绑定用户管理表单提交事件
        const addUserForm = document.getElementById('addUserForm');
        if (addUserForm) {
            addUserForm.addEventListener('submit', function(event) {
                handleUserSubmit(event, 'addUserForm', 'addUserModal');
            });
        }
        
        const editUserForm = document.getElementById('editUserForm');
        if (editUserForm) {
            editUserForm.addEventListener('submit', function(event) {
                handleUserSubmit(event, 'editUserForm', 'editUserModal');
            });
        }
        
        // 默认加载商品管理视图
        showView('products');
        
        // 绑定菜单点击事件
        document.querySelectorAll('.nav-menu a').forEach(link => {
            link.addEventListener('click', function(event) {
                // 防止默认事件
                event.preventDefault();
                
                // 从data-view属性中获取视图名称
                const viewName = this.getAttribute('data-view');
                if (viewName) {
                    showView(viewName);
                }
            });
        });
        
        // 从 localStorage 恢复上次选择的每页显示数量
        const savedPageSize = localStorage.getItem('userPageSize');
        if (savedPageSize) {
            const pageSizeSelect = document.getElementById('user-page-size');
            if (pageSizeSelect) {
                pageSizeSelect.value = savedPageSize;
                userPageSize = parseInt(savedPageSize);
            }
        }
        
        // 搜索条件变更监听
        const searchInputs = document.querySelectorAll('.search-input');
        if (searchInputs) {
            searchInputs.forEach(input => {
                input.addEventListener('change', function() {
                    userCurrentPage = 1;
                    loadUsers();
                });
            });
        }
        
        // 页面大小变更监听
        const userPageSizeSelect = document.getElementById('user-page-size');
        if (userPageSizeSelect) {
            userPageSizeSelect.addEventListener('change', function() {
                userCurrentPage = 1;
                loadUsers();
            });
        }
    });

// 加载商品列表
async function loadProducts() {
    try {
        const search = document.querySelector('.search-box').value;
        const response = await fetch(`api/manage.php?action=list&page=${currentPage}&pageSize=${pageSize}&search=${search}`);
        const result = await response.json();
        
        if (result.success) {
            totalItems = result.total;
            renderProducts(result.data);
            renderPagination();
        } else {
            console.error('API error:', result.message);
            alert('加载数据失败：' + result.message);
        }
    } catch (error) {
        console.error('Error loading products:', error);
        alert('加载数据失败，请检查网络连接');
    }
}

// 渲染商品列表
function renderProducts(products) {
    const tbody = document.querySelector('#products-view tbody');
    tbody.innerHTML = products.map(product => `
        <tr data-id="${product.id}">
            <td>${product.code}</td>
            <td><img src="${product.image}" class="product-image" alt="${product.code}"></td>
            <td>${product.info}</td>
            <td>${product.link || '无'}</td>
            <td>
                <button class="btn edit-btn">编辑</button>
                <button class="btn delete delete-btn">删除</button>
            </td>
        </tr>
    `).join('');
}

// 渲染分页
function renderPagination() {
        try {
    const pagination = document.querySelector('.pagination');
            if (!pagination) {
                console.error('未找到分页容器');
                return;
            }
            
    const pageCount = Math.ceil(totalItems / pageSize);
            
            // 计算显示的页码范围
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(pageCount, startPage + 4);
            startPage = Math.max(1, endPage - 4);
    
    let buttons = '';
            
            // 首页按钮
            buttons += `<button class="layui-btn ${currentPage === 1 ? 'layui-btn-disabled' : ''}" 
                      onclick="${currentPage === 1 ? '' : 'goToPage(1)'}" ${currentPage === 1 ? 'disabled' : ''}>首页</button>`;
            
            // 上一页按钮
            buttons += `<button class="layui-btn ${currentPage === 1 ? 'layui-btn-disabled' : ''}" 
                      onclick="${currentPage === 1 ? '' : 'goToPage(' + (currentPage - 1) + ')'}" ${currentPage === 1 ? 'disabled' : ''}>上一页</button>`;
            
            // 页码按钮
            for(let i = startPage; i <= endPage; i++) {
                buttons += `<button class="layui-btn ${i === currentPage ? 'layui-btn-normal' : ''}" 
                         onclick="${i === currentPage ? '' : 'goToPage(' + i + ')'}" ${i === currentPage ? 'disabled' : ''}>${i}</button>`;
            }
            
            // 下一页按钮
            buttons += `<button class="layui-btn ${currentPage === pageCount ? 'layui-btn-disabled' : ''}" 
                      onclick="${currentPage === pageCount ? '' : 'goToPage(' + (currentPage + 1) + ')'}" ${currentPage === pageCount ? 'disabled' : ''}>下一页</button>`;
            
            // 末页按钮
            buttons += `<button class="layui-btn ${currentPage === pageCount ? 'layui-btn-disabled' : ''}" 
                      onclick="${currentPage === pageCount ? '' : 'goToPage(' + pageCount + ')'}" ${currentPage === pageCount ? 'disabled' : ''}>末页</button>`;
            
            // 显示总数和当前页信息
            buttons += `<span class="layui-btn layui-btn-disabled">共 ${totalItems} 条记录 / ${pageCount} 页</span>`;
            
            // 更新分页区域
    pagination.innerHTML = buttons;
        } catch (error) {
            console.error('渲染分页错误:', error);
        }
}

// 显示模态框
function showModal(isEdit = false) {
    const modal = document.getElementById('modal');
    const modalTitle = document.querySelector('.modal-title');
    modalTitle.textContent = isEdit ? '编辑商品' : '添加商品';
    
        if (!isEdit) {
            // 清空表单
            const codeInput = document.getElementById('code');
            codeInput.value = '';
            codeInput.readOnly = false; // 添加模式下商品编码可编辑
            codeInput.classList.remove('readonly-input');
            
            document.getElementById('productId').value = '';
            document.getElementById('info').value = '';
            document.getElementById('link').value = '';
            document.getElementById('image').value = '';
            document.getElementById('previewImage').style.display = 'none';
            
            // 清空规格
            productSpecs = [];
            const specsContainer = document.getElementById('specs-container');
            specsContainer.innerHTML = '';
        }
        
        // 调整模态框为flex显示
    modal.style.display = 'flex';
        
        // 滚动条回到顶部
        setTimeout(() => {
            const modalBody = modal.querySelector('.modal-body');
            if (modalBody) {
                modalBody.scrollTop = 0;
            }
        }, 10);
}

// 隐藏模态框
function hideModal() {
    const modal = document.getElementById('modal');
    modal.style.display = 'none';
    
    // 如果有未保存的上传文件，删除它
    if (currentUploadedFile && !document.getElementById('productId').value) {
        deleteUploadedFile(currentUploadedFile);
        currentUploadedFile = null;
    }
}

// 编辑商品
    function editProduct(id) {
        fetch(`api/manage.php?action=get&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('productId').value = data.product.id;
                    document.getElementById('code').value = data.product.code;
                    document.getElementById('info').value = data.product.info;
                    document.getElementById('image').value = data.product.image;
                    document.getElementById('link').value = data.product.link;
                    
                    // 处理规格
                    renderSpecsToForm(data.product.specs || []);
                    
                    // 预览图片
                    const previewImage = document.getElementById('previewImage');
                    if (data.product.image) {
                        previewImage.src = data.product.image;
                        previewImage.style.display = 'block';
                        // 保存原始图片路径，用于后续判断是否需要删除旧图片
                        previewImage.setAttribute('data-original-src', data.product.image);
                        // 更新当前上传文件变量，避免删除现有图片
                        currentUploadedFile = data.product.image;
                    } else {
                        previewImage.style.display = 'none';
                        previewImage.removeAttribute('data-original-src');
                    }
                    
                    // 更新模态框标题
                    document.querySelector('.modal-title').textContent = '编辑商品';
                    
                    // 显示模态框
                    const modal = document.getElementById('modal');
                    modal.style.display = 'flex';
                } else {
                    showMessage('获取商品数据失败: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('编辑商品错误:', error);
                showMessage('获取商品数据失败', 'error');
            });
}

// 删除商品
    function deleteProduct(id) {
        showConfirm('确定要删除这个商品吗？', function(confirmed) {
            if (!confirmed) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);

            fetch('api/manage.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('商品删除成功', 'success');
            loadProducts();
            } else {
                    showMessage('删除失败: ' + (data.message || '未知错误'), 'error');
                }
            })
            .catch(error => {
                console.error('删除商品错误:', error);
                showMessage('删除失败，请检查网络连接', 'error');
            });
        });
}

// 保存商品
    function saveProduct(event) {
        event.preventDefault();
        
        const id = document.getElementById('productId').value;
        const code = document.getElementById('code').value;
        const info = document.getElementById('info').value;
        const image = document.getElementById('image').value;
        const link = document.getElementById('link').value;
        const specs = getSpecsFromForm();
        
        // 保存当前图片路径以便之后比较
        const oldImage = document.querySelector('#previewImage').getAttribute('data-original-src') || '';
        
        // 表单验证
        if (!code.trim()) {
            showMessage('商品编码不能为空', 'error');
            return;
        }
        
        const formData = new FormData();
        formData.append('code', code);
        formData.append('info', info);
        formData.append('image', image);
        formData.append('link', link);
        formData.append('specs', JSON.stringify(specs));
        
        let action = 'create';
        if (id) {
            action = 'update';
            formData.append('id', id);
        }
        
        formData.append('action', action);
        
        // 显示加载提示
        layui.use(['layer'], function(){
            const layer = layui.layer;
            const loadingIndex = layer.load(1, {
                shade: [0.1, '#fff']
            });
            
            fetch('api/manage.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                layer.close(loadingIndex);
                if (data.success) {
                    showMessage(id ? '商品更新成功' : '商品添加成功', 'success');
                    hideModal();
                    loadProducts();
                    
                    // 只在编辑模式且更新了图片时删除旧图片
                    if (id && id !== 'undefined' && image !== oldImage && oldImage && image) {
                        // 确保新旧图片不同且都存在
                        deleteUploadedFile(oldImage);
                    }
                } else {
                    showMessage('操作失败: ' + (data.message || '未知错误'), 'error');
                }
            })
            .catch(error => {
                layer.close(loadingIndex);
                console.error('保存商品错误:', error);
                showMessage('操作失败: ' + error.message, 'error');
            });
        });
}

// 处理图片上传
async function handleImageUpload(file) {
    try {
        const code = document.getElementById('code').value;
        if (!code) {
            alert('请先填写商品编码');
            return;
        }

        const uploadStatus = document.createElement('div');
        uploadStatus.textContent = '上传中...';
        imageUpload.appendChild(uploadStatus);

        const reader = new FileReader();
        
        reader.onload = async function(e) {
            try {
                const imageData = e.target.result;
                
                if (imageData.length > 5 * 1024 * 1024) {
                    throw new Error('图片大小不能超过5MB');
                }

                const formData = new FormData();
                formData.append('image', imageData);
                formData.append('code', code);

                const response = await fetch('api/upload.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // 保存临时文件名，稍后提交表单成功后再删除旧文件
                    const oldFile = currentUploadedFile;
                    currentUploadedFile = result.filename;
                    
                    // 更新表单字段和预览
                    document.getElementById('image').value = result.filename;
                    const previewImage = document.getElementById('previewImage');
                    previewImage.src = result.filename;
                    previewImage.style.display = 'block';
                    uploadStatus.textContent = '上传成功';
                    uploadStatus.style.color = 'green';
                    setTimeout(() => uploadStatus.remove(), 2000);
                } else {
                    throw new Error(result.message || '上传失败');
                }
            } catch (error) {
                console.error('Upload error:', error);
                uploadStatus.textContent = '上传失败：' + error.message;
                uploadStatus.style.color = 'red';
                setTimeout(() => uploadStatus.remove(), 3000);
            }
        };

        reader.readAsDataURL(file);
    } catch (error) {
        console.error('Handle upload error:', error);
        alert('处理文件失败：' + error.message);
    }
}

// 删除上传的文件
async function deleteUploadedFile(filename) {
    try {
        const formData = new FormData();
        formData.append('filename', filename);
        
        await fetch('api/delete_file.php', {
            method: 'POST',
            body: formData
        });
    } catch (error) {
        console.error('Delete file error:', error);
    }
}

// 页面切换
function goToPage(page) {
    currentPage = page;
    loadProducts();
}

// 用户管理函数
// 显示添加用户模态框
function showAddUserModal() {
        const modal = document.getElementById('addUserModal');
        modal.style.display = 'flex';
        
        // 滚动条回到顶部
        setTimeout(() => {
            const modalBody = modal.querySelector('.modal-body');
            if (modalBody) {
                modalBody.scrollTop = 0;
            }
        }, 10);
    }
    
    // 隐藏用户模态框
    function hideUserModal(modalId) {
        const modal = document.getElementById(modalId);
        modal.style.display = 'none';
}

// 显示编辑用户模态框
    function showEditUserModal(userOrId, username, remark, role, downloadPermission, needPasswordReset, status) {
        // 支持两种调用方式：传递用户对象或者单独的参数
        if (typeof userOrId === 'object') {
            // 传递了用户对象
            const user = userOrId;
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_username').value = user.username;
            document.getElementById('edit_password').value = '';
    document.getElementById('edit_remark').value = user.remark || '';
    document.getElementById('edit_role').value = user.role;
    document.getElementById('edit_download_permission').checked = user.download_report_permission == 1;
            document.getElementById('edit_need_password_reset').checked = user.need_password_reset == 1;
    document.getElementById('edit_status').value = user.status;
        } else {
            // 传递了单独的参数
            document.getElementById('edit_user_id').value = userOrId;
            document.getElementById('edit_username').value = username;
    document.getElementById('edit_password').value = '';
            document.getElementById('edit_remark').value = remark || '';
            document.getElementById('edit_role').value = role;
            document.getElementById('edit_download_permission').checked = downloadPermission == 1;
            document.getElementById('edit_need_password_reset').checked = needPasswordReset == 1;
            document.getElementById('edit_status').value = status;
        }
        
        const modal = document.getElementById('editUserModal');
        modal.style.display = 'flex';
        
        // 滚动条回到顶部
        setTimeout(() => {
            const modalBody = modal.querySelector('.modal-body');
            if (modalBody) {
                modalBody.scrollTop = 0;
            }
        }, 10);
    }

    // 删除用户确认
function confirmDeleteUser(userId, username) {
        showConfirm(`确定要删除用户 "${username}" 吗？此操作不可恢复！`, function(confirmed) {
            if (!confirmed) return;
            
            const formData = new FormData();
            formData.append('action', 'delete_user');
            formData.append('user_id', userId);
            
            fetch('api/manage.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('用户已成功删除', 'success');
                    loadUsers(); // 重新加载用户列表
                } else {
                    showMessage('删除失败: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('删除用户请求错误:', error);
                showMessage('删除用户出错', 'error');
            });
        });
}

// 事件监听器设置
document.addEventListener('DOMContentLoaded', function() {
    // 事件委托 - 移到 DOMContentLoaded 事件内部
    const productsTableBody = document.querySelector('#products-view tbody');
    if (productsTableBody) {
        productsTableBody.addEventListener('click', function(event) {
            const target = event.target;
            const row = target.closest('tr');
            if (!row) return;
            
            const id = row.getAttribute('data-id');
            if (!id) return;

            if (target.classList.contains('edit-btn')) {
                editProduct(id);
            } else if (target.classList.contains('delete-btn')) {
                deleteProduct(id);
            }
        });
    }
    
    // 粘贴事件
    document.addEventListener('paste', function(event) {
        const items = (event.clipboardData || event.originalEvent.clipboardData).items;
        
        for (let item of items) {
            if (item.type.indexOf('image') === 0) {
                const file = item.getAsFile();
                if (file.size > 5 * 1024 * 1024) {
                    alert('图片大小不能超过5MB');
                    return;
                }
                handleImageUpload(file);
                break;
            }
        }
    });

    // 拖放事件
    const imageUpload = document.getElementById('imageUpload');
    if (imageUpload) {
        imageUpload.addEventListener('dragover', function(event) {
            event.preventDefault();
            event.stopPropagation();
            this.style.borderColor = '#4299e1';
        });

        imageUpload.addEventListener('dragleave', function(event) {
            event.preventDefault();
            event.stopPropagation();
            this.style.borderColor = '#e2e8f0';
        });

        imageUpload.addEventListener('drop', function(event) {
            event.preventDefault();
            event.stopPropagation();
            this.style.borderColor = '#e2e8f0';
            
            const files = event.dataTransfer.files;
            if (files.length > 0) {
                const file = files[0];
                if (file.type.indexOf('image') === 0) {
                    if (file.size > 5 * 1024 * 1024) {
                        alert('图片大小不能超过5MB');
                        return;
                    }
                    handleImageUpload(file);
                }
            }
        });

        // 点击上传
        imageUpload.addEventListener('click', function() {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.onchange = function() {
                if (this.files.length > 0) {
                    const file = this.files[0];
                    if (file.size > 5 * 1024 * 1024) {
                        alert('图片大小不能超过5MB');
                        return;
                    }
                    handleImageUpload(file);
                }
            };
            input.click();
        });
    }

    // 搜索功能
    const searchBox = document.querySelector('.search-box');
    if (searchBox) {
        searchBox.addEventListener('input', function() {
            currentPage = 1;
            loadProducts();
        });
    }

    // 页面大小切换
    const pageSizeSelect = document.querySelector('.page-size');
    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function(e) {
            pageSize = parseInt(e.target.value);
            currentPage = 1;
            loadProducts();
        });
    }

    // 表单提交
    const productForm = document.getElementById('productForm');
    if (productForm) {
        productForm.addEventListener('submit', saveProduct);
    }

    // 点击模态框外部关闭
    window.onmousedown = function(event) {
        if (event.target.className === 'modal') {
            event.target.style.display = 'none';
        }
    };

    // 初始加载
    loadProducts();
});
    
    // 重置搜索条件
    function resetUserSearch() {
        const usernameSearch = document.getElementById('username-search');
        const roleFilter = document.getElementById('role-filter');
        const permissionFilter = document.getElementById('permission-filter');
        const statusFilter = document.getElementById('status-filter');
        const loginStatusFilter = document.getElementById('login-status-filter');
        
        if (usernameSearch) usernameSearch.value = '';
        if (roleFilter) roleFilter.value = '';
        if (permissionFilter) permissionFilter.value = '';
        if (statusFilter) statusFilter.value = '';
        if (loginStatusFilter) loginStatusFilter.value = '';
        
        userCurrentPage = 1;
        loadUsers();
    }
    
    // 用户页面切换
    function goToUserPage(page) {
        userCurrentPage = page;
        loadUsers();
    }
    
    // 渲染用户分页
    function renderUserPagination() {
        try {
            const paginationDivs = document.querySelectorAll('#user-pagination');
            if (!paginationDivs || paginationDivs.length === 0) {
                console.error('未找到用户分页容器');
                return;
            }
            
        const pageCount = Math.ceil(userTotalItems / userPageSize);
        
        // 计算显示的页码范围
        let startPage = Math.max(1, userCurrentPage - 2);
        let endPage = Math.min(pageCount, startPage + 4);
        startPage = Math.max(1, endPage - 4);
        
        let buttons = '';
        
        // 首页按钮
            buttons += `<button class="layui-btn ${userCurrentPage === 1 ? 'layui-btn-disabled' : ''}" 
                    onclick="${userCurrentPage === 1 ? '' : 'goToUserPage(1)'}" ${userCurrentPage === 1 ? 'disabled' : ''}>首页</button>`;
        
        // 上一页按钮
            buttons += `<button class="layui-btn ${userCurrentPage === 1 ? 'layui-btn-disabled' : ''}" 
                    onclick="${userCurrentPage === 1 ? '' : 'goToUserPage(' + (userCurrentPage - 1) + ')'}" ${userCurrentPage === 1 ? 'disabled' : ''}>上一页</button>`;
        
        // 页码按钮
        for(let i = startPage; i <= endPage; i++) {
                buttons += `<button class="layui-btn ${i === userCurrentPage ? 'layui-btn-normal' : ''}" 
                       onclick="${i === userCurrentPage ? '' : 'goToUserPage(' + i + ')'}" ${i === userCurrentPage ? 'disabled' : ''}>${i}</button>`;
        }
        
        // 下一页按钮
            buttons += `<button class="layui-btn ${userCurrentPage === pageCount ? 'layui-btn-disabled' : ''}" 
                    onclick="${userCurrentPage === pageCount ? '' : 'goToUserPage(' + (userCurrentPage + 1) + ')'}" ${userCurrentPage === pageCount ? 'disabled' : ''}>下一页</button>`;
        
        // 末页按钮
            buttons += `<button class="layui-btn ${userCurrentPage === pageCount ? 'layui-btn-disabled' : ''}" 
                    onclick="${userCurrentPage === pageCount ? '' : 'goToUserPage(' + pageCount + ')'}" ${userCurrentPage === pageCount ? 'disabled' : ''}>末页</button>`;
        
        // 显示总数和当前页信息
            buttons += `<span class="layui-btn layui-btn-disabled">共 ${userTotalItems} 条记录 / ${pageCount} 页</span>`;
        
        // 更新所有分页区域
        paginationDivs.forEach(div => {
            div.innerHTML = buttons;
        });
        } catch (error) {
            console.error('渲染用户分页错误:', error);
        }
    }
    
    // 修改加载用户函数以支持分页和实时搜索
    async function loadUsers() {
        try {
            const usernameQuery = document.getElementById('username-search')?.value || '';
            const roleQuery = document.getElementById('role-filter')?.value || '';
            const permissionQuery = document.getElementById('permission-filter')?.value || '';
            const statusQuery = document.getElementById('status-filter')?.value || '';
            const loginStatusQuery = document.getElementById('login-status-filter')?.value || '';
            const pageSizeSelect = document.getElementById('user-page-size');
            
            if (pageSizeSelect) {
                userPageSize = parseInt(pageSizeSelect.value);
            // 保存当前选择到 localStorage
            localStorage.setItem('userPageSize', userPageSize);
            }
            
            const queryParams = new URLSearchParams({
                action: 'list_users',
                page: userCurrentPage,
                pageSize: userPageSize,
                username: usernameQuery,
                role: roleQuery,
                permission: permissionQuery,
                status: statusQuery,
                login_status: loginStatusQuery
            });
            
            
            const response = await fetch(`manage.php?${queryParams.toString()}`);
            const result = await response.json();
            
            
            if (result.success) {
                userTotalItems = result.total;
                renderUsers(result.data);
                renderUserPagination();
            } else {
                console.error('API错误:', result.message);
                if (typeof layer !== 'undefined') {
                layer.msg('加载数据失败：' + result.message, {icon: 2});
                } else {
                    alert('加载数据失败：' + result.message);
                }
            }
        } catch (error) {
            console.error('加载用户错误:', error);
            if (typeof layer !== 'undefined') {
            layer.msg('加载数据失败，请检查网络连接', {icon: 2});
            } else {
                alert('加载数据失败，请检查网络连接');
            }
        }
    }
    
    // 修改渲染用户列表函数
    function renderUsers(users) {
        try {
        const tbody = document.querySelector('#user-list');
            if (!tbody) {
                console.error('找不到用户列表容器 #user-list');
                return;
            }
            
            if (users.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" class="text-center">没有找到符合条件的用户</td></tr>';
                return;
            }
            
        tbody.innerHTML = users.map(user => {
            // 处理登录状态显示
            let loginStatusClass = 'status-inactive';
            let loginStatusText = '离线';
            
            if (user.login_status === 'online') {
                loginStatusClass = 'status-active';
                loginStatusText = '在线';
            } else if (user.login_status === 'forced_offline') {
                loginStatusClass = 'status-warning';
                loginStatusText = `被强制下线 (by ${user.forced_by_username || '系统'} at ${user.force_logout_time || '-'})`;
            }
                
                // 计算登录会话剩余有效期
                let sessionTimeLeft = '无会话';
                let sessionTimeLeftClass = 'status-inactive';
                
                if (user.login_status === 'online' && user.last_login) {
                    const lastLogin = new Date(user.last_login);
                    const expiresAt = new Date(lastLogin.getTime() + 7 * 24 * 60 * 60 * 1000); // 7天有效期
                    const now = new Date();
                    const diffMs = expiresAt - now;
                    
                    if (diffMs > 0) {
                        // 计算剩余时间
                        const days = Math.floor(diffMs / (1000 * 60 * 60 * 24));
                        const hours = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                        const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
                        
                        // 格式化显示，精确到分钟
                        sessionTimeLeft = `${days}天${hours}小时${minutes}分`;
                        
                        // 调整颜色阈值
                        // 绿色：剩余时间超过2天
                        // 黄色：剩余时间少于2天但超过12小时
                        // 红色：剩余时间少于12小时或已过期
                        if (diffMs > 2 * 24 * 60 * 60 * 1000) { 
                            sessionTimeLeftClass = 'status-active';
                        } else if (diffMs > 12 * 60 * 60 * 1000) {
                            sessionTimeLeftClass = 'status-warning';
                        } else {
                            sessionTimeLeftClass = 'status-error';
                        }
                    } else {
                        sessionTimeLeft = '已过期';
                        sessionTimeLeftClass = 'status-error';
                    }
                }
            
            return `
            <tr>
                <td>${user.username}</td>
                <td>${user.remark || ''}</td>
                <td>${user.role === 'admin' ? '管理员' : '普通用户'}</td>
                <td>
                    <span class="status-badge ${user.download_report_permission == 1 ? 'status-active' : 'status-inactive'}">
                        ${user.download_report_permission == 1 ? '可下载' : '不可下载'}
                    </span>
                </td>
                <td>
                    <span class="status-badge ${user.status === 'active' ? 'status-active' : 'status-inactive'}">
                        ${user.status === 'active' ? '正常' : '禁用'}
                    </span>
                </td>
                <td>${user.last_login_ip || '-'}</td>
                <td>
                    <span class="status-badge ${loginStatusClass}">
                        ${loginStatusText}
                    </span>
                </td>
                    <td>
                        <span class="status-badge ${sessionTimeLeftClass}">
                            ${sessionTimeLeft}
                    </span>
                </td>
                <td>${user.created_at}</td>
                <td>${user.last_login || '从未登录'}</td>
                <td>
                    <button class="layui-btn layui-btn-xs user-operation-btn edit" onclick='showEditUserModal(${JSON.stringify(user).replace(/'/g, "&#39;")})' title="编辑用户：${user.username}">
                        <i class="layui-icon layui-icon-edit"></i>编辑
                    </button>
                    <button class="layui-btn layui-btn-xs layui-btn-danger user-operation-btn delete" onclick="confirmDeleteUser(${user.id}, '${user.username}')" title="删除用户：${user.username}">
                        <i class="layui-icon layui-icon-delete"></i>删除
                        
                    </button>
                    <button class="layui-btn layui-btn-xs ${user.login_status === 'online' ? 'layui-btn-warm' : 'layui-btn-disabled'} user-operation-btn" 
                            onclick="${user.login_status === 'online' ? `forceLogout(${user.id}, '${user.username}')` : 'void(0)'}"
                            title="${user.login_status === 'online' ? `强制下线用户：${user.username}` : '该用户已离线'}">
                        <i class="layui-icon layui-icon-logout"></i>强制下线
                    </button>
                </td>
            </tr>
            `;
            }).join('');
        } catch (error) {
            console.error('渲染用户列表错误:', error);
            const tbody = document.querySelector('#user-list');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="11" class="text-center">渲染用户列表出错，请查看控制台</td></tr>';
            }
        }
    }
    
    // 添加页面加载和事件监听
    document.addEventListener('DOMContentLoaded', function() {
        // 初始化layui模块
        layui.use(['layer'], function(){
            window.layer = layui.layer;
        });
        
        // 切换到用户管理视图时加载用户列表
        document.getElementById('menu-users').addEventListener('click', function() {
            userCurrentPage = 1;
            loadUsers();
        });
    });

    // 添加导出功能
    function exportData(type) {
        // 检查上次导出时间
        const lastExportTime = localStorage.getItem('last' + type.charAt(0).toUpperCase() + type.slice(1) + 'ExportTime');
        const now = Date.now();
        
        if (lastExportTime && (now - parseInt(lastExportTime)) < 5 * 60 * 1000) { // 5分钟
            const remainingTime = 5 - Math.floor((now - parseInt(lastExportTime)) / 60000);
            layer.msg(`操作过于频繁，请在${remainingTime}分钟后再试`, {icon: 0, time: 3000});
            return;
        }
        
        // 显示确认对话框
        layer.confirm('确定要导出' + (type === 'users' ? '用户列表' : '商品列表') + '吗？', {
            btn: ['确定', '取消'],
            title: '导出确认'
        }, function(index) {
            layer.close(index);
            // 记录导出时间
            localStorage.setItem('last' + type.charAt(0).toUpperCase() + type.slice(1) + 'ExportTime', now.toString());
            // 执行导出
            window.location.href = `api/export.php?type=${type}`;
        });
    }

    // 强制下线功能
    function forceLogout(userId, username) {
        showConfirm(`确定要强制用户 "${username}" 下线吗？`, function(confirmed) {
            if (!confirmed) return;
            
            // 确保layer已初始化
            if (typeof layui !== 'undefined' && typeof layui.layer === 'undefined') {
                layui.use(['layer'], function(){
                    window.layer = layui.layer;
                    executeForceLogout();
                });
            } else {
                executeForceLogout();
            }
            
            function executeForceLogout() {
                fetch('api/force_logout.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'user_id=' + userId
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showMessage('用户已成功强制下线', 'success');
                        // 刷新用户列表
                        loadUsers();
                        } else {
                        showMessage('强制下线失败：' + result.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('强制下线请求错误:', error);
                    showMessage('强制下线请求出错', 'error');
                });
            }
        });
    }

    // 初始化导航菜单
    document.querySelectorAll('.nav-menu a').forEach(item => {
        item.addEventListener('click', function() {
            // 移除所有active类
            document.querySelectorAll('.nav-menu a').forEach(i => i.classList.remove('active'));
            // 添加active类到当前点击项
            this.classList.add('active');
            
            // 隐藏所有视图
            document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
            
            // 显示对应视图
            const viewId = this.getAttribute('data-view') + '-view';
            document.getElementById(viewId).classList.add('active');
            
            // 如果是日志视图，加载日志
            if (this.getAttribute('data-view') === 'logs') {
                loadLogs();
            }
        });
    });

    // 加载日志函数
    function loadLogs(date = null) {
        const logsContainer = document.getElementById('logs-container');
        if (!logsContainer) {
            console.error('日志容器元素未找到');
            return;
        }
        
        logsContainer.innerHTML = '<div class="empty-logs"><i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop"></i><p>加载日志中...</p></div>';
        
        let url = '?action=get_logs';
        if (date) {
            url += '&date=' + date;
        }
        
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateLogDateSelect(data.dates, data.current_date);
                    allLogs = data.data; // 存储所有日志数据
                    logTotalItems = allLogs.length;
                    renderFilteredLogs(); // 筛选并显示日志
                } else {
                    logsContainer.innerHTML = '<div class="empty-logs"><i class="layui-icon layui-icon-close-fill"></i><p>加载失败: ' + data.message + '</p></div>';
                }
            })
            .catch(error => {
                console.error('加载日志错误:', error);
                logsContainer.innerHTML = '<div class="empty-logs"><i class="layui-icon layui-icon-close-fill"></i><p>加载失败: ' + error.message + '</p></div>';
            });
    }
    
    // 更新日志日期选择器
    function updateLogDateSelect(dates, currentDate) {
        const select = document.getElementById('log-date-select');
        select.innerHTML = '';
        
        if (dates.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = '无可用日志';
            select.appendChild(option);
            select.disabled = true;
            return;
        }
        
        select.disabled = false;
        dates.forEach(date => {
            const option = document.createElement('option');
            option.value = date;
            option.textContent = date;
            if (date === currentDate) {
                option.selected = true;
            }
            select.appendChild(option);
        });
        
        // 添加事件监听
        select.onchange = function() {
            loadLogs(this.value);
        };
    }
    
    // 筛选并渲染日志
    function renderFilteredLogs() {
        
        const usernameFilter = document.getElementById('log-username-filter').value.toLowerCase();
        const ipFilter = document.getElementById('log-ip-filter').value.toLowerCase();
        const actionFilter = document.getElementById('log-action-filter').value;
        
        // 筛选日志
        const filteredLogs = allLogs.filter(log => {
            const username = (log.username || '').toLowerCase();
            const ip = (log.ip || '').toLowerCase();
            const action = log.action || '';
            
            return (usernameFilter === '' || username.includes(usernameFilter)) &&
                   (ipFilter === '' || ip.includes(ipFilter)) &&
                   (actionFilter === '' || action.includes(actionFilter));
        });
        
        logTotalItems = filteredLogs.length;
        
        // 分页处理
        const startIndex = (logCurrentPage - 1) * logPageSize;
        const endIndex = Math.min(startIndex + logPageSize, filteredLogs.length);
        const logsToShow = filteredLogs.slice(startIndex, endIndex);
        
        // 渲染日志列表
        renderLogs(logsToShow);
        
        // 渲染分页
        renderLogPagination();
        
        // 添加筛选条件事件监听
        document.getElementById('log-username-filter').addEventListener('input', debounce(applyLogFilters, 500));
        document.getElementById('log-ip-filter').addEventListener('input', debounce(applyLogFilters, 500));
        document.getElementById('log-action-filter').addEventListener('change', applyLogFilters);
    }
    
    // 防抖函数
    function debounce(func, delay) {
        let timer;
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout(timer);
            timer = setTimeout(() => func.apply(context, args), delay);
        };
    }
    
    // 渲染日志分页
    function renderLogPagination() {
        try {
            const paginationDiv = document.getElementById('log-pagination');
            if (!paginationDiv) {
                console.error('未找到日志分页容器');
                return;
            }
            
            const pageCount = Math.ceil(logTotalItems / logPageSize);
            
            // 计算显示的页码范围
            let startPage = Math.max(1, logCurrentPage - 2);
            let endPage = Math.min(pageCount, startPage + 4);
            startPage = Math.max(1, endPage - 4);
            
            let buttons = '';
            
            // 首页按钮
            buttons += `<button class="layui-btn ${logCurrentPage === 1 ? 'layui-btn-disabled' : ''}" 
                      onclick="${logCurrentPage === 1 ? '' : 'goToLogPage(1)'}" ${logCurrentPage === 1 ? 'disabled' : ''}>首页</button>`;
            
            // 上一页按钮
            buttons += `<button class="layui-btn ${logCurrentPage === 1 ? 'layui-btn-disabled' : ''}" 
                      onclick="${logCurrentPage === 1 ? '' : 'goToLogPage(' + (logCurrentPage - 1) + ')'}" ${logCurrentPage === 1 ? 'disabled' : ''}>上一页</button>`;
            
            // 页码按钮
            for(let i = startPage; i <= endPage; i++) {
                buttons += `<button class="layui-btn ${i === logCurrentPage ? 'layui-btn-normal' : ''}" 
                         onclick="${i === logCurrentPage ? '' : 'goToLogPage(' + i + ')'}" ${i === logCurrentPage ? 'disabled' : ''}>${i}</button>`;
            }
            
            // 下一页按钮
            buttons += `<button class="layui-btn ${logCurrentPage === pageCount ? 'layui-btn-disabled' : ''}" 
                      onclick="${logCurrentPage === pageCount ? '' : 'goToLogPage(' + (logCurrentPage + 1) + ')'}" ${logCurrentPage === pageCount ? 'disabled' : ''}>下一页</button>`;
            
            // 末页按钮
            buttons += `<button class="layui-btn ${logCurrentPage === pageCount ? 'layui-btn-disabled' : ''}" 
                      onclick="${logCurrentPage === pageCount ? '' : 'goToLogPage(' + pageCount + ')'}" ${logCurrentPage === pageCount ? 'disabled' : ''}>末页</button>`;
            
            // 显示总数和当前页信息
            buttons += `<span class="layui-btn layui-btn-disabled">共 ${logTotalItems} 条记录 / ${pageCount} 页</span>`;
            
            // 更新分页区域
            paginationDiv.innerHTML = buttons;
        } catch (error) {
            console.error('渲染日志分页错误:', error);
        }
    }
    
    

    // 定期检查并更新用户在线状态和剩余有效期
    function updateSessionExpiresAt() {
        // 仅在用户页面激活时才执行
        const userView = document.getElementById('users-view');
        if (userView && userView.classList.contains('active')) {
            loadUsers();
        }
    }
    
    // 每60秒更新一次用户数据
    setInterval(updateSessionExpiresAt, 60000);
    
    // IP封禁管理变量
    let ipCurrentSearch = '';
    
    // 重置IP搜索条件
    function resetIpSearch() {
        const ipSearch = document.getElementById('ip-search');
        if (ipSearch) ipSearch.value = '';
        ipCurrentSearch = '';
        loadIpList();
    }
    
    // 刷新IP列表
    function refreshIpList() {
        const ipSearch = document.getElementById('ip-search');
        if (ipSearch) ipCurrentSearch = ipSearch.value.trim();
        loadIpList();
    }
    
    // 加载IP封禁列表
    function loadIpList() {
        try {
            const ipList = document.getElementById('ip-list');
            if (!ipList) {
                console.error('未找到IP列表容器');
                return;
            }
            
            ipList.innerHTML = '<tr><td colspan="5" class="text-center"><i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop"></i> 正在加载...</td></tr>';
            
            fetch('api/ip_ban.php?action=list' + (ipCurrentSearch ? '&search=' + encodeURIComponent(ipCurrentSearch) : ''))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderIpList(data.data);
                    } else {
                        ipList.innerHTML = `<tr><td colspan="5" class="text-center">加载失败: ${data.message}</td></tr>`;
                    }
                })
                .catch(error => {
                    console.error('加载IP封禁列表错误:', error);
                    ipList.innerHTML = `<tr><td colspan="5" class="text-center">加载失败: ${error.message}</td></tr>`;
                });
        } catch (error) {
            console.error('IP封禁管理错误:', error);
        }
    }
    
    // 渲染IP封禁列表
    function renderIpList(ipList) {
        const ipListContainer = document.getElementById('ip-list');
        if (!ipListContainer) {
            console.error('未找到IP列表容器');
            return;
        }
        
        if (ipList.length === 0) {
            ipListContainer.innerHTML = '<tr><td colspan="5" class="text-center">暂无封禁记录</td></tr>';
            return;
        }
        
        let html = '';
        ipList.forEach(ip => {
            const blockTimeRemaining = new Date(ip.last_attempt).getTime() + 24 * 60 * 60 * 1000 - new Date().getTime();
            const hours = Math.floor(blockTimeRemaining / (60 * 60 * 1000));
            const minutes = Math.floor((blockTimeRemaining % (60 * 60 * 1000)) / (60 * 1000));
            const timeRemaining = blockTimeRemaining > 0 ? `${hours}小时 ${minutes}分钟` : '已过期';
            
            html += `
            <tr>
                <td>${ip.ip_address}</td>
                <td>${ip.failures}</td>
                <td>${ip.last_attempt}</td>
                <td>${timeRemaining}</td>
                <td>
                    <button class="layui-btn layui-btn-danger layui-btn-sm" onclick="unbanIp('${ip.ip_address}')">
                        解除封禁
                    </button>
                    <div class="extend-form" style="display:inline-flex; gap:8px; align-items:center;">
                        <input type="number" id="extend-${ip.ip_address}" class="layui-input" placeholder="小时" min="1" style="width:80px;">
                        <button class="layui-btn layui-btn-sm" onclick="extendIpBan('${ip.ip_address}')">
                            追加时间
                        </button>
                    </div>
                </td>
            </tr>
            `;
        });
        
        ipListContainer.innerHTML = html;
    }
    
    // 解除IP封禁
    function unbanIp(ip) {
        console.log('解除IP封禁:', ip);
        
        showConfirm(`确定要解除 ${ip} 的封禁吗？`, function(confirmed) {
            if (!confirmed) return;
            
            fetch('api/ip_ban.php?action=unban', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ip=${encodeURIComponent(ip)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('解除封禁成功', 'success');
                    loadIpList();
                        } else {
                    showMessage('操作失败: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('解除封禁错误:', error);
                showMessage('操作失败: ' + error.message, 'error');
            });
        });
    }
    
    // 延长IP封禁时间
    function extendIpBan(ip) {
        const hours = parseInt(document.getElementById(`extend-${ip}`).value);
        
        if (isNaN(hours) || hours < 1) {
            showMessage('请输入有效的小时数', 'error');
            return;
        }
        
        fetch('api/ip_ban.php?action=extend', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `ip=${encodeURIComponent(ip)}&hours=${hours}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage('封禁时间已更新', 'success');
                loadIpList();
            } else {
                showMessage('操作失败: ' + data.message, 'error');
                    }
                })
                .catch(error => {
            console.error('延长封禁时间错误:', error);
            showMessage('操作失败: ' + error.message, 'error');
        });
    }
    
    // 添加IP搜索输入框的事件监听
    document.addEventListener('DOMContentLoaded', function() {
        const ipSearch = document.getElementById('ip-search');
        if (ipSearch) {
            ipSearch.addEventListener('input', debounce(function() {
                ipCurrentSearch = this.value.trim();
                loadIpList();
            }, 500));
        }
    });

    // 渲染日志内容
    function renderLogs(logs) {
        const logsContainer = document.getElementById('logs-container');
        
        if (logs.length === 0) {
            logsContainer.innerHTML = '<div class="empty-logs"><i class="layui-icon layui-icon-notice"></i><p>没有符合条件的日志记录</p></div>';
            return;
        }
        
        // 创建表格
        let html = `
            <table class="log-table">
                <thead>
                    <tr>
                        <th>操作时间</th>
                        <th>操作用户</th>
                        <th>用户IP</th>
                        <th>IP归属地</th>
                        <th>操作类型</th>
                        <th>详细信息</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        // 遍历日志
        logs.forEach((log, index) => {
            // 根据操作类型设置badge样式
            let badgeClass = 'badge-visit';
            if (log.action.includes('登录')) {
                badgeClass = 'badge-login';
            } else if (log.action.includes('登出')) {
                badgeClass = 'badge-logout';
            } else if (log.action.includes('修改') || log.action.includes('更新')) {
                badgeClass = 'badge-modify';
            } else if (log.action.includes('错误') || log.action.includes('失败')) {
                badgeClass = 'badge-error';
            } else if (log.action.includes('管理')) {
                badgeClass = 'badge-admin';
            } else if (log.action.includes('下载')) {
                badgeClass = 'badge-download';
            }
            
            // 获取用户信息
            const userId = log.user_id || '未登录';
            const username = log.username || '未知用户';
            const ipLocation = log.ip_location || '未知';
            
            html += `
                <tr class="log-entry" onclick="toggleLogDetails(${index})">
                    <td>${log.timestamp}</td>
                    <td>${username} (${userId})</td>
                    <td>${log.ip}</td>
                    <td>${ipLocation}</td>
                    <td><span class="badge ${badgeClass}">${log.action}</span></td>
                    <td>${log.message} <span class="badge">查看详情</span></td>
                </tr>
                <tr>
                    <td colspan="6">
                        <div id="log-data-${index}" class="log-data">
                            <strong>详细数据:</strong>
                            ${JSON.stringify(log.data, null, 2)}
                        </div>
                    </td>
                </tr>
            `;
        });
        
        html += `
                </tbody>
            </table>
        `;
        
        logsContainer.innerHTML = html;
    }
    
    // 切换显示日志详情
    function toggleLogDetails(index) {
        const logData = document.getElementById(`log-data-${index}`);
        logData.classList.toggle('show');
        
        // 切换行的展开状态
        const logEntry = logData.parentNode.parentNode.previousElementSibling;
        logEntry.classList.toggle('expanded');
    }
    
    // 刷新日志
    function refreshLogs() {
        const dateSelect = document.getElementById('log-date-select');
        loadLogs(dateSelect.value);
    }

    // 添加规格行
    function addSpecRow(spec = {}) {
        const specsContainer = document.getElementById('specs-container');
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><input type="text" class="spec-name layui-input" value="${spec.spec_name || ''}" placeholder="规格"></td>
            <td><input type="text" class="spec-value layui-input" value="${spec.spec_value || ''}" placeholder="备注1"></td>
            <td><input type="text" class="spec-remark layui-input" value="${spec.spec_remark || ''}" placeholder="备注2"></td>
            <td><button type="button" class="layui-btn layui-btn-danger layui-btn-sm" onclick="removeSpecRow(this)"><i class="layui-icon layui-icon-delete"></i></button></td>
        `;
        specsContainer.appendChild(row);
    }
    
    // 移除规格行
    function removeSpecRow(button) {
        const row = button.closest('tr');
        row.parentNode.removeChild(row);
    }
    
    // 获取编辑表单中的规格数据
    function getSpecsFromForm() {
        const specs = [];
        const rows = document.querySelectorAll('#specs-container tr');
        
        rows.forEach(row => {
            const specName = row.querySelector('.spec-name').value.trim();
            const specValue = row.querySelector('.spec-value').value.trim();
            const specRemark = row.querySelector('.spec-remark').value.trim();
            
            if (specName || specValue) {
                specs.push({
                    spec_name: specName,
                    spec_value: specValue,
                    spec_remark: specRemark
                });
            }
        });
        
        return specs;
    }
    
    // 加载商品规格
    function loadProductSpecs(productId) {
        if (!productId) {
            productSpecs = [];
            const specsContainer = document.getElementById('specs-container');
            specsContainer.innerHTML = '';
            return Promise.resolve();
        }
        
        // 获取商品规格信息
        return fetch(`api/manage.php?action=get_specs&product_id=${productId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    productSpecs = data.data || [];
                    
                    // 清空现有规格
                    const specsContainer = document.getElementById('specs-container');
                    specsContainer.innerHTML = '';
                    
                    // 添加规格行
                    if (productSpecs.length > 0) {
                        productSpecs.forEach(spec => addSpecRow(spec));
                    } else {
                        // 如果没有规格数据，默认添加一个空行
                        addSpecRow();
                    }
                } else {
                    console.error('加载商品规格失败:', data.message);
                }
            })
            .catch(error => {
                console.error('加载商品规格错误:', error);
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        // 处理会话消息（从POST操作后的重定向）
        const sessionMessage = document.getElementById('session-message');
        if (sessionMessage) {
            const message = sessionMessage.getAttribute('data-message');
            const type = sessionMessage.getAttribute('data-type');
            if (message) {
                layui.use(['layer'], function(){
                    const layer = layui.layer;
                    const icon = type === 'success' ? 1 : 
                                type === 'error' ? 2 : 
                                type === 'question' ? 3 : 
                                type === 'warning' ? 7 : 0;
                    
                    layer.msg(message, {
                        icon: icon,
                        time: 2000,
                        shade: 0.3
                    });
                });
            }
        }
        
        // 页面加载完成后的初始化
        const usernameSearch = document.getElementById('username-search');
        const roleFilter = document.getElementById('role-filter');
        const permissionFilter = document.getElementById('permission-filter');
        const statusFilter = document.getElementById('status-filter');
        const loginStatusFilter = document.getElementById('login-status-filter');
        const userPageSizeSelect = document.getElementById('user-page-size');
        
        // 为用户管理筛选条件添加事件监听
        if (usernameSearch) {
            usernameSearch.addEventListener('input', debounce(applyUserFilters, 500));
        }
        
        if (roleFilter) {
            roleFilter.addEventListener('change', applyUserFilters);
        }
        
        if (permissionFilter) {
            permissionFilter.addEventListener('change', applyUserFilters);
        }
        
        if (statusFilter) {
            statusFilter.addEventListener('change', applyUserFilters);
        }
        
        if (loginStatusFilter) {
            loginStatusFilter.addEventListener('change', applyUserFilters);
        }
        
        if (userPageSizeSelect) {
            userPageSizeSelect.addEventListener('change', function() {
                userCurrentPage = 1;
                loadUsers();
            });
        }
        
        // 为商品表单添加提交事件
        const productForm = document.getElementById('productForm');
        if (productForm) {
            productForm.addEventListener('submit', saveProduct);
        }
        
        // 初始加载商品列表
        loadProducts();
        
        // 为添加商品按钮添加事件监听
        const addProductBtn = document.querySelector('button[onclick="showModal()"]');
        if (addProductBtn) {
            addProductBtn.addEventListener('click', function() {
                // 当点击添加商品按钮时，确保表单中有一个空规格行
                setTimeout(function() {
                    const specsContainer = document.getElementById('specs-container');
                    if (specsContainer && specsContainer.children.length === 0) {
                        addSpecRow();
                    }
                }, 100);
            });
        }
        
        // 移除重复的事件监听器，因为规格添加按钮已经有onclick="addSpecRow()"的属性
    });

    // 添加一个通用的模态对话框函数
    function showMessage(message, type = 'info') {
        layui.use(['layer'], function(){
            const layer = layui.layer;
            const icon = type === 'success' ? 1 : 
                        type === 'error' ? 2 : 
                        type === 'question' ? 3 : 
                        type === 'warning' ? 7 : 0;
            
            layer.msg(message, {
                icon: icon,
                time: 2000,
                shade: 0.3
            });
        });
    }
    
    // 添加一个确认对话框函数
    function showConfirm(message, callback) {
        layui.use(['layer'], function(){
            const layer = layui.layer;
            layer.confirm(message, {
                icon: 3,
                title: '确认',
                btn: ['确定', '取消']
            }, function(index){
                layer.close(index);
                if (typeof callback === 'function') {
                    callback(true);
                }
            }, function(index){
                layer.close(index);
                if (typeof callback === 'function') {
                    callback(false);
                }
            });
        });
    }

    // 修改用户列表筛选函数
    function applyUserFilters() {
        userCurrentPage = 1;
        loadUsers();
    }

    // 检查用户会话状态
    function checkSessionStatus() {
        fetch('check_session.php')
            .then(response => response.json())
            .then(data => {
                if (!data.authenticated || data.session_expired) {
                    if (data.message) {
                        showSessionExpiredDialog(data.message, data.reason);
                    }
                }
            })
            .catch(error => {
                console.error('检查会话状态失败:', error);
            });
    }

    // 显示会话过期对话框
    function showSessionExpiredDialog(message, reason) {
        // 如果已经存在弹窗，则不再创建新的
        if (document.querySelector('.session-expired-dialog')) {
            return;
        }

        layui.use(['layer'], function(){
            const layer = layui.layer;
            
            // 根据不同的退出原因设置不同的标题和图标
            let title = '会话已过期';
            let icon = 0;
            
            if (reason === 'forced_logout') {
                title = '账号已被强制下线';
                icon = 2;
            } else if (reason === 'other_device') {
                title = '账号在其他设备登录';
                icon = 3;
            } else if (reason === 'expired') {
                title = '会话已过期';
                icon = 0;
            }
            
            const layerIndex = layer.alert(message, {
                title: title,
                icon: icon,
                closeBtn: 0,
                btn: ['重新登录'],
                yes: function(index) {
                    window.location.href = 'login.php';
                }
            });
            
            // 确保弹窗按钮可点击
            setTimeout(() => {
                const layerBtn = document.querySelector(`.layui-layer-btn0`);
                if (layerBtn) {
                    layerBtn.style.pointerEvents = 'auto';
                }
            }, 100);
            
            // 禁用页面其他元素的交互，但不包括弹窗
            document.querySelectorAll('button:not(.layui-layer-btn0), a:not(.layui-layer-btn0), input').forEach(el => {
                if (!el.closest('.layui-layer')) {
                    el.disabled = true;
                    el.style.pointerEvents = 'none';
                }
            });
        });
    }
    
    // 定期检查会话状态（每30秒检查一次）
    setInterval(checkSessionStatus, 30000);
    
    // 页面加载时检查一次会话状态
    document.addEventListener('DOMContentLoaded', function() {
        checkSessionStatus();
        // ... 其他初始化代码 ...
    });

    // 加载站点配置
    async function loadSiteConfig() {
        try {
            const response = await fetch('api/manage.php?action=get_site_config');
            const result = await response.json();
            
            if (result.success) {
                const configs = result.data;
                document.getElementById('site_title').value = configs.site_title || '';
                document.getElementById('site_h1').value = configs.site_h1 || '';
                document.getElementById('tip_text').value = configs.tip_text || '';
                document.getElementById('enable_ip_ban').checked = configs.enable_ip_ban === '1';
                document.getElementById('enable_watermark').checked = configs.enable_watermark === '1';
                document.getElementById('session_lifetime').value = configs.session_lifetime || '';
                document.getElementById('enable_baidu_api').checked = configs.enable_baidu_api === '1';
                document.getElementById('baidu_api_key').value = configs.baidu_api_key || '';
                document.getElementById('baidu_api_secret').value = configs.baidu_api_secret || '';
                document.getElementById('contact_info').value = configs.contact_info || '';
                document.getElementById('error_notice').value = configs.error_notice || '';
                document.getElementById('icp_number').value = configs.icp_number || '';
                
                // 触发百度API设置的显示/隐藏
                toggleBaiduApiFields();
            }
        } catch (error) {
            console.error('加载配置失败:', error);
            showMessage('加载配置失败', 'error');
        }
    }

    // 保存站点配置
    async function saveSiteConfig(event) {
        event.preventDefault();
        
        const configs = {
            site_title: document.getElementById('site_title').value,
            site_h1: document.getElementById('site_h1').value,
            tip_text: document.getElementById('tip_text').value,
            enable_ip_ban: document.getElementById('enable_ip_ban').checked ? '1' : '0',
            enable_watermark: document.getElementById('enable_watermark').checked ? '1' : '0',
            session_lifetime: document.getElementById('session_lifetime').value,
            enable_baidu_api: document.getElementById('enable_baidu_api').checked ? '1' : '0',
            baidu_api_key: document.getElementById('baidu_api_key').value,
            baidu_api_secret: document.getElementById('baidu_api_secret').value,
            contact_info: document.getElementById('contact_info').value,
            error_notice: document.getElementById('error_notice').value,
            icp_number: document.getElementById('icp_number').value
        };

        try {
            const response = await fetch('api/manage.php?action=update_site_config', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(configs)
            });

            const result = await response.json();
            if (result.success) {
                showMessage('保存成功', 'success');
            } else {
                showMessage(result.message || '保存失败', 'error');
            }
        } catch (error) {
            console.error('保存失败:', error);
            showMessage('保存失败', 'error');
        }
    }

    // 事件监听
    document.addEventListener('DOMContentLoaded', function() {
        // ... 其他代码 ...
        
        // 百度API启用状态切换
        document.getElementById('enable_baidu_api').addEventListener('change', toggleBaiduApiFields);
        
        // 站点配置表单提交
        document.getElementById('siteConfigForm').addEventListener('submit', saveSiteConfig);
        
        // 切换到站点设置视图时加载配置
        document.getElementById('menu-site').addEventListener('click', function() {
            loadSiteConfig();
        });
        
        // 切换水印设置显示状态
        document.getElementById('enable_watermark').addEventListener('change', toggleWatermarkSettings);
        
        // 水印启用状态切换
        document.getElementById('enable_watermark').addEventListener('change', toggleWatermarkSettings);
        
        // 站点配置表单提交
        document.getElementById('siteConfigForm').addEventListener('submit', saveSiteConfig);
    });

    // 切换水印设置显示状态
    function toggleWatermarkSettings() {
        const enabled = document.getElementById('enable_watermark').checked;
        document.getElementById('watermark_settings').style.display = enabled ? 'block' : 'none';
    }

    // 加载IP封禁列表
    async function loadIpBanList() {
        try {
            const ipListElement = document.getElementById('ip-list');
            if (!ipListElement) {
                console.error('未找到IP列表容器');
                return;
            }
            
            ipListElement.innerHTML = '<tr><td colspan="5" class="text-center"><i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop"></i> 正在加载...</td></tr>';
            
            const response = await fetch('api/manage.php?action=get_ip_ban_list');
            const result = await response.json();
            
            if (result.success) {
                if (!result.data || result.data.length === 0) {
                    ipListElement.innerHTML = '<tr><td colspan="5" class="text-center">暂无封禁记录</td></tr>';
                    return;
                }
                
                ipListElement.innerHTML = '';
                result.data.forEach(item => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${item.ip || item.ip_address}</td>
                        <td>${item.reason || '-'}</td>
                        <td>${item.created_at}</td>
                        <td>自动解封</td>
                        <td>
                            <button class="layui-btn layui-btn-danger layui-btn-sm" onclick="removeIpBan(${item.id})">
                                解除封禁
                            </button>
                        </td>
                    `;
                    ipListElement.appendChild(tr);
                });
            } else {
                ipListElement.innerHTML = `<tr><td colspan="5" class="text-center">加载失败: ${result.message || '未知错误'}</td></tr>`;
            }
        } catch (error) {
            const ipListElement = document.getElementById('ip-list');
            ipListElement.innerHTML = `<tr><td colspan="5" class="text-center">加载失败: ${error.message || '未知错误'}</td></tr>`;
            console.error('加载IP封禁列表失败:', error);
        }
    }

    // 添加IP封禁
    async function addIpBan() {
        const form = document.getElementById('addIpBanForm');
        const formData = new FormData(form);
        const data = {
            ip: formData.get('ip'),
            reason: formData.get('reason')
        };
        
        try {
            const response = await fetch('api/manage.php?action=add_ip_ban', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            if (result.success) {
                showMessage('success', result.message);
                bootstrap.Modal.getInstance(document.getElementById('addIpBanModal')).hide();
                form.reset();
                loadIpBanList();
            } else {
                showMessage('error', result.message);
            }
        } catch (error) {
            showMessage('error', '操作失败');
        }
    }

    // 解除IP封禁
    async function removeIpBan(id) {
        if (!confirm('确定要解除此IP的封禁吗？')) {
            return;
        }
        
        try {
            const response = await fetch('api/manage.php?action=remove_ip_ban', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ id })
            });
            
            const result = await response.json();
            if (result.success) {
                showMessage('success', result.message);
                loadIpBanList();
            } else {
                showMessage('error', result.message);
            }
        } catch (error) {
            showMessage('error', '操作失败');
        }
    }

    // 页面加载完成后执行
    document.addEventListener('DOMContentLoaded', () => {
        loadIpBanList();
    });

    function toggleBaiduApiFields() {
        const enabled = document.getElementById('enable_baidu_api').checked;
        const settings = document.getElementById('baidu_api_settings');
        const keyInput = document.getElementById('baidu_api_key');
        const secretInput = document.getElementById('baidu_api_secret');
        
        settings.style.display = enabled ? 'block' : 'none';
        
        // 根据启用状态设置必填属性
        if (enabled) {
            keyInput.setAttribute('required', 'required');
            secretInput.setAttribute('required', 'required');
        } else {
            keyInput.removeAttribute('required');
            secretInput.removeAttribute('required');
            // 清空值
            keyInput.value = '';
            secretInput.value = '';
        }
    }

    // 别名函数，用于兼容性
    const toggleBaiduApiSettings = toggleBaiduApiFields;

    // 页面加载时初始化
    document.addEventListener('DOMContentLoaded', function() {
        toggleBaiduApiFields();
    });

    async function handleUserSubmit(event, formId, modalId) {
        event.preventDefault();
        
        const form = document.getElementById(formId);
        if (!form) {
            showMessage('表单不存在', 'error');
            return;
        }
        
        // 显示加载提示
        const loadingIndex = layer.load(1, {
            shade: [0.1, '#fff']
        });
        
        const formData = new FormData(form);
        const action = formData.get('action');
        
        try {
            // 发送到服务器
            const response = await fetch('api/user_manage.php', {
                method: 'POST',
                body: formData
            });
            
            // 检查HTTP状态码
            if (!response.ok) {
                throw new Error(`HTTP错误：${response.status}`);
            }
            
            const result = await response.json();
            
            // 关闭加载提示
            layer.close(loadingIndex);
            
            if (result.success) {
                showMessage(result.message || '操作成功', 'success');
                
                // 关闭模态框
                if (modalId) {
                    hideUserModal(modalId);
                }
                
                // 重新加载用户列表
                loadUsers();
                
                // 清空表单
                form.reset();
            } else {
                showMessage(result.message || '操作失败', 'error');
            }
        } catch (error) {
            // 关闭加载提示
            layer.close(loadingIndex);
            
            console.error('用户操作错误:', error);
            showMessage('操作失败: ' + error.message, 'error');
        }
    }
    
    // 重置用户密码
    async function resetUserPassword(userId) {
        if (!confirm('确定要重置该用户的密码吗？将被重置为默认密码: zxc123456')) return;
        
        try {
            const response = await fetch('api/user_manage.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=reset_password&user_id=${userId}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                showMessage('密码重置成功', 'success');
            } else {
                showMessage(result.message || '重置失败', 'error');
            }
        } catch (error) {
            console.error('密码重置错误:', error);
            showMessage('操作失败: ' + error.message, 'error');
        }
    }
    
    // 修改用户状态
    async function toggleUserStatus(userId, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
        const actionText = newStatus === 'active' ? '启用' : '禁用';
        
        if (!confirm(`确定要${actionText}该用户吗？`)) return;
        
        try {
            const response = await fetch('api/user_manage.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=toggle_status&user_id=${userId}&status=${newStatus}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                showMessage(`用户${actionText}成功`, 'success');
                loadUsers();
            } else {
                showMessage(result.message || '操作失败', 'error');
            }
        } catch (error) {
            console.error('切换用户状态错误:', error);
            showMessage('操作失败: ' + error.message, 'error');
        }
    }
    
    // 强制用户下线
    async function forceUserLogout(userId, username) {
        if (!confirm(`确定要强制用户 "${username}" 下线吗？`)) return;
        
        try {
            const response = await fetch('api/user_manage.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=force_logout&user_id=${userId}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                showMessage('用户已被强制下线', 'success');
                loadUsers();
            } else {
                showMessage(result.message || '操作失败', 'error');
            }
        } catch (error) {
            console.error('强制下线错误:', error);
            showMessage('操作失败: ' + error.message, 'error');
        }
    }

    // 加载数据看板数据
    async function loadDashboardData() {
        try {
            
            // 获取商品总数
            try {
                const productsResponse = await fetch('api/manage.php?action=list&pageSize=1');
                const productsData = await productsResponse.json();
                if (productsData.success) {
                    document.getElementById('total-products').textContent = productsData.total || 0;
                } else {
                    console.error('获取商品总数失败:', productsData.message);
                }
            } catch (error) {
                console.error('获取商品总数错误:', error);
            }
            
            // 获取用户总数和在线用户数
            try {
                const usersResponse = await fetch('api/manage.php?action=list_users');
                const usersData = await usersResponse.json();
                if (usersData.success) {
                    const totalUsers = usersData.total || 0;
                    document.getElementById('total-users').textContent = totalUsers;
                    
                    // 计算在线用户数
                    const onlineUsers = usersData.data.filter(user => user.login_status === 'online').length;
                    document.getElementById('online-users').textContent = onlineUsers;
                } else {
                    console.error('获取用户数据失败:', usersData.message);
                }
            } catch (error) {
                console.error('获取用户数据错误:', error);
            }
            
            // 获取系统信息
            try {
                const systemInfoResponse = await fetch('api/system_info.php');
                const systemInfoText = await systemInfoResponse.text();
                
                try {
                    const systemInfoData = JSON.parse(systemInfoText);
                    
                    if (systemInfoData.success) {
                        document.getElementById('php-version').textContent = systemInfoData.php_version || '未知';
                        document.getElementById('mysql-version').textContent = systemInfoData.mysql_version || '未知';
                        document.getElementById('server-os').textContent = systemInfoData.server_os || '未知';
                        document.getElementById('server-software').textContent = systemInfoData.server_software || '未知';
                        document.getElementById('last-update-time').textContent = systemInfoData.last_update || '未知';
                    } else {
                        console.error('获取系统信息失败:', systemInfoData.message);
                    }
                } catch (jsonError) {
                    console.error('系统信息JSON解析失败:', jsonError);
                    console.log('获取到的原始响应:', systemInfoText);
                }
            } catch (error) {
                console.error('获取系统信息请求错误:', error);
            }
            
        } catch (error) {
            console.error('加载数据看板数据失败:', error);
            showMessage('加载数据看板数据失败', 'error');
        }
    }

    // 初始显示商品视图
    showView('dashboard');
    
    // 注册菜单点击事件
    document.querySelectorAll('.nav-menu a').forEach(link => {
        link.addEventListener('click', function() {
            // 移除所有active类
            document.querySelectorAll('.nav-menu a').forEach(i => i.classList.remove('active'));
            // 添加active类到当前点击项
            this.classList.add('active');
            
            // 隐藏所有视图
            document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
            
            // 显示对应视图
            const viewId = this.getAttribute('data-view') + '-view';
            document.getElementById(viewId).classList.add('active');
            
            // 如果是日志视图，加载日志
            if (this.getAttribute('data-view') === 'logs') {
                loadLogs();
            }
        });
    });

    // 页面加载完成后激活"数据看板"菜单项
    document.addEventListener('DOMContentLoaded', function() {
        const dashboardLink = document.querySelector('.nav-menu a[data-view="dashboard"]');
        if (dashboardLink) {
            dashboardLink.click(); // 触发点击事件
        }
    });

    // 从表单获取规格信息
    function getSpecsFromForm() {
        const rows = document.querySelectorAll('#specs-container tr');
        const specs = [];
        
        rows.forEach(row => {
            const specName = row.querySelector('input[name="spec_name[]"]').value;
            const specValue = row.querySelector('input[name="spec_value[]"]').value;
            const specRemark = row.querySelector('input[name="spec_remark[]"]').value;
            
            // 如果规格名称不为空
            specs.push({
                spec_name: specName,
                spec_value: specValue,
                spec_remark: specRemark
            });
        });
        
        return specs;
    }

    // 将规格渲染到表单
    function renderSpecsToForm(specs) {
        // 清空现有规格
        const container = document.getElementById('specs-container');
        container.innerHTML = '';
        
        // 如果没有规格，添加一个空行
        if (!specs || specs.length === 0) {
            addSpecRow();
            return;
        }
        
        // 添加所有规格
        specs.forEach(spec => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><input type="text" name="spec_name[]" value="${spec.spec_name || ''}"></td>
                <td><input type="text" name="spec_value[]" value="${spec.spec_value || ''}"></td>
                <td><input type="text" name="spec_remark[]" value="${spec.spec_remark || ''}"></td>
                <td>
                    <button type="button" class="layui-btn layui-btn-danger layui-btn-sm" onclick="removeSpecRow(this)">
                        <i class="layui-icon layui-icon-delete"></i>
                    </button>
                </td>
            `;
            container.appendChild(row);
        });
    }

    // 添加规格行
    function addSpecRow() {
        const container = document.getElementById('specs-container');
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><input type="text" name="spec_name[]"></td>
            <td><input type="text" name="spec_value[]"></td>
            <td><input type="text" name="spec_remark[]"></td>
            <td>
                <button type="button" class="layui-btn layui-btn-danger layui-btn-sm" onclick="removeSpecRow(this)">
                    <i class="layui-icon layui-icon-delete"></i>
                </button>
            </td>
        `;
        container.appendChild(row);
    }

    // 删除规格行
    function removeSpecRow(button) {
        const row = button.closest('tr');
        row.parentNode.removeChild(row);
        
        // 确保至少有一行规格
        const container = document.getElementById('specs-container');
        if (container.children.length === 0) {
            addSpecRow();
        }
    }

    </script>
    
    <!-- 引入页脚信息JS -->
    <script src="js/guoke-footer.js"></script>
</body>
</html>