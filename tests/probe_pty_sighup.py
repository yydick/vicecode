#!/usr/bin/env python3
"""
探针（**不参与跑批**）：验证「真实终端关闭」的内核语义 —— 关掉 pty master 后进程会怎样？

为什么用 Python 而不是 PHP：需要子进程是 **session leader 且该 pty 是它的 controlling terminal**
（setsid + TIOCSCTTY），PHP 没有这两个 ioctl 的入口。`pty.fork()` 正好替我们做了这两步，
于是"关 master → 内核给前台进程组/session leader 发 SIGHUP"这一真实语义可以被复现。

结论（2026-09-18 实测）：
  - 进程被 **SIGHUP 直接终止**（不是挂住）—— 所以"终端关闭会让主循环空转挂住"这个担忧不成立；
  - 同进程组的交互 bash 会被一起收走（实测**无孤儿**），但**不执行 finally** → rc 临时文件会残留
    （这条已在 D9 用「bash 读完 rc 即自删」修掉）。

对照实验（同一轮做的）：在**没有** controlling terminal 的 pty 上关 master，用户态**完全感知不到**
（waitEvent/select/feof/阻塞 fread 全试过）—— 见 `tests/probe_pty_eof.php`。

用法：python3 tests/probe_pty_sighup.py
"""
import os
import pty
import signal
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

cfgdir = "/tmp/vc_probe_sighup"
os.makedirs(cfgdir, exist_ok=True)

env = dict(os.environ)
env.update({
    "COLUMNS": "120",
    "LINES": "40",
    "VICECODE_CONFIG": os.path.join(cfgdir, "vicerc"),
})

pid, fd = pty.fork()
if pid == 0:
    # 子进程：pty.fork() 已 setsid + TIOCSCTTY，这个 pty 就是它的 controlling terminal
    try:
        os.execvpe("php", ["php", "bin/vicecode.php"], env)
    except Exception as e:                                  # pragma: no cover
        os.write(2, f"exec failed: {e}\n".encode())
        os._exit(127)

# 父进程：先收一点输出，作为「应用真的起来了」的正向锚点
settle = time.time() + 2.0
out = b""
while time.time() < settle:
    try:
        r, _, _ = __import__("select").select([fd], [], [], 0.2)
        if r:
            chunk = os.read(fd, 65536)
            if not chunk:
                break
            out += chunk
    except OSError:
        break
    if b"1049h" in out and len(out) > 2000:
        break

print(f"启动阶段收到 {len(out)} 字节；含 alternate screen 开启（ESC[?1049h）: {b'1049h' in out}")

print("关闭 pty master（= 终端窗口关掉）...")
try:
    os.close(fd)
except OSError as e:
    print(f"close master 出错: {e}")

deadline = time.time() + 8
status = None
while time.time() < deadline:
    wpid, st = os.waitpid(pid, os.WNOHANG)
    if wpid == pid:
        status = st
        break
    time.sleep(0.05)

if status is None:
    print("结果：8s 内进程没退出 —— 挂住了（与结论不符，需重新审视）")
    os.kill(pid, signal.SIGKILL)
    os.waitpid(pid, 0)
    sys.exit(1)

if os.WIFSIGNALED(status):
    signo = os.WTERMSIG(status)
    print(f"结果：进程被信号 {signo}（{signal.Signals(signo).name}）终止 ← 预期就是 SIGHUP")
    sys.exit(0 if signo == signal.SIGHUP else 1)

print(f"结果：进程自行退出，exitcode={os.WEXITSTATUS(status)}（与结论不符，需重新审视）")
sys.exit(1)
