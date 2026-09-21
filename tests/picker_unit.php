<?php
declare(strict_types=1);

/**
 * 状态栏可点段的选项列表（语言 / 主题）—— 无终端单测（不依赖 tty）。
 *
 * 覆盖：
 *  1) 点状态栏「语言」段 → 弹出选项列表；不可点的段点了不该有反应；
 *  2) ↑↓ 环形移动、Enter 应用、Esc 只关列表（不退出程序、不改语言）；
 *  3) 鼠标点列表项 = 应用，点别处 = 关闭；
 *  4) 语言 / 主题**真的**切换了；
 *  5) 浮层真的画在屏幕上、位置在状态栏上方、且不抹掉底层。
 *
 * ⚠️ 两条纪律，都是踩过的坑：
 *  - 渲染断言必须**注册 DropdownOverlay 渲染器**，否则浮层被静默跳过、什么都不画；
 *  - 鼠标命中的行列一律**从渲染帧逐格量**，不照抄产品代码的几何 —— GIT 的 `Commit ▾`
 *    正是因为「测试与实现共用同一个错公式」而长期没被发现（点了没反应、测试还绿）。
 *    也因此不能用 `vc_rebuild_screen`（它会归一化掉标点、列号随之失真）。
 *
 * 运行：php tests/picker_unit.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/isolation.php';
vc_isolate_config('vc_picker');
putenv('APP_LOCALE=zh_CN');

use App\App;
use App\Core\Theme;
use App\Widget\DropdownOverlay;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer as TuiBuffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Position\Position;
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

/** 渲染一帧成「逐格字符网格」`grid[row][col] = 单字`（列号即屏幕列，汉字占一格 + 占位空格） */
function renderGrid(App $app, Area $vp): array
{
    $rs = [];
    foreach ((new CoreExtension())->widgetRenderers() as $r) {
        $rs[] = $r;
    }
    $rs[] = DropdownOverlay::renderer();   // ← 漏了这句，覆盖层会被静默跳过
    $renderer = new AggregateWidgetRenderer($rs);

    $buf = TuiBuffer::empty($vp);
    $renderer->render($renderer, $app->render($vp), $buf, $buf->area());

    $grid = [];
    for ($y = 0; $y < $vp->height; $y++) {
        for ($x = 0; $x < $vp->width; $x++) {
            $grid[$y][$x] = $buf->get(Position::at($x, $y))->char;
        }
    }
    return $grid;
}

/**
 * 在网格里逐格找一段文字，返回它的 {row,col}。
 *
 * ⚠️ 按**字符**（`mb_str_split`）而不是字节数比对：一个多字节字符只占**一格**
 * （`strlen()` 拿它当 3 格，永远匹配不上 —— 写这条时就这么错过一次）。
 * ⚠️ 另：要求 needle 里每个字符各占一格，故不要拿含全角字符的串来找（宽字符的右邻
 * 是占位格）。本文件只找纯 ASCII 与单个边框字符，够用。
 */
function findInGrid(array $grid, string $needle): ?array
{
    $chars = mb_str_split($needle);
    $n = count($chars);
    if ($n === 0) {
        return null;
    }
    foreach ($grid as $y => $row) {
        $w = count($row);
        for ($x = 0; $x + $n <= $w; $x++) {
            $ok = true;
            for ($k = 0; $k < $n; $k++) {
                if ($row[$x + $k] !== $chars[$k]) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return ['row' => $y, 'col' => $x];
            }
        }
    }
    return null;
}

$vp = Area::fromDimensions(120, 40);

echo "== 1) 点状态栏「语言」段 → 弹出列表 ==\n";
$app = new App();
$r = $app->statusBar->assemble(120);      // 先摆一帧，placed 才有内容
check($app->picker() === null, '初始没有列表');

$seg = null;
foreach ($r['placed'] as $p) {
    if ($p['k'] === 'locale') {
        $seg = $p;
    }
}
check($seg !== null, '前置：语言段在 placed 里');
check(($seg['pick'] ?? null) === 'locale', '语言段带 pick=locale（可点）');
check(($seg['cmd'] ?? null) === null, '语言段不带插件命令（两种可点机制互斥）');
check(($app->statusBar->clickSegment($seg['x0'])['pick'] ?? null) === 'locale',
    '命中语言段首列返回它的 pick id');

$statusArea = $app->areas($vp)['status'];
$app->handle(MouseEvent::new(
    MouseEventKind::Down,
    MouseButton::Left,
    $statusArea->position->x + $seg['x0'],
    $statusArea->position->y,
    0
), $vp);
check($app->picker()?->id === 'locale',
    '点语言段 → 打开语言列表（实际 ' . var_export($app->picker()?->id, true) . '）');
check(count($app->picker()?->options ?? []) >= 2, '列表至少两项（当前有 zh_CN / en）');
check($app->picker()?->sel === $app->picker()?->currentIndex,
    '初始高亮落在**当前值**上（不是第一项）');

echo "\n== 2) 不可点的段点了没反应 ==\n";
$app2 = new App();
// 用 file 段当例子：它在 120 列下一定在 placed 里（focus 那种低优先级段会被丢弃，
// 拿被丢弃的段去测「不可点」等于什么都没测）。
$plain = null;
foreach ($app2->statusBar->assemble(120)['placed'] as $p) {
    if ($p['k'] === 'file') {
        $plain = $p;
    }
}
check($plain !== null, '前置：file 段在 placed 里');
check(($plain['cmd'] ?? null) === null && ($plain['pick'] ?? null) === null,
    'file 段既无 cmd 也无 pick');
check($plain !== null && $app2->statusBar->clickSegment($plain['x0']) === null,
    '无 cmd / 无 pick 的段命中判定返回 null（不可点）');
