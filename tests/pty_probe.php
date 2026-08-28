<?php
declare(strict_types=1);

/**
 * 真实 pty 驱动探针：proc_open(['pty']) 起 bin/tui_swoole_probe.php，
 * 发一个键，判 exit=0（协程读键可用）。超时/失败则 exit=1。
 *
 * 运行：php tests/pty_probe.php   （建议外层 timeout 兜底，避免极端卡死）
 */

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, 'bin/tui_swoole_probe.php'], $descs, $pipes);
if ($proc === false) {
    echo "[FAIL] 无法启动 probe\n";
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);

// pty 读：精确忽略对端关闭的 EIO（errno=5），不掩盖其它错误
$readSafe = static function ($stream, int $len) {
    set_error_handler(static function (int $no, string $str): bool {
        return str_contains($str, 'errno=5') || str_contains($str, 'Input/output error');
    });
    try {
        return fread($stream, $len);
    } finally {
        restore_error_handler();
    }
};

usleep(300000); // 给终端初始化时间
fwrite($pipes[0], 'a'); // 发一个键，验证协程 fread 能读到

$out = '';
$start = microtime(true);
while (microtime(true) - $start < 5) {
    $r = [$pipes[1]];
    $w = $e = [];
    if (stream_select($r, $w, $e, 0, 200000) > 0) {
        $chunk = $readSafe($pipes[1], 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $out .= $chunk;
    }
    $st = proc_get_status($proc);
    if (!$st['running']) {
        break;
    }
}

// 兜底：若还活着，发 q/Esc 尝试退出（正常情况下 probe 已自行结束）
$guard = 0;
while (proc_get_status($proc)['running'] && $guard < 3) {
    fwrite($pipes[0], $guard % 2 === 0 ? "q" : "\x1b");
    usleep(150000);
    $guard++;
}
$code = proc_close($proc);

$ok = $code === 0;
echo $ok
    ? "[OK] 协程读键在真实 pty 可用 (exit=0)\n"
    : "[FAIL] 协程读键不可用 (exit=$code)\n";
if (!$ok) {
    file_put_contents(__DIR__ . '/probe_dump.log', $out);
}
exit($ok ? 0 : 1);
