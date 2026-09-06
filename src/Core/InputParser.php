<?php
declare(strict_types=1);

namespace App\Core;

use PhpTui\Term\Event;
use PhpTui\Term\EventParser;

/**
 * 把 Swoole 协程读到的原始字节喂给 php-tui/term 的 EventParser，
 * 解析出键鼠事件。EventParser 内部已支持方向键、功能键、字符键以及
 * SGR 鼠标转义序列（\e[<...M / \e[<...m）。
 */
final class InputParser
{
    private EventParser $parser;

    /**
     * OSC 52 剪贴板响应旁路捕获：终端把系统剪贴板内容以 `\e]52;c;<base64>\a`
     * （或 ST 终结 `\e\\`）经 stdin 回传，php-tui 的 EventParser 会把它当乱码按键，
     * 故在这里单独剥离、解析、回调，不让它污染事件流。
     * 查询本身（base64==`?`）也可能被终端回显，同样在此识别并忽略。
     * @var string
     */
    private string $oscBuf = '';

    /** @var ?\Closure(string):void */
    private ?\Closure $clipboardHandler = null;

    public function __construct()
    {
        $this->parser = EventParser::new();
    }

    /** 注册剪贴板读取回调（Clipboard 响应到达时调用）。 */
    public function setClipboardHandler(callable $cb): void
    {
        $this->clipboardHandler = $cb;
    }

    /**
     * @return Event[]
     */
    public function feed(string $bytes, bool $more = true): array
    {
        // more=true：pty 下 fread 常把转义序列（如 \e[5~）拆成多段返回，
        // 必须让 EventParser 在内部 buffer 里暂存不完整的序列，等后续字节拼齐再解析；
        // 否则孤 \e 会被立刻当成 Esc 冲掉、后面的 [5~ 退化成若干字符键 —— 方向键 / PageUp/Down 全失灵。
        // 已完整结束的序列（~/$/字母等终结符）无论 more 如何都会立即吐出，仅孤 \e 会等下一字节。
        $this->oscBuf .= $bytes;

        // 畸形序列保护：超出 1MiB 还拼不出完整 OSC 52，直接丢弃（避免无限累积撑爆内存）
        if (strlen($this->oscBuf) > (1 << 20)) {
            $this->oscBuf = '';
        }

        $pos = 0;
        $len = strlen($this->oscBuf);
        $foundStart = false;
        while (true) {
            $p = strpos($this->oscBuf, "\x1b]52;", $pos);
            if ($p === false) {
                break; // 没有更多 OSC 52
            }
            $foundStart = true;
            // OSC 起点之前的普通字节：照常交给 EventParser
            if ($p > $pos) {
                $this->parser->advance(substr($this->oscBuf, $pos, $p - $pos), $more);
            }
            // 从起点找终结符：BEL(\x07) 或 ST(\x1b\\)，取最先出现者
            $bel = strpos($this->oscBuf, "\x07", $p);
            $st = strpos($this->oscBuf, "\x1b\\", $p);
            $end = null;
            $tlen = 0;
            if ($bel !== false && ($st === false || $bel <= $st)) {
                $end = $bel;
                $tlen = 1;
            } elseif ($st !== false) {
                $end = $st;
                $tlen = 2;
            }
            if ($end === null) {
                // 不完整：起点起的整段都是同一段未完 OSC 52，全部保留等后续字节补全
                break;
            }
            $seq = substr($this->oscBuf, $p, $end - $p + $tlen);
            $this->handleOsc52($seq);
            $pos = $end + $tlen;
        }

        // 收尾：把 $pos 之后尚未推进的内容处理掉
        if ($pos < $len) {
            $tail = substr($this->oscBuf, $pos);
            if ($foundStart) {
                // 中断在「已找到 OSC 起点但无终结符」：整段都是不完整 OSC，全部保留不 advance
                $this->oscBuf = $tail;
            } else {
                // 从未找到 OSC 起点：尾部可能是不完整 OSC 标记前缀（如跨 feed 拆开的 `\e]5`），
                // 仅保留该前缀，其余普通字节 advance 给 EventParser。
                $keep = 0;
                $tlen2 = strlen($tail);
                for ($k = $tlen2; $k > 0; $k--) {
                    if (str_starts_with("\x1b]52;", substr($tail, 0, $k))) {
                        $keep = $k;
                        break;
                    }
                }
                if ($keep > 0) {
                    if ($keep < $tlen2) {
                        $this->parser->advance(substr($tail, 0, $tlen2 - $keep), $more);
                    }
                    $this->oscBuf = substr($tail, $tlen2 - $keep);
                } else {
                    $this->parser->advance($tail, $more);
                    $this->oscBuf = '';
                }
            }
        } else {
            $this->oscBuf = '';
        }

        return $this->parser->drain();
    }

    /** 解析一段完整 OSC 52 序列（含终结符），base64 解码后回调；`?` 查询回显忽略。 */
    private function handleOsc52(string $seq): void
    {
        if ($this->clipboardHandler === null) {
            return;
        }
        // 去掉终结符（BEL 或 ST）
        $body = $seq;
        if (substr($body, -2) === "\x1b\\") {
            $body = substr($body, 0, -2);
        } elseif (substr($body, -1) === "\x07") {
            $body = substr($body, 0, -1);
        }
        if (preg_match('/^\x1b\]52;([cps]);(.+)$/s', $body, $m) !== 1) {
            return;
        }
        $data = $m[2];
        if ($data === '?') {
            return; // 查询回显，忽略
        }
        $text = base64_decode($data, true);
        if ($text === false) {
            return;
        }
        ($this->clipboardHandler)($text);
    }
}
