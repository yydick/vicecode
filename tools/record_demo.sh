#!/usr/bin/env bash
#
# 录制 ViceCode 演示动图（真实 pty 会话 → asciinema .cast → GIF）。
#
# 前置依赖（本机需自行安装，脚本不做安装动作）：
#   - tmux      ：用于在伪终端里驱动应用并注入按键
#   - asciinema ：录制终端会话
#   - agg       ：把 .cast 转成 GIF（cargo install agg）
#
# 用法：
#   bash tools/record_demo.sh           # 生成 docs/demo.gif
#   OUT=my_demo.gif bash tools/record_demo.sh
#
# 说明：脚本会自动把焦点切到终端面板、按 F2 进入交互式 PTY、跑一条命令、
# 退 vim、Ctrl+D 退出 shell、Esc 退出应用，并把全过程录成 GIF。
set -euo pipefail

SESSION="vicecode_demo"
OUT="${OUT:-docs/demo.gif}"
CAST="docs/demo.cast"

need() { command -v "$1" >/dev/null 2>&1 || { echo "缺少依赖: $1（请先安装）"; exit 1; }; }
need tmux
need asciinema
need agg

mkdir -p docs
tmux kill-session -t "$SESSION" 2>/dev/null || true

# 启动应用（在 tmux 会话里跑真实 pty）
tmux new-session -d -s "$SESSION" "php bin/vicecode.php"
sleep 1.5

# 后台开始录制：asciinema 挂到同一个 tmux 会话，命令(tmux attach)退出即停止录制
asciinema rec -c "tmux attach -t $SESSION" "$CAST" &
REC_PID=$!
sleep 1

# 演示按键序列
tmux send-keys -t "$SESSION" Tab Tab                 # 焦点 → 终端面板
sleep 0.6
tmux send-keys -t "$SESSION" 'echo "ViceCode interactive terminal demo"' Enter
sleep 1.2
tmux send-keys -t "$SESSION" F2                      # 进入交互式 PTY（捕获态）
sleep 1.5
tmux send-keys -t "$SESSION" 'vim README.md' Enter
sleep 1.5
tmux send-keys -t "$SESSION" ':q!' Enter             # 退出 vim
sleep 0.6
tmux send-keys -t "$SESSION" C-d                     # Ctrl+D 退出 shell → 自动回运行器
sleep 1.0
tmux send-keys -t "$SESSION" Escape                  # Esc 退出应用

# 等待录制进程自然结束（asciinema 在 tmux attach 被 detach 后退出）
wait "$REC_PID" 2>/dev/null || true
# 兜底：确保 tmux 会话已结束
sleep 0.5
tmux kill-session -t "$SESSION" 2>/dev/null || true

# .cast → GIF
agg "$CAST" "$OUT"
echo "已生成 $OUT"
