<?php
declare(strict_types=1);

/**
 * 真实 pty 验收：侧栏列表横向滚动后的**渲染内容**与**点击命中**。
 *
 * headless 单测（tests/sidebar_hscroll.php）已覆盖计算逻辑，但这两类 bug 的表现
 * 依赖「真实终端的差分渲染 + SGR 鼠标坐标 + 键盘横滚按键」，必须走 pty 复验：
 *
 *  - B5 双重截断：横滚后行文本本该显示长名的**尾部**，旧实现滚过头就整行空白；
 *  - B6 命中列漏减 hScroll：横滚后点在三角上没反应、点在旧位置（名称区）却 toggle。
 *
 * 判定手法（差分渲染下不能靠「文本消失」，只能靠「重新写入」）：
 *  目录 inner 展开时其子项 innerchild 占一行；折叠 → 该行被擦除（不写字节），
 *  再展开 → 该行重新写入。**同一位置点两次**，第二次之后的输出若含 'innerchild'
 *  说明第一次确实折叠成功（命中三角）。点在名称区则两次都只选中，不会重写子项行。
 *  ⚠️ 两次点击间隔必须 > 400ms，否则会被合成双击（双击目录也会 toggle，对照组就废了）。
 *
 * 运行：timeout 120 php tests/pty_sidebar_hscroll.php
 */

chdir(__DIR__ . '/..');

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use PhpTui\Tui\Display\Area;

// ── 临时目录：一个超长名目录 L（尾部带 TAIL 标记），其下有 inner/ → inner/ 下 innerchild.txt ──
$root = sys_get_temp_dir() . '/vc_ptyh_' . getmypid();
$L = 'd_' . str_repeat('abcd', 20) . 'TAIL'; // 84 列，远超侧栏 innerW(28)
mkdir($root . '/' . $L . '/inner', 0777, true);
file_put_contents($root . '/' . $L . '/inner/innerchild.txt', "x\n");
// 超长名**文件**（尾部 zzend 是唯一标记）+ 一个只用来「承焦点」的目录 anchor
$G = 'g_' . str_repeat('0123456789', 5) . 'zzend.log'; // 61 列，远超 innerW(28)
mkdir($root . '/anchor', 0777, true);
file_put_contents($root . '/' . $G, "y\n");

// ── 探针：按产品自身的布局/树算屏幕坐标（不写死偏移）──
chdir($root);
$probe = new App();
$sb = $probe->areas(Area::fromDimensions(120, 40))['sidebar'];
$sbx = $sb->position->x;
$sby = $sb->position->y;
// 展开 L（与下面的点击序列一致），再按 visible() 顺序取行号
foreach ($probe->sidebar->tree()->roots as $n) {
    if ($n->name === $L && $n->isDir) {
        $n->ensureChildren();
        $n->expanded = true;
    }
}
$rowOf = static function (string $name) use ($probe, $sby): ?int {
    foreach ($probe->sidebar->tree()->visible() as $i => $n) {
        if ($n->name === $name) {
            return $sby + 3 + $i;
        }
    }
    return null;
};
$rowL = $rowOf($L);
$rowInner = $rowOf('inner');
chdir(__DIR__ . '/..');
// 轮 E 用：visible = [L(目录), anchor(目录), G(文件)]（目录在前、按名排序）
$rowAnchor = $sby + 3 + 1;
$rowGFile = $sby + 3 + 2;
$colNameD0 = $sbx + 6; // depth=0 名称列（避开三角，点目录只选中、不展开）

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}
if ($rowL === null || $rowInner === null) {
    echo "  [FAIL] 探针算不出 L / inner 的屏幕行号\n";
    exit(1);
}

// SGR 1-based 列：0-based x = sbx+3+2*depth-hScroll → +1
$colArrowD0 = $sbx + 4;                 // depth=0 三角（hScroll=0）
$colArrowD1 = $sbx + 6;                 // depth=1 三角（hScroll=0）
$colArrowD1Scrolled = $sbx + 2;         // depth=1 三角（hScroll=4 → 屏幕 x=sbx+1）
$SR = "\x1b[1;2C";                      // Shift+→（步进 4 列）

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

/** 条件等待：读到连续 3 次空才收尾（比固定 sleep 稳，也更快） */
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

