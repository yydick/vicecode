<?php
declare(strict_types=1);

/**
 * 真实 pty 鼠标验收：侧栏文件条目能否用鼠标打开。
 *
 * 为什么必须走 pty：headless 直接构造 MouseEvent 会绕过 SGR 转义解析、鼠标捕获模式
 * （?1000h/?1002h/?1003h/?1006h）与真实终端的按键/点击时序，只有 pty 才能暴露
 * 「事件根本没送达」这一类问题。
 *
 * 断言方式：编辑器标题会渲染成「编辑器 : <文件名>」，归一化后连成「编辑器gitignore」；
 * 侧栏条目只贡献「gitignore」。故断言「编辑器gitignore」出现 = 文件确实被打开进编辑器。
 *
 * 运行：timeout 90 php tests/pty_mouse.php
 */

$env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40']);

/** 归一化：剥 ANSI，只留字母数字与汉字（差分渲染会把整词拆成逐格写入） */
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
 * 跑一轮：喂完字节序列后 Esc 退出，返回归一化后的全部输出。
 * @param string[] $seq
 */
function runOnce(array $env, array $seq, callable $readPty): string
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, 'bin/tui.php'], $descs, $pipes, null, $env);
    if ($proc === false) {
        return '';
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);

    $out = '';
    usleep(400000);                       // 等首帧渲染
    $out .= (string) $readPty($pipes[1], 65536);

    foreach ($seq as [$bytes, $us]) {
        fwrite($pipes[0], $bytes);
        usleep($us);
        $out .= (string) $readPty($pipes[1], 65536);
    }

    fwrite($pipes[0], "\x1b");            // Esc 退出
    usleep(400000);
    $out .= (string) $readPty($pipes[1], 65536);

    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    proc_close($proc);
    return normalize($out);
}

// 0-based (5,13) 是侧栏的 .gitignore 条目（120x40 下：sidebar.y=0，树条目从 y=3 起，
// 第 11 个可见节点 idx=10）。SGR 鼠标坐标是 1-based，故 (6,14)。
$COL = 6;
$ROW = 14;
$press = "\x1b[<0;{$COL};{$ROW}M";
$release = "\x1b[<0;{$COL};{$ROW}m";

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

echo "== 单击（按下 + 释放）==\n";
$out1 = runOnce($env, [[$press, 300000], [$release, 400000]], $readPty);
check(str_contains($out1, 'gitignore'), '画面上出现 .gitignore 条目（鼠标事件到达应用）');
check(str_contains($out1, '编辑器gitignore'), '单击后文件被打开进编辑器（标题含文件名）');

echo "== 双击（按下 + 释放 + 按下 + 释放）==\n";
$out2 = runOnce($env, [
    [$press, 200000], [$release, 200000],
    [$press, 200000], [$release, 400000],
], $readPty);
check(str_contains($out2, '编辑器gitignore'), '双击后文件被打开进编辑器');

echo "== 展开目录后点其中的文件（真实使用路径）==\n";
// 刻意用 bin/ 而不是 src/：这里的坐标只能硬编码（pty 是黑盒，查不到树结构），
// 而 src/ 的子目录数量会随重构变化——本次就因新增 src/Text/ 让 src/App.php 下移一行而失效。
// bin/ 下只放文件、不会再增子目录，且 tui.php 按字母序（'.' < '_'）恒为第一个文件。
// 0-based (5,2)=bin 目录；展开后 bin/tui.php 位于 idx=3 → 0-based y=6 → SGR row=7
$out4 = runOnce($env, [
    ["\x1b[<0;6;6M", 200000], ["\x1b[<0;6;6m", 200000],   // 点 bin 目录（选中）
    ["\r", 300000],                                        // Enter 展开
    ["\x1b[<0;6;7M", 250000], ["\x1b[<0;6;7m", 450000],   // 点 bin/tui.php
], $readPty);
check(str_contains($out4, '编辑器tuiphp'), '展开 bin 后点 tui.php 成功打开进编辑器');

echo "== 单击行首三角应展开（VSCode 习惯）==\n";
// src 在 0-based y=10（SGR row=11）；三角在 0-based x=3（SGR col=4），名称区 x=10（SGR col=11）
$out6 = runOnce($env, [
    ["\x1b[<0;4;11M", 200000], ["\x1b[<0;4;11m", 500000],
], $readPty);
check(str_contains($out6, 'appphp'), '单击 src 行首三角 → 展开（侧栏出现 src/App.php）');
check(!str_contains($out6, '编辑器appphp'), '点三角只展开，不把目录当文件打开');

$out7 = runOnce($env, [
    ["\x1b[<0;11;11M", 200000], ["\x1b[<0;11;11m", 500000],
], $readPty);
check(!str_contains($out7, 'appphp'), '点条目名只选中、不展开（三角命中区未越界）');

echo "== 双击目录应展开（VSCode 习惯）==\n";
// 0-based (5,10) = src 目录 → SGR (6,11)。两次按下间隔必须 < 400ms 才构成双击，
// 故按下/释放之间只等 50ms、两次按下之间 200ms（贴近真实双击节奏）。
$out5 = runOnce($env, [
    ["\x1b[<0;6;11M", 50000], ["\x1b[<0;6;11m", 150000],
    ["\x1b[<0;6;11M", 50000], ["\x1b[<0;6;11m", 500000],
], $readPty);
check(str_contains($out5, 'appphp'), '双击 src 目录 → 展开（侧栏出现 src/App.php）');
check(!str_contains($out5, '编辑器appphp'), '双击目录只展开，不会把目录当文件打开');

echo "== 目录条目单击（应展开而非打开）==\n";
// 0-based (5,3) 是 .docs 目录；SGR 1-based (6,4)
$out3 = runOnce($env, [
    ["\x1b[<0;6;4M", 300000], ["\x1b[<0;6;4m", 400000],
], $readPty);
check(!str_contains($out3, '编辑器docs'), '点目录不会把目录当文件打开');

echo $failed ? "\n结论：鼠标交互存在问题\n" : "\n结论：真实 pty 下鼠标点击侧栏条目正常\n";
exit($failed ? 1 : 0);
