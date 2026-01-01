<?php
require_once 'config.php';
$message = '';
$download_srt = '';
$download_txt = '';
$refresh_page = false; // 标记是否需要刷新页面

// 处理文件上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($env_error)) {
    if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $message = "请选择要上传的音频文件！";
    } else {
        $file = $_FILES['audio_file'];
        $file_name = mb_convert_encoding($file['name'], 'UTF-8', 'auto');
        
        // 前端已校验，后端二次验证文件大小
        if ($file['size'] > MAX_FILE_SIZE) {
            $message = "文件大小超过10M限制！当前文件大小：" . round($file['size']/1024/1024, 2) . "M";
            log_message("文件过大：$file_name (" . round($file['size']/1024/1024, 2) . "M)");
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ALLOWED_FORMATS)) {
                $message = "不支持的文件格式！仅支持：" . implode('、', ALLOWED_FORMATS);
                log_message("格式错误：$file_name ($ext)");
            } else {
                // 生成唯一文件名
                $unique_id = uniqid();
                $audio_path = TEMP_DIR . "audio_$unique_id.$ext";
                $srt_path = TEMP_DIR . "subtitle_$unique_id.srt";
                $txt_path = TEMP_DIR . "text_$unique_id.txt";

                // 保存上传文件
                if (move_uploaded_file($file['tmp_name'], $audio_path)) {
                    log_message("文件上传成功：$audio_path");
                    
                    // 构造Python命令
                    $python_script = __DIR__ . '\\process_audio.py';
                    $command = '"' . PYTHON_PATH . '" "' . $python_script . '" "' . $audio_path . '" "' . $srt_path . '" "' . $txt_path . '" 2>&1';
                    log_message("执行命令：$command");
                    
                    // 执行Python脚本
                    $output = [];
                    exec($command, $output, $return_code);
                    
                    // 处理Python输出（转UTF-8）
                    $output_str = '';
                    foreach ($output as $line) {
                        $output_str .= mb_convert_encoding($line, 'UTF-8', 'GBK') . "\n";
                    }
                    log_message("Python返回码：$return_code | 输出：$output_str");

                    // 检查处理结果
                    if ($return_code === 0 && file_exists($srt_path) && file_exists($txt_path)) {
                        $message = "处理成功！请下载文件：";
                        // 传递唯一ID用于下载后清理
                        $download_srt = "?download=srt&file=" . basename($srt_path) . "&uid=$unique_id";
                        $download_txt = "?download=txt&file=" . basename($txt_path) . "&uid=$unique_id";
                        log_message("处理完成：SRT=$srt_path, TXT=$txt_path");
                    } else {
                        $message = "处理失败！错误信息：\n$output_str";
                        log_message("处理失败：$output_str");
                        // 清理临时文件
                        clean_temp_files("audio_$unique_id");
                    }
                } else {
                    $message = "文件保存失败！请检查temp目录权限";
                    log_message("文件保存失败：$audio_path");
                }
            }
        }
    }
}