/** 单击（按下 + 释放）；间隔 > 双击阈值(400ms) 由调用方保证 */
$click = static fn (int $col, int $row0): array => [
    "\x1b[<0;{$col};" . ($row0 + 1) . "M",
    "\x1b[<0;{$col};" . ($row0 + 1) . "m",
];

/**
 * 跑一轮：返回每一步归一化后的输出（键 = 步骤 tag）。
 * @param array<int,array{0:string,1:string,2:?string}> $steps [字节, tag, 后等待方式]
 */
function runOnce(array $env, array $steps, callable $readPty, callable $drain): array
{
    $descs = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
    $proc = proc_open([PHP_BINARY, 'bin/vicecode.php', $GLOBALS['root']], $descs, $pipes, null, $env);
    if ($proc === false) {
        return [];
    }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);

    usleep(600000);
    $res = ['init' => normalize((string) $drain($pipes[1]))];
    foreach ($steps as [$bytes, $tag, $waitUs]) {
        fwrite($pipes[0], $bytes);
        usleep($waitUs);
        $res[$tag] = normalize((string) $drain($pipes[1]));
    }
    if (getenv('VC_DEBUG') !== false) {
        foreach ($res as $tag => $o) {
            echo "    debug[$tag] " . substr((string) $o, 0, 120) . "\n";
        }
    }
    fwrite($pipes[0], "\x11"); // Ctrl+Q 退出
    usleep(300000);
    $drain($pipes[1]);
    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    proc_close($proc);
    return $res;
}

// 内置 clock 插件每秒写时间数字，会穿插进归一化文本 → 全程禁用插件目录
$pluginsDir = __DIR__ . '/../plugins';
$disabledDir = __DIR__ . '/../plugins.disabled_for_hscroll';
$renamed = false;
if (is_dir($pluginsDir) && !is_dir($disabledDir)) {
    $renamed = @rename($pluginsDir, $disabledDir);
}
register_shutdown_function(static function () use ($disabledDir, $pluginsDir): void {
    if (is_dir($disabledDir) && !is_dir($pluginsDir)) {
        @rename($disabledDir, $pluginsDir);
    }
});

$env = array_merge(getenv(), [
    'COLUMNS' => '120',
    'LINES' => '40',
    'APP_LOCALE' => 'zh_CN',
    'VICECODE_CONFIG' => tempnam(sys_get_temp_dir(), 'vc_ptyh'),
]);

$seq = static function (array $clicks, int $extraShiftRight = 0) use ($click, $SR, $rowL, $rowInner, $colArrowD0, $colArrowD1): array {
    $steps = [];
    // 1) 展开长名目录 L（depth=0 三角，hScroll=0）
    foreach ($click($colArrowD0, $rowL) as $i => $b) {
        $steps[] = [$b, 'openL' . $i, 300000];
    }
    // 2) 展开 inner（depth=1 三角，hScroll=0）→ innerchild 行首次写入
    foreach ($click($colArrowD1, $rowInner) as $i => $b) {
        $steps[] = [$b, 'openInner' . $i, 300000];
    }
    // 3) 横滚（可选）
    for ($i = 0; $i < $extraShiftRight; $i++) {
        $steps[] = [$SR, 'sr' . $i, 120000];
    }
    return $steps;
};

/**
 * 一次点击 = 按下(M) + 释放(m) 两步，而 onClick 在**按下**就触发，
 * 故断言要把两步的输出拼起来看（只看出队尾那一步会得到空串）。
 */
$both = static fn (array $out, string $tag): string => ( $out[$tag . '0'] ?? '') . ($out[$tag . '1'] ?? '');

echo "== 基线：hScroll=0 点 depth=1 三角能展开 ==\n";
$outA = runOnce($env, $seq([]), $readPty, $drain);
check(str_contains($both($outA, 'openInner'), 'innerchild'), 'hScroll=0：点 inner 三角 → 子项 innerchild 写入画面');
check(str_contains($both($outA, 'openL'), 'inner'), 'hScroll=0：点 L 三角 → 展开（inner 出现）');

