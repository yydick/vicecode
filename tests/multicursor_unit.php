<?php
declare(strict_types=1);

/**
 * 编辑器**多光标**（多行同时编辑）—— 无终端单测。
 *
 * 覆盖：
 *  1) Alt+↑/↓ 加光标（列沿用边界光标、到顶/到底不动、每行最多一个）；
 *  2) 打字同时插入所有光标处，且**每个光标的列各自正确**；
 *  3) 退格/Delete 同时删（含跨行合并时的**行号补偿**）；
 *  4) Enter 在多光标下每行各插一行；
 *  5) Tab/Shift+Tab 同时作用于所有光标行；
 *  6) Esc 先取消多光标（**反面对照**：单光标时 Esc 仍走全局退出）；
 *  7) 渲染：多个 REVERSED 格出现在各自行的正确列；
 *  8) Alt+点击经 App::handle 全链路加光标，且**不产生选区**；
 *  9) 多光标下**不参与**的功能：自动配对、粘贴。
 *
 * 运行：php tests/multicursor_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
putenv('APP_LOCALE=zh_CN');

use App\App;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use App\Text\SpanClip;
use PhpTui\Tui\Widget\Margin;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;

vc_isolate_config('vc_multicursor');

$failed = false;
function check(bool $cond, string $msg): void
{
    global $failed;
    echo ($cond ? '  [OK] ' : '  [FAIL] ') . $msg . "\n";
    if (!$cond) {
        $failed = true;
    }
}

$vp = Area::fromDimensions(120, 40);

$ext = new CoreExtension();
$rs = [];
foreach ($ext->widgetRenderers() as $r) {
    $rs[] = $r;
}
$renderer = new AggregateWidgetRenderer($rs);

/** 开编辑器 + 指定内容，返回 [App, 文件路径] */
function editorWith(string $content): array
{
    $file = vc_tmp_file('vc_mc_file');
    file_put_contents($file, $content);
    $app = new App();
    $app->openFile($file);   // 打开后焦点自动切到 editor
    global $vp;
    $app->render($vp);
    return [$app, $file];
}

function type(App $app, string $text, Area $vp): void
{
    foreach (mb_str_split($text) as $ch) {
        $app->handle(CharKeyEvent::new($ch, 0), $vp);
    }
}

/** 按一个 Coded 键（可带修饰） */
function pressKey(App $app, KeyCode $code, Area $vp, int $mods = 0): void
{
    $app->handle(CodedKeyEvent::new($code, $mods), $vp);
}

function altUp(App $app, Area $vp): void
{
    pressKey($app, KeyCode::Up, $vp, KeyModifiers::ALT);
}

function altDown(App $app, Area $vp): void
{
    pressKey($app, KeyCode::Down, $vp, KeyModifiers::ALT);
}

/** 编辑器区域内所有 REVERSED 格子的 [x,y]（用来断言多光标画了几处、在哪一行哪一列） */
function reversedCells(App $app, Area $vp, AggregateWidgetRenderer $renderer): array
{
    $buf = TuiBuffer::empty($vp);
    $renderer->render($renderer, $app->render($vp), $buf, $buf->area());
    $inner = $app->areas($vp)['editor']->inner(new Margin(1, 1));
    $out = [];
    for ($y = $inner->position->y; $y < $inner->bottom(); $y++) {
        for ($x = $inner->position->x; $x < $inner->right(); $x++) {
            $cell = $buf->get(new Position($x, $y));
            if (($cell->modifiers & Modifier::REVERSED) !== 0) {
                $out[] = [$x, $y];
            }
        }
    }
    return $out;
}

// ═══════════ 1) Alt+↑/↓ 加光标 ═══════════
echo "== Alt+↑/↓ 加光标 ==\n";

[$a1, ] = editorWith("abc\ndef\nghi");
check(!$a1->buffer->hasMultipleCursors(), '前置：单光标');

