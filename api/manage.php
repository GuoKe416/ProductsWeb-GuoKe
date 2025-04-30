<?php
header('Content-Type: application/json');

require_once '../db_config.php';
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
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch($action) {
        case 'create':
            // 检查商品编码是否已存在
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE code = ?");
            $stmt->execute([$_POST['code']]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => '商品编码已存在']);
                break;
            }
            
            $stmt = $pdo->prepare("INSERT INTO products (code, image, info, link) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_POST['code'], $_POST['image'], $_POST['info'], $_POST['link']]);
            $productId = $pdo->lastInsertId();
            
            // 处理商品规格
            if (isset($_POST['specs'])) {
                $specs = json_decode($_POST['specs'], true);
                if (is_array($specs) && !empty($specs)) {
                    $specInsertStmt = $pdo->prepare("INSERT INTO product_specs (product_id, spec_name, spec_value, spec_remark) VALUES (?, ?, ?, ?)");
                    foreach ($specs as $spec) {
                        if (!empty($spec['spec_name']) || !empty($spec['spec_value'])) {
                            $specInsertStmt->execute([
                                $_POST['code'], // 使用商品编码
                                $spec['spec_name'],
                                $spec['spec_value'],
                                $spec['spec_remark']
                            ]);
                        }
                    }
                }
            }
            
            // 记录日志
            $logger->log('product_create', '添加商品', [
                'code' => $_POST['code'],
                'product_id' => $productId
            ]);
            
            echo json_encode(['success' => true, 'id' => $productId]);
            break;
            
        case 'update':
            // 获取原始商品编码
            $stmt = $pdo->prepare("SELECT code FROM products WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $originalCode = $stmt->fetchColumn();
            
            // 更新商品信息
            $stmt = $pdo->prepare("UPDATE products SET code=?, image=?, info=?, link=? WHERE id=?");
            $stmt->execute([$_POST['code'], $_POST['image'], $_POST['info'], $_POST['link'], $_POST['id']]);
            
            // 处理商品规格 - 先删除旧规格
            $deleteStmt = $pdo->prepare("DELETE FROM product_specs WHERE product_id = ?");
            $deleteStmt->execute([$originalCode]); // 使用原始商品编码
            
            // 添加新规格
            if (isset($_POST['specs'])) {
                $specs = json_decode($_POST['specs'], true);
                if (is_array($specs) && !empty($specs)) {
                    $specInsertStmt = $pdo->prepare("INSERT INTO product_specs (product_id, spec_name, spec_value, spec_remark) VALUES (?, ?, ?, ?)");
                    foreach ($specs as $spec) {
                        if (!empty($spec['spec_name']) || !empty($spec['spec_value'])) {
                            try {
                                $specInsertStmt->execute([
                                    $_POST['code'], // 使用新的商品编码
                                    $spec['spec_name'],
                                    $spec['spec_value'],
                                    $spec['spec_remark']
                                ]);
                            } catch (PDOException $e) {
                                // 记录错误但继续执行
                                error_log("Error saving spec: " . $e->getMessage());
                                continue;
                            }
                        }
                    }
                }
            }
            
            // 记录日志
            $logger->log('product_update', '更新商品', [
                'id' => $_POST['id'],
                'code' => $_POST['code'],
                'original_code' => $originalCode
            ]);
            
            echo json_encode(['success' => true]);
            break;
            
        case 'delete':
            // 先获取商品编码
            $stmt = $pdo->prepare("SELECT code FROM products WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $productCode = $stmt->fetchColumn();
            
            if (!$productCode) {
                echo json_encode(['success' => false, 'message' => '商品不存在']);
                break;
            }
            
            // 开始事务
            $pdo->beginTransaction();
            
            try {
                // 删除商品规格
                $deleteSpecsStmt = $pdo->prepare("DELETE FROM product_specs WHERE product_id = ?");
                $deleteSpecsStmt->execute([$productCode]);
                
                // 获取商品图片路径
                $stmt = $pdo->prepare("SELECT image FROM products WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // 删除商品
                $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                
                // 删除商品图片文件
                if (!empty($product['image'])) {
                    $imagePath = "./uploads/" . basename($product['image']);
                    
                    // 调用delete_file.php删除图片文件
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'http://'.$_SERVER['HTTP_HOST'].'/api/delete_file.php');
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, ['filename' => 'uploads/'.basename($product['image'])]);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($ch);
                    curl_close($ch);
                }
                
                // 记录日志
                $logger->log('product_delete', '删除商品', [
                    'id' => $_POST['id'],
                    'code' => $productCode
                ]);
                
                // 提交事务
                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                // 回滚事务
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => '删除失败: ' . $e->getMessage()]);
            }
            break;
            
        case 'get_specs':
            $productId = isset($_GET['product_id']) ? $_GET['product_id'] : '';
            
            if (empty($productId)) {
                echo json_encode(['success' => false, 'message' => '产品ID不能为空']);
                break;
            }
            
            // 获取商品规格
            $stmt = $pdo->prepare("SELECT * FROM product_specs WHERE product_id = ? ORDER BY id ASC");
            $stmt->execute([$productId]);
            $specs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $specs]);
            break;
            
        case 'get':
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            
            if (empty($id)) {
                echo json_encode(['success' => false, 'message' => '商品ID不能为空']);
                break;
            }
            
            // 获取商品信息
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                echo json_encode(['success' => false, 'message' => '商品不存在']);
                break;
            }
            
            // 获取商品规格
            $stmt = $pdo->prepare("SELECT * FROM product_specs WHERE product_id = ? ORDER BY id ASC");
            $stmt->execute([$product['code']]);
            $specs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 将规格添加到商品对象
            $product['specs'] = $specs;
            
            echo json_encode(['success' => true, 'product' => $product]);
            break;
            
        case 'list':
            $search = $_GET['search'] ?? '';
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $pageSize = isset($_GET['pageSize']) ? (int)$_GET['pageSize'] : 30;
            $offset = ($page - 1) * $pageSize;
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            
            $where = '';
            $params = [];
            if ($id) {
                $where = "WHERE id = :id";
                $params[':id'] = $id;
            } elseif ($search) {
                $where = "WHERE code LIKE :search";
                $params[':search'] = "%$search%";
            }
            
            // 获取总数
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products $where");
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->execute();
            $total = $stmt->fetchColumn();
            
            // 获取分页数据
            $sql = "SELECT * FROM products $where ORDER BY code LIMIT :offset, :limit";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $products,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize
            ]);
            break;
            
        case 'list_users':
            $username = isset($_GET['username']) ? trim($_GET['username']) : '';
            $role = isset($_GET['role']) ? trim($_GET['role']) : '';
            $permission = isset($_GET['permission']) ? trim($_GET['permission']) : '';
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';

            $query = "SELECT u.id, u.username, u.remark, u.role, u.download_report_permission, 
                            u.created_at, u.last_login, u.status, u.last_login_ip, u.login_status,
                            u.force_logout_time, a.username as forced_by_username
                     FROM users u 
                     LEFT JOIN users a ON u.force_logout_by = a.id 
                     WHERE 1=1";
            
            $params = []; // 初始化参数数组

            if ($username) {
                $query .= " AND u.username LIKE ?";
                $params[] = "%$username%";
            }
            if ($role) {
                $query .= " AND u.role = ?";
                $params[] = $role;
            }
            if ($permission !== '') {
                $query .= " AND u.download_report_permission = ?";
                $params[] = (int)$permission;
            }
            if ($status) {
                $query .= " AND u.status = ?";
                $params[] = $status;
            }

            $stmt = $pdo->prepare($query);
            
            // 绑定参数
            foreach ($params as $index => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($index + 1, $value, $type);
            }
            
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 处理登录状态显示
            foreach ($users as &$user) {
                switch ($user['login_status']) {
                    case 'online':
                        $user['login_status_text'] = '在线';
                        break;
                    case 'offline':
                        $user['login_status_text'] = '离线';
                        break;
                    case 'forced_offline':
                        $user['login_status_text'] = sprintf('被强制下线 (by %s at %s)', 
                            $user['forced_by_username'], 
                            date('Y-m-d H:i:s', strtotime($user['force_logout_time']))
                        );
                        break;
                    default:
                        $user['login_status_text'] = '未知';
                }
            }
            
            // 返回用户数据
            echo json_encode(['success' => true, 'data' => $users, 'total' => count($users)]);
            exit;
            
        case 'get_ip_ban_list':
            $stmt = $pdo->prepare("SELECT id, ip_address as ip, failures as reason, last_attempt as created_at FROM ip_log WHERE failures >= 5 ORDER BY last_attempt DESC");
            $stmt->execute();
            $ip_bans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $ip_bans]);
            break;
            
        case 'add_ip_ban':
            $data = getJsonPostData();
            if (!isset($data['ip']) || !filter_var($data['ip'], FILTER_VALIDATE_IP)) {
                echo json_encode(['success' => false, 'message' => 'IP地址无效']);
                return;
            }
            
            $stmt = $pdo->prepare("INSERT INTO ip_ban (ip, reason) VALUES (?, ?)");
            if ($stmt->execute([$data['ip'], $data['reason'] ?? ''])) {
                // 记录日志
                $logger->log('ip_ban_add', '手动添加IP封禁', [
                    'ip' => $data['ip'],
                    'reason' => $data['reason'] ?? ''
                ]);
                
                echo json_encode(['success' => true, 'message' => 'IP已封禁']);
            } else {
                echo json_encode(['success' => false, 'message' => '添加失败']);
            }
            break;
            
        case 'remove_ip_ban':
            $data = getJsonPostData();
            if (!isset($data['id']) || !is_numeric($data['id'])) {
                echo json_encode(['success' => false, 'message' => '参数无效']);
                return;
            }
            
            // 先获取IP地址信息，以便记录在日志中
            $stmt = $pdo->prepare("SELECT ip_address FROM ip_log WHERE id = ?");
            $stmt->execute([$data['id']]);
            $ip = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("DELETE FROM ip_log WHERE id = ?");
            if ($stmt->execute([$data['id']])) {
                // 记录日志
                $logger->log('ip_ban_remove', '解除IP封禁', [
                    'id' => $data['id'],
                    'ip' => $ip
                ]);
                
                echo json_encode(['success' => true, 'message' => 'IP已解封']);
            } else {
                echo json_encode(['success' => false, 'message' => '删除失败']);
            }
            break;
            
        case 'get_site_config':
            $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config");
            $stmt->execute();
            $configs = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $configs[$row['config_key']] = $row['config_value'];
            }
            echo json_encode(['success' => true, 'data' => $configs]);
            break;
            
        case 'update_site_config':
            $data = getJsonPostData();
            if (!is_array($data)) {
                echo json_encode(['success' => false, 'message' => '无效的数据格式']);
                break;
            }
            
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) 
                                     VALUES (?, ?) 
                                     ON DUPLICATE KEY UPDATE config_value = ?");
                                     
                foreach ($data as $key => $value) {
                    $stmt->execute([$key, $value, $value]);
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => '配置已更新']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => '更新失败：' . $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// 获取POST JSON数据
function getJsonPostData() {
    $json = file_get_contents('php://input');
    if (!$json) {
        return [];
    }
    return json_decode($json, true) ?? [];
}
?>