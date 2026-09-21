<?php
declare(strict_types=1);

/**
 * 探针（不进跑批）：在**真实 pty** 里用大目录启动 bin/vicecode.php，取证两件事——
 *   1) 峰值内存（/proc/<pid>/status 的 VmHWM）会不会顶到 PHP 的 memory_limit；
 *   2) 退出时终端状态**有没有真的还原**（重点看鼠标上报 `?1000l`）。
 *
 * 起因：用户跑 `php bin/vicecode.php /data/Jobs/CaiZhiDao/CaiZhiDao/` 退出后，
 * shell 里被灌进一堆 `35;57;39M`（SGR 鼠标上报原文），怀疑 OOM。
 *
 * ⚠️ 判据说明：终端的鼠标上报是 `ESC [ < b ; x ; y M`，其中 `ESC [ <` 会被终端
 * 当控制序列吃掉，**只剩数字部分显示在 shell 里** —— 这正是用户看到的样子。
 * 所以「shell 里出现 35;57;39M」等价于「退出时没发 ?1000l」。
 *
 * 运行：php tests/probe_vicecode_bigmem.php [目录]
 */

$dir = $argv[1] ?? '/data/Jobs/CaiZhiDao/CaiZhiDao';
if (!is_dir($dir)) {
    fwrite(STDERR, "不是目录：{$dir}\n");
    exit(2);
}

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$cmd = [PHP_BINARY, 'bin/vicecode.php', $dir];
$proc = proc_open($cmd, $descs, $pipes, dirname(__DIR__));
if ($proc === false) {
    fwrite(STDERR, "无法启动\n");
    exit(1);
}

$pid = proc_get_status($proc)['pid'];

/** 读峰值常驻内存（VmHWM，KB） */
$peakKb = static function (int $pid): int {
    $s = @file_get_contents("/proc/{$pid}/status");
    if (!is_string($s)) {
        return 0;
    }
    if (preg_match('/VmHWM:\s+(\d+) kB/', $s, $m) === 1) {
        return (int) $m[1];
    }
    return 0;
};

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
$peak = 0;
$deadline = microtime(true) + 20.0;

// ── 阶段 1：起来 + 静置，同时采样内存 ──
while (microtime(true) < $deadline) {
    $r = [$pipes[1]];
    $w = $e = [];
    if (stream_select($r, $w, $e, 0, 100000) > 0) {
        $chunk = $readPty($pipes[1], 65536);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $out .= $chunk;
    }
    $peak = max($peak, $peakKb($pid));
    $st = proc_get_status($proc);
    if (!$st['running']) {
        break;
    }
}

$alive = proc_get_status($proc)['running'];
echo "启动 5s 后进程存活：" . ($alive ? '是' : '否') . "\n";
echo "首屏字节数：" . strlen($out) . "\n";
echo "期间峰值 RSS：{$peak} kB（" . round($peak / 1024, 1) . " MB）\n";

// ── 阶段 2：Ctrl+Q 退出，收尾 ──
if ($alive) {
    fwrite($pipes[0], "\x11"); // Ctrl+Q
}
$tail = '';
$end = microtime(true) + 10.0;
while (microtime(true) < $end) {
    $r = [$pipes[1]];
    $w = $e = [];
    if (stream_select($r, $w, $e, 0, 100000) > 0) {
        $chunk = $readPty($pipes[1], 65536);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $tail .= $chunk;
    }
    $peak = max($peak, $peakKb($pid));
    if (!proc_get_status($proc)['running']) {
        break;
    }
}
$out .= $tail;

$status = proc_get_status($proc);
$exit = $status['running'] ? null : $status['exitcode'];
if ($status['running']) {
    proc_terminate($proc, SIGKILL);
}
proc_close($proc);

echo "最终峰值 RSS：{$peak} kB（" . round($peak / 1024, 1) . " MB）\n";
echo "退出码：" . var_export($exit, true) . "\n";
echo "PHP memory_limit：" . ini_get('memory_limit') . "\n";

// ── 关键取证：终端状态序列 ──
$has = static fn(string $needle): string => str_contains($out, $needle) ? '有' : '**没有**';
echo "\n--- 终端状态序列 ---\n";
echo "  进入备用屏 ?1049h / 离开 ?1049l ：" . $has("\e[?1049h") . " / " . $has("\e[?1049l") . "\n";
echo "  开鼠标   ?1000h                 ：" . $has("\e[?1000h") . "\n";
echo "  关鼠标   ?1000l                 ：" . $has("\e[?1000l") . "\n";
echo "  关 SGR   ?1006l                 ：" . $has("\e[?1006l") . "\n";
echo "  显示光标 ?25h                   ：" . $has("\e[?25h") . "\n";

echo "\n--- 有没有 PHP 致命错误 / 内存耗尽 ---\n";
foreach (['Allowed memory size', 'Fatal error', 'Uncaught', 'Stack trace'] as $needle) {
    echo "  {$needle}：" . $has($needle) . "\n";
}
