-- 创建配置表
CREATE TABLE IF NOT EXISTS `system_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(50) NOT NULL,
  `config_value` text,
  `config_description` varchar(255),
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建用户表
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `remark` varchar(20) DEFAULT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `download_report_permission` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `need_password_reset` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_ip` varchar(45) DEFAULT NULL,
  `login_status` enum('online','offline','forced_offline') DEFAULT 'offline',
  `session_id` varchar(255) DEFAULT NULL,
  `session_expires_at` datetime DEFAULT NULL,
  `force_logout_time` datetime DEFAULT NULL,
  `force_logout_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建商品表
CREATE TABLE IF NOT EXISTS `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `image` text,
  `info` text,
  `link` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建商品规格表
CREATE TABLE IF NOT EXISTS `product_specs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` varchar(50) NOT NULL,
  `spec_name` varchar(255) NOT NULL,
  `spec_value` text,
  `spec_remark` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `product_specs_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`code`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建商品图片特征表
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

-- 创建IP封禁日志表
CREATE TABLE IF NOT EXISTS `ip_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `failures` int(11) NOT NULL DEFAULT '1',
  `last_attempt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建登出会话表
CREATE TABLE IF NOT EXISTS `logout_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(255) NOT NULL,
  `user_id` int(11) NOT NULL,
  `logout_reason` enum('other_device','forced_logout','expired') NOT NULL,
  `logout_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建系统日志表
CREATE TABLE IF NOT EXISTS `system_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 插入默认配置
INSERT INTO `system_config` (`config_key`, `config_value`, `config_description`) VALUES
('site_title', '商品文件库 - GuoKe', '网站标题'),
('site_h1', '商品文件库 - GuoKe', '网站H1标题'),
('site_footer', '© {year} 商品文件库 - GuoKe 版权所有', '网站页脚'),
('tip_text', '点击商品可查看详细信息和文件 | 图片上右键可选择复制图像', '提示文本'),
('enable_ip_ban', '1', '是否启用IP封禁功能（0:禁用, 1:启用）'),
('enable_watermark', '0', '是否启用页面水印（0:禁用, 1:启用）'),
('session_lifetime', '7', '会话登录有效期（天数，0表示不限制）'),
('enable_baidu_api', '0', '是否启用百度API商品识别（0:禁用, 1:启用）'),
('baidu_api_key', '', '百度API Key'),
('baidu_api_secret', '', '百度API Secret'),
('contact_info', '联系管理员微信：hyk416-', '联系管理员信息'),
('error_notice', '如遇到问题，请联系管理员：hyk416-', '异常通知内容'),
('icp_number', '', '网站备案号');

-- 插入默认管理员账号（密码：admin123）
INSERT INTO `users` (`username`, `password`, `role`, `download_report_permission`, `status`, `need_password_reset`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, 'active', 1); 