<?php
declare(strict_types=1);

/**
 * M2 真实 pty 验收：在真终端里切到终端面板、跑命令、看输出、中断长命令、退出。
 *
 * 覆盖 R2/R3/R7 与「命令运行期间 UI 不崩、退出干净」：
 *  - 跑短命令，断言其输出真的被渲染到画面上（证明流式输出 + 重绘通路可用）；
 *  - 起一条长命令再 Ctrl+C，断言应用没被一起退出（R7 判定顺序生效）；
 *  - 最后 Esc 干净退出，终端还原。
 *
 * 运行：timeout 90 php tests/pty_term.php
 * 注意：禁止裸跑 bin/tui.php，一律走本脚本（外层加 timeout 兜底）。
 */

// pty 默认没有窗口尺寸，php-tui 会拿到 0×0 从而渲染不出任何内容
// （Terminal 依次试 SizeFromEnvVarProvider → SizeFromSttyProvider，pty 下两者都为空）。
// 显式给 COLUMNS/LINES，让画面真的画出来，命令输出才可见、可断言。
$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40']);
$proc = proc_open([PHP_BINARY, 'bin/tui.php'], $descs, $pipes, null, $env);
if ($proc === false) {
    echo "[FAIL] 无法启动 bin/tui.php\n";
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);

/**
 * 画面是差分渲染：同一行的字符会被拆成多次「带光标定位」的写入，
 * 直接 grep 整词匹配不到（实际是 "p t y - s t d e r r"）。
 * 故先剥掉 ANSI 序列，再只保留字母数字与汉字后比对。
 */
function normalize(string $raw): string
{
    $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $raw);   // OSC
    $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);             // CSI
    $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);
    return strtolower((string) preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $s));
}

// [字节, 延迟微秒]
// 命令刻意用「输出与命令行不同」的形式：echo $((6*7)) 输出 42，
// 而输入行是 "echo $((6*7))"（归一化后为 echo67，不含 42），
// 这样断言到 42 才能证明「命令真的执行并渲染」，而不是输入行的残留。
$seq = [
    ["\t", 60000],                        // sidebar -> editor
    ["\t", 60000],                        // editor -> terminal
    ['echo $((6*7))', 60000],             // R1 输入行打字
    ["\r", 800000],                       // R2 提交（真实终端里是 CodedKeyEvent(Enter)）
    ['echo err-marker >&2; exit 3', 60000],
    ["\r", 800000],                       // R5 stderr + 非零退出码
    ['sleep 30', 60000],
    ["\r", 500000],                       // 起一条长命令
    ["\x03", 900000],                     // R7 Ctrl+C：只中断命令，不退出应用
    ['echo alive-marker', 60000],         // 若应用还活着，这条能提交并出结果
    ["\r", 800000],
    ["\x1b", 400000],                     // Esc：空输入 → 退出
];

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
$feed = static function (string $bytes) use ($pipes): void {
    fwrite($pipes[0], $bytes);
};
$drain = static function () use ($pipes, $readPty, &$out): void {
    while (true) {
        $r = [$pipes[1]];
        $w = $e = [];
        if (stream_select($r, $w, $e, 0, 30000) <= 0) {
            return;
        }
        $chunk = $readPty($pipes[1], 8192);
        if ($chunk === '' || $chunk === false) {
            return;
        }
        $out .= $chunk;
    }
};

foreach ($seq as [$bytes, $us]) {
    if (!proc_get_status($proc)['running']) {
        break;
    }
    $feed($bytes);
    usleep($us);
    $drain();
}

// 兜底退出：优先 Esc（terminate 面板空输入时即退出），再 q
$guard = 0;
while (proc_get_status($proc)['running'] && $guard < 6) {
    $feed($guard % 2 === 0 ? "\x1b" : "q");
    usleep(200000);
    $drain();
    $guard++;
}
$code = proc_close($proc);

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

$fatal = str_contains($out, 'Fatal error') || str_contains($out, 'Uncaught') || str_contains($out, 'PHP Warning');
$n = normalize($out);

echo "== M2 真实 pty 验收 ==\n";
check($code === 0, "干净退出 exit=$code");
check(!$fatal, '无 Fatal / Uncaught / Warning');
check(str_contains($n, '42'), 'R2/R3 命令真的执行了，且输出被渲染到画面（输出 42）');
check(str_contains($n, 'errmarker'), 'R5 stderr 输出进入面板');
check(str_contains($n, '退出码3'), 'R5 非零退出码提示可见');
check(str_contains($n, '已中断'), 'R7 Ctrl+C 中断提示可见');
check(str_contains($n, 'alivemarker'), 'R7 中断后应用仍存活（Ctrl+C 未误退出）');

if ($failed) {
    file_put_contents(__DIR__ . '/pty_term_dump.log', $out);
    echo "  (已转储原始输出到 tests/pty_term_dump.log)\n";
}
echo $failed ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failed ? 1 : 0);
