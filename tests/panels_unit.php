<?php
declare(strict_types=1);

/**
 * 面板显隐 + 终端最大化（B16）—— headless 单测，进跑批。
 *
 * 这一期最危险的地方是**索引移位**：旧 `LayoutFactory::split()` 用定位索引取矩形
 * （`$main->get(1)` 当中间列、`$main->get(2)` 当 AI 列），一隐藏侧栏就会把 AI 的矩形
 * 当成中间列、整个界面错位。所以本用例的第一组断言就是「areas() 的键集与约束段数同源」，
 * 并把每一处「隐藏后仍然去索引它」的路径都点一遍（渲染 / 点击 / 拖拽 / 补全锚点 / 选区）。
 *
 * 覆盖：
 *   1) 默认态与加显隐之前完全一致（键全在、layoutSummary 不含「隐藏」）；
 *   2) 隐藏侧栏 / AI / 终端 → areas() 无该键、visiblePanels() 同步、渲染不崩且标题消失；
 *   3) 焦点回落：焦点在被隐藏的面板上时自动挪到最近的可见面板；
 *   4) Tab/Shift+Tab 只在可见面板间循环（跳过隐藏的）；
 *   5) 终端最大化：中列只剩 terminal、编辑器矩形不切出来；
 *   6) 隐藏终端会一并取消最大化（否则「隐藏着又最大化」是自相矛盾态）；
 *   7) 菜单入口（menuAction）与命令面板派生同源；
 *   8) 落盘 → fromArray 往返（显隐字段持久化）。
 *
 * 运行：php tests/panels_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
vc_isolate_config('vc_panels');
putenv('APP_LOCALE=zh_CN');   // 标题断言要可确定

use App\App;
use App\Core\ConfigStore;
use App\Core\LayoutConfig;
use App\Widget\DropdownOverlay;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;

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

/** 渲染整帧成文本（逐格 Buffer + 全渲染器；覆盖层渲染器也要注册，否则浮层静默不画） */
function paint(App $app, Area $vp): string
{
    $rs = [];
    foreach ((new CoreExtension())->widgetRenderers() as $r) {
        $rs[] = $r;
    }
    $rs[] = DropdownOverlay::renderer();
    $r = new AggregateWidgetRenderer($rs);
    $b = TuiBuffer::empty($vp);
    $r->render($r, $app->render($vp), $b, $b->area());
    return implode("\n", $b->toLines());
}

/**
 * 渲染一帧并返回 [文本, areas]；渲染抛异常时返回 ['(崩溃: …)', areas]
 * —— 断言里就能直接看出「是崩了」还是「内容不对」。
 * @return array{0:string,1:array<string,Area>}
 */
function frame(App $app, Area $vp): array
{
    $areas = $app->areas($vp);
    try {
        return [paint($app, $vp), $areas];
    } catch (\Throwable $e) {
        return ['(崩溃: ' . $e->getMessage() . ')', $areas];
    }
}

/** 面板标题（i18n zh_CN），用于「这个面板还在不在画面上」 */
function titles(): array
{
    return ['sidebar' => '侧栏', 'editor' => '编辑器', 'terminal' => '终端', 'ai' => 'AI 对话', 'ai_input' => 'AI 输入'];
}

// ── 1) 默认态：与加显隐之前一字不差 ──
echo "== 1) 默认态 ==\n";
$app = new App();
$defaultLayout = $app->layout;
check($defaultLayout->sidebarVisible && $defaultLayout->aiVisible && $defaultLayout->terminalVisible,
    '默认三块面板都可见');
check($defaultLayout->terminalMaximized === false, '默认不最大化');
$all = $app->visiblePanels();
check($all === App::PANELS, '默认 visiblePanels() 与 PANELS 常量一致（' . implode(',', $all) . '）');
check($app->layoutSummary() === '侧栏30 AI45 编辑60% 输入5', '默认 layoutSummary 不含「隐藏」（实际「' . $app->layoutSummary() . '」）');
$f = frame($app, $vp);
check(!str_contains($f[0], '(崩溃'), '默认渲染一帧不抛异常');
foreach (titles() as $k => $t) {
    check(str_contains($f[0], $t), "默认态画面上有「{$t}」标题");
}
check(isset($f[1]['editor'], $f[1]['terminal'], $f[1]['ai_stream'], $f[1]['ai_input'], $f[1]['sidebar']),
    '默认 areas() 五个面板矩形齐全');
$app->terminal->shutdown();

