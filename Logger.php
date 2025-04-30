<?php
/**
 * 日志工具类
 * 用于记录用户操作日志并支持按天分割日志文件
 */
class Logger {
    private $logDir;
    private $logFile;
    private $buffer = [];
    private $bufferSize = 2; // 缓冲区大小，超过这个数量才会写入文件
    private $maxLogDays = 7;  // 日志保留天数
    
    /**
     * 构造函数
     */
    public function __construct() {
        $this->logDir = dirname(__FILE__) . '/logs';
        
        // 确保日志目录存在
        if (!file_exists($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
        
        // 设置当天的日志文件
        $this->logFile = $this->logDir . '/log_' . date('Y-m-d') . '.log';
        
        // 清理过期日志
        $this->cleanOldLogs();
    }
    
    /**
     * 记录操作日志
     * 
     * @param string $action 操作类型
     * @param string $message 日志消息
     * @param array $data 相关数据
     * @param int $userId 操作用户ID
     * @return bool 是否成功
     */
    public function log($action, $message, $data = [], $userId = null) {
        if ($userId === null && isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'action' => $action,
            'message' => $message,
            'data' => $data
        ];
        
        // 添加到缓冲区
        $this->buffer[] = $logEntry;
        
        // 如果缓冲区达到设定大小，则写入文件
        if (count($this->buffer) >= $this->bufferSize) {
            return $this->flush();
        }
        
        return true;
    }
    
    /**
     * 将缓冲区日志写入文件
     * 
     * @return bool 是否成功
     */
    public function flush() {
        if (empty($this->buffer)) {
            return true;
        }
        
        // 重新检查当前日期的日志文件
        $this->logFile = $this->logDir . '/log_' . date('Y-m-d') . '.log';
        
        $content = '';
        foreach ($this->buffer as $entry) {
            $content .= json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
        
        $result = file_put_contents($this->logFile, $content, FILE_APPEND | LOCK_EX);
        
        if ($result !== false) {
            // 清空缓冲区
            $this->buffer = [];
            return true;
        }
        
        return false;
    }
    
    /**
     * 清理过期日志
     */
    private function cleanOldLogs() {
        $files = glob($this->logDir . '/log_*.log');
        $cutoffDate = date('Y-m-d', strtotime("-{$this->maxLogDays} days"));
        
        foreach ($files as $file) {
            $datePart = preg_replace('/^.*log_(\d{4}-\d{2}-\d{2})\.log$/', '$1', $file);
            if ($datePart < $cutoffDate) {
                unlink($file);
            }
        }
    }
    
    /**
     * 析构函数，确保所有日志都被写入
     */
    public function __destruct() {
        $this->flush();
    }
    
    /**
     * 获取指定日期的日志
     * 
     * @param string $date 日期格式 Y-m-d
     * @return array 日志数组
     */
    public function getLogs($date) {
        global $conn;
        
        // 文件名格式为 log_2023-01-01.log
        $filename = $this->logDir . '/log_' . $date . '.log';
        
        if (!file_exists($filename)) {
            return [];
        }
        
        $logs = [];
        $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $logData = json_decode($line, true);
            if ($logData) {
                // 获取用户名
                if (isset($logData['user_id']) && !empty($logData['user_id'])) {
                    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                    $stmt->bind_param("i", $logData['user_id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($user = $result->fetch_assoc()) {
                        $logData['username'] = $user['username'];
                    }
                }
                
                // 获取IP归属地
                if (isset($logData['ip']) && !empty($logData['ip'])) {
                    $logData['ip_location'] = $this->getIpLocation($logData['ip']);
                }
                
                $logs[] = $logData;
            }
        }
        
        // 按时间倒序排序
        usort($logs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        return $logs;
    }
    
    // 获取IP归属地
    private function getIpLocation($ip) {
        // 内网IP特殊处理
        if (strpos($ip, '127.0.0.1') === 0 || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
            return '内网IP';
        }
        
        // 文件缓存机制，确保在不同请求之间也能保持缓存
        $cacheDir = dirname(__FILE__) . '/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $cacheFile = $cacheDir . '/ip_cache.json';
        $ipCache = [];
        
        // 读取缓存文件
        if (file_exists($cacheFile)) {
            $ipCache = json_decode(file_get_contents($cacheFile), true) ?: [];
        }
        
        // 检查缓存中是否存在
        if (isset($ipCache[$ip])) {
            return $ipCache[$ip];
        }
        
        try {
            // 使用淘宝IP库API查询
            $url = "http://ip.taobao.com/outGetIpInfo?ip={$ip}&accessKey=alibaba-inc";
            $response = @file_get_contents($url);
            
            if ($response) {
                $data = json_decode($response, true);
                if (isset($data['data']) && isset($data['data']['country']) && isset($data['data']['region'])) {
                    $location = $data['data']['country'];
                    if ($data['data']['country'] === '中国') {
                        $location = $data['data']['region'] . $data['data']['city'];
                        if (!empty($data['data']['isp'])) {
                            $location .= ' ' . $data['data']['isp'];
                        }
                    }
                    // 更新缓存
                    $ipCache[$ip] = $location;
                    file_put_contents($cacheFile, json_encode($ipCache));
                    return $location;
                }
            }
            
            // 备用方案：使用IPIP的免费API
            $url = "https://freeapi.ipip.net/{$ip}";
            $response = @file_get_contents($url);
            
            if ($response) {
                $data = json_decode($response, true);
                if ($data && count($data) >= 4) {
                    $location = implode(' ', array_filter(array_slice($data, 0, 4)));
                    // 更新缓存
                    $ipCache[$ip] = $location;
                    file_put_contents($cacheFile, json_encode($ipCache));
                    return $location;
                }
            }
            
            // 如果API都失败，返回默认值
            $ipCache[$ip] = '未知';
            file_put_contents($cacheFile, json_encode($ipCache));
            return '未知';
        } catch (Exception $e) {
            $ipCache[$ip] = '查询失败';
            file_put_contents($cacheFile, json_encode($ipCache));
            return '查询失败';
        }
    }
    
    /**
     * 获取可用的日志日期列表
     * 
     * @return array 日期列表，格式为 Y-m-d
     */
    public function getAvailableDates() {
        $files = glob($this->logDir . '/log_*.log');
        $dates = [];
        
        foreach ($files as $file) {
            $datePart = preg_replace('/^.*log_(\d{4}-\d{2}-\d{2})\.log$/', '$1', $file);
            $dates[] = $datePart;
        }
        
        // 按日期排序，最新的在前
        rsort($dates);
        
        return $dates;
    }
} 