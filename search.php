<?php
// 检查是否启用百度API
$stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'enable_baidu_api'");
$stmt->execute();
$enableBaiduApi = $stmt->fetchColumn();

if ($enableBaiduApi !== '1') {
    // 使用普通搜索
    $stmt = $pdo->prepare("SELECT * FROM products WHERE code LIKE ? OR info LIKE ? ORDER BY code LIMIT 10");
    $searchTerm = "%{$_GET['q']}%";
    $stmt->execute([$searchTerm, $searchTerm]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $results, 'method' => 'normal']);
    exit;
}

