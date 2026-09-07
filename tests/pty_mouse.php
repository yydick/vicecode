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

chdir(__DIR__ . '/..');

// ── 探针：用同尺寸布局 + 产品自身的 FileTree 精确算出各树节点的屏幕坐标，不再写死偏移 ──
// 早期版本把 .gitignore 标成 0-based (5,15)（idx=11），但 src/Text/ 等新目录让节点下移、
// idx 变成 12，硬编码坐标随即失效。这里直接读 $tree->visible()（与渲染同款），按 product
// SidebarPanel::content() 的 offset 规则算屏幕行，目录增删都不再影响坐标。
require __DIR__ . '/../vendor/autoload.php';
use App\App;
use PhpTui\Tui\Display\Area;

$probe = new App();
$sb = $probe->areas(Area::fromDimensions(120, 40))['sidebar'];
$sbx = $sb->position->x;
$sby = $sb->position->y;
$tree = $probe->sidebar->tree();
$visible = $tree->visible();
$selIdx = 0;
foreach ($visible as $i => $n) {
    if ($n->path === $probe->selectedPath) {
        $selIdx = $i;
        break;
    }
}
$rowsH = max(0, $sb->height - 4);
$offset = ($selIdx < $rowsH) ? 0 : max(0, $selIdx - $rowsH + 1);
/** 顶层节点名 → 0-based 屏幕行（与 content() 渲染一致：sb.y+3+idx-offset） */
$rowOf = static function (string $name) use ($visible, $sby, $offset): ?int {
    foreach ($visible as $i => $n) {
        if ($n->name === $name) {
            return $sby + 3 + ($i - $offset);
        }
    }
    return null;
};
// depth0 行结构：│» ▶ name/ → 三角命中区 0-based x∈[sbx+3, sbx+4]，名称从 sbx+5 起。
$arrowCol = $sbx + 4; // 点三角：0-based x=sbx+3 → SGR col=sbx+4
$nameCol  = $sbx + 6; // 点名称：0-based x=sbx+5 → SGR col=sbx+6（避开三角，避免误触展开）

$rG   = $rowOf('.gitignore'); // 顶层文件
$rSrc = $rowOf('src');        // 顶层目录（有子目录）
$rBin = $rowOf('bin');        // 顶层目录（只有文件，稳定）
$rDocs = $rowOf('.docs');     // 顶层目录

$env = array_merge(getenv(), ['COLUMNS' => '120', 'LINES' => '40', 'VICECODE_CONFIG' => tempnam(sys_get_temp_dir(), 'vc_mouse')]);

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
    $proc = proc_open([PHP_BINARY, 'bin/vicecode.php'], $descs, $pipes, null, $env);
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

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// 探针对任意顶层节点必须解析出行号，否则说明树结构假设变了，直接报错而非静默错配坐标。
foreach (['.gitignore' => $rG, 'src' => $rSrc, 'bin' => $rBin, '.docs' => $rDocs] as $nm => $rr) {
    if ($rr === null) {
        echo "  [FAIL] 探针找不到顶层节点 {$nm}（visible 数=" . count($visible) . "）\n";
        $failed = true;
    }
}
// SGR 1-based 坐标封装
$seq = static fn (int $col, int $row0): array => [
    ["\x1b[<0;{$col};" . ($row0 + 1) . "M", 250000],
    ["\x1b[<0;{$col};" . ($row0 + 1) . "m", 450000],
];
$seqDbl = static fn (int $col, int $row0): array => [
    ["\x1b[<0;{$col};" . ($row0 + 1) . "M", 50000],
    ["\x1b[<0;{$col};" . ($row0 + 1) . "m", 150000],
    ["\x1b[<0;{$col};" . ($row0 + 1) . "M", 50000],
    ["\x1b[<0;{$col};" . ($row0 + 1) . "m", 450000],
];

echo "== 单击顶层文件（按下 + 释放）→ 打开进编辑器 ==\n";
$out1 = runOnce($env, $seq($nameCol, $rG), $readPty);
check(str_contains($out1, 'gitignore'), '画面上出现 .gitignore 条目（鼠标事件到达应用）');
check(str_contains($out1, '编辑器gitignore'), '单击后文件被打开进编辑器（标题含文件名）');

echo "== 双击顶层文件（按下 + 释放 + 按下 + 释放）==\n";
$out2 = runOnce($env, $seqDbl($nameCol, $rG), $readPty);
check(str_contains($out2, '编辑器gitignore'), '双击后文件被打开进编辑器');

echo "== 展开目录后点其中的文件（真实使用路径）==\n";
// bin 只有文件、顶层位置由探针算出；展开后首个子项 vicecode.php 正好在 bin 下一行。
$out4 = runOnce($env, [
    ...$seq($arrowCol, $rBin),   // 点 bin 三角 → 展开（箭头命中即 toggle，无需再按 Enter）
    ...$seq($nameCol, $rBin + 1), // 点 bin/vicecode.php（展开后紧邻 bin 的下一行）
], $readPty);
check(str_contains($out4, '编辑器vicecodephp'), '展开 bin 后点 vicecode.php 成功打开进编辑器');

echo "== 单击行首三角应展开（VSCode 习惯）==\n";
$out6 = runOnce($env, $seq($arrowCol, $rSrc), $readPty);
check(str_contains($out6, 'appphp'), '单击 src 行首三角 → 展开（侧栏出现 src/App.php）');
check(!str_contains($out6, '编辑器appphp'), '点三角只展开，不把目录当文件打开');

echo "== 单击条目名只选中、不展开（三角命中区未越界）==\n";
$out7 = runOnce($env, $seq($nameCol, $rSrc), $readPty);
check(!str_contains($out7, 'appphp'), '点条目名只选中、不展开（三角命中区未越界）');

echo "== 双击目录应展开（VSCode 习惯）==\n";
// 两次按下间隔 < 400ms 才构成双击，故按下/释放之间只等 50ms、两次按下之间 200ms。
$out5 = runOnce($env, $seqDbl($nameCol, $rSrc), $readPty);
check(str_contains($out5, 'appphp'), '双击 src 目录 → 展开（侧栏出现 src/App.php）');
check(!str_contains($out5, '编辑器appphp'), '双击目录只展开，不会把目录当文件打开');

echo "== 目录条目单击（应只选中而非打开）==\n";
$out3 = runOnce($env, $seq($nameCol, $rDocs), $readPty);
check(!str_contains($out3, '编辑器docs'), '点目录不会把目录当文件打开');

echo $failed ? "\n结论：鼠标交互存在问题\n" : "\n结论：真实 pty 下鼠标点击侧栏条目正常\n";
exit($failed ? 1 : 0);
