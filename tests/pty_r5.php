<?php
declare(strict_types=1);

/**
 * 真实 pty 验收：R5 拖拽分隔条。
 *
 * 为什么必须走 pty：headless 单测（tests/r5_unit.php）已确定性覆盖命中/跟随/clamp/交叉约束，
 * 但这里要确认「真实终端发来的 SGR 拖拽序列（cb=32 的 motion bit → Drag，m 结尾 → Up）
 * 能被 EventParser 正确解析、并驱动 App 改变布局」——这是 headless 构造 MouseEvent 绕不过去的。
 *
 * 断言点选「状态栏尺寸摘要」：拖拽中状态栏固定显示如 "side50 ai45 edit76% input3"（en locale），
 * rebuildScreen 重建最终帧后 grep 该数字，既避开差分渲染的「文本残留」坑，也直接证明布局真的变了。
 *
 * 前提：bin/vicecode.php 已启用 SGR 鼠标报告（tests/pty_menu.php 测试 5「点击 Help 标签」已 PASS
 * 即证明 Down 解析可用；Drag 是同一协议的 motion 变体，EventParser::parseCb 明确支持）。
 *
 * 运行：timeout 120 php tests/pty_r5.php
 */

$env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40', 'APP_LOCALE' => 'en']);

/** ANSI 屏幕重建：同 tests/pty_menu.php，按光标/擦除序列重建最终可见帧（详见该文件注释） */
function rebuildScreen(string $raw, int $w, int $h): string
{
    $grid = array_fill(0, $h, array_fill(0, $w, ' '));
    $r = 0; $c = 0;
    $len = strlen($raw);
    $i = 0;
    while ($i < $len) {
        $ch = $raw[$i];
        if ($ch === "\x1b") {
            if (isset($raw[$i + 1]) && $raw[$i + 1] === '[') {
                $j = $i + 2;
                $params = '';
                while ($j < $len && $raw[$j] !== '' && !ctype_alpha($raw[$j]) && $raw[$j] !== '~') {
                    $params .= $raw[$j]; $j++;
                }
                $cmd = ($j < $len) ? $raw[$j] : '';
                $j++;
                $nums = array_map('intval', explode(';', $params === '' ? '1' : $params));
                switch ($cmd) {
                    case 'H': case 'f':
                        $r = max(0, ($nums[0] ?? 1) - 1); $c = max(0, ($nums[1] ?? 1) - 1); break;
                    case 'A': $r = max(0, $r - ($nums[0] ?? 1)); break;
                    case 'B': $r = min($h - 1, $r + ($nums[0] ?? 1)); break;
                    case 'C': $c = min($w - 1, $c + ($nums[0] ?? 1)); break;
                    case 'D': $c = max(0, $c - ($nums[0] ?? 1)); break;
                    case 'J':
                        if (($nums[0] ?? 0) === 2 || ($nums[0] ?? 0) === 3) { $grid = array_fill(0, $h, array_fill(0, $w, ' ')); }
                        break;
                    case 'K':
                        if (($nums[0] ?? 0) === 0) { for ($k = $c; $k < $w; $k++) { $grid[$r][$k] = ' '; } }
                        elseif (($nums[0] ?? 0) === 1) { for ($k = 0; $k <= $c; $k++) { $grid[$r][$k] = ' '; } }
                        elseif (($nums[0] ?? 0) === 2) { for ($k = 0; $k < $w; $k++) { $grid[$r][$k] = ' '; } }
                        break;
                }
                $i = $j; continue;
            }
            $i++;
            while ($i < $len && !ctype_alpha($raw[$i]) && $raw[$i] !== "\x1b") { $i++; }
            if ($i < $len) { $i++; }
            continue;
        }
        if ($ch === "\r") { $c = 0; $i++; continue; }
        if ($ch === "\n") { $r = min($h - 1, $r + 1); $c = 0; $i++; continue; }
        if ($ch === "\x00" || $ch === "\x08") { $i++; continue; }
        if ($r >= 0 && $r < $h && $c >= 0 && $c < $w) { $grid[$r][$c] = $ch; }
        $c++;
        if ($c >= $w) { $c = 0; $r = min($h - 1, $r + 1); }
        $i++;
    }
    $lines = [];
    foreach ($grid as $row) { $lines[] = rtrim(implode('', $row)); }
    $t = implode("\n", $lines);
    $res = preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $t);
    return $res === null ? strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $t)) : $res;
}

