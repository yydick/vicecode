<?php
declare(strict_types=1);

/**
 * 教学程序 01 — 最小可用的 TUI「Hello」。
 *
 * 目标：理解一个 TUI 程序最不可省略的「生命周期四步」：
 *   进入  →  渲染  →  读事件  →  还原
 * 不引入任何布局、组件、状态，只画一个带边框的框，按 q 退出。
 *
 * 运行：php examples/01-hello.php
 * 退出：按 q（或 Esc）
 *
 * 关于终端所有权：本程序不碰 Swoole，终端完全由 php-tui/term 管理。
 * 这是经过验证、在真实 pty（WSL / Windows Terminal）上不会花屏、退出后回显正常的方案。
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib.php';

// ── 1. 需要的类 ────────────────────────────────────────────────
use PhpTui\Term\Terminal;        // 终端句柄：管 raw mode / 转义动作 / 事件
use PhpTui\Term\Actions;         // 各种转义动作（进/出全屏、隐藏光标等）
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend; // 把 php-tui 的绘制输出接到上面的 Terminal
use PhpTui\Tui\DisplayBuilder;   // 构造 Display（绘图画布）
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;     // 带边框/标题的容器
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget; // 一段文本
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;

// ── 2. 进入终端（生命周期第一步）────────────────────────────────
$term = Terminal::new(eventProvider: new BlockingTtyEventProvider());
$term->enableRawMode();          // 关闭终端回显与行缓冲，让我们可以逐键捕获
$term->queue(                    // 把动作排队（不会立刻发，等 flush）
    Actions::alternateScreenEnable(), // 切到「备用屏幕」，退出后原屏幕内容不丢
    Actions::cursorHide(),               // 隐藏光标，画面更干净
    Actions::setTitle('01 Hello')        // 给终端标签页/窗口设个标题
);
$term->flush();                  // 真正把上面的转义序列写出去

// ── 3. 准备「画布」───────────────────────────────────────────
// Display 是我们往上面画 Widget 的对象；Backend 决定画到哪（这里是 php-tui/term）。
// fullscreen() 表示占满整个终端区域。
$backend = PhpTermBackend::new($term);
$display = DisplayBuilder::default($backend)->fullscreen()->build();

// ── 4. 事件源 ─────────────────────────────────────────────────
// $term->events()->next() 是「阻塞式」同步读：没有按键/鼠标时就停在这里等。
// 这恰恰避开了 Swoole 在真实 pty 上 Event::add(STDIN) 不触发的坑。
$events = $term->events();

$quit = false;
while (!$quit) {
    // ── 4a. 渲染（每帧都重画整个界面，这是 immediate-mode 范式）──
    $display->draw(
        BlockWidget::default()
            ->borders(Borders::ALL)                      // 四周边框
            ->borderStyle(Style::default()->fg(AnsiColor::LightBlue))
            ->widget(
                ParagraphWidget::fromString(
                    "Hello, TUI!\n\n" .
                    "  这是 php-tui 0.2.1 + php-tui/term 的最小骨架。\n" .
                    "  按 q 退出。"
                )
            )
    );

    // ── 4b. 读一个事件（阻塞）──
    $event = $events->next();
    if ($event === null) {
        break; // stdin 关闭（EOF），例如管道结束
    }

    // ── 4c. 处理事件：这里只认「q」和「Esc」──
    if ($event instanceof CharKeyEvent && strtolower($event->char) === 'q') {
        $quit = true;
    } elseif ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc) {
        $quit = true;
    }
}

// ── 5. 还原终端（生命周期最后一步，缺一不可，否则退出后回显丢失/花屏）──
$term->queue(
    Actions::alternateScreenDisable(), // 退回原屏幕
    Actions::cursorShow()                      // 恢复光标
);
$term->flush();
$term->disableRawMode();            // 恢复回显与行缓冲
