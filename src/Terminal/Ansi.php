<?php
declare(strict_types=1);

namespace App\Terminal;

/**
 * ANSI / 脏字节清洗：把命令输出的任意字节块整理成可安全渲染的纯文本。
 *
 * 为什么独立成模块而不是留在 TerminalBuffer 里：
 * 这是纯函数（入 string 出 string、无状态依赖），后续 M4 搜索高亮、M5 LLM 输出
 * 等都需要把外部来源的 ANSI 颜色序列清掉再喂给 php-tui——届时可以直接复用，
 * 不必从 TerminalBuffer 里抠出来。也符合「可复用的先拆出来」的重构原则。
 *
 * 清洗顺序的关键点：
 * - 先整段移除 ANSI 序列（CSI / OSC / 其余两字符转义），再剔 C0 控制符。
 *   否则 `ls --color` 之类会留下 "[31mfoo[0m" 这种残渣。
 * - ANSI 序列属于 C0 范畴（\033 起头），但我们不解析颜色，留在行里会让
 *   php-tui 的显示宽度算错（一个 "\033[31m" 被算成多个半角宽字符）。
 * - 不处理 \n：换行要保留，否则整块输出坍成一行。
 */
final class Ansi
{
    /**
     * 清洗命令输出字节：去 ANSI、归一化换行、制表符转空格、剔 C0/DEL、修非法 UTF-8。
     */
    public static function sanitize(string $s): string
    {
        // 先整段移除 ANSI 序列再剔控制符，否则 `ls --color` 会留下 "[31mfoo[0m" 这种残渣
        $s = (string) preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $s);          // CSI（颜色/光标）
        $s = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $s); // OSC（窗口标题等）
        $s = (string) preg_replace('/\x1B[@-Z\\\\-_]/', '', $s);                     // 其余两字符转义
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = str_replace("\t", '    ', $s);
        // 剔除除 \n 外的 C0 控制符与 DEL
        $s = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $s);
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        return $s;
    }
}
