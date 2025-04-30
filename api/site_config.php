<?php
session_start();
require_once '../db_config.php';
require_once '../auth.php';

header('Content-Type: application/json');

// 如果指定了key参数，允许直接获取单个配置项
if (isset($_GET['key'])) {
    $key = $_GET['key'];
    $stmt = $conn->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'value' => $row['config_value']]);
    } else {
        echo json_encode(['success' => false, 'message' => '配置项不存在']);
    }
    exit;
}

// 检查是否为管理员
if (!$auth->isAdmin()) {
    echo json_encode(['success' => false, 'message' => '没有权限执行此操作']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get':
        // 获取所有配置
        $stmt = $conn->prepare("SELECT config_key, config_value, config_description FROM system_config");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $configs = [];
        while ($row = $result->fetch_assoc()) {
            $configs[$row['config_key']] = [
                'value' => $row['config_value'],
                'description' => $row['config_description']
            ];
        }
        
        echo json_encode(['success' => true, 'data' => $configs]);
        break;
        
    case 'update':
        // 更新配置
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            echo json_encode(['success' => false, 'message' => '无效的请求数据']);
            exit;
        }
        
        try {
            foreach ($data as $key => $value) {
                $stmt = $conn->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
                $stmt->bind_param("ss", $value, $key);
                if (!$stmt->execute()) {
                    throw new Exception("更新配置 $key 失败");
                }
            }
            
            echo json_encode(['success' => true, 'message' => '配置已更新']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '未知操作']);
} 