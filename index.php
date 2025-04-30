<?php
session_start();

// 检查是否已安装
require_once 'install_check.php';
checkInstallation();

// 引入数据库配置文件
require_once 'db_config.php';
require_once 'auth.php';

// 创建PDO连接
try {
    $pdo = new PDO("mysql:host={$servername};dbname={$dbname}", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("连接失败: " . $e->getMessage());
}

// 创建Auth实例
$auth = new Auth($conn);

$counterFile = 'counter.json'; // 访问次数存储文件

// 如果计数器文件不存在，则初始化它
if (!file_exists($counterFile)) {
    file_put_contents($counterFile, json_encode(['count' => 0]));
}

// 读取当前计数
$counterData = json_decode(file_get_contents($counterFile), true);
$currentCount = $counterData['count'] + 1;

// 更新计数
$counterData['count'] = $currentCount;
file_put_contents($counterFile, json_encode($counterData));

/**
 * 更新或添加IP的失败尝试记录到数据库
 */
function updateIpFailureLog($conn, $ip) {
    $stmt = $conn->prepare("INSERT INTO ip_log (ip_address, failures) VALUES (?, 1) ON DUPLICATE KEY UPDATE failures=failures+1, last_attempt=NOW()");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
}

/**
 * 获取指定IP的失败尝试次数
 */
function getIpFailures($conn, $ip) {
    $stmt = $conn->prepare("SELECT failures FROM ip_log WHERE ip_address=?");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['failures'];
    }
    return 0;
}

/**
 * 检查IP是否被封禁
 */
function isIpBlocked($conn, $ip, &$reason, &$blockTimeRemaining) {
    $stmt = $conn->prepare("SELECT failures, last_attempt FROM ip_log WHERE ip_address=?");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // 计算封禁剩余时间
        $blockTimeRemaining = strtotime($row['last_attempt']) + 24 * 60 * 60 - time();
        
        // 只有当失败次数达到或超过5次，并且还在封禁期内时才返回true
        if ($row['failures'] >= 5 && $blockTimeRemaining > 0) {
            $hours = floor($blockTimeRemaining / 3600);
            $minutes = floor(($blockTimeRemaining % 3600) / 60);
            $seconds = $blockTimeRemaining % 60;
            
            $reason = "您的IP（{$ip}）已被封禁，请等待" . sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds) . "后重新访问，或者联系管理员解除封禁";
            return true;
        } else if ($blockTimeRemaining <= 0) {
            // 如果封禁期已过，则删除记录
            $deleteStmt = $conn->prepare("DELETE FROM ip_log WHERE ip_address=?");
            $deleteStmt->bind_param("s", $ip);
            $deleteStmt->execute();
        }
    }
    return false;
}

$visitorIp = $_SERVER['REMOTE_ADDR'];
$blockReason = '';
$blockTimeRemaining = 0; // 初始化变量，避免未定义

if (isIpBlocked($conn, $visitorIp, $blockReason, $blockTimeRemaining)) {
    echo '<link href="./icon.css" rel="stylesheet">';
    echo <<<HTML
    <div id="block-container">
        <div class="block-card">
            <i class="material-icons icon-warning">warning</i>
            <div class="block-content">
                <h2>访问受限</h2>
                <div id="block-message">{$blockReason}</div>
                <div class="countdown-box">
                    <span class="material-icons">timer</span>
                    <span id="countdown-timer">00:00:00</span>
                </div>
                <div class="contact-info">
                    <i class="material-icons"></i>
                    <span>请联系管理员解除封禁</span>
                </div>
            </div>
        </div>
    </div>
    <style>
        #block-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: #f5f7fa;
            padding: 20px;
        }

        .block-card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            max-width: 600px;
            width: 100%;
            text-align: center;
            animation: fadeInUp 0.6s ease-out;
        }

        .icon-warning {
            color: #ff5252;
            font-size: 64px;
            margin-bottom: 20px;
        }

        .block-content h2 {
            color: #2d3748;
            margin-bottom: 20px;
            font-size: 1.8rem;
        }

        #block-message {
            color: #4a5568;
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .countdown-box {
            background: #fff5f5;
            border-radius: 8px;
            padding: 16px;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
        }

        .countdown-box span:first-child {
            color: #ff5252;
        }

        #countdown-timer {
            font-family: monospace;
            font-size: 1.4rem;
            color: #2d3748;
            font-weight: 600;
        }

        .contact-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #4a5568;
            font-size: 1rem;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
    <script>
        let endTime = Date.now() + {$blockTimeRemaining} * 1000;
        
        function updateTimer() {
            const remaining = endTime - Date.now();
            if(remaining <= 0) location.reload();
            
            const hours = Math.floor(remaining / 3600000);
            const minutes = Math.floor((remaining % 3600000) / 60000);
            const seconds = Math.floor((remaining % 60000) / 1000);
            
            // 使用函数声明替代箭头函数
            function format(num) {
                return String(Math.floor(num)).padStart(2, '0');
            }
            
            // 使用字符串拼接替代模板字符串
            document.getElementById('countdown-timer').textContent = 
                format(hours) + ":" + format(minutes) + ":" + format(seconds);
            
            document.getElementById('block-message').innerHTML = 
                '检测到异常访问行为（IP：{$visitorIp}）<br>' +
                '<strong>如需解封请将上述 IP 发给管理员解除</strong>';
            
            document.getElementById('countdown-timer').textContent = 
                '或等待 '+format(hours) + ":" + format(minutes) + ":" + format(seconds)+' 后自动解封';
        }

        setInterval(updateTimer, 1000);
        updateTimer();
    </script>