echo "== 横滚后：点三角的「屏幕实际位置」仍能 toggle（B6）==\n";
// 横滚 4 列后 inner 三角从 SGR col sbx+6 移到 sbx+2；同一点两次（间隔 600ms，避开双击）
$stepsB = $seq([], 1);
foreach ($click($colArrowD1Scrolled, $rowInner) as $i => $b) {
    $stepsB[] = [$b, 'hit1' . $i, 600000];
}
foreach ($click($colArrowD1Scrolled, $rowInner) as $i => $b) {
    $stepsB[] = [$b, 'hit2' . $i, 600000];
}
$outB = runOnce($env, $stepsB, $readPty, $drain);
check(str_contains($both($outB, 'openInner'), 'innerchild'), '横滚前：inner 已展开（前置条件成立）');
check(
    str_contains($both($outB, 'hit2'), 'innerchild'),
    '横滚 4 列：点屏幕上的三角位置（col=' . $colArrowD1Scrolled . '）→ 折叠后又展开，子项行重写'
);

echo "== 横滚后：点「旧公式位置」不再 toggle（B6 反例）==\n";
$stepsC = $seq([], 1);
foreach ($click($colArrowD1, $rowInner) as $i => $b) {
    $stepsC[] = [$b, 'old1' . $i, 600000];
}
foreach ($click($colArrowD1, $rowInner) as $i => $b) {
    $stepsC[] = [$b, 'old2' . $i, 600000];
}
$outC = runOnce($env, $stepsC, $readPty, $drain);
check(str_contains($both($outC, 'openInner'), 'innerchild'), '对照组前置条件：inner 同样已展开');
check(
    !str_contains($both($outC, 'old2'), 'innerchild'),
    '横滚 4 列：点旧位置（col=' . $colArrowD1 . '，那里已是名称区）→ 不 toggle（命中列已随 hScroll 偏移）'
);

echo "== 横滚到底：长名目录尾部 TAIL 可见（B5：不是空白行）==\n";
$stepsD = $seq([], 16); // 16×4=64 列，足以滚到最右（maxHScroll-innerW≈61）
$outD = runOnce($env, $stepsD, $readPty, $drain);
$tailSeen = false;
foreach ($outD as $tag => $o) {
    if (str_starts_with((string) $tag, 'sr') && str_contains($o, 'tail')) {
        $tailSeen = true;
        break;
    }
}
check(!str_contains($outD['init'] ?? '', 'tail'), '初始帧看不到长名目录尾部（前置条件：TAIL 在可视区外）');
check($tailSeen, '横滚过程中：长名目录尾部 TAIL 出现在画面（横滚能看到被截掉的内容）');

echo "== 超长文件名：横滚后尾部可见，且点它能打开 ==\n";
$stepsE = [];
foreach ($click($colNameD0, $rowAnchor) as $i => $b) {   // 点 anchor 名称：只选中 + 焦点落到侧栏
    $stepsE[] = [$b, 'anchor' . $i, 300000];
}
for ($i = 0; $i < 9; $i++) {                              // 9×4=36 列，滚到 G 行尾部
    $stepsE[] = [$SR, 'gsr' . $i, 120000];
}
foreach ($click($sbx + 10, $rowGFile) as $i => $b) {      // 点长名文件行（任意列都该打开）
    $stepsE[] = [$b, 'gopen' . $i, 400000];
}
$outE = runOnce($env, $stepsE, $readPty, $drain);
check(!str_contains($outE['init'] ?? '', 'zzend'), '初始帧看不到长名文件尾部（前置条件：zzend 在可视区外）');
$tailSeenG = false;
foreach ($outE as $tag => $o) {
    if (str_starts_with((string) $tag, 'gsr') && str_contains($o, 'zzend')) {
        $tailSeenG = true;
        break;
    }
}
check($tailSeenG, '横滚后：超长文件名尾部 zzend 出现在画面');
check(
    str_contains($both($outE, 'gopen'), '编辑器g0123'),
    '横滚状态下点超长名文件行 → 打开进编辑器（标题「编辑器 : g_0123…」）'
);

exec('rm -rf ' . escapeshellarg($root));
if ($renamed && is_dir($disabledDir) && !is_dir($pluginsDir)) {
    @rename($disabledDir, $pluginsDir);
}

echo $failed ? "\npty 侧栏横滚验收 FAIL\n" : "\npty 侧栏横滚验收全部 PASS\n";
exit($failed ? 1 : 0);
