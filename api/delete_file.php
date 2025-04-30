<?php
header('Content-Type: application/json');

try {
    if (!isset($_POST['filename'])) {
        throw new Exception('缺少文件名参数');
    }

    $filename = $_POST['filename'];
    // 确保文件路径在uploads目录下
    if (strpos($filename, 'uploads/') !== 0) {
        throw new Exception('无效的文件路径');
    }

    $filepath = '../' . $filename;
    if (file_exists($filepath)) {
        if (unlink($filepath)) {
            echo json_encode(['success' => true]);
        } else {
            throw new Exception('删除文件失败');
        }
    } else {
        echo json_encode(['success' => true]); // 文件不存在也返回成功
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>