HTML;
    exit;
}

// 检查用户是否登录
if (!$auth->isLoggedIn()) {
    header("Location: login.php");
    exit;
}

// 检查 session_id 是否匹配
$stmt = $conn->prepare("SELECT users.session_id FROM users WHERE users.id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($currentSessionId);
$stmt->fetch();
$stmt->close();

if ($currentSessionId !== session_id()) {
    // 如果 session_id 不匹配，强制用户退出
    $auth->logout();
    $_SESSION['login_message'] = "您的账号已在其他设备登录，当前设备已被强制退出。";
    header("Location: login.php");
    exit;
}

// 检查用户是否需要登录
$requireLogin = true; // 默认需要登录
// 此处可以添加一些例外的页面或API路径

// 强制要求登录
if ($requireLogin && !$auth->isLoggedIn()) {
    header("Location: login.php");
    exit;
}

// 获取当前用户信息
$currentUser = $auth->getCurrentUser();


// 获取用户的下载权限，1表示有权限，0表示无权限
$hasDownloadPermission = isset($currentUser['download_report_permission']) ? (int)$currentUser['download_report_permission'] : 0;

// 添加水印
function addWatermark() {
    global $pdo;
    
    // 检查是否启用水印
    $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'enable_watermark'");
    $stmt->execute();
    $enableWatermark = $stmt->fetchColumn();
    
    if ($enableWatermark !== '1') {
        return;
    }
    
    $ip = $_SERVER['REMOTE_ADDR'];
    $timestamp = date('Y-m-d H:i:s');
    $watermark = "IP: {$ip}\n时间: {$timestamp}";
    
    echo "<div class='watermark'>{$watermark}</div>";
    echo "<style>
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 24px;
            color: rgba(0, 0, 0, 0.1);
            pointer-events: none;
            user-select: none;
            white-space: pre;
            z-index: 9999;
        }
    </style>";
}

