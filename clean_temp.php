<?php
require_once 'config.php';

// 清理超过1小时的临时文件
$expire_time = time() - 3600;
log_message("开始清理临时文件（过期时间：" . date('Y-m-d H:i:s', $expire_time) . "）");

// 遍历临时目录
foreach (glob(TEMP_DIR . '*') as $file) {
    if (is_file($file)) {
        $file_time = filemtime($file);
        if ($file_time < $expire_time) {
            if (unlink($file)) {
                log_message("已删除过期文件：" . basename($file) . "（修改时间：" . date('Y-m-d H:i:s', $file_time) . "）");
            } else {
                log_message("删除失败：" . basename($file));
            }
        }
    }
}
log_message("临时文件清理完成");
?>
