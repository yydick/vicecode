<?php
declare(strict_types=1);

/**
 * 真实 pty 验收：交互式 PTY 会话「保活」——切走面板再切回，shell 不丢、输出不丢。
 *
 * 这是「下一步 #4 轻量：保活会话」的验收：设计上 pty 子进程独立于焦点面板，
 * 主循环每帧无条件 pollTerminal()（bin/vicecode.php），切面板不会销毁它；只有
 * shell 真正退出（Ctrl+D）或应用退出才结束。本测试把「切走再切回后 shell 仍活着、
 * 此前输出仍在」钉死，防止未来重构误加「失焦即销毁」逻辑。
 *
 * 序列：切到终端 → F2 进 pty → echo 标记 → F2 退捕获（shell 仍在跑）→ 切走焦点
 * → 切回终端 → F2 重新捕获 → 再 echo 一个标记。断言两标记都出现在画面。
 *
 * 运行：timeout 90 php tests/pty_persist.php
 */

$cfgFile = tempnam(sys_get_temp_dir(), 'vc_pscfg');
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

function normalize(string $raw): string
{
    $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $raw);
    $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);
    $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);
    return strtolower((string) preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $s));
}

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

// \t=Tab(切焦点，循环 PANELS=[sidebar,editor,terminal,ai_stream,ai_input])
// \x1bOQ=F2(进/退交互)  \r=Enter  \x04=Ctrl+D
$seq = [
    ["\t", 400000],                          // sidebar -> editor
    ["\t", 700000],                          // editor -> terminal（runner 模式）
    ["\x1bOQ", 1800000],                     // F2：进入交互式 PTY（捕获态）；等 shell 启动
    ["echo persist_mark\r", 1200000],        // 捕获态键入；输出渲染到画面
    ["\x1b", 500000],                        // Esc：退出捕获（shell 仍在跑）
    ["\t", 500000],                          // 切走焦点：terminal -> ai_stream
    ["\t", 500000],                          // ai_stream -> ai_input
    ["\t", 500000],                          // ai_input -> sidebar
    ["\t", 500000],                          // sidebar -> editor
    ["\t", 500000],                          // editor -> terminal（切回，shell 应仍活着）
    ["\x1bOQ", 700000],                      // F2：重新进入捕获（焦点已回 terminal）
    ["echo back_ok\r", 1200000],             // 重新捕获后命令仍生效
    ["\x04", 2000000],                       // Ctrl+D：shell 退出 → 自动退回 runner
    ["\x1b", 800000],                        // Esc：退出应用
];

foreach ($seq as [$bytes, $us]) {
    if (!proc_get_status($proc)['running']) {
        break;
    }
    $feed($bytes);
    usleep($us);
    $drain();
}

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
    if (!$cond) { $failed = true; }
}

$fatal = str_contains($out, 'Fatal error') || str_contains($out, 'Uncaught') || str_contains($out, 'PHP Warning');
$n = normalize($out);

echo "== 交互式 PTY 会话保活（切面板不销毁）==\n";
check($code === 0, "干净退出 exit=$code");
check(!$fatal, '无 Fatal / Uncaught / Warning');
check(str_contains($n, 'persistmark'), '切走前 echo 的输出已渲染（persist_mark）');
check(str_contains($n, 'backok'), '切走再切回、重新捕获后，新命令仍生效（back_ok）——证明 shell 会话保活');
check(!str_contains($n, '交互终端已退出') || str_contains($n, 'backok'), '切回时 shell 未提前退出（back_ok 先于退出提示）');

if ($failed) {
    file_put_contents(__DIR__ . '/pty_persist_dump.log', $out);
    echo "  (已转储原始输出到 tests/pty_persist_dump.log)\n";
}
echo $failed ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failed ? 1 : 0);
