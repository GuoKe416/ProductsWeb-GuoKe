<?php
// 在文件开头添加根目录定义
define('ROOT_PATH', str_replace('\\', '/', dirname(__FILE__)));

// 引入安装检查函数
require_once 'install_check.php';

// 检查是否已安装
if (isInstalled()) {
    die('系统已安装，如需重新安装请删除 config.php 和 install.lock 文件');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 验证表单数据
    $dbHost = trim($_POST['db_host']);
    $dbName = trim($_POST['db_name']);
    $dbUser = trim($_POST['db_user']);
    $dbPass = $_POST['db_pass'];
    
    if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
        $error = '所有带星号(*)的字段都必须填写';
    } else {
        try {
            // 测试数据库连接
            $conn = new mysqli($dbHost, $dbUser, $dbPass);
            if ($conn->connect_error) {
                throw new Exception('数据库连接失败: ' . $conn->connect_error);
            }
            
            // 创建数据库（如果不存在）
            if (!$conn->query("CREATE DATABASE IF NOT EXISTS `$dbName`")) {
                throw new Exception('创建数据库失败: ' . $conn->error);
            }
            
            // 选择数据库
            if (!$conn->select_db($dbName)) {
                throw new Exception('选择数据库失败: ' . $conn->error);
            }
            
            // 导入SQL文件
            $sql = file_get_contents('install.sql');
            if (empty($sql)) {
                throw new Exception('无法读取 install.sql 文件');
            }
            
            // 执行SQL语句
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    if (!$conn->query($statement)) {
                        throw new Exception('执行SQL失败: ' . $conn->error . "\nSQL: " . $statement);
                    }
                }
            }
            
            // 创建IP封禁表
            $sql = "CREATE TABLE IF NOT EXISTS ip_ban (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                reason TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_ip (ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            
            if ($conn->query($sql) !== TRUE) {
                throw new Exception("创建IP封禁表失败: " . $conn->error);
            }
            
            // 清空用户表
            if (!$conn->query("TRUNCATE TABLE users")) {
                throw new Exception('清空用户表失败: ' . $conn->error);
            }
            
            // 插入默认管理员账号
            $adminUser = 'admin';
            $adminPass = '123456';
            $hashedPassword = password_hash($adminPass, PASSWORD_DEFAULT);
            
            $sql = "INSERT INTO users (username, password, role, download_report_permission, status, need_password_reset) 
                   VALUES ('$adminUser', '$hashedPassword', 'admin', 1, 'active', 0)";
            
            if (!$conn->query($sql)) {
                throw new Exception('创建默认管理员账号失败: ' . $conn->error);
            }
            
            // 创建配置文件
            $config = "<?php
// 该文件由安装程序自动生成，请勿直接修改
return [
    'db_host' => " . var_export($dbHost, true) . ",
    'db_name' => " . var_export($dbName, true) . ",
    'db_user' => " . var_export($dbUser, true) . ",
    'db_pass' => " . var_export($dbPass, true) . ",
];";
            
            if (file_put_contents(ROOT_PATH . '/config.php', $config) === false) {
                throw new Exception('无法创建配置文件');
            }
            
            // 创建 db_config.php
            $dbConfig = "<?php
// 该文件由安装程序自动生成，请勿直接修改
\$config = require_once dirname(__FILE__) . '/config.php';

// 数据库连接信息
\$servername = \$config['db_host'];
\$username = \$config['db_user'];
\$password = \$config['db_pass'];
\$dbname = \$config['db_name'];

// 创建连接
\$conn = new mysqli(\$servername, \$username, \$password, \$dbname);

// 检查连接
if (\$conn->connect_error) {
    die('Connection failed: ' . \$conn->connect_error);
}

// 设置字符集
\$conn->set_charset('utf8mb4');

// 引入通用函数
require_once dirname(__FILE__) . '/functions.php';
";
            
            if (file_put_contents(ROOT_PATH . '/db_config.php', $dbConfig) === false) {
                throw new Exception('无法创建数据库配置文件');
            }
            
            // 创建 functions.php
            $functions = "<?php
