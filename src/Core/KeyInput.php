<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 键盘输入的「可打印字符」判定。
 *
 * ## 为什么需要它：一个全应用范围的真 bug
 * 五个输入点（Editor / Terminal / AI 输入 / 侧栏 GIT 提交框 / SEARCH 输入框）原本都写成
 * `strlen($char) === 1 && ord($char) >= 32`，**这会把所有非 ASCII 字符拒之门外**——
 * UTF-8 的「你」是 3 字节，strlen 不等于 1。结果是中文/日文/全角标点全都打不进去。
 *
 * 实测 php-tui/term 0.3.4 的 EventParser 会把 UTF-8 序列正确解码成**单个**
 * `CharKeyEvent(char:'你')`（逐字节喂也行，它内部会攒），所以这里要做的是放宽字节数判断，
 * 而不是去自己拼字节。
 *
 * 判定依据：合法 UTF-8 + 首字节不是 C0 控制符（< 0x20）也不是 DEL（0x7F）。
 * 多字节字符的首字节必然 >= 0xC2，天然满足；不完整的 UTF-8 序列由 mb_check_encoding 挡掉
 * （从终端一次读到半个字的场景虽然罕见，但插进去就是永久乱码）。
 */
final class KeyInput
{
    public static function isPrintable(string $char): bool
    {
        if ($char === '') {
            return false;
        }
        if (!mb_check_encoding($char, 'UTF-8')) {
            return false;
        }
        $b0 = ord($char[0]);
        return $b0 >= 32 && $b0 !== 127;
    }
}
