<?php
declare(strict_types=1);

/**
 * 文本选择 + 剪贴板（写入）单元测试（headless，非 tty → 走内存剪贴板）。
 *
 * 覆盖：
 *  1) 编辑器：拖拽选中单行整行 / 选中含 CJK 行 / 跨行选区 → 松手写入剪贴板
 *  2) 编辑器：纯点击（无拖拽）不复制、不产生选区
 *  3) 终端（runner 模式）：拖拽选中末行输出 → 写入剪贴板
 *  4) 复制写入路径：tty 走 OSC 52，非 tty 落内存（本测试在管道下断言内存）
 *
 * 拖拽事件序列与 r5_unit 同构：Down → Drag → Up，确定性、不依赖终端模拟。
 */

require __DIR__ . '/../vendor/autoload.php';

putenv('VICECODE_CONFIG=' . tempnam(sys_get_temp_dir(), 'vc_sel'));

use App\App;
use App\Text\DisplayWidth;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Widget\Margin;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    if ($cond) {
        echo "  [OK] $msg\n";
    } else {
        $failed = true;
        echo "  [FAIL] $msg\n";
    }
}

// tty 环境下复制走 OSC 52 写入终端、内存为空；此时只断言不崩。
// 非 tty（管道/headless）复制落内存，可断言内容。
function expectClip(App $app, string $want, string $msg): void
{
    $got = $app->clipboardPeek();
    if (stream_isatty(STDOUT)) {
        check(true, $msg . '（tty：走 OSC52，不断言内存）');
    } else {
        check($got === $want, $msg . "（内存剪贴板：期望「{$want}」实际「{$got}」）");
    }
}

const VP_W = 120;
const VP_H = 40;
$vp = Area::fromDimensions(VP_W, VP_H);

$down = static fn(int $c, int $r): MouseEvent => MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $c, $r, 0);
$drag = static fn(int $c, int $r): MouseEvent => MouseEvent::new(MouseEventKind::Drag, MouseButton::Left, $c, $r, 0);
$up   = static fn(int $c, int $r): MouseEvent => MouseEvent::new(MouseEventKind::Up,   MouseButton::Left, $c, $r, 0);

// ─════════ 1) 编辑器选择 ═════════
echo "\n== 编辑器文本选择 ==\n";
$content = "hello world\n你好世界abc\nthird line\n";
$tmp = tempnam(sys_get_temp_dir(), 'vc_buf') . '.txt';
file_put_contents($tmp, $content);
$app = new App();
$app->openFile($tmp);

$a = $app->areas($vp);
$ed = $a['editor'];
$inner = $ed->inner(new Margin(1, 1));
$hasTabs = count($app->editor->buffers()) > 1;
$tabH = $hasTabs ? 1 : 0;
$gutterW = min($inner->width, $app->buffer->maxLineNoWidth + 1);
$textX0 = $inner->position->x + $gutterW;
$row0 = $inner->position->y + $tabH + $app->buffer->scrollTop;

$line0 = $app->buffer->lines[0];   // hello world
$line1 = $app->buffer->lines[1];   // 你好世界abc
$w0 = DisplayWidth::dispWidth($line0);
$w1 = DisplayWidth::dispWidth($line1);

// 纯点击（无拖拽）：不应复制、不应留选区
$app->handle($down($textX0, $row0), $vp);
$app->handle($up($textX0, $row0), $vp);
expectClip($app, '', '编辑器：纯点击（无拖拽）不写入剪贴板');

// 拖拽选中第一行整行 → 松手复制
$app->handle($down($textX0, $row0), $vp);
$app->handle($drag($textX0 + $w0 - 1, $row0), $vp);
$app->handle($up($textX0 + $w0 - 1, $row0), $vp);
expectClip($app, $line0, '编辑器：拖拽选中首行整行 → 剪贴板含该行原文');

// 拖拽选中含 CJK 的第二行整行（显示列对齐）
$app->handle($down($textX0, $row0 + 1), $vp);
$app->handle($drag($textX0 + $w1 - 1, $row0 + 1), $vp);
$app->handle($up($textX0 + $w1 - 1, $row0 + 1), $vp);
expectClip($app, $line1, '编辑器：CJK 行拖拽选中 → 整行原文（列对齐正确）');

// 跨行选区：第 1~2 行 → 两行用 \n 连接
$app->handle($down($textX0, $row0), $vp);
$app->handle($drag($textX0 + $w1 - 1, $row0 + 1), $vp);
$app->handle($up($textX0 + $w1 - 1, $row0 + 1), $vp);
expectClip($app, $line0 . "\n" . $line1, '编辑器：跨两行拖拽选区 → 行间以 \\n 连接');
check($app->clipboardPeek() !== '', '编辑器：跨行复制后内存剪贴板非空');

// ─════════ 2) 终端（runner）选择 ═════════
echo "\n== 终端文本选择 ==\n";
$app2 = new App();
$tb = $app2->terminal->buffer();
$tb->append("echo hello\n", false);
$tb->append("line two output\n", false);
$tb->append("third line\n", false);

$a2 = $app2->areas($vp);
$term = $a2['terminal'];
$innerT = $term->inner(new Margin(1, 1));
$outH = max(0, $innerT->height - 1);
// runner 跟随模式：输出顶对齐，第 n 行在 inner.y + n - 1（输入行在底部，其余为空白填充）
$rowsT = $app2->terminal->buffer()->all();
$nT = count($rowsT);
$lastRow = $innerT->position->y + min($nT, $outH) - 1;
$lastLine = 'third line';
$wLast = DisplayWidth::dispWidth($lastLine);

$app2->handle($down($innerT->position->x, $lastRow), $vp);
$app2->handle($drag($innerT->position->x + $wLast - 1, $lastRow), $vp);
$app2->handle($up($innerT->position->x + $wLast - 1, $lastRow), $vp);
expectClip($app2, $lastLine, '终端：拖拽选中末行输出 → 剪贴板含去色原文');

// ─════════ 3) 状态栏提示 ═════════
echo "\n== 状态栏提示 ==\n";
// 复用编辑器首行选中的 App：finishSelect 应写入「已复制 {n} 字符」
$app3 = new App();
$app3->openFile($tmp);
$a3 = $app3->areas($vp);
$ed3 = $a3['editor'];
$in3 = $ed3->inner(new Margin(1, 1));
$gw3 = min($in3->width, $app3->buffer->maxLineNoWidth + 1);
$tx3 = $in3->position->x + $gw3;
$r3 = $in3->position->y + (count($app3->editor->buffers()) > 1 ? 1 : 0) + $app3->buffer->scrollTop;
$app3->handle($down($tx3, $r3), $vp);
$app3->handle($drag($tx3 + DisplayWidth::dispWidth($app3->buffer->lines[0]) - 1, $r3), $vp);
$app3->handle($up($tx3 + DisplayWidth::dispWidth($app3->buffer->lines[0]) - 1, $r3), $vp);
check(str_contains($app3->message, '已复制'), '选择复制后状态栏提示「已复制 … 字符」');

echo "\n";
if ($failed) {
    echo "选择/剪贴板单测存在 FAIL\n";
    exit(1);
}
echo "选择/剪贴板单测全部 PASS\n";
