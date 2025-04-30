<?php
// 检查是否已安装
require_once 'install_check.php';
checkInstallation();

require_once 'db_config.php';
require_once 'auth.php';
require_once 'Logger.php';

// 初始化日志记录器
$logger = new Logger();

// 创建Auth实例
$auth = new Auth($conn);

$error = '';
$password_reset_message = '';

// 获取访问者IP
$visitorIp = $_SERVER['REMOTE_ADDR'];

// 在检查IP封禁之前，先检查是否启用了IP封禁功能
$enableIpBan = getConfig('enable_ip_ban', '1') === '1';

// 检查IP是否被封禁
function isIpBlocked($conn, $ip, &$reason, &$blockTimeRemaining) {
    $stmt = $conn->prepare("SELECT failures, last_attempt FROM ip_log WHERE ip_address=?");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // 计算封禁剩余时间
        $blockTimeRemaining = strtotime($row['last_attempt']) + 24 * 60 * 60 - time();
        if ($blockTimeRemaining > 0 && $row['failures'] >= 5) {
            $hours = floor($blockTimeRemaining / 3600);
            $minutes = floor(($blockTimeRemaining % 3600) / 60);
            $seconds = $blockTimeRemaining % 60;
            
            $reason = "由于账号登录失败次数过多，您的IP（{$ip}）已被封禁，请等待24小时后重新访问，或者联系管理员解除封禁";
            return true;
        } else if ($blockTimeRemaining <= 0) {
            // 移除过期的封禁记录
            $deleteStmt = $conn->prepare("DELETE FROM ip_log WHERE ip_address=?");
            $deleteStmt->bind_param("s", $ip);
            $deleteStmt->execute();
        }
    }
    return false;
}

// 更新IP失败次数
function updateIpFailureLog($conn, $ip) {
    $stmt = $conn->prepare("INSERT INTO ip_log (ip_address, failures) VALUES (?, 1) ON DUPLICATE KEY UPDATE failures=failures+1, last_attempt=NOW()");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
}

// 获取指定IP的失败次数
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

// 检查IP是否被封禁
$blockReason = '';
$blockTimeRemaining = 0;
if ($enableIpBan && isIpBlocked($conn, $visitorIp, $blockReason, $blockTimeRemaining)) {
    $error = $blockReason;
}

// 处理密码重置
if (isset($_POST['action']) && $_POST['action'] == 'reset_password') {
    if (!$auth->isLoggedIn() || !isset($_SESSION['need_password_reset']) || !$_SESSION['need_password_reset']) {
        header("Location: login.php");
        exit;
    }
    
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // 基本验证
    if (empty($new_password) || empty($confirm_password)) {
        $password_reset_message = "新密码和确认密码不能为空";
    } elseif ($new_password !== $confirm_password) {
        $password_reset_message = "两次输入的密码不一致";
    } elseif (strlen($new_password) < 6) {
        $password_reset_message = "密码长度不能少于6个字符";
    } else {
        // 检查新密码是否与原密码相同（防止用户设置为默认密码）
        if (isset($_SESSION['original_password_hash']) && password_verify($new_password, $_SESSION['original_password_hash'])) {
            $password_reset_message = "新密码不能与原密码相同，请设置一个新的密码";
            $_SESSION['show_error'] = true;  // 添加标记以显示错误信息
        } else {
            // 更新密码
            $result = $auth->updatePassword($_SESSION['user_id'], $new_password);
            
            if ($result) {
                // 记录密码重置日志
                $logger->log('修改密码', '用户成功修改密码', ['user_id' => $_SESSION['user_id']]);
                
                // 如果有登录后重定向地址，则使用，否则回到首页
                $redirect = isset($_SESSION['redirect_after_login']) ? $_SESSION['redirect_after_login'] : 'index.php';
                unset($_SESSION['redirect_after_login']);
                header("Location: " . $redirect);
                exit;
            } else {
                $password_reset_message = "密码更新失败，请重试";
                // 记录密码重置失败日志
                $logger->log('修改密码', '用户密码修改失败', ['user_id' => $_SESSION['user_id']]);
            }
        }
    }
}

// 如果用户已经登录且需要重置密码，则显示重置密码表单
if ($auth->isLoggedIn() && isset($_SESSION['need_password_reset']) && $_SESSION['need_password_reset']) {
    // 保持在登录页面，显示重置密码表单
} 
// 如果用户已登录且不需要重置密码，则重定向到首页
else if ($auth->isLoggedIn()) {
    // 如果有登录后重定向地址，则使用，否则回到首页
    $redirect = isset($_SESSION['redirect_after_login']) ? $_SESSION['redirect_after_login'] : 'index.php';
    unset($_SESSION['redirect_after_login']);
    header("Location: " . $redirect);
    exit;
}

