<?php
declare(strict_types=1);

/**
 * 教学程序 02 — 布局：把屏幕切成 VSCode 风格的多块面板。
 *
 * 本例同时教两件相关但不同的事：
 *   A) 声明式绘制  —— 用 GridWidget 把多个 Widget 嵌套排布（这是“怎么画”）。
 *   B) 命令式取矩形 —— 用 Layout::split 把同一块区域切成 Areas，
 *                      拿到每块的面坐标 (x,y,w,h)（这是“怎么算位置 / 命中测试”）。
 *
 * 为什么两套都要会？
 *   画的时候用 GridWidget 最省事；但一旦要“鼠标点到了哪块面板”、
 *   “某个面板该显示第几行”，就必须自己知道每块的面坐标——这时用 Layout::split。
 *
 * 布局（与后续真实 App 一致）：
 *   ┌────────┬───────────────┬────────────┐
 *   │ Sidebar│ Editor        │ AI Stream  │
 *   │ (30)   ├───────────────┼────────────┤
 *   │        │ Terminal      │ AI Input   │
 *   │        │               │ (3 行)     │
 *   └────────┴───────────────┴────────────┘
 *    30宽     中间(弹性)        45宽
 *
 * 运行：php examples/02-layout.php
 * 操作：Tab 或数字 1-5 切换“焦点面板”（模拟点击）；q / Esc 退出。
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib.php';

use PhpTui\Term\Terminal;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Text\Title;

// ── 进入终端 ────────────────────────────────────────────────
$term = Terminal::new(eventProvider: new BlockingTtyEventProvider());
$term->enableRawMode();
$term->queue(Actions::alternateScreenEnable(), Actions::cursorHide(), Actions::setTitle('02 Layout'));
$term->flush();

$backend = PhpTermBackend::new($term);
$display = DisplayBuilder::default($backend)->fullscreen()->build();
$events = $term->events();

// 面板的名字，顺序与下面的切分一致
$names = ['Explorer', 'Editor', 'Terminal', 'AI Stream', 'AI Input'];

/**
 * B) 命令式：用 Layout::split 算出每块的面坐标。
 * 返回一个 Area[]，下标与 $names 对应：
 *   [0]=Sidebar [1]=Editor [2]=Terminal [3]=AI Stream [4]=AI Input
 */
function splitAreas(Area $viewport): array
{
    // 根：水平三列 [侧栏30][中间弹性][右侧45]
    $root = Layout::default()
        ->constraints([Constraint::length(30), Constraint::min(10), Constraint::length(45)])
        ->direction(Direction::Horizontal)
        ->split($viewport);

    // 中间列再垂直切 [编辑器60%][终端40%]
    $main = Layout::default()
        ->constraints([Constraint::percentage(60), Constraint::percentage(40)])
        ->direction(Direction::Vertical)
        ->split($root->get(1));

    // 右侧再垂直切 [AI流75%][AI输入3行]
    $right = Layout::default()
        ->constraints([Constraint::percentage(75), Constraint::length(3)])
        ->direction(Direction::Vertical)
        ->split($root->get(2));

    return [
        $root->get(0),   // Sidebar
        $main->get(0),   // Editor
        $main->get(1),   // Terminal
        $right->get(0),  // AI Stream
        $right->get(1),  // AI Input
    ];
}

/**
 * A) 声明式：用 GridWidget 把 5 个 BlockWidget 嵌套排布。
 * GridWidget 内部用的是和上面完全一样的 Layout 算法，所以“画出来的位置”
 * 和 splitAreas() 算出来的面坐标是一一对应的。
 */
function buildWidget(array $areas, int $focus): Widget
{
    global $names;
    // 构造一个叶子面板：被聚焦时边框变绿、加粗感
    $leaf = function (int $i) use ($areas, $focus, $names): Widget {
        $a = $areas[$i];
        $rect = sprintf('rect: %d,%d  %dx%d', $a->position->x, $a->position->y, $a->width, $a->height);
        $focused = ($i === $focus);
        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle(Style::default()->fg($focused ? AnsiColor::LightGreen : AnsiColor::Gray))
            ->titles(Title::fromString(' ' . $names[$i] . ($focused ? ' *' : '') . ' '))
            ->widget(ParagraphWidget::fromString($names[$i] . "\n" . $rect . "\n(焦点用 Tab/数字切换)"));
    };

    // 中间列：编辑器 + 终端
    $main = GridWidget::default()
        ->direction(Direction::Vertical)
        ->constraints(Constraint::percentage(60), Constraint::percentage(40))
        ->widgets($leaf(1), $leaf(2));

    // 右侧列：AI 流 + AI 输入
    $right = GridWidget::default()
        ->direction(Direction::Vertical)
        ->constraints(Constraint::percentage(75), Constraint::length(3))
        ->widgets($leaf(3), $leaf(4));

    // 根：侧栏 + 中间 + 右侧
    return GridWidget::default()
        ->direction(Direction::Horizontal)
        ->constraints(Constraint::length(30), Constraint::min(10), Constraint::length(45))
        ->widgets($leaf(0), $main, $right);
}

$focus = 0;
$quit = false;

while (!$quit) {
    // 每帧重新算一次面坐标（窗口缩放时自动跟着变，无需手动处理 SIGWINCH）
    $areas = splitAreas($display->viewportArea());
    $display->draw(buildWidget($areas, $focus));

    $event = $events->next();
    if ($event === null) {
        break;
    }

    if ($event instanceof CharKeyEvent) {
        $c = strtolower($event->char);
        if ($c === 'q') {
            $quit = true;
        } elseif ($c >= '1' && $c <= '5') {
            $focus = intval($c) - 1;           // 数字键“模拟点击”某块面板
        }
        continue;
    }
    if ($event instanceof CodedKeyEvent) {
        if ($event->code === KeyCode::Esc) {
            $quit = true;
        } elseif ($event->code === KeyCode::Tab) {
            $focus = ($focus + 1) % 5;          // Tab 循环切换焦点
        }
    }
}

// 还原终端
$term->queue(Actions::alternateScreenDisable(), Actions::cursorShow());
$term->flush();
$term->disableRawMode();
