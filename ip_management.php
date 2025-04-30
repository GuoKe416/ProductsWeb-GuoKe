<?php

// 检查是否已安装
require_once 'install_check.php';
checkInstallation();

session_start();
require_once 'db_config.php';
require_once 'auth.php';
require_once 'Logger.php';

// 初始化日志记录器
$logger = new Logger();

// 检查是否为管理员
if (!$auth->isAdmin()) {
    header("Location: index.php");
    exit;
}

// 处理解除封禁
if (isset($_POST['action']) && $_POST['action'] === 'unban') {
    $ip = $_POST['ip'];
    
    // 开启事务
    $conn->begin_transaction();
    
    try {
        // 从ip_ban表删除记录（如果存在）
        $stmt = $conn->prepare("DELETE FROM ip_ban WHERE ip_address = ?");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        
        // 重置ip_log表中的失败次数
        $stmt = $conn->prepare("UPDATE ip_log SET failures = 0, last_attempt = NOW() WHERE ip_address = ?");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        
        // 提交事务
        $conn->commit();
        
        // 记录日志
        $logger->log('ip_unban', '解除IP封禁', [
            'ip' => $ip,
            'method' => '管理界面操作'
        ]);
        
        header("Location: ip_management.php?message=解封成功");
        exit;
    } catch (Exception $e) {
        // 回滚事务
        $conn->rollback();
        header("Location: ip_management.php?message=解封失败: " . $e->getMessage());
        exit;
    }
}

// 处理追加封禁时间
if (isset($_POST['action']) && $_POST['action'] === 'extend') {
    $ip = $_POST['ip'];
    $hours = (int)$_POST['hours'];
    
    $stmt = $conn->prepare("UPDATE ip_log SET last_attempt = DATE_SUB(NOW(), INTERVAL ? HOUR) WHERE ip_address = ?");
    $stmt->bind_param("is", $hours, $ip);
    $result = $stmt->execute();
    
    if ($result) {
        // 记录日志
        $logger->log('ip_ban_extend', '延长IP封禁时间', [
            'ip' => $ip,
            'hours' => $hours
        ]);
    }
    
    header("Location: ip_management.php?message=封禁时间已更新");
    exit;
}

// 获取所有被封禁的IP
$query = "SELECT ip_address, failures, last_attempt FROM ip_log WHERE failures >= 5 ORDER BY last_attempt DESC";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>IP封禁管理</title>
    <link href="css/googleapis.css" rel="stylesheet">
    <link href="./layui/css/layui.css" rel="stylesheet">
    <style>
        body {
            padding: 20px;
            background: #f5f7fa;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #333;
        }
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .extend-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .extend-form input {
            width: 80px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>IP封禁管理</h1>
            <a href="manage.php" class="layui-btn layui-btn-primary">返回管理后台</a>
        </div>
        
        <?php if (isset($_GET['message'])): ?>
        <div class="success-message">
            <?php echo htmlspecialchars($_GET['message']); ?>
        </div>
        <?php endif; ?>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>IP地址</th>
                        <th>失败次数</th>
                        <th>最后尝试时间</th>
                        <th>剩余封禁时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): 
                            $blockTimeRemaining = strtotime($row['last_attempt']) + 24 * 60 * 60 - time();
                            $hours = floor($blockTimeRemaining / 3600);
                            $minutes = floor(($blockTimeRemaining % 3600) / 60);
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['ip_address']); ?></td>
                            <td><?php echo htmlspecialchars($row['failures']); ?></td>
                            <td><?php echo htmlspecialchars($row['last_attempt']); ?></td>
                            <td>
                                <?php if ($blockTimeRemaining > 0): ?>
                                    <?php echo $hours; ?>小时 <?php echo $minutes; ?>分钟
                                <?php else: ?>
                                    已过期
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="unban">
                                    <input type="hidden" name="ip" value="<?php echo htmlspecialchars($row['ip_address']); ?>">
                                    <button type="submit" class="layui-btn layui-btn-danger layui-btn-sm">解除封禁</button>
                                </form>
                                
                                <form method="post" class="extend-form">
                                    <input type="hidden" name="action" value="extend">
                                    <input type="hidden" name="ip" value="<?php echo htmlspecialchars($row['ip_address']); ?>">
                                    <input type="number" name="hours" class="layui-input" placeholder="小时" min="1" required>
                                    <button type="submit" class="layui-btn layui-btn-sm">追加时间</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="no-data">暂无封禁记录</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html> 