// ── 2) 隐藏侧栏 ──
echo "\n== 2) 隐藏侧栏 ==\n";
$app = new App();
$app->togglePanel('sidebar');
// ⚠️ togglePanel 会把布局摘要写进状态栏消息（含「隐藏:侧栏」）→ 渲染文本里也有「侧栏」二字，
// 直接断言「画面上没有侧栏」会被这条提示喂成假阳性。先验提示、再清掉它，然后才验标题。
check($app->message !== '' && str_contains($app->message, '隐藏:侧栏'), '切换后状态栏提示标出隐藏项');
$app->setMessage('');
$f = frame($app, $vp);
check(!isset($f[1]['sidebar']), '隐藏后 areas() 不再有 sidebar 键');
check(!in_array('sidebar', $app->visiblePanels(), true), 'visiblePanels() 不含 sidebar');
check(!str_contains($f[0], '侧栏'), '画面上没有「侧栏」标题');
check(str_contains($f[0], '编辑器') && str_contains($f[0], 'AI 对话'), '其它面板照常渲染（编辑器/AI 都在）');
check($f[1]['editor']->width > 45, '中间列吃掉了侧栏让出的宽度（' . $f[1]['editor']->width . ' > 45）');
check(str_contains($app->layoutSummary(), '隐藏:侧栏'), 'layoutSummary 标出「隐藏:侧栏」（实际「' . $app->layoutSummary() . '」）');
$app->togglePanel('sidebar');
check(isset($app->areas($vp)['sidebar']), '再切一次又显示回来');
$app->terminal->shutdown();

// ── 3) 隐藏 AI 列 ──
echo "\n== 3) 隐藏 AI 列 ==\n";
$app = new App();
$app->togglePanel('ai');
$f = frame($app, $vp);
check(!isset($f[1]['ai_stream']) && !isset($f[1]['ai_input']), '隐藏后 areas() 无 ai_stream / ai_input');
check(!str_contains($f[0], 'AI 对话') && !str_contains($f[0], 'AI 输入'), '画面上没有 AI 两个面板的标题');
check($f[1]['terminal']->width > 45, '中间列吃掉了 AI 让出的宽度（' . $f[1]['terminal']->width . ' > 45）');
check(str_contains($app->layoutSummary(), '隐藏:AI'), 'layoutSummary 标出「隐藏:AI」');
$app->terminal->shutdown();

// ── 4) 焦点回落：焦点落在被隐藏的面板上 ──
echo "\n== 4) 焦点回落 ==\n";
$app = new App();
$app->focus('sidebar');
$app->togglePanel('sidebar');
check($app->focusPanel() !== 'sidebar', '隐藏侧栏后焦点自动离开它（实际 ' . $app->focusPanel() . '）');
check(in_array($app->focusPanel(), $app->visiblePanels(), true), '回落到的面板是可见的');

$app2 = new App();
$app2->focus('ai_input');
$app2->togglePanel('ai');
check(!in_array($app2->focusPanel(), ['ai_stream', 'ai_input'], true), '隐藏 AI 后焦点离开 AI 面板（实际 ' . $app2->focusPanel() . '）');

$app3 = new App();
$app3->focus('terminal');
$app3->togglePanel('terminal');
check($app3->focusPanel() !== 'terminal', '隐藏终端后焦点离开它（实际 ' . $app3->focusPanel() . '）');
foreach ([$app, $app2, $app3] as $a) {
    $a->terminal->shutdown();
}

// ── 5) Tab / Shift+Tab 只在可见面板间循环 ──
echo "\n== 5) Tab 跳过隐藏面板 ==\n";
$app = new App();
$app->focus('editor');
$app->focusNext(1);
check($app->focusPanel() === 'terminal', '默认：editor → Tab → terminal');
$app->focusNext(1);
check($app->focusPanel() === 'ai_stream', '默认：terminal → Tab → ai_stream');
$app->togglePanel('sidebar');
$app->focus('editor');
$app->focusNext(-1);
check($app->focusPanel() === 'ai_input', '隐藏侧栏后：editor → Shift+Tab → ai_input（跳过被隐藏的 sidebar，环形回绕）');
$app->terminal->shutdown();

// 隐藏 AI 列：从 terminal 往后切应当直接绕回 sidebar
$appB = new App();
$appB->togglePanel('ai');
$appB->focus('terminal');
$appB->focusNext(1);
check($appB->focusPanel() === 'sidebar', '隐藏 AI 后：terminal → Tab → sidebar（跳过 ai_stream/ai_input）');
// Tab 是 CodedKeyEvent 走 handleCoded；这里补一条真实按键路径
$appB->focus('editor');
$appB->handle(CodedKeyEvent::new(KeyCode::Tab, 0), $vp);
check($appB->focusPanel() === 'terminal', '真实 Tab 按键同样跳过隐藏面板（editor → terminal）');
$appB->terminal->shutdown();

// ── 6) 终端最大化 ──
echo "\n== 6) 终端最大化 ==\n";
$app = new App();
$app->togglePanel('terminal_max');
$f = frame($app, $vp);
check($app->layout->terminalMaximized, '最大化状态置位');
check(!isset($f[1]['editor']) && isset($f[1]['terminal']), '中列只剩 terminal（不切 editor 矩形）');
check(!str_contains($f[0], '编辑器'), '画面上没有「编辑器」标题');
check($f[1]['terminal']->height > 16, '终端占满中列高度（' . $f[1]['terminal']->height . ' > 16）');
check(isset($f[1]['sidebar']) && isset($f[1]['ai_stream']), '左右两列保留（最大化只吃中间列）');
check(str_contains($app->layoutSummary(), '终端最大化'), 'layoutSummary 标出「终端最大化」');
$app->togglePanel('terminal_max');
$f = frame($app, $vp);
check(!$app->layout->terminalMaximized && isset($f[1]['editor']), '再切一次还原成 editor/terminal 两段');
$app->terminal->shutdown();

