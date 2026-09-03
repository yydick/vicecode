<?php
declare(strict_types=1);

namespace App\Terminal;

use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Color\Color;
use PhpTui\Tui\Color\RgbColor;

/**
 * 仿真器颜色索引 → php-tui 颜色。
 *
 * 索引含义（与 Vt100Emulator 约定一致）：
 *  -1        默认（继承终端配色，返回 null 不显式设色）
 *  0–15      标准 16 色
 *  16–231    256 色立方
 *  232–255   灰度级
 *  ≥1000000  真彩色（编码见 Vt100Emulator::rgbToIndex）
 *
 * 仿真器配色走真实 xterm 调色板，独立于 ViceCode 主题——符合用户对终端的预期。
 */
final class PtyColor
{
    public static function fg(int $idx): ?Color
    {
        return self::to($idx);
    }

    public static function bg(int $idx): ?Color
    {
        return self::to($idx);
    }

    private static function to(int $idx): ?Color
    {
        if ($idx < 0) {
            return null;
        }
        if ($idx >= 1000000) {
            $packed = $idx - 1000000;
            $r = ($packed >> 16) & 0xff;
            $g = ($packed >> 8) & 0xff;
            $b = $packed & 0xff;
            return RgbColor::fromRgb($r, $g, $b);
        }
        if ($idx <= 15) {
            return AnsiColor::from($idx);
        }
        if ($idx <= 231) {
            $n = $idx - 16;
            $r = intdiv($n, 36);
            $g = intdiv($n % 36, 6);
            $b = $n % 6;
            $levels = [0, 95, 135, 175, 215, 255];
            return RgbColor::fromRgb($levels[$r], $levels[$g], $levels[$b]);
        }
        // 232–255 灰度
        $v = 8 + ($idx - 232) * 10;
        return RgbColor::fromRgb($v, $v, $v);
    }
}
