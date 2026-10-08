<?php
declare(strict_types=1);
/**
 * 终端独占（Takeover）鼠标透传端到端（真实 pty）：
 *   聚焦终端 → F2 进交互 → F5 独占 → 启动 `cat`（行回显）→
 *   发送 SGR 鼠标按下/松开序列（模拟真实终端上报的鼠标事件）→
 *   断言该序列被原样透传给 PTY 并经回显出现在 STDOUT（证明 解析→编码→转发 闭环）。
 * 运行：php tests/takeover_mouse_drive.php
 */

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes);
if ($proc === false) {
    echo "[FAIL] 无法启动 bin/vicecode.php\n";
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);

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

$seq = [
    ["\t", 60000],            // sidebar -> editor
    ["\t", 60000],            // editor -> terminal
    ["\x1bOQ", 1200000],      // F2 进入交互式 PTY
    ["\x1b[15~", 800000],     // F5 进入独占
    ["cat\n", 500000],        // 启动 cat（行回显，作鼠标字节回声壁）
    // 模拟真实终端上报一次鼠标按下+松开（1-based 10,5）。两序列不带换行，
    // 否则 php-tui 解析器会把 \n 当序列尾字节丢松开；换行单独发，让 cat 刷出整行回显。
    ["\e[<0;10;5M\e[<3;10;5m", 600000],
    ["\n", 600000],           // 换行触发 cat 回显整行（同时含按下+松开序列）
    ["\x03", 400000],         // Ctrl+C 结束 cat（回到 bash）
    ["\x1b[15~", 600000],     // F5 退出独占
    ["\x1b", 200000],         // Esc 退出捕获
    ["\x11", 500000],         // Ctrl+Q 退出应用
];

$out = '';
foreach ($seq as [$bytes, $us]) {
    fwrite($pipes[0], $bytes);
    usleep($us);
    for ($k = 0; $k < 6; $k++) {
        $r = [$pipes[1]];
        $w = $e = [];
        if (stream_select($r, $w, $e, 0, 30000) > 0) {
            $chunk = $readPty($pipes[1], 8192);
            if ($chunk !== '' && $chunk !== false) {
                $out .= $chunk;
            }
        } else {
            break;
        }
    }
    if (!proc_get_status($proc)['running']) {
        break;
    }
}
$guard = 0;
while (proc_get_status($proc)['running'] && $guard < 6) {
    fwrite($pipes[0], $guard % 2 === 0 ? "\x1b" : "\x11");
    usleep(150000);
    $guard++;
}
$code = proc_close($proc);

$down = str_contains($out, "\e[<0;10;5M");
$up = str_contains($out, "\e[<3;10;5m");
$fatal = (str_contains($out, 'Fatal error') || str_contains($out, 'Uncaught'))
    && !str_contains($out, 'Read of');

$ok = $code === 0 && $down && $up && !$fatal;
echo $down ? "  [OK] 鼠标按下序列原始透传成功 (\\e[<0;10;5M)\n"
           : "  [FAIL] 未检测到鼠标按下透传\n";
echo $up ? "  [OK] 鼠标松开序列原始透传成功 (\\e[<3;10;5m)\n"
         : "  [FAIL] 未检测到鼠标松开透传\n";
echo $ok ? "[OK] 接管鼠标透传端到端 PASS exit=$code\n"
        : "[FAIL] 接管鼠标透传端到端 FAIL exit=$code\n";
if (!$ok) {
    file_put_contents(__DIR__ . '/takeover_mouse_dump.log', $out);
    echo "  (已转储原始输出到 tests/takeover_mouse_dump.log)\n";
}
exit($ok ? 0 : 1);
