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

    /**
     * 取**末尾** $disp 显示列（在字素边界对齐，不劈开 CJK）。
     * 用于「放不下时保留尾部」的截断：路径/文件名这类信息，尾部（当前目录名、
     * 扩展名）比头部更有用，且头部往往还有标签前缀占位置。
     */
    public static function mbTailDisp(string $s, int $disp): string
    {
        if ($disp <= 0 || $s === '') {
            return '';
        }
        $w = self::dispWidth($s);
        if ($w <= $disp) {
            return $s;
        }
        $out = '';
        $acc = 0;
        foreach (array_reverse(mb_str_split($s)) as $g) {
            $cw = self::dispWidth($g);
            if ($acc + $cw > $disp) {
                break;
            }
            $out = $g . $out;
            $acc += $cw;
        }
        return $out;
    }

    /**
     * 剔除控制字符（\x00–\x1F、\x7F，含 \n / \r / ESC 序列的起始字节）。
     * 用于净化外部上报值（shell 经 OSC 报的 cwd、文件名、分支名）：控制字符
     * 不显示却仍被 dispWidth 算作 1 列，会让固定宽度的面板少显内容。
     * 走字节级替换（不加 /u），非法 UTF-8 输入也不会让 preg 返回 null。
     */
    public static function stripControl(string $s): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $s);
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

    /**
     * 按显示列宽切片（横向滚动用）：跳过前 $skipDisp 显示列，再取 $disp 显示列。
     * 在字素边界对齐（跨越 skip 边界的那个字素整段跳过），不会劈开 CJK 字符。
     */
    public static function mbSubDisp(string $s, int $skipDisp, int $disp): string
    {
        if ($disp <= 0) {
            return '';
        }
        if ($skipDisp <= 0) {
            return self::mbCutDisp($s, $disp);
        }
        $w = 0;
        $out = '';
        $collecting = false;
        foreach (mb_str_split($s) as $g) {
            $cw = self::dispWidth($g);
            if (!$collecting) {
                if ($w >= $skipDisp) {
                    // 起点已落在 skip 边界（精确对齐）→ 从这里开始收集
                    $collecting = true;
                } elseif ($w + $cw <= $skipDisp) {
                    // 整段落在 skip 区内 → 跳过
                    $w += $cw;
                    continue;
                } else {
                    // 宽字符跨越 skip 边界：整段跳过，保持字素边界干净
                    $w += $cw;
                    $collecting = true;
                    continue;
                }
            }
            if ($w + $cw > $skipDisp + $disp) {
                break;
            }
            $out .= $g;
            $w += $cw;
        }
        return $out;
    }

    /**
     * 按显示列宽软换行（AI 消息流、帮助页等长文本用）。
     *
     * ⚠️ 为什么必须自己换行：php-tui 的 `ParagraphWidget` 默认 `Wrap::None`，走
     * `LineTruncator` —— 它名虽为 Truncator，**超宽时是折行不是截断**，一行超宽 1 列
     * 就会把后续所有行整体挤下去（M1 的「幽灵行」bug，用户报的是「第 N 行出现别处的
     * 字符串」）。所以任何可能超宽的文本，都必须在应用层先切成宽度合规的若干行。
     *
     * 先按显式 `\n` 分段，再对每段按显示列宽贪心切分；宽字符不会被劈开
     * （放不下就整体挪到下一行，否则行宽会超出 1 列，又触发上面的折行）。
     * 空段保留为一个空行，否则连续换行会被吃掉。
     *
     * @return string[] 每行 dispWidth() 都 <= $width（$width<=0 时返回 ['']）
     */
    public static function mbWrapDisp(string $s, int $width): array
    {
        if ($width <= 0) {
            return [''];
        }
        $out = [];
        foreach (explode("\n", $s) as $para) {
            if ($para === '') {
                $out[] = '';
                continue;
            }
            $line = '';
            $w = 0;
            foreach (mb_str_split($para) as $g) {
                $cw = self::dispWidth($g);
                if ($line !== '' && $w + $cw > $width) {
                    $out[] = $line;
                    $line = '';
                    $w = 0;
                }
                $line .= $g;
                $w += $cw;
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * 显示列宽 → 字符索引（反向切片，供鼠标点击定位）。
     * 给定绝对显示列 $dispCol，返回落在该显示列上的字素（grapheme）起始字符索引；
     * 落在某宽字素中间时，光标停在该字素起点（不劈开 CJK）。
     * $dispCol <= 0 返回 0；$dispCol 超出行尾返回字符总数（光标落在行末之后）。
     */
    public static function mbDispToCharIndex(string $s, int $dispCol): int
    {
        if ($dispCol <= 0) {
            return 0;
        }
        $w = 0;
        $i = 0;
        foreach (mb_str_split($s) as $g) {
            $gw = self::dispWidth($g);
            if ($w + $gw > $dispCol) {
                // 点击落在字素 $i 的显示区间内 → 光标停在该字素起点
                return $i;
            }
            $w += $gw;
            $i++;
        }
        return $i; // 点击超出末尾 → 落在行末之后
    }
}
