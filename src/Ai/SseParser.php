<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * OpenAI 兼容 SSE 流的**增量**解析器（纯逻辑，无 I/O，可直接单测）。
 *
 * 为什么必须增量：流式响应是 TCP 字节流，`data:` 行的边界和 recv() 的 chunk 边界
 * **没有任何关系**——一个 chunk 可能含 3 个事件，也可能是半个事件，还可能从
 * 一个汉字的 UTF-8 中间劈开。按 chunk 整块 json_decode 必然报错或丢字。
 *
 * 用法：每收到一块字节就 push()，返回本块解析出的增量文本数组（可能为空数组）。
 * 流结束（收到 `data: [DONE]`）后 isDone() 为 true，之后的字节一律忽略。
 *
 * 只认 OpenAI 兼容形态：`data: {"choices":[{"delta":{"content":"..."}}]}`。
 * Claude 的 `content_block_delta` 事件名不同，不在本类职责内（真要接得另开分支）。
 *
 * ⚠️ 半行**保持原始字节**不外泄：UTF-8 汉字占 3 字节，chunk 边界随时可能把它劈开，
 * 此时若先过一遍 sanitize，mb_convert_encoding 会把半个字符「修」成替换符，
 * 拼回去就永久乱码（M4 搜索踩过同一个坑）。
 */
final class SseParser
{
    /** 跨 chunk 的未完成数据（原始字节，未清洗） */
    private string $buf = '';

    private bool $done = false;

    /** 解析失败时记录最后一条错误（不抛异常：网络流里的坏数据不该炸掉 UI） */
    private ?string $error = null;

    public function isDone(): bool
    {
        return $this->done;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * 喂入一块字节，返回本块解析出的增量文本（按出现顺序，可能为空数组）。
     *
     * @return string[]
     */
    public function push(string $bytes): array
    {
        if ($bytes === '') {
            return [];
        }
        if ($this->done) {
            return []; // 已结束，后续字节（如 keep-alive）忽略
        }

        $this->buf .= $bytes;
        $out = [];

        // SSE 事件以空行分隔，但各家实现参差不齐：OpenAI 用 \n\n，有的只发 \n。
        // 故按「行」处理而不是死等空行，遇到空行就当事件结束（幂等：空事件不产出）。
        while (($pos = strpos($this->buf, "\n")) !== false) {
            $rawLine = substr($this->buf, 0, $pos);
            $this->buf = substr($this->buf, $pos + 1);
            $text = $this->consumeLine($rawLine);
            if ($text !== null) {
                $out[] = $text;
            }
            if ($this->done) {
                break;
            }
        }

        return $out;
    }

    /**
     * 流已结束（进程退出）时调用：把残留的最后一行（没有换行符结尾）也解析掉。
     * @return string[]
     */
    public function flush(): array
    {
        if ($this->done || $this->buf === '') {
            return [];
        }
        $rest = $this->buf;
        $this->buf = '';
        $text = $this->consumeLine($rest);
        return $text === null ? [] : [$text];
    }

    /** 解析单行 SSE。返回增量文本；无内容/非 data 行返回 null。 */
    private function consumeLine(string $rawLine): ?string
    {
        $line = rtrim($rawLine, "\r");
        if ($line === '' || $line[0] === ':') {
            return null; // 空行（事件分隔）或注释/心跳
        }
        // 只取 `data:` 前缀；`event:` / `id:` / `retry:` 等本类不需要，忽略。
        // 注意允许 `data:` 后无空格（规范允许，各家实现也不一致）。
        if (stripos($line, 'data:') !== 0) {
            return null;
        }
        $payload = ltrim(substr($line, 5), ' ');
        if ($payload === '') {
            return null;
        }
        if ($payload === '[DONE]') {
            $this->done = true;
            return null;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            // 半截 JSON 不会走到这里（整行才解析），走到这说明是真的坏数据。
            // 记下来但不抛——一条坏数据不该让整个回答消失。
            $this->error = 'bad json: ' . substr($payload, 0, 80);
            return null;
        }

        // 错误响应也可能是 200 + SSE 体（OpenAI 的 overload 之类）
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $msg = $decoded['error']['message'] ?? 'unknown error';
            $this->error = is_string($msg) ? $msg : 'unknown error';
            $this->done = true;
            return null;
        }

        $choices = $decoded['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            return null;
        }
        // 多 choice 时只取第 0 个（n=1 的默认形态），不去猜该合并哪一个。
        $first = $choices[0] ?? null;
        if (!is_array($first)) {
            return null;
        }
        $delta = $first['delta'] ?? null;
        if (!is_array($delta)) {
            return null;
        }
        $content = $delta['content'] ?? null;
        // content 可能是 null（role-only 的首帧），也可能是非字符串，一律当无内容。
        if (!is_string($content) || $content === '') {
            return null;
        }
        return $content;
    }
}
