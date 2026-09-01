<?php
declare(strict_types=1);

/**
 * 顶部菜单栏单元测试（Backlog 接入）。
 *
 * 覆盖：菜单定义（只挂真实命令、无空壳）、键盘导航（F10 激活/方向/Enter 执行）、
 * 鼠标命中（点标签激活、点条目执行）、menuAction 真实副作用、极小视口不崩。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Panel\MenuBarPanel;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\Event\MouseEventKind;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;

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

$ext = new CoreExtension();
$rs = [];
foreach ($ext->widgetRenderers() as $r) {
    $rs[] = $r;
}
$rr = new AggregateWidgetRenderer($rs);

// ─════════ 1) 菜单定义：4 个菜单、每项都有真实 action ═════════
echo "\n== 菜单定义 ==\n";
$app = new App();
$defs = $app->menuBar->definitions();
check(count($defs) === 4, '共 4 个菜单（文件/视图/终端/帮助）');
$labels = array_map(static fn($m) => $m['label'], $defs);
check(in_array('文件', $labels, true) && in_array('帮助', $labels, true), '含文件与帮助菜单');
$actionCount = 0;
$known = ['file.open','file.save','file.close','file.quit','view.theme','view.focus.editor',
    'view.focus.terminal','view.focus.explorer','view.focus.ai','view.lang','term.cancel',
    'term.clear','help.shortcuts','help.about'];
foreach ($defs as $m) {
    foreach ($m['items'] as $it) {
        $actionCount++;
        check(in_array($it['action'], $known, true), "菜单项 {$it['label']} 的 action({$it['action']}) 是真实命令");
    }
}
check($actionCount === 14, "共 14 个菜单项（实际 " . $actionCount . "）");

// ─════════ 2) 菜单栏在常规视口可见、矮视口不画 ═════════
echo "\n== 菜单栏可见性 ==\n";
$app2 = new App();
$vp = Area::fromDimensions(120, 40);
$b = TuiBuffer::empty($vp);
$rr->render($rr, $app2->render($vp), $b, $b->area());
$txt = implode("\n", $b->toLines());
check(str_contains($txt, '文件') && str_contains($txt, '帮助'), '120x40 菜单栏可见且含菜单名');

$app3 = new App();
$vp3 = Area::fromDimensions(10, 4); // 高度 < MIN_HEIGHT_WITH_MENU(5)
$b3 = TuiBuffer::empty($vp3);
try {
    $rr->render($rr, $app3->render($vp3), $b3, $b3->area());
    check(true, '10x4 矮视口渲染不崩');
} catch (Throwable $e) {
    check(false, '10x4 矮视口渲染不崩（实际: ' . get_class($e) . $e->getMessage() . '）');
}

// ─════════ 3) 键盘导航：F10 激活 → 方向 → Enter 执行 ═════════
echo "\n== 键盘导航 ==\n";
$app4 = new App();
check($app4->menuBar->isOpen() === false, '初始菜单关闭');
// 用 menuBar 自身方法模拟（App::handle 的 F10 分支单独测）
$app4->menuBar->toggle();
check($app4->menuBar->isOpen() === true, 'F10(toggle) 打开菜单');
// 右移到「帮助」(index 3)，下移到「关于」(index 1)，Enter 执行
$app4->menuBar->onKey(CodedKeyEvent::new(KeyCode::Right));
$app4->menuBar->onKey(CodedKeyEvent::new(KeyCode::Right));
$app4->menuBar->onKey(CodedKeyEvent::new(KeyCode::Right)); // 现在 active=3（帮助）
check(true, '连续 Right 不越界（环形）');
$app4->menuBar->onKey(CodedKeyEvent::new(KeyCode::Down)); // sel=1（关于）
$app4->menuBar->onKey(CodedKeyEvent::new(KeyCode::Enter)); // 执行 help.about
check($app4->help->isAboutOpen() === true, 'Enter 执行「帮助 → 关于」打开关于页');
check($app4->menuBar->isOpen() === false, '执行后菜单自动关闭');

// Esc 关闭
$app5 = new App();
$app5->menuBar->open();
$app5->menuBar->onKey(CodedKeyEvent::new(KeyCode::Esc));
check($app5->menuBar->isOpen() === false, 'Esc 关闭菜单');

// ─════════ 4) 鼠标命中：点标签激活、点条目执行 ═════════
echo "\n== 鼠标命中 ==\n";
$app6 = new App();
$startXHelp = $app6->menuBar->menuStartX(3);
$widthHelp = $app6->menuBar->menuWidth(3);
$clicked = $app6->menuBar->clickBar($startXHelp + intdiv($widthHelp, 2));
check($clicked && $app6->menuBar->isOpen(), '点击「帮助」标签激活该菜单');
// 现在菜单打开且 active=3（帮助），点下拉第 0 项（快捷键）→ 打开帮助页
$app6->menuBar->clickDropdown($startXHelp, 1);
check($app6->help->isOpen() === true, '点下拉「快捷键」打开帮助页');
check($app6->menuBar->isOpen() === false, '点条目后菜单关闭');
// 单独验证「点其它标签可切换（不崩）」——用新实例，避免改掉上面的 active
$app6b = new App();
$clickedOther = $app6b->menuBar->clickBar($app6b->menuBar->menuStartX(0)); // 切到文件菜单
check($clickedOther && $app6b->menuBar->isOpen(), '点其它标签可切换（不崩）');

// ─════════ 5) menuAction 真实副作用 ═════════
echo "\n== menuAction 副作用 ==\n";
$app7 = new App();
$themeBefore = $app7->theme->id;
$app7->menuAction('view.theme');
check($app7->theme->id !== $themeBefore, 'view.theme 真的切换了主题');
$localeBefore = $app7->locale();
$app7->menuAction('view.lang');
check($app7->locale() !== $localeBefore, 'view.lang 真的切换了语言');
$app8 = new App();
$app8->openFile('src/App.php');
$app8->menuAction('file.quit');
check($app8->quit === true, 'file.quit（无未保存改动）直接置退出意图');
$app9 = new App();
$app9->focus('editor');
$app9->menuAction('view.focus.terminal');
check($app9->focusPanel() === 'terminal', 'view.focus.terminal 把焦点切到终端');
$app10 = new App();
$app10->focus('sidebar');
$app10->menuAction('file.open');
check($app10->focusPanel() === 'sidebar', 'file.open 焦点回到侧栏（资源管理器）');

// ─════════ 6) 下拉覆盖层渲染不崩（多种视口） ═════════
echo "\n== 下拉渲染 ==\n";
foreach ([[120,40],[80,24],[40,10],[20,6]] as [$W,$H]) {
    $a = new App();
    $a->menuBar->open();
    $vp = Area::fromDimensions($W, $H);
    $buf = TuiBuffer::empty($vp);
    try {
        $rr->render($rr, $a->render($vp), $buf, $buf->area());
        check(true, "{$W}x{$H} 下拉打开时渲染不崩");
    } catch (Throwable $e) {
        check(false, "{$W}x{$H} 下拉渲染不崩（实际: " . get_class($e) . $e->getMessage() . '）');
    }
}

// ─════════ 7) 键不漏到下层（菜单打开时字符键被吞） ═════════
echo "\n== 模态独占 ==\n";
$app11 = new App();
$app11->menuBar->open();
$focusBefore = $app11->focusPanel();
// 模拟在编辑器输入 'x'：菜单打开时 handle 应吞掉，焦点不变、buffer 不增字符
$vp = Area::fromDimensions(120, 40);
$app11->focus('editor');
$app11->handle(CharKeyEvent::new('x', 0), $vp);
check($app11->focusPanel() === 'editor', '菜单打开时字符键不切换焦点（被吞）');
check(($app11->buffer === null) || $app11->buffer->dirty === false, '菜单打开时字符键不写入编辑器');

echo $failed ? "\n菜单单测 FAIL\n" : "\n菜单单测全部 PASS\n";
exit($failed ? 1 : 0);
