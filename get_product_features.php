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
    $productCodes = $input['productCodes'] ?? [];
    
    if (empty($productCodes)) {
        echo json_encode([]);
        exit;
    }
    
    // 创建占位符
    $placeholders = implode(',', array_fill(0, count($productCodes), '?'));
    
    // 准备查询
    $stmt = $conn->prepare("SELECT product_code, pixel_data FROM product_images WHERE product_code IN ($placeholders)");
    
    // 绑定参数
    $types = str_repeat('s', count($productCodes));
    $stmt->bind_param($types, ...$productCodes);
    
    // 执行查询
    $stmt->execute();
    $result = $stmt->get_result();
    
    $features = [];
    while ($row = $result->fetch_assoc()) {
        $features[] = $row;
    }
    
    echo json_encode($features);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();
?>