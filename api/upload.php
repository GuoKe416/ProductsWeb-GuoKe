<?php
header('Content-Type: application/json');

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

// 检查是否存在uploads目录，如果不存在则创建
$uploadsDir = '../uploads';
if (!file_exists($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

try {
    // 获取POST数据
    $imageData = $_POST['image'] ?? '';
    $code = $_POST['code'] ?? '';
    
    if (empty($imageData) || empty($code)) {
        throw new Exception('缺少必要参数');
    }
    
    // 验证并清理商品编码（只允许字母、数字、下划线和中划线）
    if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_\-（）\(\)]+$/u', $code)) {
        throw new Exception('商品编码格式无效');
    }
    
    // 从Base64数据中提取图片类型和数据
    if (preg_match('/^data:image\/(jpeg|png|gif|jpg);base64,/', $imageData, $matches)) {
        $imageType = $matches[1];
        $imageData = str_replace('data:image/' . $imageType . ';base64,', '', $imageData);
        $imageData = str_replace(' ', '+', $imageData);
        $imageData = base64_decode($imageData);
        
        if ($imageData === false) {
            throw new Exception('图片数据解码失败');
        }
    } else {
        throw new Exception('无效的图片格式');
    }
    
    // 生成文件名（使用商品编码）
    $filename = $code . '.' . $imageType;
    $filepath = $uploadsDir . '/' . $filename;
    
    // 确保uploads目录存在
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }
    
    // 如果文件已存在，先删除
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    
    // 保存图片
    if (file_put_contents($filepath, $imageData) === false) {
        throw new Exception('保存图片失败');
    }
    
    // 设置文件权限
    chmod($filepath, 0644);
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'filename' => '/uploads/' . $filename // 返回绝对路径
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>