$readPty = static function ($stream, int $len) {
    set_error_handler(static function (int $no, string $str): bool {
        return str_contains($str, 'errno=5') || str_contains($str, 'Input/output error');
    });
    try { return fread($stream, $len); } finally { restore_error_handler(); }
};

/**
 * 跑一个拖拽序列：Down → Drag → Up，分别捕获每步后的原始输出。最后 Ctrl+Q 退出。
 * @param array<int,array{0:string,1:int,2:string}> $seq [bytes, us, tag]
 * @return array<string,string> tag => 该步读取到的原始输出
 */
function runDrag(array $env, callable $readPty, array $seq): array
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
    if ($proc === false) { return []; }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);

    usleep(500000);
    $res = ['init' => (string) $readPty($pipes[1], 65536)];
    foreach ($seq as [$bytes, $us, $tag]) {
        fwrite($pipes[0], $bytes);
        usleep($us);
        $res[$tag] = (string) $readPty($pipes[1], 65536);
    }
    fwrite($pipes[0], "\x11"); // Ctrl+Q
    usleep(300000);
    $readPty($pipes[1], 65536);
    foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
    proc_close($proc);
    return $res;
}

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) { $failed = true; }
}

// SGR 鼠标（1-based 坐标，EventParser 会减 1）：cb=0 左键，cb=32 加 motion bit → Drag，结尾 m = Up
// 视口 120x40：菜单栏 row0；主区 y=1；侧栏宽30(x0..29)、中间列 x30..74、AI x75..119。
$sideDown = "\x1b[<0;31;11M";   // col31(0based30=侧栏右边界) row11(0based10=主区内)
$sideDrag = "\x1b[<32;51;11M";  // 拖到 col51(0based50) → 侧栏宽 50
$sideUp   = "\x1b[<0;51;11m";

$editDown = "\x1b[<0;53;24M";   // col53(0based52，在中间列[30..74]内) row24(0based23，编辑器下边界±1)
$editDrag = "\x1b[<32;53;31M";   // 拖到 row31(0based30) → 比例 (30-1)/38≈0.763 → edit76%
$editUp   = "\x1b[<0;53;31m";

echo "== 1) 真实 pty 拖拽侧栏分隔条（竖拖，宽度 30→50）==\n";
$out = runDrag($env, $readPty, [
    [$sideDown, 200000, 'down'],
    [$sideDrag, 350000, 'drag'],
    [$sideUp,   200000, 'up'],
]);
$screen = rebuildScreen($out['drag'] ?? '', 120, 40);
check(isset($out['drag']), '拖拽中能读到终端输出');
check(str_contains($screen, 'side50'), "拖拽中状态栏显示侧栏宽 50（实际含 'side50'：{$screen}）");

echo "== 2) 真实 pty 拖拽编辑器/终端分隔条（横拖，比例 60%→76%）==\n";
$out2 = runDrag($env, $readPty, [
    [$editDown, 200000, 'down'],
    [$editDrag, 350000, 'drag'],
    [$editUp,   200000, 'up'],
]);
$screen2 = rebuildScreen($out2['drag'] ?? '', 120, 40);
check(isset($out2['drag']), '拖拽中能读到终端输出');
check(str_contains($screen2, 'edit76'), "拖拽中状态栏显示编辑器比例 76%（实际含 'edit76'：{$screen2}）");

echo $failed ? "\npty R5 验收 FAIL\n" : "\npty R5 验收全部 PASS\n";
exit($failed ? 1 : 0);
