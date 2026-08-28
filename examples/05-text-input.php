<?php
declare(strict_types=1);

/**
 * 教学程序 05 — 文本「输入 / 输出」的最简交互。
 *
 * 目标：理解 TUI 里「接收键盘文本」与「回显输出」的基本模型：
 *   - 用一个字符串缓冲 ($input) 累积用户敲的字符；
 *   - 回车把 $input 提交成一条「输出」，清空缓冲；
 *   - 退格删除缓冲最后一个字符；
 *   - 画面：上方是输出历史（只读），下方是输入行（带光标）。
 *
 * 运行：php examples/05-text-input.php
 * 操作：直接打字；Backspace 删除；Enter 提交；Esc 或 Ctrl+C 退出。
 *
 * 说明：本例刻意不引入滚动条 / 分栏布局（那是 02-layout、06-list-scroll 的主题），
 * 只聚焦「字符如何进来、如何显示」这条主线。
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib.php';

use PhpTui\Term\Terminal;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
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
    Actions::cursorHide(),
    Actions::setTitle('05 Text Input')
);
$term->flush();

$backend = PhpTermBackend::new($term);
$display = DisplayBuilder::default($backend)->fullscreen()->build();
$events = $term->events();

$output = [];          // 已提交的输出历史（字符串数组）
$input  = '';          // 当前正在输入的缓冲
$quit   = false;
$maxOut = 14;          // 简单起见，最多显示最近 14 行输出（真实滚动见 06）

while (!$quit) {
    $lines = array_slice($output, -$maxOut);
    $lines[] = '';                          // 空行分隔
    $lines[] = '> ' . $input . '▌';         // 输入行，▌ 是“假光标”

    $display->draw(
        BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle(Style::default()->fg(AnsiColor::LightGreen))
            ->titles(Title::fromString(' TEXT INPUT (Esc / Ctrl+C 退出) '))
            ->widget(ParagraphWidget::fromString(implode("\n", $lines)))
    );

    $event = $events->next();
    if ($event === null) {
        break; // EOF
    }

    // ── 字符事件：可打印字符 / 回车 / 退格 / Ctrl+C ──
    if ($event instanceof CharKeyEvent) {
        // Ctrl+C：修饰键含 CONTROL 且字符为 'c' → 退出
        if (($event->modifiers & KeyModifiers::CONTROL) && strtolower($event->char) === 'c') {
            $quit = true;
            continue;
        }
        $c = $event->char;
        if ($c === "\r" || $c === "\n") {
            $output[] = ($input === '' ? '(空)' : $input); // 回车提交
            $input = '';
        } elseif ($c === "\x7f" || $c === "\x08") {
            $input = substr($input, 0, -1);                // 退格删除
        } elseif (strlen($c) === 1 && ord($c) >= 32) {
            $input .= $c;                                  // 普通可打印 ASCII
        }
        continue;
    }

    // ── 编码键事件：Esc 退出、Backspace 删除 ──
    if ($event instanceof CodedKeyEvent) {
        if ($event->code === KeyCode::Esc) {
            $quit = true;
        } elseif ($event->code === KeyCode::Backspace) {
            $input = substr($input, 0, -1);
        }
    }
}

// ── 还原终端 ────────────────────────────────────────────────
$term->queue(
    Actions::alternateScreenDisable(),
    Actions::cursorShow()
);
$term->flush();
$term->disableRawMode();
