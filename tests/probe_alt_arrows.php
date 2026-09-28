<?php
declare(strict_types=1);

/**
 * 探针（不进跑批）：真实 pty 里验证 **Alt+↓ 的字节能端到端加出编辑光标**。
 *
 * 为什么单开一个探针：headless 单测（`tests/multicursor_unit.php`）是直接构造
 * `CodedKeyEvent(Up/Down, KeyModifiers::ALT)` 的，**跳过了「终端发的字节 → 解析器」这一段**。
 * 而这一段正是本项目踩过坑的地方：Alt+**字母**在真实终端不可用（`pty_menu.php` 实测），
 * Alt+方向键走的是 xterm 的 `;N` 修饰位，是**另一条**路径，必须单独验。
 *
 * 断言方式：不看反显格（`vc_rebuild_screen` 归一化后只剩字符），看**文本效果** ——
 * Alt+↓ 加出光标后打一个字符，屏幕上应同时出现两行被改过的内容。
 *
 * 运行：php tests/probe_alt_arrows.php
 */

chdir(__DIR__ . '/..');
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
require __DIR__ . '/lib/pty_screen.php';

$cfg = vc_isolate_config('vc_probe_alt');
$file = vc_tmp_file('vc_probe_alt_file', '.txt');
file_put_contents($file, "aaa\nbbb\nccc\n");

const W = 120;
const H = 40;

$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$env = array_merge(getenv(), ['COLUMNS' => (string) W, 'LINES' => (string) H, 'VICECODE_CONFIG' => $cfg]);
$proc = proc_open([PHP_BINARY, 'bin/vicecode.php', $file], $descs, $pipes, getcwd(), $env);
if ($proc === false) {
    fwrite(STDERR, "无法启动\n");
    exit(1);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$readPty = static function ($stream, int $len) {
    set_error_handler(static fn (int $no, string $str): bool
        => str_contains($str, 'errno=5') || str_contains($str, 'Input/output error'));
    try {
        return fread($stream, $len);
    } finally {
        restore_error_handler();
    }
};

$out = '';
/** 边收边重建最终帧，等归一化屏幕里出现 $needle */
$waitFor = static function (string $needle, float $seconds) use ($pipes, $readPty, &$out): bool {
    $deadline = microtime(true) + $seconds;
    while (true) {
        $r = [$pipes[1], $pipes[2]];
        $w = $e = [];
        if (stream_select($r, $w, $e, 0, 50000) > 0) {
            foreach ([1, 2] as $fd) {
                $chunk = $readPty($pipes[$fd], 8192);
                if (is_string($chunk) && $chunk !== '') {
                    $out .= $chunk;
                }
            }
        }
        if (str_contains(vc_rebuild_screen($out, W, H), $needle)) {
            return true;
        }
        if (microtime(true) >= $deadline) {
            return false;
        }
    }
};

$ok = true;
// ① 正向锚点：文件内容先渲染出来（否则后面"没变化"的判据都是空转）
if (!$waitFor('aaa', 8.0)) {
    echo "  [FAIL] 前置：编辑器没渲染出文件内容\n";
    $ok = false;
} else {
    echo "  [OK] 前置：编辑器载入了文件（屏幕上有 aaa）\n";
}

// ② Alt+↓（xterm 序列 ESC[1;3B）→ 在第 2 行加一个光标
fwrite($pipes[0], "\x1b[1;3B");
usleep(400000);

// ③ 打一个字符 → 两行都应被改到（单光标只会改一行）
fwrite($pipes[0], 'X');
if ($waitFor('xaaa', 5.0) && str_contains(vc_rebuild_screen($out, W, H), 'xbbb')) {
    echo "  [OK] Alt+↓ 端到端加出光标：一次输入同时改了第 1、2 行（xAaa/xBbb 同现）\n";
} else {
    $screen = vc_rebuild_screen($out, W, H);
    echo "  [FAIL] 没看到两行同时被改（Alt+↓ 的字节没能加出光标）\n";
    echo "         屏幕里含 xaaa：" . var_export(str_contains($screen, 'xaaa'), true)
        . "，含 xbbb：" . var_export(str_contains($screen, 'xbbb'), true) . "\n";
    $ok = false;
}

// ④ Esc 取消多光标后，再打一个字符**只改一行**（反向可失败：证明 ③ 的两行确实是多光标造成的）
fwrite($pipes[0], "\x1b");        // Esc：先取消多光标（不是退出程序）
usleep(400000);                   // 孤立 ESC 要等解析器的空闲冲刷（~150ms）才成为事件
fwrite($pipes[0], 'Y');
// ⚠️ 必须通过 waitFor 读一遍输出 —— 直接 usleep 再重建帧拿到的还是**旧帧**
//（`$out` 只在 waitFor 里累积），会误判成"Esc 没生效"。
// 断言挑**只可能来自这次输入**的串：主光标在 (0,1)（打完 X 之后）插 Y 得 "XYaaa"，
// 第 2 行必须还是 "Xbbb"。不能去数"整屏有几行含 y"（全屏字符，会把状态栏/侧栏算进去）。
$sawXy = $waitFor('xyaaa', 3.0);
$after = vc_rebuild_screen($out, W, H);
$oneLine = $sawXy && !str_contains($after, 'xybbb');
if ($oneLine) {
    echo "  [OK] Esc 取消多光标后输入只改一行（xyaaa 出现、xybbb 不出现）\n";
} else {
    echo "  [FAIL] Esc 之后输入改到了多行（xyaaa=" . var_export($sawXy, true)
        . " xybbb=" . var_export(str_contains($after, 'xybbb'), true) . "）\n";
    echo "         状态栏是否仍显示多光标："
        . var_export(str_contains($after, '个光标'), true) . "\n";
    $ok = false;
}

// 退出：buffer 是脏的（刚打了 X/Y）→ Ctrl+Q 会弹「确认退出」，要再按 y
fwrite($pipes[0], "\x11");
usleep(500000);
$status = proc_get_status($proc);
if ($status['running']) {
    fwrite($pipes[0], 'y');   // 确认放弃改动并退出
}
$deadline = microtime(true) + 6;
while (microtime(true) < $deadline) {
    $r = [$pipes[1]];
    $w = $e = [];
    stream_select($r, $w, $e, 0, 100000);
    $readPty($pipes[1], 8192);
    if (!proc_get_status($proc)['running']) {
        break;
    }
}
$st = proc_get_status($proc);
if ($st['running']) {
    proc_terminate($proc, SIGKILL);
    echo "  [FAIL] 进程没能干净退出\n";
    $ok = false;
} else {
    echo "  [OK] 干净退出（exit=" . $st['exitcode'] . "）\n";
}
proc_close($proc);
@unlink($file);

echo $ok ? "\n探针结论：Alt+方向键端到端可用\n" : "\n探针结论：FAIL\n";
exit($ok ? 0 : 1);