altDown($a1, $vp);
check(count($a1->buffer->extraCursors()) === 1 && $a1->buffer->extraCursors()[0] === [1, 0],
    'Alt+↓ 在下一行加一个光标（实际 ' . json_encode($a1->buffer->extraCursors()) . '）');
altDown($a1, $vp);
check(count($a1->buffer->extraCursors()) === 2, '再 Alt+↓ 继续往下长（实际 ' . count($a1->buffer->extraCursors()) . ' 个）');

// 到底了：不新增、也不把主光标挪走（主光标仍在第 0 行）
$mainRowBefore = $a1->buffer->cursorRow;
altDown($a1, $vp);
check(count($a1->buffer->extraCursors()) === 2, '到底后 Alt+↓ 不新增光标');
check($a1->buffer->cursorRow === $mainRowBefore,
    '到底后 Alt+↓ 也不会把主光标挪走（实际 row=' . $a1->buffer->cursorRow . '）');

// 到顶同理
[$a2, ] = editorWith("abc\ndef");
altUp($a2, $vp);
check(!$a2->buffer->hasMultipleCursors(), '在第 0 行 Alt+↑ 加不上（不越界造光标）');
check($a2->buffer->cursorRow === 0, '且主光标没有被挪动');

// 每行最多一个：光标在 (0,0) 与 (1,0)，把主光标挪到第 1 行 → 那一行不能出现"两个光标"
[$a3, ] = editorWith("abc\ndef\nghi");
altDown($a3, $vp);                       // extra = [[1,0]]
pressKey($a3, KeyCode::Down, $vp);            // 主光标移到第 1 行（与 extra 同行）
type($a3, 'X', $vp);
check($a3->buffer->lines[1] === 'Xdef',
    '主光标移到已有光标的行后打字：只改一次、不重复插（实际 ' . var_export($a3->buffer->lines[1], true) . '）');

// ═══════════ 2) 打字同时作用于所有光标 ═══════════
echo "== 打字同时插入 ==\n";

[$a4, ] = editorWith("abc\ndef\nghi");
altDown($a4, $vp);
altDown($a4, $vp);
check(count($a4->buffer->extraCursors()) === 2, '前置：3 个光标（主 + 2）');
type($a4, 'X', $vp);
check($a4->buffer->lines === ['Xabc', 'Xdef', 'Xghi'],
    '一次输入同时插到 3 行行首（实际 ' . json_encode($a4->buffer->lines) . '）');
check($a4->buffer->cursorCol === 1 && $a4->buffer->extraCursors() === [[1, 1], [2, 1]],
    '每个光标的列各自右移 1（主 col=' . $a4->buffer->cursorCol . ' extra='
    . json_encode($a4->buffer->extraCursors()) . '）');

// 不同列：Alt+↓ 造出来的光标是同列的，再用 Alt+点击造一个不同列的
[$a5, ] = editorWith("abc\ndef\nghi");
altDown($a5, $vp);                        // extra = [[1,0]]
type($a5, 'Y', $vp);                      // 两行都插 Y：Yabc / Ydef，光标各在 col 1
check($a5->buffer->lines[0] === 'Yabc' && $a5->buffer->lines[1] === 'Ydef', '两行同时插入 Y');

// ═══════════ 3) 退格 / Delete ═══════════
echo "== 退格 / Delete ==\n";

[$a6, ] = editorWith("abc\ndef\nghi");
// 把主光标放到第 0 行行尾（col 3），Alt+↓ 加的光标列沿用主光标列 → 三行都 col 3
pressKey($a6, KeyCode::End, $vp);
check($a6->buffer->cursorCol === 3, '前置：主光标到行尾');
altDown($a6, $vp);
altDown($a6, $vp);
pressKey($a6, KeyCode::Backspace, $vp);
check($a6->buffer->lines === ['ab', 'de', 'gh'],
    '退格同时删掉每行最后一个字符（实际 ' . json_encode($a6->buffer->lines) . '）');

