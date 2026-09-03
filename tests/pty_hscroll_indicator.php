<?php
declare(strict_types=1);

/**
 * 真实 pty 验收：横向滚动边界指示符 ‹/›（编辑器标题栏）。
 *
 * headless 单测（tests/hscroll_unit.php §8）已验证 EditorPanel::content() 每帧
 * 计算的 hLeft/hRight 逻辑；但「标题栏拼接 + 真实终端渲染」这环必须走 pty 复验
 * （feedback 落差：差分渲染 / 事件类型 / 多字节标题）。
 *
 * 这里不重建整屏（差分渲染下整屏重建容易因多字节错位漏判），改为直接对原始 pty
 * 字节流做断言——本应用里 ‹(E2 80 B9)/›(E2 80 BA) 仅出现在编辑器标题指示符：
 *   - 初始（scrollLeft=0）：原始输出含 ›（右侧还有内容）；
 *   - 滚到最右后：原始输出含 ‹（左侧还有隐藏内容），且「最右那一步」的原始输出
 *     已把 › 擦除（不再含 ›）——证明指示随滚动状态正确翻转。
 *
 * 运行：timeout 120 php tests/pty_hscroll_indicator.php
 */

$env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40', 'APP_LOCALE' => 'en']);
$env['VICECODE_CONFIG'] = tempnam(sys_get_temp_dir(), 'vc_hicfg');

$readPty = static function ($stream, int $len) {
    set_error_handler(static function (int $no, string $str): bool {
        return str_contains($str, 'errno=5') || str_contains($str, 'Input/output error');
    });
    try { return fread($stream, $len); } finally { restore_error_handler(); }
};

$drain = static function ($stream) use ($readPty): string {
    $acc = '';
    $empty = 0;
    while ($empty < 3) {
        $b = $readPty($stream, 65536);
        if ($b === '' || $b === false) {
            $empty++;
        } else {
            $acc .= $b;
            $empty = 0;
        }
        usleep(20000);
    }
    return $acc;
};

function runKeys(array $env, callable $drain, string $file, array $seq): array
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, 'bin/vicecode.php', $file], $descs, $pipes, null, $env);
    if ($proc === false) { return []; }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);

    usleep(600000);
    $res = ['init' => $drain($pipes[1])];
    foreach ($seq as [$bytes, $us, $tag]) {
        fwrite($pipes[0], $bytes);
        usleep($us);
        $res[$tag] = $drain($pipes[1]);
    }
    fwrite($pipes[0], "\x11"); // Ctrl+Q
    usleep(300000);
    $drain($pipes[1]);
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

// 超宽行：200 列。120 宽终端下编辑器可见宽 < 200，初始即溢出（标题含 ›）；
// 滚到最右需 scrollLeft≈(200-可见宽)，发 60 次 Shift+→（步进 4）足以到底。
$line = str_repeat('x', 200);
$tf = tempnam(sys_get_temp_dir(), 'vc_hi');
file_put_contents($tf, $line);

$SR = "\x1b[1;2C"; // Shift+Right
$RIGHT = "\xe2\x80\xba"; // ›
$LEFT  = "\xe2\x80\xb9"; // ‹

echo "== 真实 pty 横向滚动边界指示符 ‹/› ==\n";
$seq = [];
for ($n = 1; $n <= 60; $n++) {
    $seq[] = [$SR, 50000, "r$n"];
}
$out = runKeys($env, $drain, $tf, $seq);
$initRaw = $out['init'] ?? '';
$steps = array_slice($out, 1); // r1..r60
$lastRaw = end($steps);

check($initRaw !== '', '应用启动后有终端输出');
check(str_contains($initRaw, $RIGHT), '初始（scrollLeft=0）：标题渲染 ›（右侧还有内容）');
check(!str_contains($initRaw, $LEFT), '初始（scrollLeft=0）：标题不含 ‹（左侧已到头）');

$anyLeft = false;
foreach ($steps as $s) {
    if (str_contains($s, $LEFT)) { $anyLeft = true; break; }
}
check($anyLeft, '滚动过程中：标题出现 ‹（滚到最右后左侧指示翻转）');
check(!str_contains($lastRaw, $RIGHT), '最右一步：› 已被擦除（标题不再显示右侧指示）');

unlink($tf);

echo $failed ? "\npty 横向滚动边界指示符验收 FAIL\n" : "\npty 横向滚动边界指示符验收全部 PASS\n";
exit($failed ? 1 : 0);
