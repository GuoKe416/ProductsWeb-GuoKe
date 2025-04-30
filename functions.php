<?php
/**
 * 获取系统配置
 * @param string $key 配置键名
 * @param mixed $default 默认值
 * @return mixed 配置值
 */
function getConfig($key, $default = null) {
    global $conn;
    
    static $configs = null;
    
    // 如果配置未加载，从数据库加载所有配置
    if ($configs === null) {
        $configs = [];
        $stmt = $conn->prepare("SELECT config_key, config_value FROM system_config");
        if ($stmt && $stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $configs[$row['config_key']] = $row['config_value'];
            }
        }
    }
    
    // 返回配置值，如果不存在则返回默认值
    return isset($configs[$key]) ? $configs[$key] : $default;
}

/**
 * 更新系统配置
 * @param string $key 配置键名
 * @param mixed $value 配置值
 * @return bool 是否更新成功
 */
function updateConfig($key, $value) {
    global $conn;
    
    $stmt = $conn->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?) 
                           ON DUPLICATE KEY UPDATE config_value = ?");
    $stmt->bind_param("sss", $key, $value, $value);
    
    return $stmt->execute();
}

/**
 * 批量更新系统配置
 * @param array $configs 配置数组，键为配置名，值为配置值
 * @return bool 是否全部更新成功
 */
function updateConfigs($configs) {
    global $conn;
    
    $success = true;
    
    foreach ($configs as $key => $value) {
        if (!updateConfig($key, $value)) {
            $success = false;
        }
    }
    
    return $success;
}