<?php
declare(strict_types=1);

namespace App\Widget;

use App\Core\StatusPicker;
use App\Core\Theme;
use App\Text\DisplayWidth;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Widget;

/**
 * 状态栏可点段弹出的选项列表（语言 / 主题）。
 *
 * 复用 {@see DropdownOverlay} 的透明叠加机制：只画自己那块子矩形、其余缓冲不动，
 * 所以列表不会把底下的界面抹掉。**调用方必须确保该渲染器已注册**
 * （`DisplayBuilder::addWidgetRenderer(DropdownOverlay::renderer())`，见 `bin/vicecode.php`），
 * 否则本 widget 会被静默跳过、什么都不显示。
 *
 * 位置：贴**状态栏上方**右对齐到被点的那个段（与「从状态栏弹出来」的直觉一致）。
 * 上方放不下时缩高度（而不是改到下方 —— 状态栏下面没有东西）。
 */
final class PickerOverlay
{
    /** 面板至少要有这么宽才值得弹出 */
    private const MIN_PANEL_W = 14;

    /**
     * 浮层的几何：**渲染与鼠标命中必须共用这一份**。
     *
     * 与侧栏 `gitRects` 存在的理由相同 —— 位置算两遍迟早会漂移（GIT 的 `Commit ▾`
     * 就是这么坏的：渲染画在左边、命中判定却查面板最右一列，点了毫无反应）。
     *
     * @param int $anchorX 被点段的左沿（绝对列），面板默认对齐到它
     * @param int $bottom 状态栏顶行的绝对行号（面板底边紧贴它上方）
     * @return array{startX:int,top:int,panelW:int,panelH:int,rows:int,offset:int}|null
     *         null = 不画（视口太小 / 一行都放不下）
     */
    public static function geometry(StatusPicker $picker, int $anchorX, int $bottom, int $vpW): ?array
    {
        $count = count($picker->options);
        if ($count === 0 || $vpW < self::MIN_PANEL_W + 2 || $bottom < 4) {
            return null;
        }

        // 行数：全列出，但最多占状态栏上方的一半（留出上下文）
        $rows = min($count, max(1, $bottom - 2));
        $panelH = $rows + 2;   // 上下边框

        // 宽度：标题 / 各选项（含 2 列标记前缀）取最大，再夹到视口内
        $contentW = DisplayWidth::dispWidth(DisplayWidth::sanitizeContent($picker->title));
        foreach ($picker->options as $o) {
            $contentW = max($contentW, 2 + DisplayWidth::dispWidth(DisplayWidth::sanitizeContent($o['label'])));
        }
        $innerW = max(self::MIN_PANEL_W - 2, min($vpW - 4, $contentW + 2));
        $panelW = $innerW + 2;

        return [
            'startX' => max(0, min($anchorX, $vpW - $panelW)),
            'top' => max(0, $bottom - $panelH),
            'panelW' => $panelW,
            'panelH' => $panelH,
            'rows' => $rows,
            // 选项多于可视行时让高亮项留在可视区（与命令面板同一手法）
            'offset' => max(0, min($picker->sel - intdiv($rows, 2), $count - $rows)),
        ];
    }

    /**
     * 组装浮层；`geometry()` 返回 null 时返回 null（不画）。
     */
    public static function widget(
        StatusPicker $picker,
        int $anchorX,
        int $bottom,
        int $vpW,
        Theme $theme,
    ): ?Widget {
        $g = self::geometry($picker, $anchorX, $bottom, $vpW);
        if ($g === null) {
            return null;
        }
        $innerW = $g['panelW'] - 2;
        $slice = array_slice($picker->options, $g['offset'], $g['rows'], true);

        $base = Style::default()->fg($theme->color('menuDrop'));
        $hitStyle = Style::default()->fg($theme->color('menuSel'))->addModifier(Modifier::REVERSED);

        $lines = [];
        foreach ($slice as $i => $o) {
            $hit = $i === $picker->sel;
            $row = ($picker->isCurrent($i) ? '● ' : '  ')
                . DisplayWidth::sanitizeContent($o['label']);
            $lines[] = Line::fromSpans(
                Span::styled(DisplayWidth::mbCutDisp($row . ' ', $innerW), $hit ? $hitStyle : $base)
            );
        }

        $panel = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' ' . DisplayWidth::sanitizeContent($picker->title) . ' '))
            ->widget(ParagraphWidget::fromLines(...$lines));

        return new DropdownOverlay($panel, $g['startX'], $g['top'], $g['panelW'], $g['panelH']);
    }
}
