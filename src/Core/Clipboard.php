<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 系统剪贴板（复制写入 + 读取请求）。
 *
 * - 写入（copy）：tty 环境写 OSC 52 序列 `\e]52;c;<base64>\a` 把文字推到系统剪贴板；
 *   非 tty 降级为进程内内存剪贴板（便于单测断言、且不污染 stdout）。
 * - 读取请求（requestRead）：仅 tty 环境向终端发 OSC 52 查询 `\e]52;c;?\a`，
 *   终端异步把内容以转义序列经 stdin 回传，由 InputParser 旁路捕获后回调（见 EventLoop）。
 *   非 tty 没有系统剪贴板可读，requestRead 是 no-op，读取走 App 的内存剪贴板降级。
 *
 * 长度上限：单条 OSC 52 由终端决定上限（主流模拟器远大于 4K；tmux 约 100KB）。
 * 不主动分块——xterm 规范中每条 OSC 52 是**替换**剪贴板而非追加，分块只会丢内容。
 */
final class Clipboard
{
    /** 非 tty 时存放的内存剪贴板（仅降级 / 测试用） */
    private string $memory = '';

    public function copy(string $text): void
    {
        if ($text === '') {
            return;
        }
        if (stream_isatty(STDOUT)) {
            $b64 = base64_encode($text);
            // ⚠️ 必须一次性发完整 OSC 52。xterm 规范里每条 OSC 52 是**替换**剪贴板而非追加，
            // 拆成多条（按 4K base64 分块）会只保留最后一块、其余静默丢失——长消息复制后粘贴出
            // 半截乱码。单条上限由终端决定（主流模拟器均远大于 4K；tmux 约 100KB），超出是终端
            // 限制，不应靠「分块」规避，分块反而制造了截断 bug。
            fwrite(STDOUT, "\x1b]52;c;" . $b64 . "\x07");
            fflush(STDOUT);
            return;
        }
        $this->memory = $text;
    }

    /** 取内存剪贴板内容（非 tty 降级路径；主要给测试断言用） */
    public function peek(): string
    {
        return $this->memory;
    }

    /** 发起读取系统剪贴板的请求（仅 tty）。非 tty 是 no-op，读取走内存剪贴板降级。 */
    public function requestRead(): void
    {
        if (!stream_isatty(STDOUT)) {
            return;
        }
        fwrite(STDOUT, "\x1b]52;c;?\x07");
        fflush(STDOUT);
    }
}
