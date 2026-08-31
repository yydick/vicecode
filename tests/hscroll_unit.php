<?php
declare(strict_types=1);

/**
 * 横向滚动（M4 打磨 + 横向滚动补全）单测（不依赖 tty）：
 *  1) DisplayWidth::mbSubDisp 切片（ASCII 连续 / CJK 边界 / 末尾不足 / skip<=0 退化）；
 *  2) EditorPanel::onScrollH 钳制（上界 maxW-1 / 下界 0）；content() 用 scrollLeft 决定可见窗口；
 *  3) SidebarPanel::onScrollH 改变 hScroll，长路径在滚动后进入视口；
 *  4) TerminalPanel::onScrollH 改变 hScroll，长输出行在滚动后进入视口；
 *  5) App::handleMouse 把 ScrollLeft/ScrollRight 按焦点分发到各面板 onScrollH。
 *
 * 运行：php tests/hscroll_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Editor\Buffer;
use App\Text\DisplayWidth;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Position\Position;

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

// ─────────────── 1) mbSubDisp ───────────────
echo "== mbSubDisp ==\n";
// 纯 ASCII：跳过 4 列后取 4 列，必须连续不丢字符（修复前的 bug 会丢一字符）
check(DisplayWidth::mbSubDisp('abcdefgh', 4, 4) === 'efgh', "ASCII 跳过4取4连续（得 'efgh'，修复前为 'f'）");
check(DisplayWidth::mbSubDisp('abcdef', 0, 3) === 'abc', "ASCII 跳过0取3（得 'abc'）");
// CJK 边界：起点精确落在 skip 边界时直接纳入，不整段跳过
check(DisplayWidth::mbSubDisp('中abc日', 0, 4) === '中ab', "CJK 跳过0取4（得 '中ab'，4 列）");
check(DisplayWidth::mbSubDisp('中abc日', 2, 4) === 'abc', "CJK 跳过2取4（起点精确对齐，得 'abc'）");
check(DisplayWidth::mbSubDisp('中abc日', 4, 3) === 'c日', "CJK 跳过4取3（精确对齐起点，得 'c日'）");
// 末尾不足：请求的列数超出剩余，返回剩余部分
check(DisplayWidth::mbSubDisp('abcdef', 4, 10) === 'ef', "跳过后剩余不足请求列（得 'ef'）");
// 短串
check(DisplayWidth::mbSubDisp('x', 0, 5) === 'x', "短串不越界（得 'x'）");
// skip<=0 退化成 mbCutDisp
check(DisplayWidth::mbSubDisp('hello', -1, 3) === 'hel', "skip<=0 退化为 mbCutDisp（得 'hel'）");
check(DisplayWidth::mbSubDisp('hello', 0, 3) === 'hel', "skip=0 取前3（得 'hel'）");
// disp<=0 返回空
check(DisplayWidth::mbSubDisp('hello', 5, 0) === '', "disp<=0 返回空");

// ─────────────── 渲染工具 ───────────────
$vp = Area::fromDimensions(160, 50);
$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $r) {
    $renderers[] = $r;
}
$renderer = new AggregateWidgetRenderer($renderers);

function paint(App $app, Area $vp, AggregateWidgetRenderer $r): array
{
    $buf = TuiBuffer::empty($vp);
    $r->render($r, $app->render($vp), $buf, $buf->area());
    return $buf->toLines();
}

// ─────────────── 2) EditorPanel::onScrollH + content() ───────────────
echo "== EditorPanel::onScrollH + content() ==\n";
// 单行 201 列：col25='M'、col35='N'，其余 'x'
$line0 = str_repeat('x', 25) . 'M' . str_repeat('x', 9) . 'N' . str_repeat('x', 166);
$tf = tempnam(sys_get_temp_dir(), 'vc_ed');
file_put_contents($tf, $line0);
$app = new App();
$app->openFile($tf);
$buf = $app->buffer;
check($buf !== null && $buf->scrollLeft === 0, 'openFile 后 scrollLeft 初始为 0');

// onScrollH 钳制（不触发 content()，纯字段逻辑）
$app->editor->onScrollH(50);
check($buf->scrollLeft === 50, 'onScrollH(50) 后 scrollLeft=50');
$app->editor->onScrollH(1000);
check($buf->scrollLeft === 201, 'onScrollH 上界钳到 maxW-1=201（行宽 202）');
$app->editor->onScrollH(-5000);
check($buf->scrollLeft === 0, 'onScrollH 下界钳到 0');

// 直接驱动 content() 验证 scrollLeft 决定可见窗口（用小型 Area 控制 textW=18）
$area = Area::fromDimensions(22, 5); // inner 20 → gutter 2 → textW 18
$buf->cursorRow = 0;
$buf->cursorCol = 15;               // 落在 [10,28) 内，content() 不回弹
$app->editor->onScrollH(10);        // scrollLeft=10
$wdg1 = $app->editor->content($area, false);
$b1 = TuiBuffer::empty($area);
$renderer->render($renderer, $wdg1, $b1, $b1->area());
$row1 = $b1->toLines()[0];
check(str_contains($row1, 'M') && !str_contains($row1, 'N'), 'scrollLeft=10：窗口含 M、不含 N');

$buf->cursorCol = 30;               // 落在 [25,43) 内
$app->editor->onScrollH(15);        // scrollLeft=25
$wdg2 = $app->editor->content($area, false);
$b2 = TuiBuffer::empty($area);
$renderer->render($renderer, $wdg2, $b2, $b2->area());
$row2 = $b2->toLines()[0];
check(str_contains($row2, 'N'), 'scrollLeft=25：窗口含 N（横向滚动后可见区右移）');
unlink($tf);

// ─────────────── 3) SidebarPanel::onScrollH（Search tab 长路径）───────────────
echo "== SidebarPanel::onScrollH ==\n";
$app = new App();
$app->sidebar->tabIndex = 2; // SEARCH
$long = str_repeat('a', 120) . 'SIDEMARK';
foreach ([
    'src/' . $long . ':1:foo',
    'src/' . $long . ':2:bar',
] as $ln) {
    $app->search->ingestLine($ln);
}
$app->search->totalMatches = 2;
$app->search->totalFiles = 1;

$before = paint($app, $vp, $renderer);
check(!str_contains(implode("\n", $before), 'SIDEMARK'), '未横向滚动时 SIDEMARK 不在视口内');
$app->sidebar->onScrollH(300); // 跳到最右（content() 钳到 maxHScroll-innerW）
$after = paint($app, $vp, $renderer);
check(str_contains(implode("\n", $after), 'SIDEMARK'), '横向滚动到末尾后 SIDEMARK 进入视口');
check(implode("\n", $before) !== implode("\n", $after), '侧栏横向滚动改变了渲染文本');

// 下界钳制
$app->sidebar->onScrollH(-1000);
check($app->sidebar->hScroll === 0, '侧栏 hScroll 下界钳到 0');

// ─────────────── 4) TerminalPanel::onScrollH（长输出行）───────────────
echo "== TerminalPanel::onScrollH ==\n";
$app = new App();
$app->focus('terminal');
$app->terminal->buffer()->append(str_repeat('y', 150) . 'TERMMARK' . "\n", false);

$tBefore = paint($app, $vp, $renderer);
check(!str_contains(implode("\n", $tBefore), 'TERMMARK'), '终端未横向滚动时 TERMMARK 不在视口内');
$app->terminal->onScrollH(400); // 跳到最右（content() 钳到 maxW-1）
$tAfter = paint($app, $vp, $renderer);
check(str_contains(implode("\n", $tAfter), 'TERMMARK'), '终端横向滚动到末尾后 TERMMARK 进入视口');
// 下界钳制
$app->terminal->onScrollH(-1000);
check($app->terminal->hScroll === 0, '终端 hScroll 下界钳到 0');

// ─────────────── 5) App::handleMouse 分发 ───────────────
echo "== App::handleMouse 分发 ==\n";
$line0 = str_repeat('z', 200);
$tf = tempnam(sys_get_temp_dir(), 'vc_hm');
file_put_contents($tf, $line0);
$app = new App();
$app->openFile($tf);

// 焦点=editor → ScrollRight 增 scrollLeft，ScrollLeft 减（钳 0）
$app->focus('editor');
$app->handle(MouseEvent::new(MouseEventKind::ScrollRight, MouseButton::Left, 0, 0, 0), $vp);
check($app->buffer->scrollLeft === 4, 'editor 焦点收到 ScrollRight：scrollLeft=4');
$app->handle(MouseEvent::new(MouseEventKind::ScrollLeft, MouseButton::Left, 0, 0, 0), $vp);
check($app->buffer->scrollLeft === 0, 'editor 焦点收到 ScrollLeft：scrollLeft 钳回 0');

// 焦点=terminal → onScrollH
$app->focus('terminal');
$app->handle(MouseEvent::new(MouseEventKind::ScrollRight, MouseButton::Left, 0, 0, 0), $vp);
check($app->terminal->hScroll === 4, 'terminal 焦点收到 ScrollRight：hScroll=4');

// 焦点=sidebar → onScrollH
$app->focus('sidebar');
$app->sidebar->tabIndex = 2;
$app->handle(MouseEvent::new(MouseEventKind::ScrollRight, MouseButton::Left, 0, 0, 0), $vp);
check($app->sidebar->hScroll === 4, 'sidebar 焦点收到 ScrollRight：hScroll=4');
unlink($tf);

// ─────────────── 6) 编辑器点击/End/方向键（字符↔显示列修复）───────────────
echo "== 编辑器 点击/End/方向键 ==\n";
// 显示列 → 字符索引（CJK 占 2 列，点击汉字列不能当字符索引用）
check(DisplayWidth::mbDispToCharIndex('中文abc', 0) === 0, "显示列0 → 字符索引0（中）");
check(DisplayWidth::mbDispToCharIndex('中文abc', 1) === 0, "显示列1（中字内）→ 索引0");
check(DisplayWidth::mbDispToCharIndex('中文abc', 2) === 1, "显示列2（文起点）→ 索引1");
check(DisplayWidth::mbDispToCharIndex('中文abc', 3) === 1, "显示列3（文字内）→ 索引1");
check(DisplayWidth::mbDispToCharIndex('中文abc', 4) === 2, "显示列4（a 起点）→ 索引2");
check(DisplayWidth::mbDispToCharIndex('中文abc', 100) === 5, "显示列超尾 → 行末之后（索引5）");

// 点击汉字列：onClick 把显示列换算成字符索引（修 bug：点「文」不再错位成索引2）
$lineCJK = '中文abc';
$tc = tempnam(sys_get_temp_dir(), 'vc_cjk');
file_put_contents($tc, $lineCJK);
$appC = new App();
$appC->openFile($tc);
$areaC = Area::fromDimensions(22, 5); // inner 20 → gutter 2 → textW 18，文本起点 abs x=3
$posC = Position::at(5, 1);       // 显示列 2（「文」起点）= abs x 3+2=5，行 0
$appC->editor->onClick($posC, ['editor' => $areaC]);
check($appC->buffer->cursorCol === 1, '点击「文」所在显示列 → 字符索引 1（不再错位成 2）');
unlink($tc);

// End：CJK 长行按 End 后，行尾应滚动进入视口（修 bug：光标消失/行尾不显示）
$lineEnd = str_repeat('中', 50) . 'END';
$te = tempnam(sys_get_temp_dir(), 'vc_end');
file_put_contents($te, $lineEnd);
$appE = new App();
$appE->openFile($te);
$earea = Area::fromDimensions(22, 5); // textW=18
$appE->editor->onKey(CodedKeyEvent::new(KeyCode::End, 0), ['editor' => $earea]);
check($appE->buffer->scrollLeft > 0, 'CJK 长行按 End：scrollLeft>0（行尾已滚入，光标不再消失）');
$wdgE = $appE->editor->content($earea, false);
$bE = TuiBuffer::empty($earea);
$renderer->render($renderer, $wdgE, $bE, $bE->area());
check(str_contains($bE->toLines()[0], 'END'), 'CJK 长行按 End：行尾 END 进入视口（横向跟随生效）');
unlink($te);

// 滚轮横滚在「光标位于行首」时也持久生效（修 bug：原先每帧被 content() 拉回 0，横滚看不到效果）
$cw = tempnam(sys_get_temp_dir(), 'vc_cw');
file_put_contents($cw, str_repeat('x', 200));
$appW = new App();
$appW->openFile($cw);
$appW->buffer->cursorCol = 0; // 光标在行首
$appW->editor->onScrollH(40); // 滚轮右滚
check($appW->buffer->scrollLeft === 40, '光标在行首也能横滚（scrollPinned 不被清零）');
$appW->editor->content(Area::fromDimensions(22, 5), false); // 渲染一帧
check($appW->buffer->scrollLeft === 40, '横滚后 content() 不清零 scrollLeft（横滚看得到效果）');
unlink($cw);

// ─────────────── 7) 跨行光标移动（行尾右移→下一行首 / 行首左移→上一行尾）───────────────
echo "== 跨行光标移动 ==\n";
$buf = Buffer::fromString('t', "abc\ndef\nghi");
$buf->cursorRow = 0;
$buf->cursorCol = 3; // 行0「abc」末尾
$buf->moveRight();
check($buf->cursorRow === 1 && $buf->cursorCol === 0, '行尾按右 → 下一行开头');
$buf->cursorRow = 1;
$buf->cursorCol = 0; // 行1「def」开头
$buf->moveLeft();
check($buf->cursorRow === 0 && $buf->cursorCol === 3, '行首按左 → 上一行末尾');
$buf->cursorRow = 2;
$buf->cursorCol = 3; // 末行「ghi」末尾
$buf->moveRight();
check($buf->cursorRow === 2 && $buf->cursorCol === 3, '末行行尾右移不越界');
$buf->cursorRow = 0;
$buf->cursorCol = 0; // 首行开头
$buf->moveLeft();
check($buf->cursorRow === 0 && $buf->cursorCol === 0, '首行行首左移不越界');

// ─────────────── 结果 ───────────────
echo "\n";
if ($failed) {
    echo "横向滚动单测：存在失败 ✗\n";
    exit(1);
}
echo "横向滚动单测：全部通过 ✓\n";
