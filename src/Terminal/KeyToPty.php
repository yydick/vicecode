<?php
declare(strict_types=1);

namespace App\Terminal;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;

/**
 * php-tui 事件 → PTY 字节流。
 *
 * 捕获模式下，App 把（除 F2/Esc 退出键外的）按键经本类编码后写入 PTY，
 * 由真实 shell / 全屏程序解释。返回 null 表示「这是捕获退出键，App 已单独处理」。
 */
final class KeyToPty
{
    /**
     * @param object $event CharKeyEvent | CodedKeyEvent | FunctionKeyEvent | MouseEvent …
     * @return ?string 转发给 PTY 的字节；null 表示捕获退出键（不应转发）
     */
    public static function encode(object $event): ?string
    {
        if ($event instanceof FunctionKeyEvent) {
            // F2 由 App 当作捕获开关处理；其余功能键正常编码
            if ($event->number === 2) {
                return null;
            }
            return self::functionKey($event->number);
        }

        if ($event instanceof CharKeyEvent) {
            if ($event->char === "\x1b") {
                return null; // 孤立 ESC：App 当退出捕获处理
            }
            // 退格键在不同终端上可能以 \x08(BS / Control-H) 或 \x7f(DEL) 到达；
            // shell / readline / vim 在 smkx（应用键模式）下统一按 DEL(\x7f) 解释回退，
            // 故无论来的是哪种都归一到 \x7f，否则 \x08 在 readline 里只是左移、看起来「没反应」。
            if ($event->char === "\x08" || $event->char === "\x7f") {
                return "\x7f";
            }
            // Ctrl+字母 → 控制字节（ord(c)&0x1f）。其它原样 UTF-8 转发。
            if (($event->modifiers & KeyModifiers::CONTROL) !== 0) {
                $ch = strtolower($event->char);
                $o = ord($ch);
                if ($o >= 0x61 && $o <= 0x7a) { // a–z
                    return chr($o & 0x1f);
                }
                if ($event->char === ' ') { // Ctrl+Space
                    return "\x00";
                }
                // Ctrl+其它（如 @ / 数字）：按原始控制映射
                if ($o >= 0x40 && $o <= 0x5f) {
                    return chr($o & 0x1f);
                }
                return $event->char;
            }
            return $event->char;
        }

        if ($event instanceof CodedKeyEvent) {
            if ($event->code === KeyCode::Esc) {
                return null;
            }
            return self::codedKey($event);
        }

        if ($event instanceof MouseEvent) {
            return self::mouseEvent($event);
        }

        // 其它事件：不转发
        return null;
    }

    /**
     * 鼠标事件 → SGR 鼠标序列（xterm `?1006h` 格式），供接管期透传给 PTY。
     *
     * 格式：`\e[<B;X;YM`（按下/拖拽/滚轮）或 `\e[<B;X;Ym`（松开）。
     * 坐标 1-based（MouseEvent 是 0-based，故 +1）。
     * B 编码：低 2 位按钮（0=左 1=中 2=右），松开 +3，拖拽 +32，滚轮 64/65(上下)/66/67(左右)；
     * 修饰位 shift=4 / alt=8 / ctrl=16（与 xterm SGR 一致）。
     */
    private static function mouseEvent(MouseEvent $e): ?string
    {
        $mods = 0;
        if (($e->modifiers & KeyModifiers::SHIFT) !== 0) {
            $mods |= 4;
        }
        if (($e->modifiers & KeyModifiers::ALT) !== 0) {
            $mods |= 8;
        }
        if (($e->modifiers & KeyModifiers::CONTROL) !== 0) {
            $mods |= 16;
        }
        $btnBase = match ($e->button) {
            MouseButton::Left => 0,
            MouseButton::Middle => 1,
            MouseButton::Right => 2,
            default => 0,
        };
        $x = $e->column + 1;
        $y = $e->row + 1;
        switch ($e->kind) {
            case MouseEventKind::Down:
                $B = $btnBase + $mods;
                $suffix = 'M';
                break;
            case MouseEventKind::Drag:
                $B = $btnBase + 32 + $mods;
                $suffix = 'M';
                break;
            case MouseEventKind::Up:
                $B = $btnBase + 3 + $mods;
                $suffix = 'm';
                break;
            case MouseEventKind::ScrollUp:
                $B = 64 + $mods;
                $suffix = 'M';
                break;
            case MouseEventKind::ScrollDown:
                $B = 65 + $mods;
                $suffix = 'M';
                break;
            case MouseEventKind::ScrollLeft:
                $B = 66 + $mods;
                $suffix = 'M';
                break;
            case MouseEventKind::ScrollRight:
                $B = 67 + $mods;
                $suffix = 'M';
                break;
            case MouseEventKind::Moved:
                $B = 32 + $mods;
                $suffix = 'M';
                break;
            default:
                return null;
        }
        return "\e[<" . $B . ';' . $x . ';' . $y . $suffix;
    }

    private static function functionKey(int $n): ?string
    {
        return match ($n) {
            1 => "\x1bOP",
            2 => "\x1bOQ",
            3 => "\x1bOR",
            4 => "\x1bOS",
            5 => "\x1b[15~",
            6 => "\x1b[17~",
            7 => "\x1b[18~",
            8 => "\x1b[19~",
            9 => "\x1b[20~",
            10 => "\x1b[21~",
            11 => "\x1b[23~",
            12 => "\x1b[24~",
            default => null,
        };
    }

    private static function codedKey(CodedKeyEvent $e): ?string
    {
        $ctrl = ($e->modifiers & KeyModifiers::CONTROL) !== 0;
        return match ($e->code) {
            KeyCode::Enter => "\r",
            KeyCode::Backspace => "\x7f",
            KeyCode::Delete => "\x1b[3~",
            KeyCode::Tab => "\t",
            // 应用光标键模式（smkx 之后程序期待的序列）：SS3 \eO + 方向字母；
            // 带 Ctrl 时用 \e[1;5 + 字母。发 ANSI 裸序列 \e[A 在 readline/vim 里常被忽略。
            KeyCode::Up => $ctrl ? "\x1b[1;5A" : "\x1bOA",
            KeyCode::Down => $ctrl ? "\x1b[1;5B" : "\x1bOB",
            KeyCode::Right => $ctrl ? "\x1b[1;5C" : "\x1bOC",
            KeyCode::Left => $ctrl ? "\x1b[1;5D" : "\x1bOD",
            KeyCode::Home => $ctrl ? "\x1b[1;5H" : "\x1bOH",
            KeyCode::End => $ctrl ? "\x1b[1;5F" : "\x1bOF",
            KeyCode::PageUp => "\x1b[5~",
            KeyCode::PageDown => "\x1b[6~",
            KeyCode::Insert => "\x1b[2~",
            default => null,
        };
    }
}
