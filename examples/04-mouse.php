<?php
declare(strict_types=1);

/**
 * 教学程序 04 — 鼠标：点击、命中测试、滚轮。
 *
 * 与 02 衔接：02 用 Layout::split 算出了每块面板的矩形 (Area)，但只用数字键“模拟点击”。
 * 这里用真正的鼠标事件完成命中测试。
 *
 * 要点：
 *   1) 必须开启鼠标捕获：进入时队列 Actions::enableMouseCapture()，
 *      退出时队列 Actions::disableMouseCapture()（否则收不到鼠标事件）。
 *   2) MouseEvent 字段：kind(MouseEventKind) / button(MouseButton) /
 *      column / row（都是 0 基的终端格子坐标）/ modifiers（同键盘的位标志）。
 *   3) 命中测试：用 02 算出的 Area，调用 $area->containsPosition(new Position($col,$row))
 *      判断点击落在哪块面板。
 *   4) 滚轮：kind 为 ScrollUp / ScrollDown，用来调整一个滚动偏移。
 *
 * 运行：php examples/04-mouse.php
 * 操作：鼠标点击三块面板之一（聚焦变绿）；滚轮上下（看 scroll 变化）；
 *       q / Esc 退出。注意：滚轮需要终端支持 SGR 鼠标（php-tui/term 默认解析 \e[<...M/m）。
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib.php';

use PhpTui\Term\Terminal;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\MouseButton;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Text\Title;

// ── 进入终端（注意多了 enableMouseCapture）────────────────────
$term = Terminal::new(eventProvider: new BlockingTtyEventProvider());
$term->enableRawMode();
$term->queue(
    Actions::alternateScreenEnable(),
    Actions::enableMouseCapture(),   // ★ 关键：开启鼠标捕获
    Actions::cursorHide(),
    Actions::setTitle('04 Mouse')
);
$term->flush();

$backend = PhpTermBackend::new($term);
$display = DisplayBuilder::default($backend)->fullscreen()->build();
$events = $term->events();

$names = ['Sidebar', 'Editor', 'Terminal'];  // 与下面的切分顺序一致

/**
 * 用 Layout::split 算出每块面板的矩形，顺序与 GridWidget 的 widgets 对应：
 *   [0]=Sidebar [1]=Editor [2]=Terminal [3]=Log
 * 顶部 80% 是三列面板，底部固定 8 行是事件日志。
 */
function splitAreas(Area $viewport): array
{
    $root = Layout::default()
        ->constraints([Constraint::percentage(80), Constraint::length(8)])
        ->direction(Direction::Vertical)
        ->split($viewport);

    $panes = Layout::default()
        ->constraints([Constraint::length(30), Constraint::min(10), Constraint::min(10)])
        ->direction(Direction::Horizontal)
        ->split($root->get(0));

    return [$panes->get(0), $panes->get(1), $panes->get(2), $root->get(1)];
}

// 命中测试：返回点击落在哪块面板（-1 表示没击中任何面板）
function hitPane(array $areas, int $col, int $row): int
{
    $pos = new Position($col, $row);
    foreach ($areas as $i => $a) {
        if ($a->containsPosition($pos)) {
            return $i;
        }
    }
    return -1;
}

$focus  = 1;        // 当前聚焦的面板下标（默认 Editor）
$scroll = 0;        // 滚轮滚动偏移
$log    = [];       // 原始鼠标事件日志（新在最前）
$maxLog = 6;
$quit   = false;

while (!$quit) {
    $areas = splitAreas($display->viewportArea());

    $leaf = function (int $i) use ($areas, $focus, $names): Widget {
        $a = $areas[$i];
        $rect = sprintf('rect %d,%d %dx%d', $a->position->x, $a->position->y, $a->width, $a->height);
        $on = ($i === $focus);
        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle(Style::default()->fg($on ? AnsiColor::LightGreen : AnsiColor::Gray))
            ->titles(Title::fromString(' ' . $names[$i] . ($on ? ' *' : '') . ' '))
            ->widget(ParagraphWidget::fromString($names[$i] . "\n" . $rect . "\n点击我聚焦"));
    };
    // 让 $leaf 知道 $names 之外的日志面板文案
    $logWidget = BlockWidget::default()
        ->borders(Borders::ALL)
        ->borderStyle(Style::default()->fg(AnsiColor::LightCyan))
        ->titles(Title::fromString(' MOUSE LOG (scroll=' . $scroll . ') '))
        ->widget(ParagraphWidget::fromString(implode("\n", $log) ?: '(无事件)'));

    $panesRow = GridWidget::default()
        ->direction(Direction::Horizontal)
        ->constraints(Constraint::length(30), Constraint::min(10), Constraint::min(10))
        ->widgets($leaf(0), $leaf(1), $leaf(2));

    $root = GridWidget::default()
        ->direction(Direction::Vertical)
        ->constraints(Constraint::percentage(80), Constraint::length(8))
        ->widgets($panesRow, $logWidget);

    $display->draw($root);

    $event = $events->next();
    if ($event === null) {
        break;
    }

    // 记录原始事件
    if ($event instanceof MouseEvent) {
        $line = sprintf('%s %s @(%d,%d) mods=%d',
            $event->kind->name, $event->button->name, $event->column, $event->row, $event->modifiers);
        array_unshift($log, $line);
        if (count($log) > $maxLog) {
            $log = array_slice($log, 0, $maxLog);
        }
    }

    // ── 交互 ──
    if ($event instanceof MouseEvent) {
        switch ($event->kind) {
            case MouseEventKind::Down:
                $hit = hitPane($areas, $event->column, $event->row);
                if ($hit >= 0 && $hit < 3) {
                    $focus = $hit;          // 点击面板 → 聚焦（命中测试）
                }
                break;
            case MouseEventKind::ScrollDown:
                $scroll += 1;
                break;
            case MouseEventKind::ScrollUp:
                $scroll = max(0, $scroll - 1);
                break;
            // Drag / Moved / Up / ScrollLeft / ScrollRight 仅记录在日志
        }
    } elseif ($event instanceof CharKeyEvent && strtolower($event->char) === 'q') {
        $quit = true;
    } elseif ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc) {
        $quit = true;
    }
}

// 还原终端（含关闭鼠标捕获）
$term->queue(
    Actions::alternateScreenDisable(),
    Actions::disableMouseCapture(),
    Actions::cursorShow()
);
$term->flush();
$term->disableRawMode();
