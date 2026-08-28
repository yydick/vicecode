<?php
declare(strict_types=1);

/**
 * 教学程序 06 — 可滚动列表（Explorer 的地基）。
 *
 * 把前面学的拼起来：
 *   - 02 布局：用 viewportArea 当一个全屏列表区；
 *   - 05 文本：把列表项渲染成多行文本；
 *   - 03 键盘：↑/↓ 移动选中项，Enter “打开”；
 *   - 04 鼠标：滚轮滚动、点击某项直接选中（命中测试）。
 *
 * 核心教学点：滚动视口数学（viewport math）。列表可能比屏幕高，
 * 所以只渲染“可见窗口” [offset, offset+visible)，并保证选中项始终在窗口内。
 *
 * 运行：php examples/06-list-scroll.php
 * 操作：↑/↓ 或鼠标滚轮移动选中；点击某项选中；Enter 在底部状态行“打开”该项；
 *       q / Esc 退出。
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
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Text\Title;

// ── 进入终端 ────────────────────────────────────────────────
$term = Terminal::new(eventProvider: new BlockingTtyEventProvider());
$term->enableRawMode();
$term->queue(
    Actions::alternateScreenEnable(),
    Actions::enableMouseCapture(),
    Actions::cursorHide(),
    Actions::setTitle('06 List Scroll')
);
$term->flush();

$backend = PhpTermBackend::new($term);
$display = DisplayBuilder::default($backend)->fullscreen()->build();
$events = $term->events();

// 真实列出当前目录（给 Explorer 打样）；过滤掉 . 和 ..
$raw = array_filter(scandir('.'), fn ($f) => $f !== '.' && $f !== '..');
$items = array_values($raw);
$selected = 0;     // 当前选中项下标
$offset   = 0;     // 滚动偏移（可见窗口起点）
$status   = '用 ↑/↓ 或滚轮浏览，Enter 打开，q 退出';  // 底部状态
$quit = false;

// 把选中项移到可见窗口内，并夹紧边界 —— 这是滚动的核心数学
function clampView(Area $vp, array &$items, int &$selected, int &$offset): int
{
    $n = count($items);
    if ($n === 0) {
        $selected = 0; $offset = 0;
        return 0;
    }
    $selected = max(0, min($selected, $n - 1));
    // 边框占 2 行 + 顶部标题/路径行占 1 行 = 内容区高度
    $visible = max(1, $vp->height - 2 - 1);
    $offset = max(0, min($offset, $n - $visible));
    if ($selected < $offset) {
        $offset = $selected;                 // 选中项在窗口上方 → 滚上去
    } elseif ($selected >= $offset + $visible) {
        $offset = $selected - $visible + 1;  // 选中项在窗口下方 → 滚下来
    }
    return $visible;
}

while (!$quit) {
    $vp = $display->viewportArea();
    $visible = clampView($vp, $items, $selected, $offset);

    // 组装可见窗口内的行
    $lines = [];
    $lines[] = '📁 ' . getcwd();   // 第 1 行：路径（占 1 行）
    for ($i = $offset; $i < min($offset + $visible, count($items)); $i++) {
        $mark = ($i === $selected) ? '▶ ' : '  ';
        $name = $items[$i];
        if (is_dir($name)) {
            $name .= '/';
        }
        $lines[] = $mark . $name;
    }

    $display->draw(
        BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle(Style::default()->fg(AnsiColor::LightBlue))
            ->titles(Title::fromString(' EXPLORER '))
            ->widget(ParagraphWidget::fromString(implode("\n", $lines)))
    );

    $event = $events->next();
    if ($event === null) {
        break;
    }

    if ($event instanceof CharKeyEvent) {
        if (strtolower($event->char) === 'q') {
            $quit = true;
        } elseif ($event->char === "\r" || $event->char === "\n") {
            $status = '打开: ' . ($items[$selected] ?? '(空)');
        }
        continue;
    }

    if ($event instanceof CodedKeyEvent) {
        if ($event->code === KeyCode::Esc) {
            $quit = true;
        } elseif ($event->code === KeyCode::Up) {
            $selected = max(0, $selected - 1);
        } elseif ($event->code === KeyCode::Down) {
            $selected = min(count($items) - 1, $selected + 1);
        }
        continue;
    }

    if ($event instanceof MouseEvent) {
        // 滚轮：直接移动选中项（clampView 会保证其可见）
        if ($event->kind === MouseEventKind::ScrollDown) {
            $selected = min(count($items) - 1, $selected + 1);
        } elseif ($event->kind === MouseEventKind::ScrollUp) {
            $selected = max(0, $selected - 1);
        } elseif ($event->kind === MouseEventKind::Down) {
            // 点击命中：计算点击落在第几项
            // 列表内容区从 area.y+1(上边框)+1(路径行) 开始
            $topInner = $vp->position->y + 2;
            $clicked = $event->row - $topInner + $offset;
            if ($clicked >= 0 && $clicked < count($items)) {
                $selected = $clicked;
            }
        }
    }
}

// 还原终端
$term->queue(
    Actions::alternateScreenDisable(),
    Actions::disableMouseCapture(),
    Actions::cursorShow()
);
$term->flush();
$term->disableRawMode();
