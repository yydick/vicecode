<?php
declare(strict_types=1);

/**
 * M1 边界 + 光标精度用例（无 tty，逐 cell 校验）。
 * 专治 M0 那类「headless 绿但真实有 bug」：极小视口、超长行、多行、空文件、CJK、null buffer 滚轮。
 * 关键：渲染进 TuiBuffer 后读 Cell.modifiers，断言编辑器光标 REVERSED 落在正确列（不溢出到面板外）。
 *
 * 运行：php tests/m1_edge.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Editor\Buffer;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Display\Cell;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;
use PhpTui\Tui\Widget\Margin;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Position\Position;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;

putenv('APP_LOCALE=en'); // 仅测逻辑，语言无关

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

function render(App $app, Area $vp): TuiBuffer
{
    $ext = new CoreExtension();
    $r = [];
    foreach ($ext->widgetRenderers() as $x) {
        $r[] = $x;
    }
    $renderer = new AggregateWidgetRenderer($r);
    $buf = TuiBuffer::empty($vp);
    $renderer->render($renderer, $app->render($vp), $buf, $buf->area());
    return $buf;
}

/** 在编辑器区域找 REVERSED 单元格；返回 [x,y] 或 null */
function findReversed(TuiBuffer $buf, Area $editor): ?array
{
    $inner = $editor->inner(new Margin(1, 1));
    for ($y = $inner->position->y; $y < $inner->bottom(); $y++) {
        for ($x = $inner->position->x; $x < $inner->right(); $x++) {
            $cell = $buf->get(new Position($x, $y));
            if (($cell->modifiers & Modifier::REVERSED) !== 0) {
                return [$x, $y];
            }
        }
    }
    return null;
}

// ───────── 1) 光标精度：短行，col=1 ─────────
echo "== 光标反显列精度 ==\n";
$app = new App();
$b = Buffer::empty('t');
$b->lines = ['abc', 'defg'];
$b->cursorRow = 0;
$b->cursorCol = 1; // 在 'b' 上
$app->buffer = $b;
$app->focusIndex = array_search('editor', App::PANELS);
$vp = Area::fromDimensions(120, 40);
$ed = $app->areas($vp)['editor'];
$inner = $ed->inner(new Margin(1, 1));
$gutterW = min($inner->width, $b->maxLineNoWidth + 1);
$expectedX = $inner->position->x + $gutterW + 1; // curRel=1, scrollLeft=0
$expectedY = $inner->position->y;
$buf = render($app, $vp);
$pos = findReversed($buf, $ed);
check($pos !== null, '编辑器内有 REVERSED 光标单元格');
if ($pos !== null) {
    check($pos[0] === $expectedX && $pos[1] === $expectedY, "光标落在 (x=$expectedX,y=$expectedY)，实际 (" . $pos[0] . ',' . $pos[1] . ')');
    check($pos[0] < $ed->right() - 1, '光标不溢出到右边框之外');
}

// ───────── 2) 超长单行：横向滚动后光标仍在可视区内 ─────────
echo "== 超长单行横向滚动 ==\n";
$b2 = Buffer::empty('t');
$b2->lines = [str_repeat('x', 200)];
$b2->cursorRow = 0;
$b2->cursorCol = 195;
$app->buffer = $b2;
$buf2 = render($app, $vp);
$pos2 = findReversed($buf2, $ed);
check($pos2 !== null, '超长行光标可见（已横向滚动）');
if ($pos2 !== null) {
    check($pos2[0] >= $inner->position->x && $pos2[0] < $ed->right() - 1, '超长行光标在编辑器内不溢出');
}

// ───────── 3) 多行纵向滚动：翻页不越界 ─────────
echo "== 多行翻页边界 ==\n";
$b3 = Buffer::empty('t');
$b3->lines = array_map('strval', range(1, 500));
$b3->cursorRow = 499;
$b3->cursorCol = 0;
$app->buffer = $b3;
$app->handle(CodedKeyEvent::new(KeyCode::PageUp, 0), $vp);
check($b3->cursorRow > 0 && $b3->cursorRow < 499, 'PageUp 从末行上移且不越界');
$app->handle(CodedKeyEvent::new(KeyCode::PageUp, 0), $vp);
$app->handle(CodedKeyEvent::new(KeyCode::PageUp, 0), $vp);
// 连续翻到顶部
for ($i = 0; $i < 40; $i++) {
    $app->handle(CodedKeyEvent::new(KeyCode::PageUp, 0), $vp);
}
check($b3->cursorRow === 0, '连续 PageUp 停在首行（不越界）');
for ($i = 0; $i < 60; $i++) {
    $app->handle(CodedKeyEvent::new(KeyCode::PageDown, 0), $vp);
}
check($b3->cursorRow === 499, '连续 PageDown 停在末行（不越界）');

