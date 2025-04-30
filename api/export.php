<?php
require_once '../db_config.php';
require_once '../auth.php';

session_start();

// 检查用户是否已登录，未登录则拒绝访问
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '未授权访问']);
    exit;
}

// 检查是否为管理员
try {
    $pdo = new PDO("mysql:host={$servername};dbname={$dbname}", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $role = $stmt->fetchColumn();
    
    if ($role !== 'admin') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '权限不足']);
        exit;
    }
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '数据库错误']);
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

$type = $_GET['type'] ?? '';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="export_' . date('Y-m-d') . '.csv"');

// 输出 UTF-8 BOM，以确保 Excel 正确显示中文
echo "\xEF\xBB\xBF";

try {
    switch($type) {
        case 'users':
            // 获取所有用户数据
            $stmt = $pdo->prepare("SELECT u.id, u.username, u.remark, u.role, 
                                        u.download_report_permission, u.created_at, 
                                        u.last_login, u.status, u.need_password_reset, 
                                        u.last_login_ip, u.login_status, 
                                        u.force_logout_time, u.session_expires_at, 
                                        a.username as forced_by_username
                                 FROM users u 
                                 LEFT JOIN users a ON u.force_logout_by = a.id 
                                 ORDER BY u.id");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 输出表头
            $headers = [
                '用户名', '备注', '角色', '下载权限', 
                '账号状态', '最后登录IP', '登录状态', '剩余有效期',
                '创建时间', '最后登录', '强制下线时间', '强制下线操作人'
            ];
            echo implode(',', $headers) . "\n";

            // 输出数据
            foreach ($users as $user) {
                // 处理登录状态显示
                $loginStatus = $user['login_status'];
                switch ($loginStatus) {
                    case 'online':
                        $loginStatus = '在线';
                        break;
                    case 'offline':
                        $loginStatus = '离线';
                        break;
                    case 'forced_offline':
                        $loginStatus = '被强制下线';
                        break;
                    default:
                        $loginStatus = '未知';
                }

                // 计算剩余有效期
                $sessionTimeLeft = '无会话';
                if ($user['login_status'] === 'online' && $user['last_login']) {
                    $lastLogin = new DateTime($user['last_login']);
                    $expiresAt = new DateTime($user['session_expires_at']);
                    $now = new DateTime();
                    if ($expiresAt > $now) {
                        $diff = $now->diff($expiresAt);
                        $sessionTimeLeft = $diff->days . '天' . $diff->h . '小时' . $diff->i . '分';
                    } else {
                        $sessionTimeLeft = '已过期';
                    }
                }

                $row = [
                    $user['username'],
                    $user['remark'],
                    $user['role'] === 'admin' ? '管理员' : '普通用户',
                    $user['download_report_permission'] == 1 ? '可下载' : '不可下载',
                    $user['status'] === 'active' ? '正常' : '禁用',
                    $user['last_login_ip'] ?: '-',
                    $loginStatus,
                    $sessionTimeLeft,
                    $user['created_at'],
                    $user['last_login'] ?: '从未登录',
                    $user['force_logout_time'] ?: '-',
                    $user['forced_by_username'] ?: '-'
                ];

                // 处理CSV特殊字符
                $row = array_map(function($field) {
                    $field = str_replace('"', '""', $field);
                    return '"' . $field . '"';
                }, $row);

                echo implode(',', $row) . "\n";
            }
            break;

        case 'products':
            // 获取当前域名
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            $domain = $protocol . $_SERVER['HTTP_HOST'];

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products");
            $stmt->execute();
            $count = $stmt->fetchColumn();

            if ($count == 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => '商品数据为空，无法导出']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM products ORDER BY code");
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 输出表头
            echo "商品编码,商品信息,图片链接,文件链接\n";

            // 输出数据
            foreach ($products as $product) {
                // 为图片和链接添加域名前缀
                $image = $product['image'] ? $domain . '/' . ltrim($product['image'], '/') : '';
                $link = $product['link'] ? $domain . '/' . ltrim($product['link'], '/') : '';
                
                $row = [
                    $product['code'],
                    $product['info'],
                    $image,
                    $link
                ];

                // 处理CSV特殊字符
                $row = array_map(function($field) {
                    $field = str_replace('"', '""', $field);
                    return '"' . $field . '"';
                }, $row);

                echo implode(',', $row) . "\n";
            }
            break;

        default:
            die('未知的导出类型');
    }
} catch (PDOException $e) {
    die('导出失败：' . $e->getMessage());
} 