// 处理文件下载（核心修改：下载后清理+标记刷新）
if (isset($_GET['download']) && isset($_GET['file']) && isset($_GET['uid'])) {
    $type = $_GET['download'];
    $file = basename($_GET['file']);
    $uid = $_GET['uid'];
    $file_path = TEMP_DIR . $file;

    if (file_exists($file_path)) {
        // 设置下载头信息
        header('Content-Type: application/octet-stream; charset=utf-8');
        if ($type === 'srt') {
            header('Content-Disposition: attachment; filename="字幕_' . date('YmdHis') . '.srt"');
        } else {
            header('Content-Disposition: attachment; filename="文本_' . date('YmdHis') . '.txt"');
        }
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        
        // 下载后批量清理该次生成的所有文件
        clean_temp_files("audio_$uid");
        clean_temp_files("subtitle_$uid");
        clean_temp_files("text_$uid");
        
        log_message("下载并清理UID[$uid]的所有临时文件");
        exit;
    } else {
        $message = "下载文件不存在！";
        $refresh_page = true; // 标记需要刷新
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>智能音频转文本工具</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Microsoft Yahei", sans-serif;
        }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            padding: 40px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #333;
            font-size: 2.2rem;
            margin-bottom: 10px;
        }
        .header p {
            color: #666;
            font-size: 1rem;
        }
        .upload-area {
            border: 2px dashed #667eea;
            border-radius: 10px;
            padding: 50px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 30px;
        }
        .upload-area:hover {
            background: #f8f9ff;
            border-color: #764ba2;
        }
        .upload-area i {
            font-size: 3rem;
            color: #666;
            margin-bottom: 15px;
        }
        .upload-area p {
            color: #666;
            font-size: 1.1rem;
        }
        .upload-area .file-name {
            color: #333;
            font-weight: bold;
            margin-top: 10px;
        }
        .btn-container {
            text-align: center;
            margin-bottom: 30px;
        }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 40px;
            border-radius: 50px;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            box-shadow: none;
        }
        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 1rem;
            line-height: 1.6;
            white-space: pre-wrap;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .download-buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }
        .download-btn {
            background: #28a745;
            color: white;
            text-decoration: none;
            padding: 10px 30px;
            border-radius: 50px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .download-btn:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        .env-error {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #ffeeba;
        }
        .hidden {
            display: none;
        }
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            .header h1 {
                font-size: 1.8rem;
            }
            .download-buttons {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <!-- 环境错误提示 -->
        <?php if ($env_error): ?>
            <div class="env-error">
                <i class="fas fa-exclamation-triangle"></i> 系统配置错误：<?php echo $env_error; ?>
            </div>
        <?php endif; ?>

        <!-- 头部标题 -->
        <div class="header">
            <h1><i class="fas fa-microphone-lines"></i> 智能音频转文本工具</h1>
            <p>支持MP3/WAV/M4A/FLAC格式，最大文件大小：10MB | 优化中文/粤语识别</p>
        </div>

        <!-- 消息提示 -->
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, '成功') ? 'success' : 'error'; ?>">
                <?php echo nl2br(htmlspecialchars($message)); ?>
                
                <!-- 下载按钮 -->
                <?php if ($download_srt && $download_txt): ?>
                    <div class="download-buttons">
                        <a href="<?php echo $download_srt; ?>" class="download-btn" id="srtDownload">
                            <i class="fas fa-file-subtitle"></i> 下载字幕文件(SRT)
                        </a>
                        <a href="<?php echo $download_txt; ?>" class="download-btn" id="txtDownload">
                            <i class="fas fa-file-alt"></i> 下载纯文本文件(TXT)
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- 上传表单 -->
        <form id="uploadForm" method="POST" enctype="multipart/form-data" <?php if ($env_error) echo 'class="hidden"'; ?>>
            <div class="upload-area" onclick="document.getElementById('audioFile').click()">
                <i class="fas fa-cloud-upload-alt"></i>
                <p>点击选择音频文件</p>
                <p class="file-name" id="fileName"></p>
                <input type="file" id="audioFile" name="audio_file" accept=".mp3,.wav,.m4a,.flac,.ogg" class="hidden" />
            </div>
            
            <div class="btn-container">
                <button type="submit" class="btn" id="submitBtn" disabled>
                    <i class="fas fa-cogs"></i> 开始处理
                </button>
            </div>
        </form>
    </div>

    <script>
        // 页面刷新标记（下载后自动刷新）
        <?php if ($refresh_page): ?>
            window.location.href = window.location.href.split('?')[0]; // 刷新到首页
        <?php endif; ?>

        // 文件选择和大小校验
        const audioFile = document.getElementById('audioFile');
        const fileName = document.getElementById('fileName');
        const submitBtn = document.getElementById('submitBtn');
        const MAX_SIZE = <?php echo MAX_FILE_SIZE; ?>; // 10M

        audioFile.addEventListener('change', function(e) {
            if (this.files.length === 0) {
                fileName.textContent = '';
                submitBtn.disabled = true;
                return;
            }

            const file = this.files[0];
            const fileSize = file.size;
            const sizeMB = (fileSize / 1024 / 1024).toFixed(2);

            // 前端实时校验文件大小
            if (fileSize > MAX_SIZE) {
                fileName.textContent = `❌ ${file.name} (${sizeMB}MB) - 超过10MB限制！`;
                fileName.style.color = '#dc3545';
                submitBtn.disabled = true;
            } else {
                fileName.textContent = `✅ ${file.name} (${sizeMB}MB)`;
                fileName.style.color = '#28a745';
                submitBtn.disabled = false;
            }
        });

        // 提交表单时禁用按钮
        document.getElementById('uploadForm').addEventListener('submit', function() {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 处理中...';
        });

        // 监听下载按钮点击，下载完成后刷新页面
        const downloadButtons = document.querySelectorAll('.download-btn');
        downloadButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                // 下载完成后延迟刷新（确保文件下载完成）
                setTimeout(() => {
                    window.location.href = window.location.href.split('?')[0];
                }, 1000);
            });
        });
    </script>
</body>
</html>
