<?php
declare(strict_types=1);

/**
 * pty 屏幕重建助手：把累积的原始字节流（含 CSI 定位/擦除）重放成**最终一帧**文本。
 *
 * 为什么需要它：php-tui 走差分渲染——只把「相对上一帧变了」的格重发到 pty，且同一行的
 * 字符会被拆成多次「定位 + 写入」。所以**直接对累积流做子串匹配会踩两个坑**：
 *   1) 整词被拆开（实测状态栏 `Model config reloaded` 在流里成了 `modelconfig` + `eloaded`）；
 *   2) 旧帧内容与当前内容交错（记忆里那条「插件 demo 已禁用 → 插扩件展 demo器已 禁z用_」）。
 * 正解是重放成最终帧：重建之后同一行的文本是连续的，再剥掉非字母数字与汉字做归一化匹配。
 *
 * ⚠️ **必须多字节感知**（照抄旧 test 里那份逐字节写的重建器会静默毁掉全部中文断言）：
 * 一次取整个 UTF-8 序列落进一格、列号按 `DisplayWidth::dispWidth()` 推进（汉字占 2 列，
 * 右邻格记成占位），否则汉字会被拆成两格、归一化用的 `/u` 正则直接匹配失败。
 *
 * `tests/pty_r5.php` / `pty_command_palette.php` / `pty_plugin_v11.php` 里各有一份**逐字节**的
 * 内联版本（历史遗留，只对 ASCII 断言成立）。**新测试请用本文件的函数**；老测试未迁移以免
 * 摊大改动面——真要迁，顺手把它们的 CJK 断言一并验一遍。
 */

/**
 * 重放字节流 → 归一化后的整屏文本（只留小写字母数字与汉字，行间以 \n 分隔）。
 *
 * @param int $w 终端列数（与启动应用时的 COLUMNS 一致）
 * @param int $h 终端行数
 */
function vc_rebuild_screen(string $raw, int $w, int $h): string
{
    // OSC 序列（如剪贴板/cwd 上报）不影响字符网格，先整体剥掉，免得它的载荷被当正文写进格子
    $raw = (string) preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $raw);

    $cells = array_fill(0, $h, array_fill(0, $w, ' '));
    $cont  = array_fill(0, $h, array_fill(0, $w, false));   // 宽字符的右邻占位格
    $r = 0;
    $c = 0;
    $len = strlen($raw);
    $i = 0;

    while ($i < $len) {
        $ch = $raw[$i];

        if ($ch === "\x1b") {
            if (isset($raw[$i + 1]) && $raw[$i + 1] === '[') {
                $j = $i + 2;
                $params = '';
                while ($j < $len && $raw[$j] !== '' && !ctype_alpha($raw[$j]) && $raw[$j] !== '~') {
                    $params .= $raw[$j];
                    $j++;
                }
                $cmd = $j < $len ? $raw[$j] : '';
                $j++;
                $nums = array_map('intval', explode(';', $params === '' ? '1' : $params));
                switch ($cmd) {
                    case 'H':
                    case 'f':
                        $r = max(0, ($nums[0] ?? 1) - 1);
                        $c = max(0, ($nums[1] ?? 1) - 1);
                        break;
                    case 'A':
                        $r = max(0, $r - ($nums[0] ?? 1));
                        break;
                    case 'B':
                        $r = min($h - 1, $r + ($nums[0] ?? 1));
                        break;
                    case 'C':
                        $c = min($w - 1, $c + ($nums[0] ?? 1));
                        break;
                    case 'D':
                        $c = max(0, $c - ($nums[0] ?? 1));
                        break;
                    case 'G':
                        $c = max(0, ($nums[0] ?? 1) - 1);
                        break;
                    case 'J':
                        if (($nums[0] ?? 0) === 2 || ($nums[0] ?? 0) === 3) {
                            $cells = array_fill(0, $h, array_fill(0, $w, ' '));
                            $cont  = array_fill(0, $h, array_fill(0, $w, false));
                        }
                        break;
                    case 'K':
                        $mode = $nums[0] ?? 0;
                        $from = $mode === 1 ? 0 : $c;
                        $to   = $mode === 0 ? $w - 1 : ($mode === 1 ? $c : $w - 1);
                        for ($k = $from; $k <= $to && $k < $w; $k++) {
                            $cells[$r][$k] = ' ';
                            $cont[$r][$k] = false;
                        }
                        break;
                }
                $i = $j;
                continue;
            }
            // 其它 ESC 序列（如 ESC( B 选字符集）：跳到终结符
            $i++;
            while ($i < $len && !ctype_alpha($raw[$i]) && $raw[$i] !== "\x1b") {
                $i++;
            }
            if ($i < $len) {
                $i++;
            }
            continue;
        }

        if ($ch === "\r") {
            $c = 0;
            $i++;
            continue;
        }
        if ($ch === "\n") {
            $r = min($h - 1, $r + 1);
            $c = 0;
            $i++;
            continue;
        }
        if ($ch === "\x00" || $ch === "\x08") {
            $i++;
            continue;
        }

        // ── 取一个完整 UTF-8 码点（多字节感知的关键）──
        $o = ord($ch);
        $clen = $o < 0x80 ? 1 : ($o < 0xE0 ? 2 : ($o < 0xF0 ? 3 : 4));
        if ($i + $clen > $len) {
            break;                                  // 尾部残字节：丢弃
        }
        $char = substr($raw, $i, $clen);
        $i += $clen;

        $dw = \App\Text\DisplayWidth::dispWidth($char);
        if ($dw <= 0) {
            continue;                               // 零宽/控制字符不进网格
        }
        if ($r >= 0 && $r < $h && $c >= 0 && $c < $w) {
            $cells[$r][$c] = $char;
            $cont[$r][$c] = false;
            if ($dw === 2 && $c + 1 < $w) {
                $cont[$r][$c + 1] = true;           // 右邻格是占位，渲染时跳过
            }
        }
        $c += $dw;
        if ($c >= $w) {
            $c = 0;
            $r = min($h - 1, $r + 1);
        }
    }

    $lines = [];
    for ($row = 0; $row < $h; $row++) {
        $txt = '';
        for ($col = 0; $col < $w; $col++) {
            if ($cont[$row][$col]) {
                continue;
            }
            $txt .= $cells[$row][$col];
        }
        $txt = rtrim($txt);
        // 逐行归一化（而不是整屏拼完再归一化）：行与行之间保留 \n，这样「一个词」不可能
        // 跨行拼出来造成假阳性——状态栏断言尤其需要这个性质。
        $one = preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fff}]/u', '', $txt);
        $lines[] = strtolower($one === null ? (string) preg_replace('/[^a-zA-Z0-9]/', '', $txt) : $one);
    }
    return implode("\n", $lines);
}
