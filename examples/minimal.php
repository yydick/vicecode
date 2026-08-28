<?php
declare(strict_types=1);

/**
 * 最小 TUI 交互研究样本（无 Swoole）。
 *
 * 验证三件最基础的事：
 *   1) 终端生命周期：进 alternate screen + raw mode + 鼠标捕获，退出时反向还原；
 *   2) 真实渲染：用 php-tui Display 画一个带边框的框；
 *   3) 真实输入：用 php-tui/term 的 Terminal::events()->next() 阻塞读键/鼠标。
 *
 * 操作：q / Esc 退出；方向键或鼠标点击移动右下角的 "dot" 坐标显示。
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
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Text\Title;

$term = Terminal::new(eventProvider: new BlockingTtyEventProvider());
$term->enableRawMode();
$term->queue(
    Actions::alternateScreenEnable(),
    Actions::enableMouseCapture(),
    Actions::cursorHide(),
    Actions::setTitle('Minimal TUI')
);
$term->flush();

$backend = PhpTermBackend::new($term);
$display = DisplayBuilder::default($backend)->fullscreen()->build();
$events = $term->events();

$lines = [
    'Hello TUI — the simplest interaction.',
    '',
    '  q / Esc : quit',
    '  arrows  : move the dot',
    '  click   : move the dot',
    '',
    '  dot: (0,0)',
];
$cursor = [0, 0];
$quit = false;

while (!$quit) {
    $lines[count($lines) - 1] = sprintf('  dot: (%d,%d)', $cursor[0], $cursor[1]);
    $display->draw(
        BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle(Style::default()->fg(AnsiColor::LightBlue))
            ->titles(Title::fromString(' MINIMAL '))
            ->widget(ParagraphWidget::fromString(implode("\n", $lines)))
    );

    $event = $events->next();
    if ($event === null) {
        break; // EOF
    }
    if ($event instanceof CharKeyEvent && strtolower($event->char) === 'q') {
        $quit = true;
    } elseif ($event instanceof CodedKeyEvent) {
        if ($event->code === KeyCode::Esc) {
            $quit = true;
        } elseif ($event->code === KeyCode::Left) {
            $cursor[0] = max(0, $cursor[0] - 1);
        } elseif ($event->code === KeyCode::Right) {
            $cursor[0] += 1;
        } elseif ($event->code === KeyCode::Up) {
            $cursor[1] = max(0, $cursor[1] - 1);
        } elseif ($event->code === KeyCode::Down) {
            $cursor[1] += 1;
        }
    } elseif ($event instanceof MouseEvent && $event->kind === MouseEventKind::Down) {
        $cursor = [$event->column, $event->row];
    }
}

$term->queue(
    Actions::alternateScreenDisable(),
    Actions::disableMouseCapture(),
    Actions::cursorShow()
);
$term->flush();
$term->disableRawMode();
