<?php
declare(strict_types=1);

namespace App\Panel;

use App\Text\DisplayWidth;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Widget;

/**
 * 底部「面板切换条」（Terminal / Problems / Output / Debug Console / Ports）。
 * 当前项 REVERSED 高亮，其余用 editorTab 主题样式（仿编辑器标签风格）。
 * 仅外观：Problems/Output/Debug Console/Ports 无后端，切到即显示占位面板。
 */
final class BottomTabs
{
    /** view 名 => 标签文本。总量 42 列（120 宽终端区可全显示）；放不下时靠渲染截断 =
     *  不可点（命中矩形与渲染同源，与状态栏可点段同一取舍）。 */
    public const VIEWS = [
        'terminal' => 'TERMINAL',
        'problems' => 'PROBLEMS',
        'output'   => 'OUTPUT',
        'debug'    => 'DEBUG',
        'ports'    => 'PORTS',
    ];

    /** 上次渲染记录的命中矩形（单进程 CLI，安全） */
    private static array $rects = [];

    public static function render(Area $line, string $active, mixed $theme): Widget
    {
        $x = $line->position->x + 1;
        self::$rects = [];
        $spans = [];
        foreach (self::VIEWS as $view => $label) {
            $txt = ' ' . $label . ' ';
            $w = DisplayWidth::dispWidth($txt);
            $st = $view === $active
                ? Style::default()->addModifier(Modifier::REVERSED)
                : $theme->style('editorTab');
            $spans[] = Span::styled($txt, $st);
            self::$rects[] = ['x0' => $x, 'x1' => $x + $w - 1, 'view' => $view];
            $x += $w;
        }
        return ParagraphWidget::fromLines(Line::fromSpans(...$spans));
    }

    /** 命中面板切换条：返回 view 名或 null（col 为绝对列） */
    public static function hit(Area $line, int $col): ?string
    {
        foreach (self::$rects as $r) {
            if ($col >= $r['x0'] && $col <= $r['x1']) {
                return $r['view'];
            }
        }
        return null;
    }
}
