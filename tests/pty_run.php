<?php
declare(strict_types=1);

/**
 * 真实 pty 冒烟：用伪终端启动 bin/vicecode.php，喂几个键后发 q 退出，
 * 校验：进程 exit=0、无致命错误、能干净退出（验证 M1 的 App 在真实终端生命周期下可用）。
 *
 * 运行：php tests/pty_run.php
 */

$descs = [
    0 => ['pty'],
    1 => ['pty'],
    2 => ['pty'],
];
$cmd = [PHP_BINARY, 'bin/vicecode.php'];
$proc = proc_open($cmd, $descs, $pipes);
if ($proc === false) {
    echo "[FAIL] 无法启动 bin/vicecode.php\n";
    exit(1);
}

// 给终端初始化一点时间
usleep(300000);

// 焦点切到编辑器（Tab）、打开树里第一个文件（Enter 不行，因为已在 sidebar 顶层，发 Enter 展开/打开）
// 这里只验证生命周期：先 Tab 切焦点，再 q 退出。
fwrite($pipes[0], "q");

// 子进程退出后读 pty 主端会触发 EIO(errno=5)，视为 EOF；仅精确忽略该错误，不掩盖其它异常
$readPty = static function ($stream, int $len) {
    set_error_handler(static function (int $no, string $str): bool {
        return str_contains($str, 'errno=5') || str_contains($str, 'Input/output error');
    });
    try {
        return fread($stream, $len);
    } finally {
        restore_error_handler();
    }
};

$out = '';
$start = microtime(true);
while (microtime(true) - $start < 3) {
    $r = [$pipes[1]];
    $w = $e = [];
    if (stream_select($r, $w, $e, 0, 200000) > 0) {
        $chunk = $readPty($pipes[1], 4096);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $out .= $chunk;
    }
    $status = proc_get_status($proc);
    if (!$status['running']) {
        break;
    }
}

// 确保退出
if (proc_get_status($proc)['running']) {
    fwrite($pipes[0], "q");
    usleep(200000);
}
$status = proc_get_status($proc);
$firstRunning = $status['running'];
if ($firstRunning) {
    // 再尝试 Esc
    fwrite($pipes[0], "\x1b");
    usleep(200000);
    $status = proc_get_status($proc);
}

$code = proc_close($proc);

$ok = !$status['running'] && $code === 0;
echo $ok ? "[OK] bin/vicecode.php 在 pty 下干净退出 (exit=0)\n" : "[FAIL] 退出异常 (running=" . var_export($firstRunning, true) . " code=$code)\n";
exit($ok ? 0 : 1);
