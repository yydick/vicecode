<?php
declare(strict_types=1);

/**
 * M8 多终端标签 —— 无终端单测（不依赖 tty）：
 *  1) 容器操作：新建/切换/关闭/循环/至少留一个/魔法转发与实例隔离；
 *  2) 渲染冒烟：terminal 视图（实例标签条 + 底部切换条）、problems 占位视图、极小视口；
 *  3) 命中：BottomTabs 底条、hitInstanceTab 顶行（含非 terminal 视图不残留旧矩形）；
 *  4) 键位：Ctrl+` 新建 / Alt+N 切换 / Ctrl+PgUp/PgDn 循环 / Ctrl+W 关闭（含焦点与捕获态边界）。
 *
 * 运行：php tests/terminal_instance_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
vc_isolate_config('vc_term_inst');
putenv('APP_LOCALE=zh_CN');

use App\App;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Display\Area as TuiArea;
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

$ext = new CoreExtension();
$renderers = [];
foreach ($ext->widgetRenderers() as $r) {
    $renderers[] = $r;
}
$renderer = new AggregateWidgetRenderer($renderers);

function renderLines(AggregateWidgetRenderer $rr, App $app, int $w, int $h): array
{
    $vp = TuiArea::fromDimensions($w, $h);
    $b = TuiBuffer::empty($vp);
    $rr->render($rr, $app->render($vp), $b, $b->area());
    return $b->toLines();
}

// ═══════════════ 1) 容器操作 ═══════════════
echo "== 容器操作 ==\n";
$app = new App();
$term = $app->terminal;
check(count($term->tabTitles()) === 1, '初始 1 个实例');
check($term->tabTitles() === ['1: bash'], '初始标题 1: bash');
check($term->mode === 'pty', '魔法 __get 转发到活动实例（mode）');

$term->newInstance();
$term->newInstance();
check(count($term->tabTitles()) === 3, 'newInstance ×2 → 3 个');
check($term->tabTitles() === ['1: bash', '2: bash', '3: bash'], '标题按序编号');
check($app->terminal->activeIndex() === 2, '新建后切到最新（active=2）');

$term->input = 'abc';            // 写 active（第 3 个）
$term->selectInstance(0);
check($term->input === '', '实例输入缓冲互相隔离（切回第 1 个为空）');
$term->selectInstance(2);
check($term->input === 'abc', '切回第 3 个仍见 abc');
$term->selectInstance(9);
check($term->activeIndex() === 2, '越界 select 不越界');

$term->next();
check($term->activeIndex() === 0, 'next 循环到 0');
$term->prev();
check($term->activeIndex() === 2, 'prev 回到 2');

$term->closeInstance(0);
check($term->tabTitles() === ['1: bash', '2: bash'], '关闭后重排编号（原 2→1）');
$term->selectInstance(0);
$term->closeActive();
check($term->tabTitles() === ['1: bash'], 'closeActive 生效');
$term->closeInstance(0);
check(count($term->tabTitles()) === 1, '最后一个实例拒绝关闭（至少留 1）');

// ═══════════════ 2) 渲染冒烟 ═══════════════
echo "== 渲染冒烟 ==\n";
$appR = new App();
$appR->focus('terminal');
$termR = $appR->terminal;
$termR->newInstance();

$lines = renderLines($renderer, $appR, 120, 30);
$tabRowNo = null;
$botRowNo = null;
foreach ($lines as $y => $l) {
    if ($tabRowNo === null && str_contains($l, '1: bash')) {
        $tabRowNo = $y;
    }
    if ($botRowNo === null && str_contains($l, 'TERMINAL')) {
        $botRowNo = $y;
    }
}
check($tabRowNo !== null, 'terminal 视图渲染出实例标签条（1: bash）');
check(str_contains($lines[$tabRowNo] ?? '', '2: bash'), '两个实例标签都在');
check(str_contains($lines[$tabRowNo] ?? '', '✕'), '每个标签带关闭钮 ✕');
check(str_contains($lines[$tabRowNo] ?? '', '⊕'), '带新建钮 ⊕');
check($botRowNo !== null, '渲染出底部面板切换条（TERMINAL）');
$botLine = $lines[$botRowNo] ?? '';
foreach (['PROBLEMS', 'OUTPUT', 'DEBUG', 'PORTS'] as $v) {
    check(str_contains($botLine, $v), "底条含 $v");
}
check(!str_contains($botLine, 'DEBUG CONSOLE'), '底条用短标签 DEBUG（120 宽全部放得下，PORTS 可点）');
check($botRowNo > $tabRowNo, '实例标签条在底条上方');

// problems 占位视图
$appR->bottomView = 'problems';
$lines2 = renderLines($renderer, $appR, 120, 30);
$joined2 = implode("\n", $lines2);
check(str_contains($joined2, '问题 — 未实现'), 'problems 视图渲染占位文案');
check(!str_contains($joined2, '1: bash'), '占位视图无实例标签条');
$botRow2 = null;
foreach ($lines2 as $y => $l) {
    if (str_contains($l, 'TERMINAL') && str_contains($l, 'PROBLEMS')) {
        $botRow2 = $y;
    }
}
check($botRow2 !== null, '占位视图仍渲染底部切换条');

// 极小视口不崩
$appR->bottomView = 'terminal';
$tiny = renderLines($renderer, $appR, 120, 1);
check(is_array($tiny), '1 行视口渲染不崩');
$tiny2 = renderLines($renderer, $appR, 120, 3);
check(is_array($tiny2), '3 行视口渲染不崩');

// ═══════════════ 3) 命中 ═══════════════
echo "== 鼠标命中 ==\n";
// 渲染后 rects 已记录。命中测试：对整行所有列扫描收集。
// 底条：所有 5 个 view 名都可命中。
$probeArea = TuiArea::fromDimensions(120, 1);   // hit 不用 $line 参数，仅签名要求
$views = [];
for ($c = 0; $c < 120; $c++) {
    $v = \App\Panel\BottomTabs::hit($probeArea, $c);
    if ($v !== null && !in_array($v, $views, true)) {
        $views[] = $v;
    }
}
check(count($views) === 5, '底条 5 个视图均可命中（实际 ' . count($views) . '）');

// 实例标签条：switch/close/new
$appH = new App();
$appH->focus('terminal');
$appH->terminal->newInstance();
$linesH = renderLines($renderer, $appH, 120, 30);   // 先渲染记 rects
$tabY = null;
foreach ($linesH as $y => $l) {
    if (str_contains($l, '1: bash')) {
        $tabY = $y;
        break;
    }
}
check($tabY !== null, '重渲染后仍找到标签条行');
// 顶行命中需传面板外框 Area 与绝对坐标。外框 y = 内区 y - 1（tabRow 是内区顶行 = 外框 y+1）。
// 直接构造与布局一致的探测：拿 app->areas() 需要视口；改用 hitInstanceTab 的行判定语义直接测：
$vpH = TuiArea::fromDimensions(120, 30);
$areas = null;
$rm = new ReflectionMethod($appH, 'areas');
$rm->setAccessible(true);
$areas = $rm->invoke($appH, $vpH);
$tOuter = $areas['terminal'];
$topRow = $tOuter->position->y + 1;
$hitSwitch = $appH->terminal->hitInstanceTab($tOuter, 0, $topRow);
check($hitSwitch === null, 'x=0 无标签（左缩进 1 列 + 内边框）不为 null 误报');
$found = ['switch' => 0, 'close' => 0, 'new' => 0];
for ($c = 0; $c < $tOuter->position->x + $tOuter->width; $c++) {
    $h2 = $appH->terminal->hitInstanceTab($tOuter, $c, $topRow);
    if ($h2 !== null) {
        $found[$h2['action']]++;
    }
}
check($found['switch'] > 0, "顶行可命中 switch（{$found['switch']} 列）");
check($found['close'] === 4, "顶行命中 close 列数 = 2 标签 × '✕ ' 2 列（实际 {$found['close']}）");
check($found['new'] === 2, "顶行命中 new（⊕ 占 2 列宽，实际 {$found['new']}）");
$hitOther = $appH->terminal->hitInstanceTab($tOuter, 3, $topRow + 1);
check($hitOther === null, '非顶行不命中');

// 非 terminal 视图渲染后，旧矩形不残留（tabRects 每帧清）
$appH->bottomView = 'output';
renderLines($renderer, $appH, 120, 30);
$ghost = $appH->terminal->hitInstanceTab($tOuter, 3, $topRow);
check($ghost === null, '切到占位视图后顶行不残留旧标签命中');

// BottomTabs 切换：点底条 PROBLEMS 列 → bottomView 变（走 App::handleMouse 太绕，直接验语义层已由 hit 覆盖）

// ═══════════════ 4) 键位 ═══════════════
echo "== 多终端键位 ==\n";
$appK = new App();
$appK->focus('terminal');
$termK = $appK->terminal;
$vp = TuiArea::fromDimensions(120, 30);

// Ctrl+` 新建
$appK->handle(CharKeyEvent::new('`', KeyModifiers::CONTROL), $vp);
check(count($termK->tabTitles()) === 2, 'Ctrl+` 新建终端');

// Alt+1 切到第 1 个
$appK->handle(CharKeyEvent::new('1', KeyModifiers::ALT), $vp);
check($termK->activeIndex() === 0, 'Alt+1 切到第 1 个');
$appK->handle(CharKeyEvent::new('2', KeyModifiers::ALT), $vp);
check($termK->activeIndex() === 1, 'Alt+2 切到第 2 个');
$appK->handle(CharKeyEvent::new('9', KeyModifiers::ALT), $vp);
check($termK->activeIndex() === 1, 'Alt+9 越界不动（只有 2 个）');

// Ctrl+PgDn / Ctrl+PgUp 循环
$appK->handle(CodedKeyEvent::new(KeyCode::PageDown, KeyModifiers::CONTROL), $vp);
check($termK->activeIndex() === 0, 'Ctrl+PgDn 循环到下一个（1→0）');
$appK->handle(CodedKeyEvent::new(KeyCode::PageUp, KeyModifiers::CONTROL), $vp);
check($termK->activeIndex() === 1, 'Ctrl+PgUp 循环到上一个（0→1）');

// Ctrl+W 关闭当前（active=1，关掉剩 1 个）
$appK->handle(CharKeyEvent::new('w', KeyModifiers::CONTROL), $vp);
check(count($termK->tabTitles()) === 1, 'Ctrl+W 关闭当前终端');
$appK->handle(CharKeyEvent::new('w', KeyModifiers::CONTROL), $vp);
check(count($termK->tabTitles()) === 1, '仅剩 1 个时 Ctrl+W 不再关');

// 编辑器焦点下 Ctrl+W 不动终端（文件非 dirty，requestClose 直接关文件不弹确认）
$appK->openFile(vc_tmp_file('vcti'));
$appK->focus('editor');
$appK->handle(CharKeyEvent::new('w', KeyModifiers::CONTROL), $vp);
check(count($termK->tabTitles()) === 1, '编辑器焦点下 Ctrl+W 不关终端');

// 捕获态：所有多终端快捷键透传给 shell，不生效
$appK->focus('terminal');
$appK->terminal->captured = true;   // 直接注入捕获态（public 属性经 __set 转发）
$n0 = count($termK->tabTitles());
$a0 = $termK->activeIndex();
$appK->handle(CharKeyEvent::new('`', KeyModifiers::CONTROL), $vp);
$appK->handle(CharKeyEvent::new('1', KeyModifiers::ALT), $vp);
$appK->handle(CodedKeyEvent::new(KeyCode::PageDown, KeyModifiers::CONTROL), $vp);
$appK->handle(CharKeyEvent::new('w', KeyModifiers::CONTROL), $vp);
check(count($termK->tabTitles()) === $n0, '捕获态 Ctrl+` 不新建（透传）');
check($termK->activeIndex() === $a0, '捕获态 Alt+1/Ctrl+PgDn 不切换（透传）');
$appK->terminal->captured = false;

echo $failed ? "\n多终端单测 FAIL\n" : "\n多终端单测全部 PASS\n";
exit($failed ? 1 : 0);
