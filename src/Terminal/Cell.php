<?php
declare(strict_types=1);

namespace App\Terminal;

/**
 * 终端仿真器单格状态。值对象；复用处如需独立修改先 clone。
 */
final class Cell
{
    public string $ch = ' ';

    /** ANSI 颜色索引 0–255；-1 表示继承默认 */
    public int $fg = -1;

    public int $bg = -1;

    /** 位掩码：见 Vt100Emulator::FLAG_* */
    public int $flags = 0;

    /** 宽字符右半占位（渲染时跳过） */
    public bool $wide = false;

    public function isReverse(): bool
    {
        return ($this->flags & Vt100Emulator::FLAG_REVERSE) !== 0;
    }
}
