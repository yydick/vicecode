<?php
declare(strict_types=1);

/**
 * 真实 pty 验收：交互式 PTY 会话「退出保存 → 重启恢复」。
 *
 * 这是「完整 pty 持久化」的端到端钉死：
 *   运行 A：进 pty → echo MARK_OLD → 退出捕获态（shell 仍活着，emu 仍持有内容）→
 *           Ctrl+Q 干净退出（shutdown 经 saveSession 落盘快照，含 MARK_OLD）。
 *   运行 B（同 VICECODE_CONFIG）：构造末尾 maybeRestore 自动进 pty 恢复态、首帧起新 shell 并
 *           灌入快照 → 断言 MARK_OLD 凭空出现（源只能是恢复快照，而非本次键入）；
 *           再 echo MARK_NEW → 断言新 shell 仍可交互（MARK_NEW 出现）。
 *
 * 关键不变量：重启后旧标记仍在、新标记可用 = 会话延续。
 *
 * 运行：timeout 120 php tests/pty_session.php
 */

$cfgFile = tempnam(sys_get_temp_dir(), 'vc_sesscfg');
file_put_contents($cfgFile, (string) json_encode(['persistSession' => true]));
$sessionFile = dirname($cfgFile) . '/.vicecode_session';
@unlink($sessionFile); // 确保从干净状态开始

$env = array_merge(getenv(), [
    'COLUMNS' => '120',
    'LINES' => '40',
    'APP_LOCALE' => 'zh_CN',
    'VICECODE_CONFIG' => $cfgFile,
]);
$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];

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

/**
 * 跑一次 bin/vicecode.php，按序列喂键、排空输出，返回原始输出与退出码。
 * @param array<array{0:string,1:int}> $seq
 */
function runApp(string $bin, array $env, array $descs, array $seq): array
{
    global $readPty;
    $proc = proc_open([PHP_BINARY, $bin], $descs, $pipes, null, $env);
    if ($proc === false) {
        return ['out' => '', 'code' => -1, 'proc' => null];
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);
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
    // 兜底退出
    $guard = 0;
    while (proc_get_status($proc)['running'] && $guard < 6) {
        $feed($guard % 2 === 0 ? "\x1b" : "\x11");
        usleep(200000);
        $drain();
        $guard++;
    }
    $code = proc_close($proc);
    return ['out' => $out, 'code' => $code, 'proc' => $proc];
}

$bin = __DIR__ . '/../bin/vicecode.php';
$seqA = [
    ["\t", 700000],                 // sidebar -> editor
    ["\t", 700000],                 // editor -> terminal
    ["\x1bOQ", 1800000],            // F2：进入交互式 PTY（捕获态）；等 shell 启动
    ["echo MARK_OLD\r", 1200000],   // 捕获态键入
    ["\x1b", 600000],               // Esc：退出捕获（shell 仍在跑，emu 仍持有内容）
    ["\x11", 1800000],              // Ctrl+Q：干净退出 → shutdown → saveSession 落盘
];
$seqB = [
    ["\t", 700000],                 // sidebar -> editor
    ["\t", 700000],                 // editor -> terminal（焦点到终端；恢复态已 captured）
    ["echo MARK_NEW\r", 1500000],   // 新 shell 仍交互
    ["\x04", 2000000],              // Ctrl+D：shell 退出 → 退回 runner
    ["\x1b", 800000],               // Esc：退出应用
];

$ra = runApp($bin, $env, $descs, $seqA);
$sessionSaved = is_file($sessionFile);
$rb = runApp($bin, $env, $descs, $seqB);
$sessionConsumed = !is_file($sessionFile);

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

$na = normalize($ra['out']);
$nb = normalize($rb['out']);
$fatalA = str_contains($ra['out'], 'Fatal error') || str_contains($ra['out'], 'Uncaught');
$fatalB = str_contains($rb['out'], 'Fatal error') || str_contains($rb['out'], 'Uncaught');

echo "== 运行 A：退出保存 ==\n";
check($ra['code'] === 0, "干净退出 exit=$ra[code]");
check(!$fatalA, '无 Fatal / Uncaught');
check(str_contains($na, 'markold'), 'echo MARK_OLD 已渲染');
check($sessionSaved, '退出后快照文件已落盘（saveSession 生效）');

echo "\n== 运行 B：重启恢复 ==\n";
check($rb['code'] === 0, "干净退出 exit=$rb[code]");
check(!$fatalB, '无 Fatal / Uncaught');
check(str_contains($nb, 'markold'), 'MARK_OLD 凭空出现（本次运行未键入，只能源于恢复快照）');
check(str_contains($nb, 'marknew'), '新 shell 仍可交互：MARK_NEW 已渲染');
check($sessionConsumed, '恢复后快照已消费（文件清除），不会二次恢复');

@unlink($cfgFile);
@unlink($sessionFile);

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);
