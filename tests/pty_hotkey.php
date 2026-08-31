<?php
declare(strict_types=1);

/**
 * 真实 pty 退出热键验收。
 *
 * 为什么必须走 pty：headless 直接构造 CharKeyEvent 会绕过两件关键的事——
 *   1) `stty raw` 是否真的放行 0x11（Ctrl+Q 字节是 XON 流控字符，可能被终端驱动吃掉）；
 *   2) EventParser 是否把 0x11 解析成带 CONTROL 修饰的 'q'。
 * 只有真终端里发真实字节才能证明热键可用。
 *
 * 约定（2026-08-29 起）：Ctrl+Q = 退出；Ctrl+C 只保留「中断终端里正在跑的命令」，
 * 不再兼任退出热键（与「复制」冲突，误按就退出了）。
 *
 * 运行：timeout 90 php tests/pty_hotkey.php
 */

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
 * 启动应用、按需发若干步按键，返回 [是否在限定时间内退出, 退出码, 输出]
 * @param string[] $steps 每项 [字节, 之后等待毫秒]
 * @return array{0:bool,1:int,2:string}
 */
$tryHotkey = static function (array $steps, int $waitMs) use ($readPty): array {
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40']));
    if ($proc === false) {
        return [false, -1, ''];
    }
    stream_set_blocking($pipes[1], false);
    usleep(400000);                       // 等首帧渲染

    $out = (string) $readPty($pipes[1], 65536);
    foreach ($steps as [$bytes, $delayMs]) {
        fwrite($pipes[0], $bytes);
        usleep($delayMs * 1000);
        $out .= (string) $readPty($pipes[1], 65536);
    }

    $deadline = microtime(true) + $waitMs / 1000;
    $exited = false;
    while (microtime(true) < $deadline) {
        $out .= (string) $readPty($pipes[1], 65536);
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $exited = true;
            break;
        }
        usleep(50000);
    }
    $out .= (string) $readPty($pipes[1], 65536);

    if (!$exited) {
        // 收尾：先用 q，再兜底强杀，避免 proc_close 永久等待
        fwrite($pipes[0], 'q');
        usleep(300000);
        $out .= (string) $readPty($pipes[1], 65536);
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc, 9);
            usleep(200000);
        }
    }
    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    $code = proc_close($proc);
    return [$exited, $code, $out];
};

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

echo "== Ctrl+Q（0x11）应退出 ==\n";
[$exited, $code, $out] = $tryHotkey([["\x11", 100]], 1500);
check($exited, 'Ctrl+Q 让应用退出（0x11 未被 XON 流控吃掉、被正确解析）');
check($code === 0, 'Ctrl+Q 退出码为 0（干净退出）');
// 退出时应用会还原终端：关闭 alternate screen、取消鼠标捕获、显示光标
check(str_contains($out, "\x1b[?1000l") || str_contains($out, "\x1b[?1002l"), '退出时关闭了鼠标捕获（终端已还原）');
check(str_contains($out, "\x1b[?25h") || str_contains($out, "\x1b[?1049l"), '退出时恢复了光标/alternate screen');

// 关键：editor 焦点下的 Ctrl+Q。此时裸 q 会被当作输入字符（不会退出），
// 只有 Ctrl+Q 专用分支生效——这才是真正验证「Ctrl+Q 退出」而非「q 退出」的场景。
echo "== editor 焦点下 Ctrl+Q 仍应退出（q 在此处只是输入字符）==\n";
[$exitedE, $codeE] = $tryHotkey([["\t", 150], ["\x11", 100]], 1500);
check($exitedE && $codeE === 0, 'editor 焦点下 Ctrl+Q 退出（Ctrl+Q 专用分支生效）');

[$exitedE2] = $tryHotkey([["\t", 150], ['q', 100]], 1200);
check(!$exitedE2, 'editor 焦点下裸 q 不退出（它是输入字符，证明上条确由 Ctrl+Q 分支触发）');

echo "== Ctrl+C（0x03）不应退出（让位给「复制」）==\n";
[$exited2, $code2, $out2] = $tryHotkey([["\x03", 100]], 1200);
check(!$exited2, 'Ctrl+C 不再退出应用（避免误按退出、与复制冲突）');
check(str_contains($out2, 'Ctrl+Q'), '状态栏提示的退出键是 Ctrl+Q');

echo "== q 仍可退出（保留原有手感）==\n";
[$exited3, $code3] = $tryHotkey([['q', 100]], 1500);
check($exited3 && $code3 === 0, 'q 仍能退出且 exit=0');

echo $failed ? "\n结论：热键行为不符合预期\n" : "\n结论：退出热键已切换为 Ctrl+Q，Ctrl+C 不再退出\n";
exit($failed ? 1 : 0);