$st2 = $app2->areas($vp)['status'];
$app2->handle(MouseEvent::new(
    MouseEventKind::Down,
    MouseButton::Left,
    $st2->position->x + ($plain['x0'] ?? 0),
    $st2->position->y,
    0
), $vp);
check($app2->picker() === null, '点不可点的段不会弹列表');
check($app2->openPicker('nope') === false, 'openPicker(未知 id) 返回 false');

echo "\n== 3) 键盘：↑↓ 环形移动 / Esc 只关列表 ==\n";
$localeBefore = $app->locale();
$sel0 = $app->picker()?->sel;
$app->handle(CodedKeyEvent::new(KeyCode::Down, 0), $vp);
check($app->picker()?->sel !== $sel0, '↓ 移动高亮');
$app->handle(CodedKeyEvent::new(KeyCode::Up, 0), $vp);
check($app->picker()?->sel === $sel0, '↑ 移回来');
$n = count($app->picker()->options);
for ($i = 0; $i < $n; $i++) {
    $app->handle(CodedKeyEvent::new(KeyCode::Up, 0), $vp);
}
check($app->picker()?->sel === $sel0, '按满一圈回到原处（环形，不越界）');

$app->handle(CodedKeyEvent::new(KeyCode::Esc, 0), $vp);
check($app->picker() === null, 'Esc 关掉列表');
check($app->locale() === $localeBefore, 'Esc **不**改语言');
check($app->quit === false, 'Esc 只关列表，不会顺带退出应用');

echo "\n== 4) Enter 应用（语言真的变）==\n";
$app->openPicker('locale');
$app->picker()->move(1);
$want = $app->picker()->selected()['value'];
$app->handle(CodedKeyEvent::new(KeyCode::Enter, 0), $vp);
check($app->locale() === $want, "Enter 应用选中项（期望 {$want}，实际 {$app->locale()}）");
check($app->locale() !== $localeBefore, '语言确实变了');
check($app->picker() === null, '应用后列表关闭');

echo "\n== 5) 主题列表：Enter 应用 ==\n";
$app3 = new App();
$themeBefore = $app3->theme->id;
check($app3->openPicker('theme'), 'openPicker(theme) 返回 true');
check(count($app3->picker()->options ?? []) === count(Theme::ids()), '主题项数 = Theme::ids()');
check($app3->picker()?->selected()['value'] === $themeBefore, '初始高亮 = 当前主题');
$app3->handle(CodedKeyEvent::new(KeyCode::Down, 0), $vp);
$wantTheme = $app3->picker()->selected()['value'];
$app3->handle(CodedKeyEvent::new(KeyCode::Enter, 0), $vp);
check($app3->theme->id === $wantTheme,
    "主题切到 {$wantTheme}（实际 {$app3->theme->id}）");
check($app3->theme->id !== $themeBefore, '主题确实变了');

echo "\n== 6) 鼠标：点列表项 = 应用，点别处 = 关闭 ==\n";
$app4 = new App();
$app4->statusBar->assemble(120);
$app4->openPicker('locale');
$grid4 = renderGrid($app4, $vp);
$loc = findInGrid($grid4, 'English (en)');   // 纯 ASCII，逐格可比
check($loc !== null, '前置：能从渲染帧逐格定位到列表项「English (en)」'
    . ($loc === null ? '' : "（行 {$loc['row']} 列 {$loc['col']}）"));
if ($loc !== null) {
    // 列号取自渲染帧 → 真正验证「屏幕上那一格点了会生效」
    $app4->handle(MouseEvent::new(
        MouseEventKind::Down,
        MouseButton::Left,
        $loc['col'],
        $loc['row'],
        0
    ), $vp);
    check($app4->locale() === 'en', '点列表项 → 应用该项语言（实际 ' . $app4->locale() . '）');
    check($app4->picker() === null, '点列表项后列表关闭');
}

$app5 = new App();
$app5->statusBar->assemble(120);
$app5->openPicker('locale');
$app5->handle(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, 0, 0, 0), $vp);
check($app5->picker() === null, '点列表外 → 关闭列表');

echo "\n== 7) 浮层真的画在屏幕上（且在状态栏上方）==\n";
$appR = new App();
$appR->statusBar->assemble(120);
$gridBefore = renderGrid($appR, $vp);
$appR->openPicker('locale');
$gridAfter = renderGrid($appR, $vp);
$statusRow = $appR->areas($vp)['status']->position->y;

$title = findInGrid($gridAfter, 'English (en)');
check($title !== null, '列表项出现在屏幕上');
// 浮层底边框（圆角 ╰）必须紧贴状态栏上一行 —— 用渲染帧里的角字符量，而不是照抄
// PickerOverlay::geometry() 的公式：那样等于用同一个（可能错的）公式自己验自己。
$corner = findInGrid($gridAfter, '╰');
check($corner !== null, '浮层底边框（╰）出现在屏幕上');
check($corner !== null && $corner['row'] === $statusRow - 1,
    '浮层底边紧贴状态栏上一行（实际 ' . var_export($corner['row'] ?? null, true)
    . '，状态栏行 ' . $statusRow . '）');
check($title === null || $title['row'] < $statusRow,
    '列表项在状态栏**上方**（' . var_export($title['row'] ?? null, true) . ' < ' . $statusRow . '）');
check(findInGrid($gridBefore, 'English (en)') === null,
    '关闭时屏幕上没有它（阴性对照，证明上面那条不是恒真）');
check(findInGrid($gridAfter, 'ViceCode') !== null, '底层状态栏仍在（透明叠加，没把整屏抹白）');

echo $failed ? "\n状态栏选项列表 FAIL\n" : "\n状态栏选项列表 PASS\n";
exit($failed ? 1 : 0);
