<?php
declare(strict_types=1);

namespace App\Terminal;

/**
 * 命令执行器：命令跑在独立子进程，管道设非阻塞，由主循环每轮 poll() 排空。
 *
 * 为什么不用协程读管道：子进程 + 非阻塞管道本身就提供了「多进程、不阻塞主循环」，
 * 协程在这里只是换了个读法，却额外引入三件事（实测见 tests/m2_probe_runner.php）：
 *  1. HOOK_PROC 接管后 proc_close() 返回值被改写（exit 42 → 0），退出码只能改走
 *     proc_get_status()['exitcode']；
 *  2. 接管后 proc_open() 只能在协程内调用（"API must be called in the coroutine"），
 *     headless 测试无法直接驱动 runner；
 *  3. HOOK_FILE 会接管管道 fd，proc_close 后延迟释放再次 close，往 stdout 吐
 *     `WARNING network::socket_free_defer()` —— 在 TUI 里直接打进 alternate screen 花屏。
 * 故 M2 关掉 HOOK_FILE/HOOK_PROC（见 bin/vicecode.php 的 M2_HOOK_FLAGS），这里全用原生调用。
 *
 * 退出码一律取 proc_get_status()['exitcode']：它在有无 HOOK 两种环境下都正确，
 * 而 proc_close() 的返回值在 HOOK 下不可信。
 */
final class CommandRunner
{
    /** @var resource|null */
    private $proc = null;

    /** @var array<int, resource> stdout=1 / stderr=2 */
    private array $pipes = [];

    private bool $running = false;

    private ?int $exitCode = null;

    private int $termSig = 0;

    private string $command = '';

    private float $startedAt = 0.0;

    private const CHUNK = 32768;

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /** 导致进程终止的信号号（0=正常退出，9=SIGKILL，即被我们中断） */
    public function termSig(): int
    {
        return $this->termSig;
    }

    public function command(): string
    {
        return $this->command;
    }

    public function elapsed(): float
    {
        return $this->running ? microtime(true) - $this->startedAt : 0.0;
    }

    /**
     * 启动命令。返回 false 表示起不了进程（调用方负责提示）。
     */
    public function start(string $cmd, ?string $cwd = null): bool
    {
        if ($this->running) {
            return false;
        }

        $desc = [
            0 => ['file', '/dev/null', 'r'],  // 非交互：防止命令抢读终端输入
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        // 走 /bin/sh -c 以支持管道/重定向；数组形式避免二次 shell 解析
        $proc = proc_open(['/bin/sh', '-c', $cmd], $desc, $pipes, $cwd);

        if (!is_resource($proc)) {
            return false;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->proc = $proc;
        $this->pipes = $pipes;
        $this->running = true;
        $this->exitCode = null;
        $this->termSig = 0;
        $this->command = $cmd;
        $this->startedAt = microtime(true);
        return true;
    }

    /**
     * 主循环每轮调一次：非阻塞排空两个管道并回调，返回本轮是否产生了新内容。
     *
     * @param callable(string, bool):void $onChunk (bytes, isStderr)
     */
    public function poll(callable $onChunk): bool
    {
        if (!$this->running || !is_resource($this->proc)) {
            return false;
        }

        $got = $this->drain($onChunk);

        $st = proc_get_status($this->proc);
        if ($st['running'] === false) {
            // 进程已退出：再排空一次，收走管道里的残留数据（否则尾部会丢）
            $got = $this->drain($onChunk) || $got;
            $this->exitCode = $st['exitcode'];
            $this->termSig = $st['termsig'];
            $this->finish();
        }
        return $got;
    }

    /**
     * 排空两个管道。stderr 也要排：不排会打满管道缓冲把子进程堵死。
     *
     * @param callable(string, bool):void $onChunk
     */
    private function drain(callable $onChunk): bool
    {
        $got = false;
        foreach ([1 => false, 2 => true] as $idx => $isErr) {
            if (!isset($this->pipes[$idx]) || !is_resource($this->pipes[$idx])) {
                continue;
            }
            while (true) {
                $chunk = fread($this->pipes[$idx], self::CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $onChunk($chunk, $isErr);
                $got = true;
            }
        }
        return $got;
    }

    /** 中断当前命令（SIGKILL）。下一轮 poll() 会检测到退出。 */
    public function cancel(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc, SIGKILL);
        }
    }

    /** 收尾：关管道、关进程句柄、复位状态。 */
    private function finish(): void
    {
        foreach ($this->pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        $this->pipes = [];
        if (is_resource($this->proc)) {
            proc_close($this->proc);
        }
        $this->proc = null;
        $this->running = false;
    }

    /** 应用退出时调用：命令还在跑就先杀掉，避免留下孤儿进程。 */
    public function shutdown(): void
    {
        if (!$this->running) {
            return;
        }
        $this->cancel();
        // 给子进程一点时间被 SIGKILL 收割，再强制收尾
        usleep(50000);
        if (is_resource($this->proc)) {
            $st = proc_get_status($this->proc);
            $this->exitCode = $st['exitcode'];
            $this->termSig = $st['termsig'];
        }
        $this->finish();
    }
}
