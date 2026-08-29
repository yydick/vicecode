<?php
declare(strict_types=1);

namespace App\Text;

use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Span;

/**
 * 按水平视口裁剪一行 Span，并合并相邻同色字素。
 *
 * 拆自 App 的编辑器/终端输入行共用逻辑：两者都是「一行文本 + 光标 + 水平滚动」，
 * 需要把整行裁剪到 [scrollLeft, scrollLeft+textW) 再叠加光标反显。
 * M3 的 diff 视图（同样带 +/- 前缀与着色）也会复用这里。
 */
final class SpanClip
{
    /**
     * 把整行 Span 列表裁剪到水平视口 [scrollLeft, scrollLeft+textW)，并叠加光标反显。
     *
     * @param array<int,array{0:string,1:Style}> $lineSpans
     * @return array<int,Span>
     */
    public static function clip(array $lineSpans, bool $cursorHere, int $curCol, int $scrollLeft, int $textW): array
    {
        // 展开为字素列表 [g, Style]
        $gs = [];
        foreach ($lineSpans as [$text, $st]) {
            foreach (mb_str_split($text) as $g) {
                $gs[] = [$g, $st];
            }
        }
        // 光标反显
        if ($cursorHere) {
            if ($curCol >= 0 && $curCol < count($gs)) {
                [$g, $st] = $gs[$curCol];
                // 必须 clone：Style::addModifier() 是原地修改并返回 $this，
                // 而同一行的字素（乃至高亮缓存里的多行）可能共享同一个 Style 实例，
                // 直接 addModifier 会把整行（甚至其它行）一起反显。
                $gs[$curCol] = [$g === '' ? ' ' : $g, (clone $st)->addModifier(Modifier::REVERSED)];
            } else {
                // 光标在行尾（无字素）：补一个反显空格
                $gs[] = [' ', Style::default()->addModifier(Modifier::REVERSED)];
            }
        }
        // 按显示宽度裁剪到视口。
        // 必须用「起始列 + 自身宽度 <= 右边界」判断：只比较起始列的话，宽字符（占 2 列）
        // 会在边界处溢出 1 列，行总宽超出面板 → php-tui 的 LineTruncator 把这一行
        // 折成两行，后续所有行整体下移、行号错位（用户报的「幽灵行」）。
        $picked = [];
        $w = 0;
        $right = $scrollLeft + $textW;
        foreach ($gs as [$g, $st]) {
            $gw = DisplayWidth::dispWidth($g);
            if ($w >= $scrollLeft && $w + $gw <= $right) {
                $picked[] = [$g, $st];
            }
            $w += $gw;
        }
        return self::group($picked);
    }

    /**
     * 合并连续同色字素为一个 Span（降低 Span 数量）。
     * @param array<int,array{0:string,1:Style}> $picked
     * @return array<int,Span>
     */
    private static function group(array $picked): array
    {
        $out = [];
        $curText = '';
        $curStyle = null;
        foreach ($picked as [$g, $st]) {
            if ($curStyle === null) {
                $curText = $g;
                $curStyle = $st;
            } elseif (self::styleKey($st) === self::styleKey($curStyle)) {
                $curText .= $g;
            } else {
                $out[] = Span::styled($curText, $curStyle);
                $curText = $g;
                $curStyle = $st;
            }
        }
        if ($curText !== '') {
            $out[] = Span::styled($curText, $curStyle);
        }
        return $out;
    }

    private static function styleKey(Style $s): string
    {
        return ($s->fg?->name ?? '') . ':' . $s->addModifiers;
    }
}
