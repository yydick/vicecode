<?php
declare(strict_types=1);

namespace App\Text;

/**
 * 多字节安全的「显示宽度」计算与裁剪/填充（按字符数或显示列宽，均非字节）。
 *
 * 拆自 App 的私有静态工具，供编辑器、终端输入行、侧栏 tab、状态栏等所有需要
 * 在固定宽度面板里摆放文本的场合复用（M3 的 diff 视图、M4 的搜索结果、
 * M6 的帮助页都会用到同一套宽度语义）。
 *
 * 关键决策：dispWidth() 必须与 php-tui 保持同源。
 * php-tui 的 LineTruncator 用 mb_strwidth() 累加行宽来决定一行是否被「切分」成两行
 * （注意它名虽为 Truncator，超宽时其实是折行）。这里曾自维护 Unicode 宽度区间表，
 * 漏了韩文音节 U+AC00–D7A3 等，算出的宽度比 php-tui 小 → 行溢出 1 列 →
 * 整片后续行被折行挤下去（用户报的「幽灵行」）。改用 mb_strwidth 从根上杜绝两边漂移。
 */
final class DisplayWidth
{
    /** 显示列宽（CJK / 全角 / emoji 算 2 列），与 php-tui 同源 */
    public static function dispWidth(string $s): int
    {
        if ($s === '') {
            return 0;
        }
        return mb_strwidth($s);
    }

    // ── 按字符数（非列宽）──────────────────────────

    public static function mbCut(string $s, int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        return mb_substr($s, 0, $n);
    }

    public static function mbPad(string $s, int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        $len = mb_strlen($s);
        if ($len >= $n) {
            return $s;
        }
        return $s . str_repeat(' ', $n - $len);
    }

    // ── 按显示列宽 ────────────────────────────────

    /** 从字符偏移 $off 起，按显示列宽截取最多 $disp 列（在字素边界截断，不劈开 CJK） */
    public static function mbSubstrDisp(string $s, int $off, int $disp): string
    {
        return self::mbCutDisp(mb_substr($s, $off), $disp);
    }

    /** 按显示列宽截取（在字素边界截断） */
    public static function mbCutDisp(string $s, int $disp): string
    {
        if ($disp <= 0) {
            return '';
        }
        $w = 0;
        $out = '';
        foreach (mb_str_split($s) as $g) {
            $cw = self::dispWidth($g);
            if ($w + $cw > $disp) {
                break;
            }
            $out .= $g;
            $w += $cw;
        }
        return $out;
    }

    /** 按显示列宽右侧补空格对齐 */
    public static function mbPadDisp(string $s, int $disp): string
    {
        $d = self::dispWidth($s);
        if ($d >= $disp) {
            return $s;
        }
        return $s . str_repeat(' ', $disp - $d);
    }
}