// 处理登录表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $visitorIp = $_SERVER['REMOTE_ADDR'];
    $blockReason = '';
    $blockTimeRemaining = 0;

    // 检查 IP 是否被封禁
    if ($enableIpBan && isIpBlocked($conn, $visitorIp, $blockReason, $blockTimeRemaining)) {
        $_SESSION['login_message'] = $blockReason;
        // 记录IP被封禁日志
        $logger->log('登录', 'IP已被封禁尝试登录', ['ip' => $visitorIp, 'username' => $username], null);
        header("Location: login.php");
        exit;
    }

    // 获取当前 IP 的失败次数
    $failures = $enableIpBan ? getIpFailures($conn, $visitorIp) : 0;
    
    // 如果失败次数已达到5次，但还未到24小时
    if ($enableIpBan && $failures >= 5) {
        $_SESSION['login_message'] = "由于多次登录失败，您的IP已被临时封禁，请稍后再试或联系管理员。";
        // 记录尝试登录日志
        $logger->log('登录', 'IP因多次失败被封禁尝试登录', ['ip' => $visitorIp, 'username' => $username], null);
        header("Location: login.php");
        exit;
    }

    // 尝试登录
    $loginResult = $auth->login($username, $password);
    
    if ($loginResult['success']) {
        // 登录成功，清除失败记录
        if ($enableIpBan) {
            $stmt = $conn->prepare("DELETE FROM ip_log WHERE ip_address = ?");
            $stmt->bind_param("s", $visitorIp);
            $stmt->execute();
        }
        
        // 记录登录成功日志
        $logger->log('登录', '用户登录成功', ['username' => $username]);
        
        // 检查是否需要重置密码
        if (isset($_SESSION['need_password_reset']) && $_SESSION['need_password_reset']) {
            header("Location: login.php");
        } else {
            // 如果有登录后重定向地址，则使用，否则回到首页
            $redirect = isset($_SESSION['redirect_after_login']) ? $_SESSION['redirect_after_login'] : 'index.php';
            unset($_SESSION['redirect_after_login']);
            header("Location: " . $redirect);
        }
        exit;
    } else {
        // 更新失败次数
        if ($enableIpBan) {
            updateIpFailureLog($conn, $visitorIp);
            $remainingAttempts = 5 - ($failures + 1);
            
            // 记录登录失败日志
            $logger->log('登录', '用户登录失败', [
                'username' => $username,
                'ip' => $visitorIp,
                'remaining_attempts' => $remainingAttempts
            ], null);
            
            if ($remainingAttempts > 0) {
                $_SESSION['login_message'] = "用户名或密码错误 (剩余尝试次数: {$remainingAttempts}次)";
            } else {
                $_SESSION['login_message'] = "登录失败次数过多，账号已被临时封禁24小时。";
            }
        } else {
            $_SESSION['login_message'] = "用户名或密码错误";
        }
        
        header("Location: login.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>登录 - 商品库</title>
    <link href="css/googleapis.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Noto Sans SC', sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #2c3e50;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 600px;
        }
        
        h1 {
            text-align: center;
            margin-bottom: 30px;
            color: #3498db;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #4a5568;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            outline: none;
        }
        
        .form-group input:focus {
            border-color: #3498db;
        }
        
        button {
            width: 100%;
            padding: 12px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        button:hover {
            background: #2980b9;
        }
        
        .error, .notice {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .error {
            background: #fed7d7;
            color: #c53030;
        }
        
        .notice {
            background: #e6fffa;
            color: #234e52;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <?php if ($auth->isLoggedIn() && isset($_SESSION['need_password_reset']) && $_SESSION['need_password_reset']): ?>
            <h1>重置密码</h1>
            
            <?php if (!empty($password_reset_message)): ?>
                <div class="error"><?php echo htmlspecialchars($password_reset_message); ?></div>
            <?php endif; ?>
            
            <div class="notice">您被要求需要修改密码才能使用，且新密码不能与默认密码相同</div>
            
            <form method="post" action="login.php">
                <input type="hidden" name="action" value="reset_password">
                
                <div class="form-group">
                    <label for="new_password">新密码</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">确认新密码</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                
                <button type="submit">提交</button>
            </form>
        <?php else: ?>
            <h1>商品文件库 - GuoKe - 登录</h1>
            
            <?php if (!empty($error)): ?>
                <div class="error">
                    <?php echo htmlspecialchars($error); ?>
                    <?php if (isset($blockTimeRemaining) && $blockTimeRemaining > 0): ?>
                        <div id="countdown"></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php 
            // 显示从URL参数传递的消息
            if (isset($_GET['message'])): ?>
                <div class="error"><?php echo htmlspecialchars($_GET['message']); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['login_message'])): ?>
                <div class="error"><?php echo htmlspecialchars($_SESSION['login_message']); ?></div>
                <?php unset($_SESSION['login_message']); // 清除消息 ?>
            <?php endif; ?>
            
            <form method="post" action="login.php">
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit">登录</button>
            </form>
        <?php endif; ?>
    </div>

    <script>
    <?php if (isset($blockTimeRemaining) && $blockTimeRemaining > 0): ?>
    // 倒计时功能
    let endTime = Date.now() + <?php echo $blockTimeRemaining; ?> * 1000;
    
    function updateCountdown() {
        const now = Date.now();
        const remaining = endTime - now;
        
        if (remaining <= 0) {
            location.reload();
            return;
        }
        
        const hours = Math.floor(remaining / (1000 * 60 * 60));
        const minutes = Math.floor((remaining % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((remaining % (1000 * 60)) / 1000);
        
        const countdownElement = document.getElementById('countdown');
        if (countdownElement) {
            countdownElement.innerHTML = 
                `封禁剩余时间: ${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        }
    }
    
    // 每秒更新一次倒计时
    updateCountdown();
    setInterval(updateCountdown, 1000);
    <?php endif; ?>
    </script>
    
    <!-- 引入页脚信息JS -->
    <script src="js/guoke-footer.js"></script>
</body>
</html>