// ───────── 4) 极小视口：不崩、无负数宽度 ─────────
// 覆盖到退化尺寸：Grid 分出 0 宽/0 高 cell 会抛 OutOfBoundsException，
// 故布局层有「过小视口直接不画」的守卫；这里把它钉住，防止重构时守卫被丢。
// 40x10 / 20x6 是历史用例，其余为本次补的退化边界（1x1 也必须不崩）。
echo "== 极小视口（含退化尺寸 1x1）==\n";
foreach ([
    Area::fromDimensions(40, 10),
    Area::fromDimensions(20, 6),
    Area::fromDimensions(10, 4),
    Area::fromDimensions(8, 3),
    Area::fromDimensions(5, 3),
    Area::fromDimensions(2, 2),
    Area::fromDimensions(1, 1),
] as $small) {
    $app->buffer = $b;
    $app->focusIndex = array_search('editor', App::PANELS);
    $threw = false;
    try {
        $b2 = render($app, $small);
        // 也应能在编辑器内找到某种输出或至少不溢出崩溃
        $txt = implode("\n", $b2->toLines());
        $ok = strlen($txt) > 0;
    } catch (\Throwable $e) {
        $threw = true;
        $msg = $e->getMessage();
    }
    check(!$threw, '视口 ' . $small->width . 'x' . $small->height . ' 渲染无异常' . ($threw ? " ($msg)" : ''));
}

// ───────── 5) 空文件 / CJK 内容：不崩 ─────────
echo "== 空文件 / CJK ==\n";
$emptyBuf = Buffer::empty('e');
$emptyBuf->lines = [''];
$app->buffer = $emptyBuf;
$threw = false;
try {
    render($app, $vp);
} catch (\Throwable $e) {
    $threw = true;
}
check(!$threw, '空文件渲染无异常');

$cjk = Buffer::empty('c');
$cjk->lines = ['中文测试', 'english'];
$cjk->cursorRow = 0;
$cjk->cursorCol = 2; // 在 '测'
$app->buffer = $cjk;
$threw = false;
try {
    $cb = render($app, $vp);
    $p = findReversed($cb, $ed);
} catch (\Throwable $e) {
    $threw = true;
}
check(!$threw, 'CJK 内容渲染无异常');

// ───────── 6) null buffer 时滚轮 / 键入：null-safe 不崩 ─────────
echo "== null buffer 安全 ==\n";
$app->buffer = null;
$app->focusIndex = array_search('editor', App::PANELS);
$threw = false;
try {
    $app->handle(MouseEvent::new(MouseEventKind::ScrollDown, MouseButton::Left, 35, 5, 0), $vp);
    $app->handle(CharKeyEvent::new('x', 0), $vp);
    render($app, $vp);
} catch (\Throwable $e) {
    $threw = true;
    $msg = $e->getMessage();
}
check(!$threw, 'null buffer 时滚轮/键入不崩' . ($threw ? " ($msg)" : ''));

// ───────── 7) 编辑器点击定位光标（R6） ─────────
echo "== 编辑器点击定位光标 ==\n";
$app2 = new App();
$bc = Buffer::empty('t');
$bc->lines = ['abc', 'defg'];
$bc->cursorRow = 0;
$bc->cursorCol = 0;
$bc->scrollTop = 0;
$bc->scrollLeft = 0;
$app2->buffer = $bc;
$app2->focusIndex = array_search('editor', App::PANELS);
$edA = $app2->areas($vp)['editor'];
$inA = $edA->inner(new Margin(1, 1));
$gw = min(max(0, $inA->width), $bc->maxLineNoWidth + 1);
// 点击第 1 行第 2 个字符（'b'）：x = innerX + gutterW + 1，y = innerY
$clickX = $inA->position->x + $gw + 1;
$clickY = $inA->position->y;
$app2->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $clickX, $clickY, 0), $vp);
check($bc->cursorRow === 0 && $bc->cursorCol === 1, "点击 (x=$clickX,y=$clickY) → 光标 (0,1)，实际 ({$bc->cursorRow},{$bc->cursorCol})");
// 点击第 2 行末尾之后（'defg' 长度 4，点 col=6 应夹到 4）
$clickX2 = $inA->position->x + $gw + 6;
$clickY2 = $inA->position->y + 1;
$app2->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $clickX2, $clickY2, 0), $vp);
check($bc->cursorRow === 1 && $bc->cursorCol === 4, "越界点击夹到行尾 (1,4)，实际 ({$bc->cursorRow},{$bc->cursorCol})");

