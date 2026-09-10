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
use PhpTui\Tui\Widget\Margin;

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

// ─────────────── 8) 横向滚动边界指示 ‹/› ───────────────
echo "== 横向滚动边界指示 ‹/› ==\n";
// 单行 201 列宽（dispWidth=202），textW=18：scrollLeft 合法区间 [0, 184]
$lineI = str_repeat('x', 201);
$ti = tempnam(sys_get_temp_dir(), 'vc_hi');
file_put_contents($ti, $lineI);
$appI = new App();
$appI->openFile($ti);
$bI = $appI->buffer;
$iarea = Area::fromDimensions(22, 5); // inner 20 → gutter 2 → textW 18

// 未横滚：左无、右有
$appI->editor->onScrollH(0); // scrollPinned=true，scrollLeft=0
$appI->editor->content($iarea, false);
check($appI->editor->hLeft === false, '未横滚：左侧指示 ‹ 不显示');
check($appI->editor->hRight === true, '未横滚：右侧指示 › 显示（行宽超出视口）');

// 滚到最右：左有、右无（184 = 202-18，onScrollH 钳到 maxW-1=201 但 content 再钳到 184）
$appI->editor->onScrollH(1000);
$appI->editor->content($iarea, false);
check($appI->editor->hLeft === true, '滚到最右：左侧指示 ‹ 显示（左侧有隐藏内容）');
check($appI->editor->hRight === false, '滚到最右：右侧指示 › 不显示（已到行尾）');
unlink($ti);

// 短行（不溢出）：左右均无指示
$ts = tempnam(sys_get_temp_dir(), 'vc_hs');
file_put_contents($ts, 'short');
$appS = new App();
$appS->openFile($ts);
$appS->editor->onScrollH(0);
$appS->editor->content(Area::fromDimensions(22, 5), false);
check($appS->editor->hLeft === false && $appS->editor->hRight === false, '短行不溢出：左右指示均不显示');
unlink($ts);

// ─────────────── 9) 横滚后点击定位光标（屏幕显示列 → 字符索引要加 scrollLeft）───────────────
echo "== 横滚后点击定位光标 ==\n";
// 100 字符单行，textW=18 → 上界 82，scrollLeft=10 不会被 content() 钳回
$lineClick = str_repeat('abcdefghij', 10);
$t9 = tempnam(sys_get_temp_dir(), 'vc_click');
file_put_contents($t9, $lineClick);
$app9 = new App();
$app9->openFile($t9);
$area9 = Area::fromDimensions(22, 5);            // inner 20 → gutter 2 → textW 18
$inner9 = $area9->inner(new Margin(1, 1));
$gutter9 = min($inner9->width, $app9->buffer->maxLineNoWidth + 1);
$x9 = static fn (int $disp): int => $inner9->position->x + $gutter9 + $disp;
$y9 = $inner9->position->y;                      // 单文件无 tab 栏 → 可视行 0

$app9->editor->onScrollH(0);
$app9->editor->onClick(Position::at($x9(3), $y9), ['editor' => $area9]);
check($app9->buffer->cursorCol === 3, '未横滚：点显示列 3 → 字符索引 3');

$app9->editor->onScrollH(10);
$app9->editor->content($area9, false);           // 渲染一帧让钳制生效
check($app9->buffer->scrollLeft === 10, '前置条件：scrollLeft=10（100 字符行，上界 82）');
$app9->editor->onClick(Position::at($x9(0), $y9), ['editor' => $area9]);
check($app9->buffer->cursorCol === 10, '横滚 10 列后点显示列 0 → 字符索引 10（定位必须加 scrollLeft）');
$app9->editor->onClick(Position::at($x9(3), $y9), ['editor' => $area9]);
check($app9->buffer->cursorCol === 13, '横滚 10 列后点显示列 3 → 字符索引 13');
unlink($t9);

// CJK：横滚后点汉字列同样要按「显示列 + scrollLeft」换算字符索引
$lineCJK9 = str_repeat('中', 30) . 'END';         // 63 列
$t9c = tempnam(sys_get_temp_dir(), 'vc_click_cjk');
file_put_contents($t9c, $lineCJK9);
$app9c = new App();
$app9c->openFile($t9c);
$inner9c = $area9->inner(new Margin(1, 1));
$gutter9c = min($inner9c->width, $app9c->buffer->maxLineNoWidth + 1);
$app9c->editor->onScrollH(20);                    // 显示列 20 = 第 11 个「中」的起点
$app9c->editor->content($area9, false);
$app9c->editor->onClick(Position::at($inner9c->position->x + $gutter9c + 0, $inner9c->position->y), ['editor' => $area9]);
check($app9c->buffer->cursorCol === 10, 'CJK 横滚 20 列后点显示列 0 → 字符索引 10（不是 0，也不是 20）');
unlink($t9c);

