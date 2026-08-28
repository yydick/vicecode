<?php
declare(strict_types=1);

/**
 * 教学程序 03 — 键盘事件的完整模型。
 *
 * php-tui/term 把按键分成两大类，理解它们是写交互的前提：
 *   1) CharKeyEvent —— 有“字符”的键（字母/数字/空格/可打印符号，带修饰键）。
 *       字段：string $char, int $modifiers
 *   2) CodedKeyEvent —— 没有“字符”的功能键（方向键/Esc/Tab/Enter/F 键…）。
 *       字段：KeyCode $code, int $modifiers, KeyEventKind $kind
 *
 * 修饰键（Ctrl/Alt/Shift/Super…）都是“位标志”，要用 按位与 & 来检测，
 * 而不是 == 比较（一个键可能同时带 Ctrl+Alt）。
 *
 * 本程序把每一次按键“原样记录”到日志里，方便你直观看到：
 *   按 a / Shift+a / Ctrl+a / ← / Esc / Tab …… 各产生什么事件。
 * 另带一点点交互：方向键移动光标点，可打印字符进“已输入”缓冲，
 * Enter 清日志，Backspace 删缓冲，Ctrl+Q 或 Esc 退出。
 *
 * 运行：php examples/03-keyboard.php
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

// 把修饰键的整数位标志解码成可读字符串，例如 CTRL+ALT
function mods(int $m): string
{
    $parts = [];
    if ($m & KeyModifiers::CONTROL) $parts[] = 'CTRL';
    if ($m & KeyModifiers::ALT)     $parts[] = 'ALT';
    if ($m & KeyModifiers::SHIFT)   $parts[] = 'SHIFT';
    if ($m & KeyModifiers::SUPER)   $parts[] = 'SUPER';
    if ($m & KeyModifiers::META)    $parts[] = 'META';
    if ($m & KeyModifiers::HYPER)   $parts[] = 'HYPER';
    return $parts ? implode('+', $parts) : 'NONE';
}

// 把一次事件转成一行日志
function describe($event): string
{
    if ($event instanceof CharKeyEvent) {
        $ch = $event->char;
        // 把不可见字符显示成转义形式，便于观察
        $disp = match ($ch) {
            "\r" => '\\r', "\n" => '\\n', "\t" => '\\t',
            "\x7f" => '\\x7f', "\x08" => '\\x08', ' ' => '␣(space)',
            default => $ch,
        };
        return sprintf('Char  char=<%s>  mods=%s', $disp, mods($event->modifiers));
    }
    if ($event instanceof CodedKeyEvent) {
        return sprintf('Code  code=%-8s mods=%s', $event->code->name, mods($event->modifiers));
    }
    return 'Other ' . get_class($event);
}

// ── 进入终端 ────────────────────────────────────────────────
$term = Terminal::new(eventProvider: new BlockingTtyEventProvider());
$term->enableRawMode();
$term->queue(Actions::alternateScreenEnable(), Actions::cursorHide(), Actions::setTitle('03 Keyboard'));
$term->flush();

$backend = PhpTermBackend::new($term);
$display = DisplayBuilder::default($backend)->fullscreen()->build();
$events = $term->events();

$log    = [];      // 按键日志（新在最前）
$typed  = '';      // 已输入的字符缓冲
$cursor = [0, 0];  // 方向键移动的光标点
$quit   = false;
$maxLog = 18;

while (!$quit) {
    // 组装画面文本
    $lines = [];
    $lines[] = '←→↑↓ 移点 · 打字进缓冲 · Enter 清日志 · Backspace 删 · Ctrl+Q/Esc 退出';
    $lines[] = sprintf('cursor=(%d,%d)   typed: [%s]', $cursor[0], $cursor[1], $typed);
    $lines[] = str_repeat('─', 40);
    foreach ($log as $l) {
        $lines[] = $l;
    }

    $display->draw(
        BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle(Style::default()->fg(AnsiColor::LightYellow))
            ->titles(Title::fromString(' KEYBOARD EVENT LOG '))
            ->widget(ParagraphWidget::fromString(implode("\n", $lines)))
    );

    $event = $events->next();
    if ($event === null) {
        break; // EOF
    }

    // 先记录原始事件（无论如何处理，都进日志）
    array_unshift($log, describe($event));
    if (count($log) > $maxLog) {
        $log = array_slice($log, 0, $maxLog);
    }

    // ── 自定义交互逻辑 ──
    if ($event instanceof CharKeyEvent) {
        // Ctrl+Q 退出（修饰键位标志用 & 检测）
        if (($event->modifiers & KeyModifiers::CONTROL) && strtolower($event->char) === 'q') {
            $quit = true;
            continue;
        }
        $c = $event->char;
        if ($c === "\r" || $c === "\n") {
            $log = [];                              // Enter 清日志
        } elseif ($c === "\x7f" || $c === "\x08") {
            $typed = substr($typed, 0, -1);        // Backspace 删缓冲
        } elseif (strlen($c) === 1 && ord($c) >= 32) {
            $typed .= $c;                          // 可打印字符进缓冲
        }
        continue;
    }

    if ($event instanceof CodedKeyEvent) {
        switch ($event->code) {
            case KeyCode::Esc:
                $quit = true;
                break;
            case KeyCode::Left:  $cursor[0] = max(0, $cursor[0] - 1); break;
            case KeyCode::Right: $cursor[0] += 1; break;
            case KeyCode::Up:    $cursor[1] = max(0, $cursor[1] - 1); break;
            case KeyCode::Down:  $cursor[1] += 1; break;
            case KeyCode::Backspace:
                $typed = substr($typed, 0, -1);
                break;
            // Tab / Home / End / PageUp / PageDown / Delete / Insert / FKey …
            // 这里不特别处理，仅显示在日志里，方便你观察它们长什么样。
        }
    }
}

// 还原终端
$term->queue(Actions::alternateScreenDisable(), Actions::cursorShow());
$term->flush();
$term->disableRawMode();
