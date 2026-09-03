<?php
declare(strict_types=1);

/**
 * 交互式 PTY 终端 —— 真实 pty 端到端验收。
 *
 * 用 VICECODE_CONFIG 指向临时文件隔离家目录；COLUMNS/LINES 给 pty 一个确定尺寸。
 * 全程在真终端里按键，断言：
 *  - 切到终端面板 → F2 进入交互式 PTY（捕获态）；
 *  - 在捕获态键入 `echo hi_pty_42`，输出真的被渲染到画面（证明真实 shell 在 ViceCode 内跑起来）；
 *  - Esc 退出捕获（shell 仍在跑）→ 再按 F2 重新进入捕获（证明捕获可来回切换）；
 *  - 重新捕获后键入命令仍生效；
 *  - Ctrl+D 让 shell 退出 → 自动退回 runner 模式（画面出现「交互终端已退出」）。
 *  - 最后 Esc 干净退出，终端还原。
 *
 * 运行：timeout 90 php tests/pty_interactive.php
 * 注意：禁止裸跑 bin/vicecode.php，一律走本脚本（外层加 timeout 兜底）。
 */

$cfgFile = tempnam(sys_get_temp_dir(), 'vc_itcfg');
$env = array_merge(getenv(), [
    'COLUMNS' => '120',
    'LINES' => '40',
    'APP_LOCALE' => 'zh_CN',
    'VICECODE_CONFIG' => $cfgFile,
]);
$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
if ($proc === false) {
    echo "[FAIL] 无法启动 bin/vicecode.php\n";
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);

/**
 * 画面是差分渲染：同一行字符被拆成多次「带光标定位」写入，直接 grep 整词匹配不到。
 * 先剥 ANSI 序列，再只保留字母数字与汉字后比对。
 */
function normalize(string $raw): string
{
    $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $raw);   // OSC
    $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);             // CSI
    $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);
    return strtolower((string) preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $s));
}

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

// [字节, 延迟微秒]
// 字节说明：\t=Tab(切焦点)；\x1bOQ=F2(进入/退出交互)；\r=Enter；\x1b=Esc；\x04=Ctrl+D(EOF)。
$seq = [
    ["\t", 400000],                        // sidebar -> editor
    ["\t", 700000],                        // editor -> terminal（runner 模式）
    ["\x1bOQ", 1800000],                   // F2：进入交互式 PTY（捕获态）；等待 shell 启动
    ["echo hi_pty_42\r", 1200000],         // 捕获态键入命令，交给真实 bash 执行
    ["\x1b", 500000],                      // Esc：退出捕获（shell 仍在跑）
    ["\x1bOQ", 700000],                    // F2：重新进入捕获（证明捕获可来回切换）
    ["echo reenter_ok\r", 1200000],        // 重新捕获后命令仍生效
    ["\x04", 2500000],                     // Ctrl+D：shell 退出 → 自动退回 runner
    ["\x1b", 800000],                      // Esc：空输入 → 退出应用
];

foreach ($seq as [$bytes, $us]) {
    if (!proc_get_status($proc)['running']) {
        break;
    }
    $feed($bytes);
    usleep($us);
    $drain();
}

// 兜底退出：优先 Esc（runner 空输入即退出），再 q
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

echo "== 交互式 PTY 真实 pty 验收 ==\n";
check($code === 0, "干净退出 exit=$code");
check(!$fatal, '无 Fatal / Uncaught / Warning');
check(str_contains($n, 'hipty42'), 'F2 进入交互后，echo 输出真的被渲染（真实 shell 在 ViceCode 内运行）');
check(str_contains($n, 'reenterok'), 'Esc 退出捕获再 F2 重新进入后，命令仍生效');
check(str_contains($n, '交互终端已退出'), 'Ctrl+D 退出 shell 后自动退回 runner（出现「交互终端已退出」）');

if ($failed) {
    file_put_contents(__DIR__ . '/pty_interactive_dump.log', $out);
    echo "  (已转储原始输出到 tests/pty_interactive_dump.log)\n";
}
echo $failed ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failed ? 1 : 0);
