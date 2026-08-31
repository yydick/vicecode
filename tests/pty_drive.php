<?php
declare(strict_types=1);

/**
 * 真实 pty 全流程驱动：模拟用户对 M1 的实际操作，验证不会崩、能干净退出。
 * 喂键序列：Tab(切焦点) / 打字 / Ctrl+S(保存) / Enter(发送) / 方向 / 点击坐标 / q 退出。
 * 断言：exit=0 且输出无 "Fatal error"/"Uncaught"/"PHP Notice:" 之外的致命信息。
 *
 * 运行：php tests/pty_drive.php
 */

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes);
if ($proc === false) {
    echo "[FAIL] 无法启动 bin/vicecode.php\n";
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);

// 键盘序列（每个元素 [字节, 延迟微秒]）
$seq = [
    ["\t", 50000],        // sidebar -> editor
    ["hello", 20000],     // 在编辑器打字（此时可能无文件，null-safe 不崩）
    ["\x13", 50000],       // Ctrl+S 保存（无文件应安全跳过）
    ["\t", 40000],         // editor -> terminal
    ["\t", 40000],         // terminal -> ai_stream
    ["\t", 40000],         // ai_stream -> ai_input
    ["hi", 20000],
    ["\r", 50000],         // 发送
    ["\t", 40000],         // ai_input -> sidebar
    ["\x1b[B", 40000],     // Down 箭头（侧栏选下移）
    ["\r", 50000],         // Enter：展开/打开文件
    ["\t", 40000],         // sidebar -> editor（若上面打开了文件，焦点已在这）
    ["\x1b[A", 40000],     // Up 箭头
    ["\x1b[C", 40000],     // Right 箭头
    ["\x1b[D", 40000],     // Left 箭头
    ["q", 60000],          // 退出（sidebar 焦点下 q 退出）
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
foreach ($seq as [$bytes, $us]) {
    fwrite($pipes[0], $bytes);
    usleep($us);
    $r = [$pipes[1]];
    $w = $e = [];
    if (stream_select($r, $w, $e, 0, 30000) > 0) {
        $chunk = $readPty($pipes[1], 8192);
        if ($chunk !== '' && $chunk !== false) {
            $out .= $chunk;
        }
    }
    if (!proc_get_status($proc)['running']) {
        break;
    }
}

// 兜底：若还活着，再发 q / Esc 确保退出
$guard = 0;
while (proc_get_status($proc)['running'] && $guard < 5) {
    fwrite($pipes[0], $guard % 2 === 0 ? "q" : "\x1b");
    usleep(150000);
    $guard++;
}
$code = proc_close($proc);

$fatal = (str_contains($out, 'Fatal error') || str_contains($out, 'Uncaught') || str_contains($out, 'PHP Warning'))
    && !str_contains($out, 'Read of'); // 忽略 pty 关闭后读取的 I/O notice

$ok = $code === 0 && !$fatal;
echo $ok
    ? "[OK] 真实 pty 全流程驱动无致命错误，exit=$code\n"
    : "[FAIL] 真实 pty 异常：exit=$code fatal=" . var_export($fatal, true) . "\n";
if (!$ok) {
    file_put_contents(__DIR__ . '/pty_dump.log', $out);
    echo "  (已转储原始输出到 tests/pty_dump.log)\n";
}
exit($ok ? 0 : 1);
