<?php
include 'db_config.php';

header('Content-Type: application/json');

try {
    // 首先确保product_images表存在
    $tableExistsQuery = "SHOW TABLES LIKE 'product_images'";
    $result = $conn->query($tableExistsQuery);
    
    if ($result->num_rows == 0) {
        // 表不存在，创建表
        $createTableQuery = "
            CREATE TABLE IF NOT EXISTS `product_images` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `product_code` varchar(50) NOT NULL,
              `image_url` text NOT NULL,
              `image_hash` varchar(32) NOT NULL,
              `pixel_data` longtext,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `product_code` (`product_code`),
              CONSTRAINT `product_images_ibfk_1` FOREIGN KEY (`product_code`) REFERENCES `products` (`code`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $conn->query($createTableQuery);
    }

    // 获取请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    $productCode = $input['product_code'] ?? '';
    $imageUrl = $input['image_url'] ?? '';
    $pixelData = $input['pixel_data'] ?? [];
    
    if (empty($productCode) || empty($imageUrl)) {
        throw new Exception('Missing required parameters');
    }
    
    // 计算图片哈希
    $imageHash = md5($imageUrl);
    
    // 准备插入或更新语句
    $stmt = $conn->prepare("
        INSERT INTO product_images (product_code, image_url, image_hash, pixel_data) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            image_url = VALUES(image_url),
            image_hash = VALUES(image_hash),
            pixel_data = VALUES(pixel_data),
            updated_at = CURRENT_TIMESTAMP
    ");
    
    // 将像素数据转换为JSON字符串
    $pixelDataJson = json_encode($pixelData);
    
    $stmt->bind_param('ssss', $productCode, $imageUrl, $imageHash, $pixelDataJson);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();
?>