// 跨行合并 + 行号补偿：第 0 行行首 + 第 1 行行首各一个光标，一起退格
[$a7, ] = editorWith("abc\ndef\nghi");
pressKey($a7, KeyCode::Down, $vp);          // 主光标到第 1 行
pressKey($a7, KeyCode::Home, $vp);          // col 0
altUp($a7, $vp);                       // 在第 0 行加光标（col 沿用 0）
check($a7->buffer->extraCursors() === [[0, 0]], '前置：光标在 (0,0) 与 (1,0)');
pressKey($a7, KeyCode::Backspace, $vp);
// 第 1 行行首退格 → 与上一行合并；第 0 行行首退格 → 无事（已是文件开头）
check($a7->buffer->lines === ['abcdef', 'ghi'],
    '行首退格触发跨行合并，且其它行号补偿正确（实际 ' . json_encode($a7->buffer->lines) . '）');

// Delete（行内）
[$a8, ] = editorWith("abc\ndef");
pressKey($a8, KeyCode::Home, $vp);
altDown($a8, $vp);
pressKey($a8, KeyCode::Delete, $vp);
check($a8->buffer->lines === ['bc', 'ef'],
    'Delete 同时删掉每行第一个字符（实际 ' . json_encode($a8->buffer->lines) . '）');

// ═══════════ 4) Enter ═══════════
echo "== Enter（每行各插一行）==\n";

[$a9, ] = editorWith("ab\ncd\nef");
pressKey($a9, KeyCode::Home, $vp);
altDown($a9, $vp);
altDown($a9, $vp);
pressKey($a9, KeyCode::Enter, $vp);
check($a9->buffer->lines === ['', 'ab', '', 'cd', '', 'ef'],
    'Enter 在每个光标处各插一行（实际 ' . json_encode($a9->buffer->lines) . '）');
// 主光标原本在 (0,0)，它在第 0 行插了换行 → 跟到第 1 行（"ab" 那行之前的新空行之后）
check($a9->buffer->cursorRow === 1, '主光标跟到自己那一行的新位置（实际 row=' . $a9->buffer->cursorRow . '）');
// 另外两个光标原在 (1,0)/(2,0)，各自被上面两次插行**累计下移 2 行** → 3 与 5
check($a9->buffer->extraCursors() === [[3, 0], [5, 0]],
    '额外光标被行号补偿推到正确位置 [[3,0],[5,0]]（实际 ' . json_encode($a9->buffer->extraCursors()) . '）');

// ═══════════ 5) Tab / Shift+Tab ═══════════
echo "== Tab / Shift+Tab ==\n";

[$a10, ] = editorWith("ab\ncd");
pressKey($a10, KeyCode::Home, $vp);
altDown($a10, $vp);
pressKey($a10, KeyCode::Tab, $vp);
check($a10->buffer->lines === ['    ab', '    cd'],
    'Tab 同时缩进所有光标行（实际 ' . json_encode($a10->buffer->lines) . '）');
pressKey($a10, KeyCode::BackTab, $vp);
check($a10->buffer->lines === ['ab', 'cd'],
    'Shift+Tab 同时反向缩进（实际 ' . json_encode($a10->buffer->lines) . '）');

// ═══════════ 6) Esc ═══════════
echo "== Esc 语义 ==\n";

[$a11, ] = editorWith("ab\ncd");
altDown($a11, $vp);
check($a11->buffer->hasMultipleCursors(), '前置：多光标');
pressKey($a11, KeyCode::Esc, $vp);
check(!$a11->buffer->hasMultipleCursors(), 'Esc 取消多光标');
check($a11->quit === false, 'Esc 取消多光标时**不会**顺带退出程序');
check($a11->buffer->cursorRow === 0, '取消多光标后主光标位置不变');

// 反面对照：单光标时 Esc 仍走全局退出（不能被编辑器吞掉）
[$a12, ] = editorWith("ab");
pressKey($a12, KeyCode::Esc, $vp);
check($a12->quit === true || $a12->confirm !== null,
    '单光标时 Esc 仍走全局退出流程（实际 quit=' . var_export($a12->quit, true) . '）');

