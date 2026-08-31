<?php
declare(strict_types=1);

/**
 * M6 真实 pty 驱动：在真终端里按 ? 开帮助页、滚动、Esc 关闭、Ctrl+T 切主题、干净退出。
 *
 * 为什么要单开一个 pty 文件（headless 已经在 m6_unit.php 覆盖了）：
 *  1. `?` 在真实终端里是**可打印字符**（CharKeyEvent），而 headless 是我们自己 new 的事件对象；
 *     真实终端是否存在"Shift+/"的差异、会不会被别的分支先吃掉，只有 pty 能验。
 *  2. Ctrl+T / Ctrl+P 这类组合键在真实终端是 0x14 / 0x10 单字节 + CONTROL 修饰位，
 *     与 headless 构造的 CharKeyEvent 路径不同（M5 的 Ctrl+P 已经证明会差）。
 *  3. 帮助页是 CompositeWidget 叠加，真实终端的差分渲染下会不会留残影，
 *     headless 每帧新建 buffer、没有前后帧 diff，测不出来——这个必须用真实 Display。
 *
 * 运行：php tests/pty_m6.php
 */

chdir(__DIR__ . '/..');

require __DIR__ . '/../vendor/autoload.php';

function normalize(string $raw): string
{
    $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $raw);
    $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);
    $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);
    return strtolower((string) preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $s));
}

/**
 * ⚠️ proc_open 的三个 ['pty'] 描述符**共用同一个 pty**（实测：子进程同时写
 * STDOUT/STDERR 时 $pipes[1] 一次读到 "OUTERR"、$pipes[2] 读到 false）。
 * 故这里只读 $pipes[1]，它已经包含了两个流的全部输出。
 */
$readPty = static function ($stream, int $len) {
    set_error_handler(static fn () => true);
    try {
        $str = '';
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline && ($chunk = fread($stream, $len)) !== '' && $chunk !== false) {
            $str .= $chunk;
            if (str_contains($str, 'errno=5') || str_contains($str, 'Input/output error')) {
                break;
            }
            if (strlen($str) > 200000) {
                break;
            }
        }
        return $str;
    } finally {
        restore_error_handler();
    }
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

$env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40']);
$descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
$proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
if ($proc === false) {
    echo "  [FAIL] 无法启动 bin/vicecode.php\n";
    exit(1);
}
stream_set_blocking($pipes[0], false);
stream_set_blocking($pipes[1], false);
usleep(400000);
$readPty($pipes[1], 16384); // 吃掉首帧

// ── 帮助页 ────────────────────────────────────────
echo "== 帮助页（? 唤出）==\n";
fwrite($pipes[0], '?');
usleep(500000);
$help = '';
for ($i = 0; $i < 4; $i++) {
    $help .= normalize($readPty($pipes[1], 65536));
    usleep(150000);
}
check(str_contains($help, '快捷键'), '真实终端里 ? 唤出帮助页（标题「快捷键」可见）');
check(str_contains($help, 'ctrlq'), '帮助页列出 Ctrl+Q');
check(str_contains($help, 'ctrls'), '帮助页列出 Ctrl+S');
check(str_contains($help, 'ctrl'), '帮助页列出 Ctrl+T（切换主题）');
// 覆盖层要能看到底层 UI（Composite 叠加，不是整屏替换）
check(str_contains($help, '编辑器') || str_contains($help, '资源管理器'),
    '帮助页浮在界面之上，底下仍能看到面板标题');

// AI 分组在列表末尾，默认视口看不到——先不断言，等滚到底再验
check(!str_contains($help, 'ctrln'), '默认视口下看不到末尾的 Ctrl+N（说明确实需要滚动）');

// End 滚到底：内容应当变化，且末组（AI）进入视口
fwrite($pipes[0], "\x1b[F"); // End
usleep(400000);
$scrolled = '';
for ($i = 0; $i < 4; $i++) {
    $scrolled .= normalize($readPty($pipes[1], 65536));
    usleep(150000);
}
check(str_contains($scrolled, 'ctrlp') && str_contains($scrolled, 'ctrln'),
    '滚到底后 AI 分组的 Ctrl+P / Ctrl+N 进入视口（滚动真的露出更多内容）');
check(str_contains($scrolled, 'ctrll') || str_contains($scrolled, '清空对话'),
    '滚到底能看到 AI 组的 Ctrl+L 条目');

// Esc 关闭
fwrite($pipes[0], "\x1b");
usleep(500000);
$closed = '';
for ($i = 0; $i < 4; $i++) {
    $closed .= normalize($readPty($pipes[1], 65536));
    usleep(150000);
}
check(!str_contains($closed, 'ctrlt') || !str_contains($closed, '切换配色主题'),
    'Esc 关闭帮助页（键位列不再整屏出现）');
check(str_contains($closed, '编辑器') || str_contains($closed, '终端'),
    '关闭后底层 UI 正常恢复（没有被覆盖层残留）');

// ── 主题切换 ──────────────────────────────────────
echo "== 主题切换（Ctrl+T）==\n";
fwrite($pipes[0], "\x14"); // Ctrl+T = 0x14
usleep(600000);
$themed = '';
for ($i = 0; $i < 4; $i++) {
    $themed .= normalize($readPty($pipes[1], 65536));
    usleep(150000);
}
check(str_contains($themed, '午夜蓝'), 'Ctrl+T 切到午夜蓝（状态栏显示主题名）');

// 主题生效后界面仍正常（不能因为走主题取色就渲染崩）
fwrite($pipes[0], "\x14"); // 再切一次，回到深色
usleep(500000);
$back = '';
for ($i = 0; $i < 4; $i++) {
    $back .= normalize($readPty($pipes[1], 65536));
    usleep(150000);
}
check(str_contains($back, '深色'), '再按 Ctrl+T 回到深色（环形）');
check(str_contains($back, '编辑器') || str_contains($back, '终端'), '切换主题后界面仍正常渲染');

// ── 干净退出 ──────────────────────────────────────
fwrite($pipes[0], "\x11"); // Ctrl+Q
usleep(400000);
$status = proc_get_status($proc);
$deadline = microtime(true) + 5;
while ($status['running'] && microtime(true) < $deadline) {
    usleep(50000);
    $status = proc_get_status($proc);
}
$code = $status['running'] ? -1 : (int) $status['exitcode'];
// 退出前把剩下的输出读掉（含还原序列）
$tail = (string) $readPty($pipes[1], 65536);
proc_close($proc);
check($code === 0, sprintf('干净退出 exit=0（实际 %d）', $code));
check(str_contains($tail, "\x1b[?1049l"), '退出时发出还原序列 ESC[?1049l');

echo $failed ? "\nM6 pty FAIL\n" : "\nM6 pty PASS\n";
exit($failed ? 1 : 0);
