<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 1);
ini_set('max_execution_time', 600); // 10分钟超时
ini_set('default_charset', 'utf-8');

// 核心配置（请替换为你的实际路径）
define('PYTHON_PATH', 'C:\\Users\\King96\\AppData\\Local\\Programs\\Python\\Python312\\python.exe');
define('FFMPEG_PATH', 'C:\\ffmpeg\\bin\\ffmpeg.exe');
define('TEMP_DIR', __DIR__ . '\\temp\\');
define('LOG_FILE', __DIR__ . '\\audio_process.log');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10M限制
define('ALLOWED_FORMATS', ['mp3', 'wav', 'm4a', 'flac', 'ogg']);

// 初始化目录和日志
if (!is_dir(TEMP_DIR)) mkdir(TEMP_DIR, 0777, true);
if (!file_exists(LOG_FILE)) {
    $fp = fopen(LOG_FILE, 'w');
    fwrite($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fclose($fp);
}

// 日志函数（UTF-8编码）
function log_message($msg) {
    $msg = mb_convert_encoding($msg, 'UTF-8', 'auto');
    $time = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$time] $msg\n", FILE_APPEND | LOCK_EX);
}

// 环境检查
function check_env() {
    global $env_error;
    // 检查Python
    exec('"'.PYTHON_PATH.'" --version 2>&1', $out, $code);
    if ($code !== 0) {
        $env_error = "Python路径错误：" . PYTHON_PATH;
        log_message($env_error);
        return false;
    }
    // 检查FFmpeg
    exec('"'.FFMPEG_PATH.'" -version 2>&1', $out, $code);
    if ($code !== 0) {
        $env_error = "FFmpeg路径错误：" . FFMPEG_PATH;
        log_message($env_error);
        return false;
    }
    return true;
}

// 批量清理临时文件
function clean_temp_files($file_prefix = '') {
    // 清理指定前缀的文件
    if (!empty($file_prefix)) {
        $files = glob(TEMP_DIR . $file_prefix . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                log_message("清理临时文件：$file");
            }
        }
    }
    // 清理所有超过10分钟的临时文件
    $all_files = glob(TEMP_DIR . '*');
    foreach ($all_files as $file) {
        if (is_file($file) && filemtime($file) < time() - 600) {
            @unlink($file);
            log_message("清理过期临时文件：$file");
        }
    }
}

$env_error = '';
check_env();
?>