// ─────────────── 10) 超长行（200k）横滚 ───────────────
echo "== 超长行（200k 字符）横滚 ==\n";
$tBig = tempnam(sys_get_temp_dir(), 'vc_big');
file_put_contents($tBig, str_repeat('x', 200000) . 'TAIL');
$appBig = new App();
$appBig->openFile($tBig);
$areaBig = Area::fromDimensions(60, 10);
$innerBig = $areaBig->inner(new Margin(1, 1));
$textWBig = max(0, $innerBig->width - ($appBig->buffer->maxLineNoWidth + 1));

$t0 = microtime(true);
$appBig->editor->onScrollH(300000);              // 远超上界
$appBig->editor->content($areaBig, false);
$ms = (microtime(true) - $t0) * 1000;
check(
    $appBig->buffer->scrollLeft === 200004 - $textWBig,
    '超长行：scrollLeft 钳到「行宽 − 视口宽」（' . $appBig->buffer->scrollLeft . ' = 200004−' . $textWBig . '）'
);
$bBig = TuiBuffer::empty($areaBig);
$renderer->render($renderer, $appBig->editor->content($areaBig, false), $bBig, $bBig->area());
check(str_contains(implode("\n", $bBig->toLines()), 'TAIL'), '超长行：滚到最右后行尾 TAIL 可见（不是空白）');
check($ms < 1000, sprintf('超长行渲染一帧 %.0fms（阈值 1000ms，防性能回归）', $ms));
unlink($tBig);

// ─────────────── 11) 极小视口 + 横滚 ───────────────
echo "== 极小视口下横滚 ==\n";
foreach ([[8, 3], [5, 3], [2, 2]] as [$vw, $vh]) {
    $tf = tempnam(sys_get_temp_dir(), 'vc_tiny');
    file_put_contents($tf, str_repeat('x', 300));
    try {
        $aT = new App();
        $aT->openFile($tf);
        $small = Area::fromDimensions($vw, $vh);
        $aT->editor->onScrollH(1000);
        $bT = TuiBuffer::empty($small);
        $renderer->render($renderer, $aT->editor->content($small, false), $bT, $bT->area());
        check(
            $aT->buffer->scrollLeft >= 0,
            "极小视口 {$vw}x{$vh}：横滚后 scrollLeft={$aT->buffer->scrollLeft} 不为负、渲染不抛异常"
        );
    } catch (Throwable $e) {
        check(false, "极小视口 {$vw}x{$vh}：抛异常 " . $e->getMessage());
    }
    unlink($tf);
}

// ─────────────── 12) 终端长输出：横滚后选区抓取 + 超长行 + 极小视口 ───────────────
echo "== 终端长输出横滚 ==\n";

/**
 * 造一个 runner 模式终端：注入若干行输出，返回 [app, 终端Area, inner]。
 * 渲染一帧让 content() 完成 hScroll 钳制。
 */
$mkTerm = static function (string $out, int $hScroll = 0) use ($vp): array {
    $app = new App();
    $app->focus('terminal');
    $app->terminal->buffer()->append($out, false);
    $area = $app->areas($vp)['terminal'];
    $app->terminal->content($area, true);          // 先渲染：算出上界
    if ($hScroll > 0) {
        $app->terminal->hScroll = $hScroll;
    }
    $app->terminal->content($area, true);          // 再渲染：钳制生效
    return [$app, $area, $area->inner(new Margin(1, 1))];
};

// 12.1 超长输出行（5000 列）→ 上界钳制 + 滚到最右行尾可见
[$apT, $areaT, $innerT] = $mkTerm(str_repeat('z', 5000) . 'TAIL' . "\n", 99999);
$wT = max(0, $innerT->width);
check(
    $apT->terminal->hScroll === 5004 - $wT,
    '终端超长行：hScroll 钳到「行宽 − 视口宽」（' . $apT->terminal->hScroll . ' = 5004−' . $wT . '）'
);
$bT = TuiBuffer::empty($areaT);
$renderer->render($renderer, $apT->terminal->content($areaT, true), $bT, $bT->area());
check(str_contains(implode("\n", $bT->toLines()), 'TAIL'), '终端超长行：滚到最右后行尾 TAIL 可见（不是空白）');

