<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 1);
ini_set('max_execution_time', 30); // PHP仅处理任务创建，超时30秒足够
ini_set('default_charset', 'utf-8');
ini_set('memory_limit', '512M');

// ========== 请务必修改为你的实际路径 ==========
define('PYTHON_PATH', 'C:\\Users\\King96\\AppData\\Local\\Programs\\Python\\Python312\\python.exe');
define('FFMPEG_PATH', 'C:\\ffmpeg\\bin\\ffmpeg.exe');
define('ROOT_DIR', __DIR__); // 项目根目录（自动获取）
define('TEMP_DIR', ROOT_DIR . '\\temp\\');
define('TASK_DIR', ROOT_DIR . '\\tasks\\');
define('LOG_FILE', ROOT_DIR . '\\audio_process.log');
// ========== 配置结束 ==========

define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10M限制
define('ALLOWED_FORMATS', ['mp3', 'wav', 'm4a', 'flac', 'ogg']);

// 初始化目录（确保权限）
foreach ([TEMP_DIR, TASK_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
        chmod($dir, 0777); // 强制赋予读写权限
    }
}

// 初始化日志文件
if (!file_exists(LOG_FILE)) {
    $fp = fopen(LOG_FILE, 'w');
    fwrite($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fclose($fp);
    chmod(LOG_FILE, 0777);
}

// 日志函数
function log_message($msg) {
    $msg = mb_convert_encoding($msg, 'UTF-8', 'auto');
    $time = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$time] $msg\n", FILE_APPEND | LOCK_EX);
}

// 环境检查
function check_env() {
    global $env_error;
    // 检查Python
    $python_test = exec('"'.PYTHON_PATH.'" --version 2>&1', $out, $code);
    if ($code !== 0) {
        $env_error = "Python路径错误，请检查：" . PYTHON_PATH;
        log_message($env_error);
        return false;
    }
    // 检查FFmpeg
    $ffmpeg_test = exec('"'.FFMPEG_PATH.'" -version 2>&1', $out, $code);
    if ($code !== 0) {
        $env_error = "FFmpeg路径错误，请检查：" . FFMPEG_PATH;
        log_message($env_error);
        return false;
    }
    return true;
}

// 清理临时文件
function clean_temp_files($prefix = '') {
    // 清理指定前缀文件
    if ($prefix) {
        $files = glob(TEMP_DIR . $prefix . '*');
        foreach ($files as $file) is_file($file) && @unlink($file);
        
        $task_files = glob(TASK_DIR . $prefix . '*');
        foreach ($task_files as $file) is_file($file) && @unlink($file);
        
        log_message("清理前缀[$prefix]的临时文件");
    }
    
    // 清理1小时前的过期文件
    $all_files = array_merge(glob(TEMP_DIR . '*'), glob(TASK_DIR . '*'));
    foreach ($all_files as $file) {
        if (is_file($file) && filemtime($file) < time() - 3600) {
            @unlink($file);
            log_message("清理过期文件：$file");
        }
    }
}

// 写入任务状态
function write_task_status($task_id, $status, $data = []) {
    $task_file = TASK_DIR . $task_id . '.json';
    $content = json_encode([
        'status' => $status, // pending/running/success/error
        'data' => $data,
        'time' => time()
    ], JSON_UNESCAPED_UNICODE);
    file_put_contents($task_file, $content);
    chmod($task_file, 0777); // 确保Python可写
}

// 读取任务状态
function read_task_status($task_id) {
    $task_file = TASK_DIR . $task_id . '.json';
    if (!file_exists($task_file)) return ['status' => 'invalid'];
    $content = file_get_contents($task_file);
    return json_decode($content, true) ?: ['status' => 'error'];
}

// 初始化环境检查
$env_error = '';
check_env();

// 定期清理（10%概率触发）
if (rand(1, 10) == 1) clean_temp_files();
?>