// ⚠️ Esc 有**两条路径**：真实 pty 里孤立 ESC 常被解析成 `CharKeyEvent("\x1b")`，
// 而不是 `CodedKeyEvent(Esc)`。只测 Coded 那条会「headless 全绿但真实终端收不掉多光标」
// —— 这是探针 tests/probe_alt_arrows.php 实测抓到的（A2 同款坑）。两条都要挂、都要测。
[$a12b, ] = editorWith("ab\ncd");
altDown($a12b, $vp);
check($a12b->buffer->hasMultipleCursors(), '前置：多光标');
$a12b->handle(CharKeyEvent::new("\x1b", 0), $vp);
check(!$a12b->buffer->hasMultipleCursors(),
    'Esc 的另一条路径（CharKeyEvent "\\x1b"）也能取消多光标');
check($a12b->quit === false, '这条路径同样不会顺带退出程序');

// ═══════════ 7) 渲染 ═══════════
echo "== 多光标渲染 ==\n";

[$a13, ] = editorWith("ab\ncd\nef");
$one = reversedCells($a13, $vp, $renderer);
check(count($one) === 1, '单光标只画一个反显格（实际 ' . count($one) . ' 个）');
altDown($a13, $vp);
altDown($a13, $vp);
$three = reversedCells($a13, $vp, $renderer);
check(count($three) === 3, '三个光标画三个反显格（实际 ' . count($three) . ' 个）');
$ys = array_column($three, 1);
check(count(array_unique($ys)) === 3, '三个反显格在**三行**上（不同行）');
check(count(array_unique(array_column($three, 0))) === 1, '三格在**同一列**（Alt+↓ 按列对齐）');

// 直接测渲染原语：**同一行两个反显列**都要画出来。
// ⚠️ 这一条不能靠"多光标整屏渲染"来覆盖 —— 「每行最多一个光标」的不变量让显示器上永远不会出现
// 同一行两格，所以整屏断言对"只画第一列"这种退化**根本观察不到**（实测：把循环改成只取第一列，
// 整屏那三条断言照样全绿）。要覆盖它只能直接调 SpanClip。
$twoColSpans = SpanClip::clip([['abcdef', Style::default()]], [1, 4], 0, 20);
$text = '';
$revCols = 0;
foreach ($twoColSpans as $sp) {
    $text .= $sp->content;
    if (($sp->style->addModifiers & Modifier::REVERSED) !== 0) {
        $revCols += mb_strlen($sp->content);
    }
}
check($text === 'abcdef', 'SpanClip 两个反显列不改变文本内容（实际 ' . var_export($text, true) . '）');
check($revCols === 2, '同一行两个反显列**都**被反显（实际 ' . $revCols . ' 列）');
$emptySpans = SpanClip::clip([['ab', Style::default()]], [], 0, 20);
check(implode('', array_map(static fn ($s) => $s->content, $emptySpans)) === 'ab' && $emptySpans[0]->style->addModifiers === 0,
    '空光标列 = 本行无光标（不反显任何格）');

// ═══════════ 8) Alt+点击 ═══════════
echo "== Alt+点击 ==\n";

[$a14, ] = editorWith("ab\ncd");
$ed = $a14->areas($vp)['editor'];
$inner = $ed->inner(new Margin(1, 1));
$textX0 = $inner->position->x + min($inner->width, $a14->buffer->maxLineNoWidth + 1);
$row1 = $inner->position->y + 1;   // 第 2 个内容行（单 buffer 时没有 tab 栏）
$altClick = static fn (MouseEventKind $k, int $c, int $r): MouseEvent
    => MouseEvent::new($k, MouseButton::Left, $c, $r, KeyModifiers::ALT);

$a14->handle($altClick(MouseEventKind::Down, $textX0 + 1, $row1), $vp);
check($a14->buffer->extraCursors() === [[1, 1]],
    'Alt+点击在第 2 行第 2 列加光标（实际 ' . json_encode($a14->buffer->extraCursors()) . '）');
