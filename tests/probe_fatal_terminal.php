<?php
declare(strict_types=1);

/**
 * 探针（不进跑批）：验证「**不可捕获的 PHP 致命错误**」发生时终端会不会被还原。
 *
 * 背景：`bin/vicecode.php` 的 `start()` 用 try/**finally** 还原终端。但 PHP 的
 * 致命错误（E_ERROR / E_USER_ERROR / 内存耗尽）**不会执行 finally** ——
 * 实测（/tmp 两行脚本即可复现）：内存耗尽与未捕获 Error 下 finally 都不跑，
 * 而 `register_shutdown_function` 都跑。
 *
 * 于是：只要进程死于致命错误，终端就留在 raw mode + alternate screen + **鼠标上报开着**，
 * 用户回到 shell 后每动一下鼠标就被灌进 `ESC[<35;57;39M` 这类 SGR 上报
 * （`ESC [ <` 被终端当控制序列吃掉，只剩数字显示成命令 → `-bash: 35: command not found`）。
 *
 * 本探针注入的正是 `E_USER_ERROR`（不可被 try/catch 捕获，与内存耗尽同类），
 * 断言 pty 输出里**没有** `?1000l`（关鼠标）与 `?1049l`（离开备用屏）—— 即缺口真实存在。
 *
 * 运行：php tests/probe_fatal_terminal.php
 */

chdir(__DIR__ . '/..');
$root = getcwd();
require __DIR__ . '/lib/isolation.php';
$cfgFile = vc_isolate_config('vc_probe_fatal');

/** 造一份注入致命错误的入口副本 */
function makeFatalEntry(string $root): string
{
    $src = (string) file_get_contents($root . '/bin/vicecode.php');
    // 注入点：start() 里终端已进入 raw/备用屏/鼠标之后（只有这时才会留下烂摊子）
    $anchor = "    \$term->flush();\n\n    // ⚠️ 从这里到函数结束";
    if (!str_contains($src, $anchor)) {
        throw new RuntimeException('找不到注入点，bin/vicecode.php 结构变了');
    }
    $inject = "    \$term->flush();\n"
        . "    trigger_error('fatal injected by probe_fatal_terminal', E_USER_ERROR);\n\n"
        . "    // ⚠️ 从这里到函数结束";
    $out = str_replace($anchor, $inject, $src);
    $dir = $root . '/.vicecode_fatal_' . getmypid();
    @mkdir($dir);
    $path = $dir . '/vicecode.php';
    file_put_contents($path, $out);
    return $path;
}

$entry = makeFatalEntry($root);
register_shutdown_function(static function () use ($entry): void {
    @unlink($entry);
    @rmdir(dirname($entry));
});

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, $entry], $descs, $pipes, $root, ['VICECODE_CONFIG' => $cfgFile]);

$readPty = static function ($stream, int $len) {
    set_error_handler(static fn(int $no, string $str): bool
        => str_contains($str, 'errno=5') || str_contains($str, 'Input/output error'));
    try {
        return fread($stream, $len);
    } finally {
        restore_error_handler();
    }
};

$out = '';
$end = microtime(true) + 8.0;
while (microtime(true) < $end) {
    $r = [$pipes[1]];
    $w = $e = [];
    if (stream_select($r, $w, $e, 0, 200000) > 0) {
        $chunk = $readPty($pipes[1], 65536);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $out .= $chunk;
    }
    if (!proc_get_status($proc)['running']) {
        break;
    }
}
$status = proc_get_status($proc);
$exit = $status['running'] ? null : $status['exitcode'];
if ($status['running']) {
    proc_terminate($proc, SIGKILL);
}
proc_close($proc);

$has = static fn(string $n): string => str_contains($out, $n) ? '有' : '**没有**';
echo "退出码：" . var_export($exit, true) . "（致命错误应为非 0）\n";
echo "注入的致命错误可见：" . $has('fatal injected') . "\n\n";
echo "--- 终端还原序列（缺口所在）---\n";
echo "  开鼠标 ?1000h：" . $has("\e[?1000h") . "\n";
echo "  关鼠标 ?1000l：" . $has("\e[?1000l") . "   ← **没有**即缺口成立\n";
echo "  进备用屏 ?1049h：" . $has("\e[?1049h") . "\n";
echo "  离备用屏 ?1049l：" . $has("\e[?1049l") . "   ← **没有**即缺口成立\n";
echo "  显示光标 ?25h：" . $has("\e[?25h") . "\n";
