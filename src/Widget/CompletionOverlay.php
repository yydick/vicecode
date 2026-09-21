<?php
declare(strict_types=1);

namespace App\Widget;

use App\Core\CompletionItem;
use App\Core\Theme;
use App\Text\DisplayWidth;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;

/**
 * Tab 补全的候选浮层（贴在锚点上方/下方的小列表）。
 *
 * 复用 {@see DropdownOverlay} 的透明叠加机制：只画自己那块子矩形、其余缓冲不动，
 * 所以候选列表不会把底下的编辑器/输入框抹掉。**调用方必须确保该渲染器已注册**
 * （`DisplayBuilder::addWidgetRenderer(DropdownOverlay::renderer())`，见 `EventLoop` 与 `bin/vicecode.php`），
 * 否则本 widget 会被静默跳过、什么都不显示。
 *
 * ⚠️ 候选来自文件系统 / 插件，属**外部值**：渲染前一律过 `DisplayWidth::sanitizeContent()`
 * （文件名里带 ESC 会改写终端，TAB 会撑错列宽 —— 与 `BUGFIXES` D5 同一条纪律）。
 */
final class CompletionOverlay
{
    /** 面板至少要有这么宽才值得弹出（太窄会盖住输入框且看不清内容） */
    private const MIN_PANEL_W = 12;

    /**
     * 组装浮层。返回 null = **不画**（没候选、视口太小、上下都塞不下）。
     *
     * 位置策略：优先贴**锚点上方**（多行输入框在屏幕下方，向上弹才不会挡住正在打的字），
     * 上方放不下就改到锚点下方，都放不下则不画 —— 宁可不出候选，也不盖住用户正在输入的地方。
     *
     * @param list<CompletionItem> $items
     * @param Area $anchor 锚点矩形（AI 输入框整块 / 编辑器光标所在那一行）
     */
    public static function widget(
        array $items,
        int $sel,
        Area $anchor,
        int $vpW,
        int $vpH,
        Theme $theme,
    ): ?Widget {
        if ($items === [] || $vpW < self::MIN_PANEL_W + 2 || $vpH < 6) {
            return null;
        }

        // 高度：候选数，但至少给视口上下留出空间（不然浮层顶满整屏）
        $rows = min(count($items), max(1, $vpH - 4));
        $panelH = $rows + 2; // 上下边框

        // 宽度：最宽的「label   detail」+ 两侧各 1 列内边距，再夹到视口内
        $contentW = 0;
        foreach ($items as $it) {
            $w = DisplayWidth::dispWidth(DisplayWidth::sanitizeContent($it->label));
            if ($it->detail !== '') {
                $w += 2 + DisplayWidth::dispWidth(DisplayWidth::sanitizeContent($it->detail));
            }
            $contentW = max($contentW, $w);
        }
        $innerW = max(self::MIN_PANEL_W - 2, min($vpW - 4, $contentW + 2));
        $panelW = $innerW + 2;

        // 列：与锚点左沿对齐，右越界则左移夹紧
        $startX = max(0, min($anchor->left(), $vpW - $panelW));

        // 行：优先上方
        $top = $anchor->top() - $panelH;
        if ($top < 0) {
            $below = $anchor->bottom() + 1;
            $top = ($below + $panelH <= $vpH) ? $below : -1;
        }
        if ($top < 0) {
            return null;
        }

        // 滚动：让选中项大致居中（与命令面板同一手法）
        $offset = max(0, min($sel - intdiv($rows, 2), max(0, count($items) - $rows)));
        $slice = array_slice($items, $offset, $rows);

        $base = Style::default()->fg($theme->color('menuDrop'));
        $hitStyle = Style::default()->fg($theme->color('menuSel'))->addModifier(Modifier::REVERSED);

        $lines = [];
        foreach ($slice as $i => $it) {
            $hit = ($offset + $i) === $sel;
            $row = ' ' . DisplayWidth::sanitizeContent($it->label);
            if ($it->detail !== '') {
                $row .= '  ' . DisplayWidth::sanitizeContent($it->detail);
            }
            $lines[] = Line::fromSpans(
                Span::styled(DisplayWidth::mbCutDisp($row . ' ', $innerW), $hit ? $hitStyle : $base)
            );
        }

        $panel = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->widget(ParagraphWidget::fromLines(...$lines));

        return new DropdownOverlay($panel, $startX, $top, $panelW, $panelH);
    }
}
