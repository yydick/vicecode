<?php
declare(strict_types=1);

namespace App\Core;

/**
 * OSC 52 剪贴板响应的字节流旁路扫描器。
 *
 * ## 为什么存在
 * 真机粘贴（Ctrl+V / 右键）走 OSC 52 异步读取：应用发 `\e]52;c;?\a` 查询，终端把系统
 * 剪贴板内容以 `\e]52;c;<base64>\a`（BEL 结尾，部分终端用 ST `\e\\`）经 stdin 回传。
 * php-tui 的 EventParser 不认识这条序列（整段丢弃），必须在喂 parser **之前**旁路截获。
 *
 * ## 为什么要"流式"扫描
 * 剪贴板可达几十 KB（base64 后更大），fread(4096) 会把它劈成多块——不能指望一条完整
 * 序列恰好落在一个读块里。本扫描器维护跨块缓冲：见到 `\e]52;` 进入收集态，直到 BEL/ST
 * 结束才解码回调；期间的字节不喂 parser（否则会退化成一串乱按键打进 shell/编辑器）。
 *
 * ## 输出约定
 * feed() 返回「应继续喂给 EventParser 的剩余字节」；解码出的文本经构造时注入的回调
 * 交给 App::onClipboardRead。回调可能一次都没有（终端不支持 OSC 52 读），调用方须自备
 * 超时降级（App::pasteTick）。
 */
final class Osc52StreamScanner
{
    /** 收集态缓冲（\e]52; 起始后的全部字节）；null = 非收集态 */
    private ?string $buf = null;

    public function __construct(private \Closure $onText)
    {
    }

    /** 是否正在收集一条未完结的 OSC 52 响应 */
    public function collecting(): bool
    {
        return $this->buf !== null;
    }

    /**
     * 喂入一块读到的字节；返回应继续喂给 EventParser 的剩余字节。
     * （OSC 52 响应整段被旁路，不会出现在返回值里。）
     */
    public function feed(string $bytes): string
    {
        if ($this->buf !== null) {
            $bytes = $this->buf . $bytes;
            $this->buf = null;
        }

        $start = strpos($bytes, "\x1b]52;");
        if ($start === false) {
            return $bytes;   // 本块没有 OSC 52，全部放行
        }

        // 序列前段（可能是正常按键）照常放行
        $head = substr($bytes, 0, $start);
        $rest = substr($bytes, $start);

        // 找结尾：BEL 优先，ST（\e\\）兜底（部分终端实现）
        $endBel = strpos($rest, "\x07");
        $endSt = strpos($rest, "\x1b\\");
        $end = $endBel === false ? $endSt : ($endSt === false ? $endBel : min($endBel, $endSt));

        if ($end === false) {
            // 未完结：进入收集态，等下一块。前段照常放行。
            $this->buf = $rest;
            return $head;
        }

        // 完整序列：解码并回调；序列之后可能还有同块的正常按键。
        // 头 "\e]52;c;" 共 7 字节（ESC ] 5 2 ; c ;）。
        $body = substr($rest, 7, $end - 7);        // 去掉 "\e]52;c;"
        $tail = substr($rest, $end + ($end === $endBel ? 1 : 2));
        if (preg_match('/^([A-Za-z0-9+\/=]+)$/', $body, $m) === 1) {
            $text = base64_decode($m[1], true);
            if (is_string($text) && $text !== '') {
                ($this->onText)($text);
            }
        }
        // 尾段里若还有一条 OSC 52（理论罕见），递归处理
        return $head . (str_contains($tail, "\x1b]52;") ? $this->feed($tail) : $tail);
    }
}
