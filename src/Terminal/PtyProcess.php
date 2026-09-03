<?php
declare(strict_types=1);

namespace App\Terminal;

/**
 * 真实 PTY 进程封装：用 proc_open 的 pty 描述符起交互式 shell。
 *
 * 为什么用 proc_open(['pty']) 而非 posix_openpt：运行环境的 posix_openpt / posix_ioctl /
 * ioctl 均不可用（见项目 facts），只能在子进程内用 `stty rows/cols` 设窗口尺寸
 * （无 ioctl 推 master 的能力），且三描述符（0/1/2）共用同一 pty。
 *
 * 非阻塞读写：管道 set_blocking(false)，由主循环每轮读 drain 给仿真器。
 */
final class PtyProcess
{
    /** @var resource|null */
    private $proc = null;

    /** @var array<int, resource> 0=master输入 1=master输出(含stderr) */
    private array $pipes = [];

    private bool $running = false;

    private ?int $exitCode = null;

    private const CHUNK = 8192;

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * 启动交互式 shell。
     *
     * @param string $shell 例如 /bin/bash（默认取 $SHELL）
     * @param int $cols 初始列数
     * @param int $rows 初始行数
     * @param string $cwd 工作目录
     * @param array<string,string> $env 注入的环境变量（key=>value）
     */
    public function start(
        string $shell,
        int $cols,
        int $rows,
        string $cwd,
        array $env
    ): bool {
        if ($this->running) {
            return false;
        }
        $shell = $shell !== '' ? $shell : (getenv('SHELL') ?: '/bin/bash');

        // 环境：继承关键变量 + 强制 TERM=xterm-256color（让全屏程序走 256 色）
        $envArr = [];
        foreach ($env as $k => $v) {
            $envArr[] = $k . '=' . $v;
        }
        if (!isset($env['TERM'])) {
            $envArr[] = 'TERM=xterm-256color';
        }
        if (!isset($env['COLUMNS'])) {
            $envArr[] = 'COLUMNS=' . $cols;
        }
        if (!isset($env['LINES'])) {
            $envArr[] = 'LINES=' . $rows;
        }

        $desc = [
            0 => ['pty'],
            1 => ['pty'],
            2 => ['pty'],
        ];

        // bash -c 'stty rows R cols C 2>/dev/null; exec "$0" -i' "$shell"
        // argv[0]=shell, argv[2]=$0=script 里 exec 的 shell 名；用 $0 传名避免二次解析。
        $script = sprintf('stty rows %d cols %d 2>/dev/null; exec "$0" -i', $rows, $cols);
        $proc = proc_open(
            [$shell, '-c', $script, $shell],
            $desc,
            $pipes,
            $cwd !== '' ? $cwd : null,
            $envArr
        );

        if (!is_resource($proc)) {
            return false;
        }
        foreach ([0, 1, 2] as $idx) {
            if (isset($pipes[$idx]) && is_resource($pipes[$idx])) {
                stream_set_blocking($pipes[$idx], false);
            }
        }
        $this->proc = $proc;
        $this->pipes = $pipes;
        $this->running = true;
        $this->exitCode = null;
        return true;
    }

    /**
     * 非阻塞读主端输出（stderr 与 stdout 共用同一 pty）。
     * 返回读到的字节串（可能为空）。
     */
    public function read(): string
    {
        if (!$this->running || !isset($this->pipes[1]) || !is_resource($this->pipes[1])) {
            return '';
        }
        $out = '';
        while (true) {
            $chunk = @fread($this->pipes[1], self::CHUNK);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $out .= $chunk;
            if (strlen($out) > self::CHUNK) {
                break;
            }
        }
        return $out;
    }

    /**
     * 非阻塞写主端输入（转发按键字节）。返回写入字节数。
     */
    public function write(string $bytes): int
    {
        if (!$this->running || !isset($this->pipes[0]) || !is_resource($this->pipes[0])) {
            return 0;
        }
        $n = fwrite($this->pipes[0], $bytes);
        return $n === false ? 0 : (int) $n;
    }

    /**
     * 窗口尺寸变化：向 master 推 stty（best-effort，运行中的全屏程序可能未即时重排）。
     */
    public function resize(int $cols, int $rows): void
    {
        if (!$this->running) {
            return;
        }
        $this->write(sprintf("stty rows %d cols %d\n", max(1, $rows), max(1, $cols)));
    }

    /** 子进程是否真的退出了（在 poll 里调用以结算） */
    public function pollExited(): bool
    {
        if (!is_resource($this->proc)) {
            return true;
        }
        $st = proc_get_status($this->proc);
        if ($st['running'] === false) {
            $this->exitCode = $st['exitcode'];
            $this->running = false;
            return true;
        }
        return false;
    }

    /** 强制杀掉并回收（退出应用 / 测试收尾时调用） */
    public function shutdown(): void
    {
        if (!is_resource($this->proc)) {
            $this->running = false;
            return;
        }
        if ($this->running) {
            proc_terminate($this->proc, SIGKILL);
            usleep(50000);
        }
        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }
        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            fclose($this->pipes[1]);
        }
        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            fclose($this->pipes[2]);
        }
        proc_close($this->proc);
        $this->proc = null;
        $this->pipes = [];
        $this->running = false;
    }
}