/**
 * 获取系统配置
 * @param string \$key 配置键名
 * @param mixed \$default 默认值
 * @return mixed 配置值
 */
function getConfig(\$key, \$default = null) {
    global \$conn;
    
    static \$configs = null;
    
    // 如果配置未加载，从数据库加载所有配置
    if (\$configs === null) {
        \$configs = [];
        \$stmt = \$conn->prepare(\"SELECT config_key, config_value FROM system_config\");
        if (\$stmt && \$stmt->execute()) {
            \$result = \$stmt->get_result();
            while (\$row = \$result->fetch_assoc()) {
                \$configs[\$row['config_key']] = \$row['config_value'];
            }
        }
    }
    
    // 返回配置值，如果不存在则返回默认值
    return isset(\$configs[\$key]) ? \$configs[\$key] : \$default;
}

/**
 * 更新系统配置
 * @param string \$key 配置键名
 * @param mixed \$value 配置值
 * @return bool 是否更新成功
 */
function updateConfig(\$key, \$value) {
    global \$conn;
    
    \$stmt = \$conn->prepare(\"INSERT INTO system_config (config_key, config_value) VALUES (?, ?) 
                           ON DUPLICATE KEY UPDATE config_value = ?\");
    \$stmt->bind_param(\"sss\", \$key, \$value, \$value);
    
    return \$stmt->execute();
}

/**
 * 批量更新系统配置
 * @param array \$configs 配置数组，键为配置名，值为配置值
 * @return bool 是否全部更新成功
 */
function updateConfigs(\$configs) {
    global \$conn;
    
    \$success = true;
    
    foreach (\$configs as \$key => \$value) {
        if (!updateConfig(\$key, \$value)) {
            \$success = false;
        }
    }
    
    return \$success;
}";
            
            if (file_put_contents(ROOT_PATH . '/functions.php', $functions) === false) {
                throw new Exception('无法创建函数文件');
            }
            
            // 创建lock文件
            if (file_put_contents(ROOT_PATH . '/install.lock', date('Y-m-d H:i:s')) === false) {
                throw new Exception('无法创建lock文件');
            }
            
            $success = '安装成功！<br>默认管理员账号：admin<br>默认密码：123456<br><a href="login.php">点击这里登录</a>';
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>安装程序 - 商品文件库</title>
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
            min-height: 100vh;
            padding: 20px;
        }
        
        .install-container {
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
        
        .required:after {
            content: ' *';
            color: #e53e3e;
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
        
        .error, .success {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .error {
            background: #fed7d7;
            color: #c53030;
        }
        
        .success {
            background: #c6f6d5;
            color: #2f855a;
            line-height: 1.8;
        }
        
        .success a {
            color: #2c5282;
            text-decoration: none;
            font-weight: bold;
        }
        
        .success a:hover {
            text-decoration: underline;
        }
        
        .section-title {
            font-size: 1.2em;
            color: #2d3748;
            margin: 30px 0 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #edf2f7;
        }
        
        .help-text {
            font-size: 0.9em;
            color: #718096;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <div class="install-container">
        <h1>安装程序</h1>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php else: ?>
            <form method="post">
                <div class="section-title">数据库配置</div>
                
                <div class="form-group">
                    <label class="required" for="db_host">数据库主机</label>
                    <input type="text" id="db_host" name="db_host" value="localhost" required>
                    <div class="help-text">通常为 localhost 或 127.0.0.1</div>
                </div>
                
                <div class="form-group">
                    <label class="required" for="db_name">数据库名</label>
                    <input type="text" id="db_name" name="db_name" required>
                </div>
                
                <div class="form-group">
                    <label class="required" for="db_user">数据库用户名</label>
                    <input type="text" id="db_user" name="db_user" required>
                </div>
                
                <div class="form-group">
                    <label for="db_pass">数据库密码</label>
                    <input type="password" id="db_pass" name="db_pass">
                </div>
                
                <button type="submit">开始安装</button>
            </form>
        <?php endif; ?>
    </div>
   <!-- 引入页脚信息JS -->
   <script src="js/guoke-footer.js"></script>
</body>
</html> 