// ───────── 8) 侧栏 tab 行不溢出内宽（显示列宽预算，防 ] 换行） ─────────
echo "== 侧栏 tab 行不溢出 ==\n";
function lineText(TuiBuffer $buf, Area $area, int $y): string
{
    $inner = $area->inner(new Margin(1, 1));
    $s = '';
    for ($x = $inner->position->x; $x < $inner->right(); $x++) {
        $s .= $buf->get(new Position($x, $y))->char;
    }
    return $s;
}
$appT = new App();
$appT->focusIndex = array_search('sidebar', App::PANELS);
foreach ([Area::fromDimensions(120, 40), Area::fromDimensions(80, 24)] as $vp2) {
    $sbA = $appT->areas($vp2)['sidebar'];
    $innerW2 = max(0, $sbA->width - 2);
    foreach ([0, 1, 2] as $ti) {
        $appT->sidebarTabIndex = $ti;
        $bufT = render($appT, $vp2);
        $t = lineText($bufT, $sbA, $sbA->position->y + 1); // tab 行在 inner 第 0 行
        // php-tui 把宽字符渲染为 2 格（第 2 格是空格占位），故一行占用格数 == 终端显示列宽
        $cells = mb_strlen($t);
        $msg = sprintf(
            'tab=%d @ %dx%d: 占用格数 %d <= 内宽 %d（不溢出/不换行）',
            $ti, $vp2->width, $vp2->height, $cells, $innerW2
        );
        check($cells <= $innerW2, $msg);
    }
}

// ───────── 9) 文件内容边界：Tab 列对齐 / 超长行 / 无末尾换行 / CRLF ─────────
echo "== 文件内容边界 ==\n";
$appE = new App();
$appE->focusIndex = array_search('editor', App::PANELS);
$edE = $appE->areas($vp)['editor'];

// Tab 必须与「等宽普通字符」落在同一显示列：dispWidth("\t")=1，若某处按 0 或 8 计，
// 光标 / 横向滚动 / 文本选择会整体错位，且每个 Tab 累积一列。
$tabBuf = Buffer::empty('t');
$tabBuf->lines = ["\tabc", 'second'];
$tabBuf->cursorRow = 0;
$tabBuf->cursorCol = 1;
$appE->buffer = $tabBuf;
$pTab = findReversed(render($appE, $vp), $edE);
$plainBuf = Buffer::empty('t');
$plainBuf->lines = ['Xabc', 'second'];
$plainBuf->cursorRow = 0;
$plainBuf->cursorCol = 1;
$appE->buffer = $plainBuf;
$pPlain = findReversed(render($appE, $vp), $edE);
check(
    $pTab !== null && $pPlain !== null && $pTab[0] === $pPlain[0],
    'Tab 与等宽普通字符光标列一致（Tab 计 1 列不累积错位）'
        . '：Tab=' . json_encode($pTab) . ' 普通=' . json_encode($pPlain)
);

// 超长单行：渲染必须既不抛错也不卡死（高亮 + 折行都在行内做，长度是真实风险）
$longBuf = Buffer::empty('t');
$longBuf->lines = [str_repeat('x', 50000), 'tail'];
$appE->buffer = $longBuf;
$threw = false;
$t0 = microtime(true);
try {
    render($appE, $vp);
} catch (\Throwable $e) {
    $threw = true;
    $msg = $e->getMessage();
}
$ms = (microtime(true) - $t0) * 1000;
check(!$threw && $ms < 2000, sprintf('50k 字符超长行渲染不崩且耗时 %.0fms < 2000ms', $ms) . ($threw ? " ($msg)" : ''));

$tmp = tempnam(sys_get_temp_dir(), 'vcedge') . '.txt';
file_put_contents($tmp, "a\nb\nc"); // 末尾无换行
$bNoEol = Buffer::fromFile($tmp);
check($bNoEol->lines === ['a', 'b', 'c'], '末尾无换行文件按 3 行载入（不多出空行），实际 ' . json_encode($bNoEol->lines));
$bNoEol->save();
check(file_get_contents($tmp) === "a\nb\nc", '保存后不追加多余换行，实际 ' . json_encode(file_get_contents($tmp)));

file_put_contents($tmp, "a\r\nb\r\n"); // CRLF
$bCrlf = Buffer::fromFile($tmp);
check($bCrlf->lines === ['a', 'b'], 'CRLF 文件载入不残留 \\r，实际 ' . json_encode($bCrlf->lines));
unlink($tmp);

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
exit($failed ? 1 : 0);