// 获取备案号
$stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'icp_number'");
$stmt->execute();
$icpNumber = $stmt->fetchColumn();

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(getConfig('site_title', '商品文件库 - GuoKe')); ?></title>
    <link href="css/googleapis.css" rel="stylesheet">
    <link href="css/styles.css" rel="stylesheet">
    <meta name="baidu_union_verify" content="fc3a7b230fc617dfc667acb882a3117c">
    <link href="./layui/css/layui.css" rel="stylesheet">
    <script src="./layui/layui.js"></script>
    <style>
    .user-controls {
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 1000;
    }
    
    .user-info-trigger {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: #fff;
        border-radius: 8px;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
    }
    
    .user-info-trigger:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #1E9FFF;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: bold;
    }
    
    .user-brief {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
    }
    
    .user-name {
        font-weight: 500;
        color: #333;
        font-size: 14px;
    }
    
    .user-role {
        color: #666;
        font-size: 12px;
    }
    
    .dropdown-arrow {
        margin-left: 4px;
        transition: transform 0.3s;
    }
    
    .dropdown-menu {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        padding: 8px 0;
        min-width: 180px;
        display: none;
        animation: slideDown 0.3s ease;
    }
    
    .dropdown-menu.show {
        display: block;
    }
    
    .menu-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        color: #333;
        text-decoration: none;
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .menu-item:hover {
        background: #f5f5f5;
    }
    
    .menu-item i {
        font-size: 16px;
        width: 20px;
        text-align: center;
    }
    
    .menu-divider {
        height: 1px;
        background: #eee;
        margin: 8px 0;
    }
    
    .badge-permission {
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 12px;
    }
    
    .badge-permission.has-permission {
        background: #52c41a;
        color: white;
    }
    
    .badge-permission.no-permission {
        background: #ff4d4f;
        color: white;
    }
    
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @media (max-width: 768px) {
        .user-controls {
            position: static;
            margin: 10px 15px;
        }
        
        .dropdown-menu {
            position: fixed;
            top: auto;
            bottom: 0;
            left: 0;
            right: 0;
            width: 100%;
            border-radius: 16px 16px 0 0;
            padding: 16px 0;
            animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(100%);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    }

    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s, visibility 0.3s;
    }

    .modal.show {
        opacity: 1;
        visibility: visible;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .modal-content {
        position: relative;
        background-color: #fff;
        width: 90%;
        max-width: 420px;
        border-radius: 16px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        transform: translateY(-20px);
        transition: transform 0.3s;
    }

    .modal.show .modal-content {
        transform: translateY(0);
    }

    .modal-header {
        padding: 20px 24px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-header h2 {
        margin: 0;
        font-size: 20px;
        color: #333;
        font-weight: 600;
    }

    .close-btn {
        font-size: 28px;
        color: #999;
        cursor: pointer;
        transition: color 0.3s;
        line-height: 1;
    }

    .close-btn:hover {
        color: #333;
    }

    #changePasswordForm {
        padding: 24px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
        color: #666;
        font-size: 14px;
    }

    .form-group label i {
        color: #1E9FFF;
        font-size: 16px;
    }

    .form-group input {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
        box-sizing: border-box;
    }

    .form-group input:focus {
        border-color: #1E9FFF;
        box-shadow: 0 0 0 3px rgba(30, 159, 255, 0.1);
        outline: none;
    }

    .form-buttons {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        margin-top: 24px;
    }

    .btn {
        padding: 10px 24px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-primary {
        background: #1E9FFF;
        color: white;
    }

    .btn-primary:hover {
        background: #0e90fe;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(30, 159, 255, 0.2);
    }

    .btn-cancel {
        background: #f5f5f5;
        color: #666;
    }

    .btn-cancel:hover {
        background: #e8e8e8;
        transform: translateY(-1px);
    }

    @media (max-width: 768px) {
        .modal-content {
            width: 95%;
            margin: 20px;
        }

        .form-buttons {
            flex-direction: column-reverse;
        }

        .btn {
            width: 100%;
            padding: 12px;
        }
    }

    .login-expiry {
        color: #666;
        font-size: 14px;
    }

    #login-countdown {
        color: #1E9FFF;
        font-weight: 500;
    }
    </style>
</head>
<body>
   <div class="header">
        <div class="header-content">
            
            
            <h1><?php echo htmlspecialchars(getConfig('site_h1', '商品文件库 - GuoKe')); ?></h1>
            <div class="stats-bar">
                <div class="total-count">已登记数量：<span id="total-products">0</span> 个</div>
                <div class="tips">
                    <span class="tip-icon">💡</span>
                    <span class="tip-text"><?php echo htmlspecialchars(getConfig('tip_text', '点击商品可查看详细信息和文件 | 图片上右键可选择复制图像')); ?></span>
                    <strong>
                        <span class="tip-icon">⭐</span>
                        <span class="tip-text">
                            使用量：<span id="visit-count"><?php echo htmlspecialchars($currentCount); ?></span> 次
                        </span>
                    </strong>
                    <!-- 用户信息和登录状态 -->
            <div class="user-controls">
                <div class="user-info-trigger" onclick="toggleDropdown()">
                    <div class="user-avatar">
                        <?php 
                            $username = $currentUser['username'];
                            $firstChar = mb_substr($username, 0, 1, 'UTF-8');
                            echo htmlspecialchars($firstChar, ENT_QUOTES, 'UTF-8');
                        ?>
                    </div>
                    <div class="user-brief">
                        <span class="user-name"><?php echo htmlspecialchars($currentUser['username']); ?></span>
                        <span class="user-role"><?php echo $currentUser['role'] == 'admin' ? '管理员' : '用户'; ?></span>
                    </div>
                    <i class="dropdown-arrow">▼</i>
                </div>
                <div class="dropdown-menu">
                    <div class="menu-item">
                        <i>📥</i>
                        <?php if ($hasDownloadPermission): ?>
                            <span class="badge-permission has-permission">文件：可下载</span>
                        <?php else: ?>
                            <span class="badge-permission no-permission">文件：无权限</span>
                        <?php endif; ?>
                    </div>
                    <div class="menu-item">
                        <i>⏱️</i>
                        <span class="login-expiry">登录有效期：<span id="login-countdown"></span></span>
                    </div>
                    <div class="menu-divider"></div>
                    <?php if ($auth->isAdmin()): ?>
                        <a href="manage.php" class="menu-item">
                            <i>⚙️</i>
                            <span>管理后台</span>
                        </a>
                    <?php endif; ?>
                    <div class="menu-item" onclick="showChangePasswordModal()">
                        <i>🔑</i>
                        <span>修改密码</span>
                    </div>
                    <div class="menu-divider"></div>
                    <a href="logout.php" class="menu-item">
                        <i>🚪</i>
                        <span>退出登录</span>
                    </a>
                </div>
            </div>
                </div>
            </div>
        
            <div class="controls">
                <input type="text" class="search-box" placeholder="① 输入商品编码  ②点击输入框粘贴图片搜索 （支持实物图）【将返回6个相似度最高的图片】">
                
                <select class="page-size">
                    <option value="30">30条/页</option>
                    <option value="60">60条/页</option>
                    <option value="120">120条/页</option>
                </select>
            </div>
            
            <div class="pagination"></div>
        </div>
    </div>
    <div class="container">
        <div class="left-panel">
            <div class="products-grid"></div>
        </div>
        <div class="right-panel">
            <div class="product-info"></div>
        </div>
    </div>

    <!-- 修改密码模态框 -->
    <div id="changePasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>修改密码</h2>
                <span class="close-btn" onclick="hideChangePasswordModal()">&times;</span>
            </div>
            <form id="changePasswordForm" method="post" action="change_password.php">
                <div class="form-group">
                    <label for="current_password">
                        <i class="layui-icon layui-icon-password"></i>
                        当前密码
                    </label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label for="new_password">
                        <i class="layui-icon layui-icon-key"></i>
                        新密码
                    </label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">
                        <i class="layui-icon layui-icon-ok-circle"></i>
                        确认新密码
                    </label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <div class="form-buttons">
                    <button type="button" class="btn btn-cancel" onclick="hideChangePasswordModal()">取消</button>
                    <button type="submit" class="btn btn-primary">确认修改</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 会话过期模态框，不可关闭或删除 -->
    <div id="session-expired-modal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.8);">
        <div style="background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 400px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
            <h2 id="session-expired-title" style="margin-top: 0; color: #e53e3e;">会话已过期</h2>
            <div id="session-expired-message" style="margin-bottom: 20px; font-size: 16px; white-space: pre-wrap;"></div>
            <p>您需要重新登录才能继续操作。</p>
            <div style="text-align: center; margin-top: 20px;">
                <button id="session-login-button" style="padding: 8px 16px; background-color: #1E9FFF; color: white; border: none; border-radius: 4px; cursor: pointer;">重新登录</button>
            </div>
        </div>
    </div>

    <!-- 将用户下载权限传递给JS -->
    <script>
        var userHasDownloadPermission = <?php echo $hasDownloadPermission; ?>;
    </script>
    
    <script src="js/script.js"> </script>
    <script src="js/image-search.js"></script>
    <script>
    function showChangePasswordModal() {
        const modal = document.getElementById('changePasswordModal');
        if (modal) {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    function hideChangePasswordModal() {
        const modal = document.getElementById('changePasswordModal');
        if (modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    // 点击模态框外部关闭
    window.onmousedown = function(event) {
        const modal = document.getElementById('changePasswordModal');
        if (event.target === modal) {
            hideChangePasswordModal();
        }
    }

    // 表单提交前验证
    document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        
        if (newPassword !== confirmPassword) {
            e.preventDefault();
            alert('新密码和确认密码不匹配！');
        }
    });

    // 添加登录倒计时功能
    function updateLoginCountdown() {
        const expiryTime = <?php echo isset($_SESSION['expires_time']) ? $_SESSION['expires_time'] * 1000 : 0; ?>;
        const now = Date.now();
        const remaining = expiryTime - now;
        
        if (remaining <= 0) {
            location.href = 'logout.php';
            return;
        }
        
        const days = Math.floor(remaining / (1000 * 60 * 60 * 24));
        const hours = Math.floor((remaining % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((remaining % (1000 * 60 * 60)) / (1000 * 60));
        
        document.getElementById('login-countdown').textContent = 
            `${days}天${hours}小时${minutes}分钟`;
    }

    // 每分钟更新一次倒计时
    updateLoginCountdown();
    setInterval(updateLoginCountdown, 60000);

    // 会话过期处理
    document.getElementById('session-login-button').addEventListener('click', function() {
        window.location.href = 'login.php';
    });

    // 拦截控制台删除元素或修改样式的操作
    const sessionExpiredModal = document.getElementById('session-expired-modal');
    const originalDisplay = sessionExpiredModal.style.display;

    // 使模态框不可被隐藏
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                const currentDisplay = sessionExpiredModal.style.display;
                if (sessionExpiredModal.dataset.expired === 'true' && currentDisplay === 'none') {
                    sessionExpiredModal.style.display = originalDisplay;
                }
            }
        });
    });

    observer.observe(sessionExpiredModal, { attributes: true });

    // 检查会话状态的函数
    function checkSession() {
        fetch('check_session.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON:', text);
                        throw new Error('服务器返回无效的JSON格式');
                    }
                });
            })
            .then(data => {
                if (data.session_expired) {
                    const modal = document.getElementById('session-expired-modal');
                    const title = document.getElementById('session-expired-title');
                    const message = document.getElementById('session-expired-message');
                    
                    // 根据不同的退出原因显示不同的标题和内容
                    if (data.reason === 'forced_logout') {
                        title.textContent = '账号已被强制下线';
                        title.style.color = '#e53e3e'; // 红色
                    } else if (data.reason === 'other_device') {
                        title.textContent = '账号在其他设备登录';
                        title.style.color = '#dd6b20'; // 橙色
                    } else {
                        title.textContent = '会话已过期';
                        title.style.color = '#718096'; // 灰色
                    }
                    
                    message.textContent = data.message;
                    modal.style.display = 'block';
                    modal.dataset.expired = 'true';
                    
                    // 禁用页面交互
                    document.body.style.overflow = 'hidden';
                    
                    // 防止用户通过浏览器开发工具删除模态框
                    setInterval(function() {
                        if (!document.body.contains(modal)) {
                            window.location.href = 'login.php';
                        }
                    }, 500);
                }
            })
            .catch(error => {
                console.error('Error checking session:', error);
                // 在页面上显示一个提示
                const errorDiv = document.createElement('div');
                errorDiv.style.position = 'fixed';
                errorDiv.style.bottom = '20px';
                errorDiv.style.right = '20px';
                errorDiv.style.backgroundColor = '#fff1f0';
                errorDiv.style.color = '#a8071a';
                errorDiv.style.padding = '10px';
                errorDiv.style.borderRadius = '4px';
                errorDiv.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
                errorDiv.style.zIndex = '9999';
                errorDiv.textContent = '会话检查失败: ' + error.message;
                document.body.appendChild(errorDiv);
                
                // 5秒后移除错误提示
                setTimeout(() => errorDiv.remove(), 5000);
            });
    }

    // 每10秒检查一次会话状态
    setInterval(checkSession, 10000);

    // 页面加载时也检查一次
    document.addEventListener('DOMContentLoaded', checkSession);
    </script>
    <style>
        .footer {
            width: 100%;
            padding: 20px 0;
            background-color: #f5f5f5;
            text-align: center;
            margin-top: 40px;
            border-top: 1px solid #e0e0e0;
        }
        .footer p {
            margin: 5px 0;
            color: #666;
            font-size: 14px;
        }
        .footer a {
            color: #666;
            text-decoration: none;
        }
        .footer a:hover {
            color: #1E9FFF;
        }
    </style>

    <div class="footer">
        <p><?php echo str_replace('{year}', date('Y'), htmlspecialchars(getConfig('site_footer', '© {year} 商品文件库 - GuoKe 版权所有'))); ?></p>
        <?php if (!empty($icpNumber)): ?>
            <p><a href="https://beian.miit.gov.cn/" target="_blank"><?php echo htmlspecialchars($icpNumber); ?></a></p>
        <?php endif; ?>
        <div class="contact-info">
            <i class="material-icons"></i>
            <span><?php echo htmlspecialchars(getConfig('contact_info', '联系管理员微信：hyk416-')); ?></span>
        </div>
        <p>数据信息仅供内部使用，严禁外传，如因此造成损失，由使用者承担 | Powered by <a href="https://hyk416.cn" target="_blank">GuoKe</a></p>
    </div>

    <!-- 引入页脚信息JS -->
    <script src="js/guoke-footer.js"></script>
</body>
</html>