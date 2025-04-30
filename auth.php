<?php
session_start();

// 用户认证与会话管理
class Auth {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
        
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        // 确保logout_sessions表存在
        $this->createLogoutSessionsTable();
    }
    
    // 验证用户登录
    public function login($username, $password) {
        $stmt = $this->conn->prepare("SELECT users.id, users.username, users.password, users.role, users.download_report_permission, users.status, users.session_id, 
                                   users.need_password_reset 
                                   FROM users WHERE users.username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            // 检查账号状态
            if ($user['status'] == 'inactive') {
                return ["success" => false, "message" => "账号已被禁用"];
            }
            
            // 验证密码
            if (password_verify($password, $user['password'])) {
                // 获取当前会话ID和用户ID
                $currentSessionId = session_id();
                $userId = $user['id'];
                
                // 检查是否有其他设备登录（先保存旧的会话ID）
                $oldSessionId = null;
                if ($user['session_id'] && $user['session_id'] !== $currentSessionId) {
                    $oldSessionId = $user['session_id'];
                }
                
                // 获取配置的会话有效期（天数）
                $sessionLifetime = (int)getConfig('session_lifetime', 7);
                $expiresTime = $sessionLifetime > 0 ? time() + ($sessionLifetime * 24 * 60 * 60) : PHP_INT_MAX;
                
                // 设置会话
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['download_permission'] = $user['download_report_permission'];
                $_SESSION['logged_in'] = true;
                
                // 设置会话有效期
                $_SESSION['login_time'] = time();
                $_SESSION['expires_time'] = $expiresTime;
                
                // 获取客户端IP
                $ip = '';
                if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                    $ip = $_SERVER['HTTP_CLIENT_IP'];
                } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
                } else {
                    $ip = $_SERVER['REMOTE_ADDR'];
                }
                
                // 更新会话ID、登录IP和状态
                $expiresAt = $sessionLifetime > 0 ? "DATE_ADD(NOW(), INTERVAL $sessionLifetime DAY)" : "DATE_ADD(NOW(), INTERVAL 100 YEAR)";
                $stmt = $this->conn->prepare("UPDATE users SET session_id = ?, last_login = NOW(), last_login_ip = ?, login_status = 'online', session_expires_at = $expiresAt WHERE id = ?");
                $stmt->bind_param("ssi", $currentSessionId, $ip, $userId);
                $stmt->execute();
                
                // 如果存在旧会话ID，则标记它被其他设备登录挤出
                if ($oldSessionId) {
                    // 将旧会话添加到登出会话表
                    $this->recordSessionLogout($oldSessionId, $userId, 'other_device');
                }
                
                // 检查是否需要重置密码
                if ($user['need_password_reset'] == 1) {
                    $_SESSION['need_password_reset'] = true;
                    $_SESSION['original_password_hash'] = $user['password'];
                } else {
                    $_SESSION['need_password_reset'] = false;
                }
                
                // 更新session过期时间，防止用户处于激活状态时被登出
                if (!empty($_SESSION['user_id'])) {
                    // 每次请求都延长有效期到一天后
                    $this->updateSessionExpiresTime($userId);
                }
                
                return ["success" => true, "user" => $user];
            }
        }
        
        return ["success" => false, "message" => "用户名或密码错误"];
    }

    // 由于其他设备登录导致的登出
    private function logoutByOtherDevice($session_id) {
        // 创建会话数据表，用于存储被挤出的会话ID
        $this->createLogoutSessionsTable();
        
        // 查询用户ID
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE session_id = ?");
        $stmt->bind_param("s", $session_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $user_id = $user ? $user['id'] : 0;
        $stmt->close();
        
        // 将旧会话ID添加到被挤出的会话表中
        $stmt = $this->conn->prepare("INSERT INTO logout_sessions (session_id, user_id, logout_reason) VALUES (?, ?, 'other_device')");
        $stmt->bind_param("si", $session_id, $user_id);
        $stmt->execute();
        $stmt->close();
        
        // 清除用户会话
        $stmt = $this->conn->prepare("UPDATE users SET session_id = NULL, login_status = 'offline' WHERE session_id = ?");
        $stmt->bind_param("s", $session_id);
        $stmt->execute();
    }
    
    // 创建存储已登出会话的表
    private function createLogoutSessionsTable() {
        $sql = "CREATE TABLE IF NOT EXISTS logout_sessions (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(255) NOT NULL,
            user_id INT(11) NOT NULL,
            logout_reason ENUM('other_device', 'forced_logout', 'expired') NOT NULL,
            logout_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (session_id),
            INDEX (user_id)
        )";
        
        if (!$this->conn->query($sql)) {
            error_log("创建logout_sessions表失败: " . $this->conn->error);
        }
    }
    
    // 使其他会话失效
    private function logoutOtherSession($session_id) {
        $stmt = $this->conn->prepare("UPDATE users SET session_id = NULL, login_status = 'offline' WHERE session_id = ?");
        $stmt->bind_param("s", $session_id);
        $stmt->execute();
    }
    
    // 检查是否已登录
    public function isLoggedIn() {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        // 检查会话是否过期
        if (isset($_SESSION['expires_time']) && time() > $_SESSION['expires_time']) {
            $this->logout(); // 自动登出
            return false;
        }
        
        // 每次成功的登录检查都延长会话过期时间
        $this->updateSessionExpiresTime($_SESSION['user_id']);
        
        return true;
    }
    
    // 检查是否是管理员
    public function isAdmin() {
        return $this->isLoggedIn() && $_SESSION['role'] == 'admin';
    }
    
    // 检查是否有权限下载报告
    public function canDownloadReport() {
        return $this->isLoggedIn() && $_SESSION['download_permission'] == 1;
    }
    
    // 检查是否需要重置密码
    public function needPasswordReset() {
        return $this->isLoggedIn() && isset($_SESSION['need_password_reset']) && $_SESSION['need_password_reset'];
    }
    
    // 更新密码并清除重置标志
    public function updatePassword($user_id, $new_password) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $this->conn->prepare("UPDATE users SET password = ?, need_password_reset = 0 WHERE id = ?");
        $stmt->bind_param("si", $hashed_password, $user_id);
        
        $result = $stmt->execute();
        
        if ($result) {
            // 清除重置标志
            $_SESSION['need_password_reset'] = false;
            unset($_SESSION['original_password_hash']);
        }
        
        return $result;
    }
    
    // 用户登出
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            // 清除会话变量
            $_SESSION = array();
            
            // 如果设置了会话Cookie，清除它
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
            }
            
            // 销毁会话
            session_destroy();
            
            // 清除记住登录的Cookie
            if (isset($_COOKIE['remember_token'])) {
                setcookie('remember_token', '', time() - 3600, '/');
            }
            
            return true;
        }
        
        return false;
    }
    
    // 获取当前用户信息
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        $stmt = $this->conn->prepare("SELECT users.id, users.username, users.remark, users.role, users.download_report_permission, users.created_at, users.last_login, users.status FROM users WHERE users.id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            return $result->fetch_assoc();
        }
        
        return null;
    }

    public function recordSessionLogout($sessionId, $userId, $reason = 'expired') {
        $stmt = $this->conn->prepare("INSERT INTO logout_sessions (session_id, user_id, logout_reason) VALUES (?, ?, ?)");
        $stmt->bind_param("sis", $sessionId, $userId, $reason);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public function forceLogout($userId, $byUserId = 0) {
        if (!$userId) {
            return ['status' => false, 'message' => '用户ID不能为空'];
        }
        
        try {
            // 获取用户的会话ID
            $stmt = $this->conn->prepare("SELECT session_id FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $userData = $result->fetch_assoc();
            $stmt->close();
            
            if (!$userData || empty($userData['session_id'])) {
                return ['status' => false, 'message' => '找不到用户或用户未登录'];
            }
            
            // 记录此会话被强制登出
            $this->recordSessionLogout($userData['session_id'], $userId, 'forced_logout');
            
            // 更新用户状态
            $now = date('Y-m-d H:i:s');
            $stmt = $this->conn->prepare("UPDATE users SET login_status = 'forced_offline', force_logout_time = ?, force_logout_by = ? WHERE id = ?");
            $stmt->bind_param("sii", $now, $byUserId, $userId);
            $success = $stmt->execute();
            $stmt->close();
            
            if ($success) {
                return ['status' => true, 'message' => '用户已被强制登出'];
            } else {
                return ['status' => false, 'message' => '强制登出用户失败: ' . $this->conn->error];
            }
        } catch (Exception $e) {
            return ['status' => false, 'message' => '强制登出过程中发生错误: ' . $e->getMessage()];
        }
    }

    // 更新会话过期时间
    public function updateSessionExpiresTime($userId) {
        $stmt = $this->conn->prepare("UPDATE users SET session_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ? AND login_status = 'online'");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
    }

    public function checkAuth() {
        if (!session_id()) {
            session_start();
        }
        
        if (empty($_SESSION['user_id'])) {
            return false;
        }
        
        $userId = $_SESSION['user_id'];
        
        // 查询用户状态
        $stmt = $this->conn->prepare("SELECT status, login_status FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // 如果用户被禁用或者已被强制下线，清除session
            if ($user['status'] !== 'active' || $user['login_status'] === 'forced_offline') {
                $this->logout();
                return false;
            }
            
            // 更新session过期时间
            $this->updateSessionExpiresTime($userId);
            
            return true;
        }
        
        // 用户不存在，清除session
        $this->logout();
        return false;
    }
}

// 简单的必须登录验证函数
function requireLogin() {
    global $conn;
    $auth = new Auth($conn);
    
    if (!$auth->isLoggedIn()) {
        // 保存当前URL到会话，以便登录后重定向回来
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header("Location: login.php");
        exit;
    }
}

// 简单的必须为管理员验证函数
function requireAdmin() {
    global $conn;
    $auth = new Auth($conn);
    
    if (!$auth->isAdmin()) {
        header("Location: index.php?error=unauthorized");
        exit;
    }
} 