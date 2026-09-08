<?php
declare(strict_types=1);
// 延迟退出驱动：验证 TUI_DEMO=1 的后台协程重绘路径（isEmpty 查信号）在真实 pty 下
// 不卡死、无致命错误、能干净退出。3.5s 后才发 q（让 2s 后台任务先完成并触发重绘）。
$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes);
if ($proc === false) { echo "[FAIL] 无法启动\n"; exit(1); }
usleep(3500000);          // 等后台协程完成重绘
fwrite($pipes[0], 'q');   // 触发退出

// 子进程退出后读 pty 主端会触发 EIO(errno=5)，视为 EOF；仅精确忽略该错误
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
while (microtime(true) - $start < 4) {
    $r = [$pipes[1]]; $w = $e = [];
    if (stream_select($r, $w, $e, 0, 200000) > 0) {
        $c = $readPty($pipes[1], 4096);
        if ($c === '' || $c === false) break;
        $out .= $c;
    }
    if (!proc_get_status($proc)['running']) break;
}
if (proc_get_status($proc)['running']) { fwrite($pipes[0], "\x1b"); usleep(200000); }
$code = proc_close($proc);
echo ($code === 0) ? "[OK] TUI_DEMO 重绘路径干净退出 (exit=0)\n" : "[FAIL] exit=$code\n";
// 必须显式带出退出码：早期版本只 echo 不 exit，[FAIL] 场景下进程仍返回 0，
// 任何按退出码判定的跑批都会把它当成通过（永远绿）。
exit($code === 0 ? 0 : 1);
