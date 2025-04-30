<?php
/**
 * 检查系统是否已安装
 * @return bool 返回true表示已安装，false表示未安装
 */
function isInstalled() {
    return file_exists(__DIR__ . '/config.php') && file_exists(__DIR__ . '/install.lock');
}

/**
 * 检查并重定向到安装页面（如果需要）
 * @return void
 */
function checkInstallation() {
    if (!isInstalled()) {
        // 如果当前不是在安装页面，则重定向到安装页面
        if (basename($_SERVER['PHP_SELF']) !== 'install.php') {
            header('Location: install.php');
            exit;
        }
    }
} 