// 12.2 横滚后拖拽选区：抓到的必须是「屏幕列 + hScroll」对应的字符
// 循环 5 次共 180 列，远超终端视口宽 → 横滚才有效（短行上界为 0，会被 content() 钳回）
$lineT = str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 5);
$downE = static fn (int $c, int $r): MouseEvent => MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $c, $r, 0);
$dragE = static fn (int $c, int $r): MouseEvent => MouseEvent::new(MouseEventKind::Drag, MouseButton::Left, $c, $r, 0);
$upE = static fn (int $c, int $r): MouseEvent => MouseEvent::new(MouseEventKind::Up, MouseButton::Left, $c, $r, 0);

$selAt = static function (int $hScroll, int $c0, int $c1) use ($vp, $mkTerm, $lineT, $downE, $dragE, $upE): string {
    [$app, $area, $inner] = $mkTerm($lineT . "\n", $hScroll);
    $row = $inner->position->y;                    // runner 模式：首行输出在 inner.y
    $app->handle($downE($inner->position->x + $c0, $row), $vp);
    $app->handle($dragE($inner->position->x + $c1, $row), $vp);
    $app->handle($upE($inner->position->x + $c1, $row), $vp);
    return (string) $app->clipboardPeek();
};

check($selAt(0, 0, 4) === 'ABCDE', '终端未横滚：拖选列 0..4 → 复制 ABCDE');
check($selAt(10, 0, 4) === 'KLMNO', '终端横滚 10 列：拖选列 0..4 → 复制 KLMNO（抓取必须加回 hScroll）');
check($selAt(26, 0, 3) === '0123', '终端横滚 26 列：拖选列 0..3 → 复制 0123');

// 12.3 极小视口 + 横滚：不崩、hScroll 不为负
foreach ([[8, 3], [5, 3], [2, 2]] as [$vw, $vh]) {
    try {
        [$aS, $areaS] = $mkTerm(str_repeat('q', 300) . "\n", 1000);
        $small = Area::fromDimensions($vw, $vh);
        $bS = TuiBuffer::empty($small);
        $renderer->render($renderer, $aS->terminal->content($small, true), $bS, $bS->area());
        check($aS->terminal->hScroll >= 0, "终端极小视口 {$vw}x{$vh}：横滚后 hScroll={$aS->terminal->hScroll} 不为负、渲染不抛异常");
    } catch (Throwable $e) {
        check(false, "终端极小视口 {$vw}x{$vh}：抛异常 " . $e->getMessage());
    }
}

// 12.4 横滚上界按「可见区最宽行」而不是光标所在行：
// 光标停在短行时，同一屏里的长行也必须能滚过去（旧实现上界按光标行，短行上根本滚不动）
$tMix = tempnam(sys_get_temp_dir(), 'vc_mix');
file_put_contents($tMix, "short\n" . str_repeat('L', 200) . "\n");
$appMix = new App();
$appMix->openFile($tMix);
$areaMix = Area::fromDimensions(22, 5);     // textW = 18
$appMix->buffer->cursorRow = 0;             // 光标停在第 1 行（3 列短行）
$appMix->editor->onScrollH(50);
$appMix->editor->content($areaMix, false);
check(
    $appMix->buffer->scrollLeft === 50,
    '多行文件：光标在短行也能横滚到可见最长行的范围（scrollLeft=' . $appMix->buffer->scrollLeft . '，旧实现会被钳到 0）'
);
$appMix->editor->onScrollH(1000);
$appMix->editor->content($areaMix, false);
check(
    $appMix->buffer->scrollLeft === 200 - 18,
    '多行文件：上界 = 可见最长行宽 − 视口宽（' . $appMix->buffer->scrollLeft . ' = 200−18）'
);
unlink($tMix);

// ─────────────── 结果 ───────────────
echo "\n";
if ($failed) {
    echo "横向滚动单测：存在失败 ✗\n";
    exit(1);
}
echo "横向滚动单测：全部通过 ✓\n";
