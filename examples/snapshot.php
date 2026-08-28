<?php
declare(strict_types=1);

/**
 * 验收辅助：把各教学例子的“初始画面”用 headless 方式渲染成 ASCII 文本。
 * 不需要真实终端/tty，方便在对话里直接看布局对不对。
 *
 * 运行：php examples/snapshot.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer;
use PhpTui\Tui\Extension\Core\CoreExtension;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;

const COLS = 96;
const ROWS = 30;

function snap(Widget $w): string
{
    $vp = Area::fromDimensions(COLS, ROWS);
    $ext = new CoreExtension();
    $renderers = [];
    foreach ($ext->widgetRenderers() as $r) {
        $renderers[] = $r;
    }
    $renderer = new AggregateWidgetRenderer($renderers);
    $buffer = Buffer::empty($vp);
    $renderer->render($renderer, $w, $buffer, $buffer->area());
    return implode("\n", $buffer->toLines());
}

function hr(string $t): void
{
    echo "\n\033[1;36m═══ $t ═══\033[0m\n";
}

// ── 01 hello ───────────────────────────────────────────────
hr('01-hello  (单框 + 问候)');
echo snap(
    BlockWidget::default()
        ->borders(Borders::ALL)
        ->borderStyle(Style::default()->fg(AnsiColor::LightBlue))
        ->titles(Title::fromString(' MINIMAL '))
        ->widget(ParagraphWidget::fromString(
            "Hello, TUI!\n\n  这是 php-tui 0.2.1 + php-tui/term 的最小骨架。\n  按 q 退出。"
        ))
);

// ── 02 layout ──────────────────────────────────────────────
function splitAreas02(Area $vp): array
{
    $root = Layout::default()
        ->constraints([Constraint::length(30), Constraint::min(10), Constraint::length(45)])
        ->direction(Direction::Horizontal)->split($vp);
    $main = Layout::default()
        ->constraints([Constraint::percentage(60), Constraint::percentage(40)])
        ->direction(Direction::Vertical)->split($root->get(1));
    $right = Layout::default()
        ->constraints([Constraint::percentage(75), Constraint::length(3)])
        ->direction(Direction::Vertical)->split($root->get(2));
    return [$root->get(0), $main->get(0), $main->get(1), $right->get(0), $right->get(1)];
}
function build02(array $a, int $focus): Widget
{
    $names = ['Explorer', 'Editor', 'Terminal', 'AI Stream', 'AI Input'];
    $leaf = fn(int $i): Widget => BlockWidget::default()
        ->borders(Borders::ALL)
        ->borderStyle(Style::default()->fg($i === $focus ? AnsiColor::LightGreen : AnsiColor::Gray))
        ->titles(Title::fromString(' ' . $names[$i] . ($i === $focus ? ' *' : '') . ' '))
        ->widget(ParagraphWidget::fromString($names[$i] . "\nrect: " .
            $a[$i]->position->x . ',' . $a[$i]->position->y . '  ' .
            $a[$i]->width . 'x' . $a[$i]->height . "\n(焦点用 Tab/数字切换)"));
    $main = GridWidget::default()->direction(Direction::Vertical)
        ->constraints(Constraint::percentage(60), Constraint::percentage(40))
        ->widgets($leaf(1), $leaf(2));
    $right = GridWidget::default()->direction(Direction::Vertical)
        ->constraints(Constraint::percentage(75), Constraint::length(3))
        ->widgets($leaf(3), $leaf(4));
    return GridWidget::default()->direction(Direction::Horizontal)
        ->constraints(Constraint::length(30), Constraint::min(10), Constraint::length(45))
        ->widgets($leaf(0), $main, $right);
}
hr('02-layout  (VSCode 五块布局，焦点=Editor)');
echo snap(build02(splitAreas02(Area::fromDimensions(COLS, ROWS)), 1));

// ── 03 keyboard ────────────────────────────────────────────
hr('03-keyboard  (按键日志示例)');
$sampleLog = [
    'Char  char=<A>  mods=SHIFT',
    'Char  char=<a>  mods=NONE',
    'Code  code=Left mods=NONE',
    'Char  char=<c>  mods=CTRL',
    'Code  code=Esc  mods=NONE',
];
echo snap(
    BlockWidget::default()
        ->borders(Borders::ALL)
        ->borderStyle(Style::default()->fg(AnsiColor::LightYellow))
        ->titles(Title::fromString(' KEYBOARD EVENT LOG '))
        ->widget(ParagraphWidget::fromString(
            "←→↑↓ 移点 · 打字进缓冲 · Enter 清日志 · Backspace 删 · Ctrl+Q/Esc 退出\n" .
            "cursor=(3,1)   typed: [He]\n" . str_repeat('─', 40) . "\n" .
            implode("\n", $sampleLog)
        ))
);

// ── 04 mouse ───────────────────────────────────────────────
function splitAreas04(Area $vp): array
{
    $root = Layout::default()
        ->constraints([Constraint::percentage(80), Constraint::length(8)])
        ->direction(Direction::Vertical)->split($vp);
    $panes = Layout::default()
        ->constraints([Constraint::length(30), Constraint::min(10), Constraint::min(10)])
        ->direction(Direction::Horizontal)->split($root->get(0));
    return [$panes->get(0), $panes->get(1), $panes->get(2), $root->get(1)];
}
hr('04-mouse  (三面板可点击 + 鼠标日志，焦点=Editor)');
{
    $a = splitAreas04(Area::fromDimensions(COLS, ROWS));
    $names = ['Sidebar', 'Editor', 'Terminal'];
    $leaf = function (int $i) use ($a, $names): Widget {
        $on = $i === 1;
        return BlockWidget::default()->borders(Borders::ALL)
            ->borderStyle(Style::default()->fg($on ? AnsiColor::LightGreen : AnsiColor::Gray))
            ->titles(Title::fromString(' ' . $names[$i] . ($on ? ' *' : '') . ' '))
            ->widget(ParagraphWidget::fromString($names[$i] . "\nrect " .
                $a[$i]->position->x . ',' . $a[$i]->position->y . ' ' . $a[$i]->width . 'x' . $a[$i]->height . "\n点击我聚焦"));
    };
    $log = BlockWidget::default()->borders(Borders::ALL)
        ->borderStyle(Style::default()->fg(AnsiColor::LightCyan))
        ->titles(Title::fromString(' MOUSE LOG (scroll=0) '))
        ->widget(ParagraphWidget::fromString("Down Left @(5,2) mods=0\n(无更多事件)"));
    $panesRow = GridWidget::default()->direction(Direction::Horizontal)
        ->constraints(Constraint::length(30), Constraint::min(10), Constraint::min(10))
        ->widgets($leaf(0), $leaf(1), $leaf(2));
    echo snap(GridWidget::default()->direction(Direction::Vertical)
        ->constraints(Constraint::percentage(80), Constraint::length(8))
        ->widgets($panesRow, $log));
}

// ── 05 text-input ──────────────────────────────────────────
hr('05-text-input  (输出历史 + 输入行)');
$out = ['hello', 'world', 'php-tui 真好用'];
echo snap(
    BlockWidget::default()
        ->borders(Borders::ALL)
        ->borderStyle(Style::default()->fg(AnsiColor::LightGreen))
        ->titles(Title::fromString(' TEXT INPUT (Esc / Ctrl+C 退出) '))
        ->widget(ParagraphWidget::fromString(
            implode("\n", $out) . "\n\n> 你好，TUI▌"
        ))
);

// ── 06 list-scroll ─────────────────────────────────────────
hr('06-list-scroll  (可滚动目录列表)');
$fake = ['.docs', 'bin', 'composer.json', 'examples', 'plan', 'src', 'tests', 'vendor'];
$lines = ["📁 /home/yydick/works/tui"];
for ($i = 0; $i < count($fake); $i++) {
    $mark = $i === 2 ? '▶ ' : '  ';
    $lines[] = $mark . $fake[$i] . (in_array($fake[$i], ['bin','examples','plan','src','tests','vendor','.docs']) ? '/' : '');
}
echo snap(
    BlockWidget::default()
        ->borders(Borders::ALL)
        ->borderStyle(Style::default()->fg(AnsiColor::LightBlue))
        ->titles(Title::fromString(' EXPLORER '))
        ->widget(ParagraphWidget::fromString(implode("\n", $lines)))
);