$a14->handle($altClick(MouseEventKind::Up, $textX0 + 1, $row1), $vp);
check(count($a14->buffer->extraCursors()) === 1, 'Alt+点击松手后光标仍在（没被 Up 清掉）');

// 关键：Alt+点击**不能**顺带产生选区（否则拖动一下就把内容复制进剪贴板）
[$a15, ] = editorWith("hello\nworld");
$ed15 = $a15->areas($vp)['editor'];
$inner15 = $ed15->inner(new Margin(1, 1));
$tx = $inner15->position->x + min($inner15->width, $a15->buffer->maxLineNoWidth + 1);
$r0 = $inner15->position->y + 1;   // 第 2 行（不在主光标行上，才能真的加出光标）
$altDrag = static fn (MouseEventKind $k, int $c): MouseEvent
    => MouseEvent::new($k, MouseButton::Left, $c, $r0, KeyModifiers::ALT);
$a15->handle($altDrag(MouseEventKind::Down, $tx), $vp);
$a15->handle($altDrag(MouseEventKind::Drag, $tx + 3), $vp);
$a15->handle($altDrag(MouseEventKind::Up, $tx + 3), $vp);
check($a15->clipboardPeek() === '',
    'Alt+点击后再拖动松手**不产生选区复制**（实际剪贴板 ' . var_export($a15->clipboardPeek(), true) . '）');

// 对照：不带 Alt 的同款手势会复制（证明上面那条断言不是恒真）
[$a16, ] = editorWith("hello\nworld");
$ed16 = $a16->areas($vp)['editor'];
$inner16 = $ed16->inner(new Margin(1, 1));
$tx16 = $inner16->position->x + min($inner16->width, $a16->buffer->maxLineNoWidth + 1);
$r16 = $inner16->position->y + 1;
$plainDrag = static fn (MouseEventKind $k, int $c): MouseEvent
    => MouseEvent::new($k, MouseButton::Left, $c, $r16, 0);
$a16->handle($plainDrag(MouseEventKind::Down, $tx16), $vp);
$a16->handle($plainDrag(MouseEventKind::Drag, $tx16 + 3), $vp);
$a16->handle($plainDrag(MouseEventKind::Up, $tx16 + 3), $vp);
check($a16->clipboardPeek() !== '', '对照：不带 Alt 的同款手势**会**复制（实际 '
    . var_export($a16->clipboardPeek(), true) . '）');

// ═══════════ 9) 多光标下不参与的功能 ═══════════
echo "== 多光标下不参与 ==\n";

// 自动配对：单光标时打 ( 会补 )，多光标时**不补**（避免不同位置产生不同结果）
[$a17, ] = editorWith("ab\ncd");
type($a17, '(', $vp);
check($a17->buffer->lines[0] === '()ab',
    '前置：单光标下自动配对生效（`()` 且光标夹在中间）实际 ' . var_export($a17->buffer->lines[0], true) . '）');

[$a18, ] = editorWith("ab\ncd");
pressKey($a18, KeyCode::Home, $vp);
altDown($a18, $vp);
type($a18, '(', $vp);
check($a18->buffer->lines === ['(ab', '(cd'],
    '多光标下**不做**自动配对，只在各光标处插字（实际 ' . json_encode($a18->buffer->lines) . '）');

// 粘贴：只在主光标处插
[$a19, ] = editorWith("ab\ncd");
pressKey($a19, KeyCode::Home, $vp);
altDown($a19, $vp);
$a19->buffer->insertText('ZZ');   // 粘贴走的就是 insertText
check($a19->buffer->lines === ['ZZab', 'cd'],
    '多光标下粘贴只作用于主光标（不扩散到其它光标）实际 ' . json_encode($a19->buffer->lines) . '）');

echo "\n";
if ($failed) {
    echo "RESULT: FAIL\n";
    exit(1);
}
echo "RESULT: PASS（多光标全部通过）\n";