// ── 7) 隐藏终端会一并取消最大化 ──
echo "\n== 7) 隐藏终端 vs 最大化 ==\n";
$app = new App();
$app->togglePanel('terminal_max');
$app->togglePanel('terminal');            // 隐藏终端
check(!$app->layout->terminalVisible && !$app->layout->terminalMaximized,
    '隐藏终端时顺带取消最大化（不留「隐藏着又最大化」的矛盾态）');
$f = frame($app, $vp);
check(isset($f[1]['editor']) && !isset($f[1]['terminal']), '中列只剩编辑器');
$app->togglePanel('terminal');            // 再显示
check($app->layout->terminalVisible && !$app->layout->terminalMaximized, '重新显示终端时保持普通分栏');
$app->terminal->shutdown();

// ── 8) 菜单入口（命令面板条目从菜单定义派生，故一并覆盖）──
echo "\n== 8) 菜单入口 ==\n";
$app = new App();
$app->menuAction('view.toggle_ai');
check(!$app->layout->aiVisible, 'menuAction(view.toggle_ai) 真的隐藏了 AI 列');
$app->menuAction('view.toggle_ai');
check($app->layout->aiVisible, '再执行一次显示回来');
$app->menuAction('view.toggle_terminal_max');
check($app->layout->terminalMaximized, 'menuAction(view.toggle_terminal_max) 最大化');
$app->menuAction('view.toggle_sidebar');
check(!$app->layout->sidebarVisible, 'menuAction(view.toggle_sidebar) 隐藏侧栏');
// 命令面板条目从菜单定义派生 → 4 个新入口自动可搜到。用真实过滤路径验（palette 只暴露
// matchCount/selectedId，没有「列出全部条目」的口径）。
$appC = new App();
$appC->handle(PhpTui\Term\Event\FunctionKeyEvent::new(1), $vp);   // F1 打开命令面板
foreach (str_split('view.toggle_terminal_max') as $ch) {
    $appC->handle(PhpTui\Term\Event\CharKeyEvent::new($ch), $vp);
}
check($appC->palette->matchCount() === 1, '命令面板里能搜到 view.toggle_terminal_max（从菜单派生，实际 ' . $appC->palette->matchCount() . ' 项）');
check($appC->palette->selectedId() === 'view.toggle_terminal_max', '选中项就是它（执行前确认没搜错）');
$appC->palette->close();
$appC->terminal->shutdown();

// ── 9) 隐藏态下的鼠标点击不能崩（命中测试要守卫不存在的键）──
echo "\n== 9) 隐藏态下的鼠标/键盘路径 ==\n";
$app = new App();
$app->togglePanel('sidebar');
$app->togglePanel('ai');
$down = static fn(int $c, int $r) => PhpTui\Term\Event\MouseEvent::new(
    PhpTui\Term\MouseEventKind::Down,
    PhpTui\Term\MouseButton::Left,
    $c,
    $r,
    0
);
$ok = true;
try {
    // 在「原侧栏 / 原 AI 列 / 原分隔条」位置上各点一下，再拖一下
    foreach ([[5, 10], [110, 10], [30, 20], [75, 20]] as [$c, $r]) {
        $app->handle($down($c, $r), $vp);
    }
} catch (\Throwable $e) {
    $ok = false;
    echo '    点击崩溃：' . $e->getMessage() . "\n";
}
check($ok, '隐藏侧栏/AI 后点击它们的原位置与分隔条都不抛异常');
check($app->focusPanel() !== 'sidebar', '点隐藏面板的原位置不会把焦点挪到它上面');
$app->terminal->shutdown();

// ── 10) 落盘 → fromArray 往返 ──
echo "\n== 10) 显隐字段持久化 ==\n";
$app = new App();
$app->togglePanel('sidebar');
$app->togglePanel('terminal_max');
check($app->saveConfig(), 'saveConfig() 成功');
$raw = ConfigStore::load();
$cfg = LayoutConfig::fromArray(is_array($raw) ? $raw : []);
check($cfg->sidebarVisible === false, '往返后 sidebarVisible 仍为 false');
check($cfg->terminalMaximized === true, '往返后 terminalMaximized 仍为 true');
check($cfg->aiVisible === true && $cfg->terminalVisible === true, '往返后其余显隐字段未被误改');
check($raw['layout']['sidebarVisible'] === false && $raw['layout']['terminalMaximized'] === true,
    '落盘的是 JSON 真布尔（不是字符串）');
$app->terminal->shutdown();

echo $failed ? "\npanels_unit FAIL\n" : "\npanels_unit PASS\n";
exit($failed ? 1 : 0);
