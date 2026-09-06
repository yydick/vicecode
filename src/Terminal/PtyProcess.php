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

    /**
     * 0=master输入 1=master输出(含stderr)。
     * 声明为 mixed 而非 array：proc_open 的 $pipes 引用参数回填的是 stream resource，
     * AOT（TypePHP）严格类型检查会拒绝把 resource 赋给 array 属性
     * （见 docs/AOT_INCOMPATIBILITY_REPORT.md ②）。标准 PHP 下 array 也接受 resource 元素，
     * 故改 mixed 不改变运行时行为；仅为 AOT 编译兼容。
     * @var array<int, resource>
     */
    private mixed $pipes = [];

    /** bash 专用：注入 cwd 上报钩子的临时 rcfile 路径（shutdown 时清理） */
    private ?string $rcFile = null;

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
        // 用关联数组聚合，避免 COLUMNS/LINES/TERM 既从父环境拷贝又被追加造成重复键
        // （重复键时子进程取到哪个值未定义，会按错误尺寸渲染）。
        $envMap = [];
        foreach ($env as $k => $v) {
            $envMap[$k] = $v;
        }
        if (!isset($envMap['TERM'])) {
            $envMap['TERM'] = 'xterm-256color';
        }
        // COLUMNS/LINES 必须始终反映 pty 真实尺寸（面板视口），覆盖从父环境继承的过期值。
        // 否则 vim/less/top 等全屏程序会按错误的 LINES（如测试或嵌套 shell 注入的 40）渲染，
        // 把内容画进与仿真器尺寸（如面板实际 13 行）不匹配的网格，导致首/末行错位或滚出视口。
        $envMap['COLUMNS'] = (string) $cols;
        $envMap['LINES'] = (string) $rows;
        $envArr = [];
        foreach ($envMap as $k => $v) {
            $envArr[] = $k . '=' . $v;
        }

        $desc = [
            0 => ['pty'],
            1 => ['pty'],
            2 => ['pty'],
        ];

        // bash 专用：用 --rcfile 注入「cwd 上报钩子」，且先 source 用户 ~/.bashrc，
        // 既保留用户环境、又确保 PROMPT_COMMAND 不被用户 .bashrc 覆盖（常规 env 注入会被覆盖）。
        // 其它 shell（zsh/fish 等）暂不支持 cwd 捕获，退回原 exec "$0" -i 行为。
        $base = basename($shell);
        if ($base === 'bash') {
            $rc = $this->buildIntegrationRc($rows, $cols);
            $tmp = tempnam(sys_get_temp_dir(), 'vicetui_rc_');
            if ($tmp !== false) {
                file_put_contents($tmp, $rc);
                $this->rcFile = $tmp;
                $argv = [$shell, '--rcfile', $tmp, '-i'];
            } else {
                $argv = [$shell, '-c', sprintf('stty rows %d cols %d 2>/dev/null; exec "$0" -i', $rows, $cols), $shell];
            }
        } else {
            $argv = [$shell, '-c', sprintf('stty rows %d cols %d 2>/dev/null; exec "$0" -i', $rows, $cols), $shell];
        }
        $proc = proc_open(
            $argv,
            $desc,
            $pipes,
            $cwd !== '' ? $cwd : null,
            $envArr
        );

        if (!is_resource($proc)) {
            if ($this->rcFile !== null && is_file($this->rcFile)) {
                @unlink($this->rcFile);
                $this->rcFile = null;
            }
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
     * 生成注入 bash 的 rcfile：先设窗口尺寸、source 用户 ~/.bashrc（保留其环境），
     * 再 append 一个 PROMPT_COMMAND 钩子，在每轮提示符前经自定义 OSC 回显 $PWD。
     */
    private function buildIntegrationRc(int $rows, int $cols): string
    {
        $rc = sprintf("stty rows %d cols %d 2>/dev/null\n", $rows, $cols);
        $home = getenv('HOME');
        if ($home !== false && is_file($home . '/.bashrc')) {
            $rc .= '. ' . escapeshellarg($home . '/.bashrc') . "\n";
        }
        $rc .= '__vicetui_cwd() { printf \'\033]777;vicetui;cwd=%s\007\' "$PWD"; }' . "\n";
        $rc .= 'PROMPT_COMMAND="${PROMPT_COMMAND:+$PROMPT_COMMAND; }__vicetui_cwd"' . "\n";
        return $rc;
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
        if ($this->rcFile !== null && is_file($this->rcFile)) {
            @unlink($this->rcFile);
            $this->rcFile = null;
        }
    }
}
