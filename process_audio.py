import sys
import os
import traceback
import subprocess
import numpy as np
from datetime import timedelta

# ========== 核心配置 ==========
# 编码配置
os.environ['PYTHONIOENCODING'] = 'utf-8'
os.environ['LC_ALL'] = 'zh_CN.UTF-8'
os.environ['LANG'] = 'zh_CN.UTF-8'

# FFmpeg路径（请替换为实际路径）
FFMPEG_PATH = 'C:\\ffmpeg\\bin\\ffmpeg.exe'
os.environ['FFMPEG_PATH'] = FFMPEG_PATH

# 修复标准输出编码
sys.stdout = open(sys.stdout.fileno(), mode='w', encoding='utf-8', buffering=1)
sys.stderr = open(sys.stderr.fileno(), mode='w', encoding='utf-8', buffering=1)

# ========== 优化音频加载（解决FFmpeg路径问题） ==========
def custom_load_audio(file_path, sr=16000):
    """自定义音频加载，指定FFmpeg路径"""
    cmd = [
        FFMPEG_PATH,
        '-hide_banner',
        '-loglevel', 'error',
        '-i', file_path,
        '-ar', str(sr),
        '-ac', '1',  # 单声道
        '-f', 's16le',
        '-'
    ]
    
    try:
        result = subprocess.run(cmd, capture_output=True, check=True)
        audio = np.frombuffer(result.stdout, dtype=np.int16).astype(np.float32) / 32768.0
        return audio
    except subprocess.CalledProcessError as e:
        raise RuntimeError(f"音频解码失败：{e.stderr.decode('utf-8')}")
    except FileNotFoundError:
        raise RuntimeError(f"FFmpeg未找到，请检查路径：{FFMPEG_PATH}")

# ========== 优化中文识别配置 ==========
def init_whisper_model():
    """初始化Whisper模型（优化中文识别）"""
    import whisper
    # 替换默认音频加载函数
    whisper.audio.load_audio = custom_load_audio
    
    # 加载大型模型（提升中文识别率），优先使用本地缓存
    model = whisper.load_model(
        "medium",  # medium模型比base提升30%+中文识别率
        device="cpu",
        download_root=os.path.join(os.path.expanduser("~"), ".cache/whisper"),
        in_memory=True
    )
    return model

# ========== 日志输出函数 ==========
def log_print(msg):
    """UTF-8编码日志输出"""
    if isinstance(msg, str):
        print(f"[Python] {msg}", flush=True)
    else:
        print(f"[Python] {str(msg)}", flush=True)

# ========== 生成SRT字幕文件 ==========
def generate_srt(segments, output_path):
    """生成UTF-8编码的SRT字幕文件"""
    try:
        with open(output_path, 'w', encoding='utf-8') as f:
            for idx, seg in enumerate(segments, 1):
                # 格式化时间（00:00:00,000）
                start = timedelta(seconds=seg['start'])
                end = timedelta(seconds=seg['end'])
                
                start_str = str(start).replace('.', ',').zfill(8)
                end_str = str(end).replace('.', ',').zfill(8)
                
                # 补全时间格式
                if len(start_str) < 12:
                    start_str = start_str.ljust(12, '0')
                if len(end_str) < 12:
                    end_str = end_str.ljust(12, '0')
                
                # 写入字幕
                f.write(f"{idx}\n")
                f.write(f"{start_str[:12]} --> {end_str[:12]}\n")
                f.write(f"{seg['text'].strip()}\n\n")
        log_print(f"SRT文件生成成功：{output_path}")
    except Exception as e:
        raise RuntimeError(f"生成SRT失败：{str(e)}")

# ========== 生成纯文本文件 ==========
def generate_txt(segments, output_path):
    """生成纯文本文件（无时间戳）"""
    try:
        with open(output_path, 'w', encoding='utf-8') as f:
            full_text = ""
            for seg in segments:
                full_text += seg['text'].strip() + "\n"
            f.write(full_text.strip())
        log_print(f"TXT文件生成成功：{output_path}")
    except Exception as e:
        raise RuntimeError(f"生成TXT失败：{str(e)}")

# ========== 主函数 ==========
def main():
    try:
        # 检查参数
        if len(sys.argv) < 4:
            raise Exception(f"参数错误！需要3个参数（音频路径/SRT路径/TXT路径），收到{len(sys.argv)-1}个")
        
        # 解析参数（修复Windows路径编码）
        audio_path = sys.argv[1].strip()
        srt_path = sys.argv[2].strip()
        txt_path = sys.argv[3].strip()
        
        if sys.platform == 'win32':
            audio_path = audio_path.encode('gbk').decode('utf-8')
            srt_path = srt_path.encode('gbk').decode('utf-8')
            txt_path = txt_path.encode('gbk').decode('utf-8')
        
        # 检查音频文件
        if not os.path.exists(audio_path):
            raise Exception(f"音频文件不存在：{audio_path}")
        
        log_print(f"开始处理音频：{audio_path}")
        
        # 初始化模型（优化中文识别）
        log_print("加载Whisper模型（medium）- 优化中文/粤语识别...")
        model = init_whisper_model()
        
        # 音频识别（核心优化参数）
        log_print("开始语音识别（优化中文/粤语）...")
        result = model.transcribe(
            audio_path,
            language="zh",          # 指定中文
            task="transcribe",      # 转录模式
            fp16=False,             # CPU强制使用FP32
            verbose=False,          # 关闭详细输出
            temperature=0.7,        # 平衡识别准确率和多样性
            best_of=5,              # 多候选优化
            beam_size=5,            # 波束搜索优化
            patience=1.0,           # 耐心值提升识别率
            suppress_tokens="-1",   # 禁用无效token
            condition_on_previous_text=False,  # 关闭上下文依赖（提升方言识别）
            initial_prompt="请用标准中文或粤语转录，保持语句通顺"  # 提示词优化
        )
        
        # 生成文件
        segments = result['segments']
        generate_srt(segments, srt_path)
        generate_txt(segments, txt_path)
        
        log_print("音频转文本处理完成！")
        return 0
    
    except Exception as e:
        log_print(f"处理失败：{str(e)}")
        log_print(f"错误详情：{traceback.format_exc()}")
        return 1

if __name__ == '__main__':
    sys.exit(main())
