<?php
header('Content-Type: application/json');

require_once '../db_config.php';
require_once '../auth.php';
require_once '../Logger.php';

// 初始化日志记录器
$logger = new Logger();

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

    // 检查是否已登录
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }

    // 检查是否是管理员
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => '无权限']);
        exit;
    }

    $action = $_GET['action'] ?? '';

    switch($action) {
        case 'list':
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            
            $query = "SELECT * FROM ip_ban WHERE 1=1";
            $params = [];
            
            if ($search) {
                $query .= " AND ip_address LIKE ?";
                $params[] = "%$search%";
            }
            
            $query .= " ORDER BY created_at DESC";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $ip_bans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $ip_bans]);
            break;
            
        case 'unban':
            $ip = $_POST['ip'] ?? '';
            if (empty($ip)) {
                echo json_encode(['success' => false, 'message' => 'IP地址不能为空']);
                break;
            }
            
            // 开始事务确保两个表的数据一致性
            $pdo->beginTransaction();
            
            try {
                // 从ip_ban表删除记录
                $stmt = $pdo->prepare("DELETE FROM ip_ban WHERE ip_address = ?");
                $stmt->execute([$ip]);
                
                // 从ip_log表重置失败次数和最后尝试时间
                $stmt = $pdo->prepare("UPDATE ip_log SET failures = 0, last_attempt = NOW() WHERE ip_address = ?");
                $stmt->execute([$ip]);
                
                // 提交事务
                $pdo->commit();
                
                // 记录日志
                $logger->log('ip_unban', '解除IP封禁', [
                    'ip' => $ip,
                    'method' => 'API调用'
                ]);
                
                echo json_encode(['success' => true, 'message' => 'IP已解封']);
            } catch (Exception $e) {
                // 回滚事务
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => '解封失败: ' . $e->getMessage()]);
            }
            break;
            
        case 'extend':
            $ip = $_POST['ip'] ?? '';
            $hours = intval($_POST['hours'] ?? 0);
            
            if (empty($ip) || $hours <= 0) {
                echo json_encode(['success' => false, 'message' => '参数无效']);
                break;
            }
            
            $stmt = $pdo->prepare("UPDATE ip_ban SET last_attempt = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE ip_address = ?");
            if ($stmt->execute([$hours, $ip])) {
                // 记录日志
                $logger->log('ip_ban_extend', '延长IP封禁时间', [
                    'ip' => $ip,
                    'hours' => $hours
                ]);
                
                echo json_encode(['success' => true, 'message' => '封禁时间已更新']);
            } else {
                echo json_encode(['success' => false, 'message' => '更新失败']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '数据库错误：' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '系统错误：' . $e->getMessage()]);
} 