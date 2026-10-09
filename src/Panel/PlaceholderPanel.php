<?php
declare(strict_types=1);

namespace App\Panel;

use App\Text\DisplayWidth;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Margin;
use PhpTui\Tui\Widget\Widget;

/**
 * 占位面板：Problems / Output / Debug Console / Ports 无后端，仅显示居中淡色提示，
 * 维持 VSCode 底部面板的外观与切换体验。
 */
final class PlaceholderPanel
{
    public static function render(Area $area, string $label): Widget
    {
        $text = $label . ' — 未实现';
        $w = DisplayWidth::dispWidth($text);
        $inner = $area->inner(new Margin(1, 1));
        $col0 = max(0, (int) (($inner->width - $w) / 2));
        $midRow = max(0, (int) ($inner->height / 2));
        $style = Style::default()->fg(AnsiColor::DarkGray);

        $lines = [];
        for ($r = 0; $r < $inner->height; $r++) {
            if ($r === $midRow) {
                $lines[] = Line::fromSpans(
                    Span::styled(str_repeat(' ', $col0) . $text, $style)
                );
            } else {
                $lines[] = Line::fromSpans(Span::styled('', Style::default()));
            }
        }
        if ($lines === []) {
            $lines[] = Line::fromSpans(Span::styled('', Style::default()));
        }
        return ParagraphWidget::fromLines(...$lines);
    }
}
