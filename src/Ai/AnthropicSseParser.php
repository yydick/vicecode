<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * Anthropic Messages API SSE 流的**增量**解析器（纯逻辑，无 I/O，可直接单测）。
 *
 * ## 与 `SseParser` 的关系：同契约、不同协议
 * 输出的事件形状**与 `SseParser` 完全一致**（`delta` / `tool_delta` / `finish`），
 * 因此 `ChatModel::handleEvent()` 与 `toolAcc` 归并逻辑一行都不用改 —— 协议差异被关在这一层。
 * 两者不合并成一个类：事件名、结束条件、工具增量的字段名全都不同，硬塞进一个类只会
 * 变成一堆 `if ($protocol === …)`；`SseParser` 的类注释早就写明「Claude 的事件名不同，真要接得另开分支」。
 *
 * ## 为什么必须增量
 * 同 `SseParser`：`data:` 行边界与 recv() 的 chunk 边界**没有任何关系**，一个 chunk 可能含
 * 多个事件、也可能是半个事件，还可能从汉字的 UTF-8 中间劈开。故攒原始字节、凑齐一行再解析。
 *
 * ## 协议要点（以镜像文档核对，落地时按官方文档复核）
 *  - 流的**结束是 `message_stop` 事件**，Anthropic **不发 `data: [DONE]`**。
 *    照抄 OpenAI 的结束条件会永远等不到 `[DONE]`，表现为「回答完了但界面一直显示生成中」。
 *  - 官方在 `data:` 之前还会发一行 `event: <类型>`。本类**只认 `data:` 行**（同 `SseParser` 策略），
 *    事件类型一律从 data 里的 `"type"` 字段读 —— 这样即使代理/网关把 `event:` 行吃掉也不影响。
 *  - 工具调用的参数是 **`input_json_delta.partial_json`** 的多片拼接（与 OpenAI 的
 *    `function.arguments` 分片同理），`content_block_start` 里已带 `id` / `name`。
 *  - `index` 是内容块在最终 `content` 数组里的下标；本场景下一个 tool_use 块 = 一个工具调用，
 *    与 OpenAI 按 index 归并 tool_calls 的语义等价，故可直接复用同一套归并。
 *
 * ⚠️ 半行**保持原始字节**不外泄：UTF-8 汉字占 3 字节，chunk 边界随时可能把它劈开，
 * 此时若先过 sanitize，拼回去就永久乱码（M4 搜索踩过同一个坑）。
 */
final class AnthropicSseParser
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
     * 喂入一块字节，返回本块解析出的结构化事件（按出现顺序，可能为空数组）。
     *
     * @return list<array<string,mixed>>
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

        // 同 SseParser：按「行」处理而不是死等空行（各家实现对空行分隔参差不齐）
        while (($pos = strpos($this->buf, "\n")) !== false) {
            $rawLine = substr($this->buf, 0, $pos);
            $this->buf = substr($this->buf, $pos + 1);
            foreach ($this->consumeLine($rawLine) as $ev) {
                $out[] = $ev;
            }
            if ($this->done) {
                break;
            }
        }

        return $out;
    }

    /**
     * 流已结束（进程退出）时调用：把残留的最后一行（没有换行符结尾）也解析掉。
     * @return list<array<string,mixed>>
     */
    public function flush(): array
    {
        if ($this->done || $this->buf === '') {
            return [];
        }
        $rest = $this->buf;
        $this->buf = '';
        return $this->consumeLine($rest);
    }

    /**
     * 解析单行 SSE。返回 0..n 个结构化事件。
     * @return list<array<string,mixed>>
     */
    private function consumeLine(string $rawLine): array
    {
        $line = rtrim($rawLine, "\r");
        if ($line === '' || $line[0] === ':') {
            return []; // 空行（事件分隔）或注释/心跳
        }
        // 只取 `data:` 前缀。`event:` 行**刻意忽略**：类型从 data 的 "type" 字段读，
        // 少一个数据来源就少一处可能不一致的地方（两条行不同步时以 data 为准）。
        if (stripos($line, 'data:') !== 0) {
            return [];
        }
        $payload = ltrim(substr($line, 5), ' ');
        if ($payload === '') {
            return [];
        }
        // 容错：某些兼容网关会学着 OpenAI 在末尾补一个 [DONE]（官方不发）。
        // 认了它无害（本来 message_stop 就该结束），不认的话后面也没有内容。
        if ($payload === '[DONE]') {
            $this->done = true;
            return [];
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            // 半截 JSON 不会走到这里（整行才解析），走到这说明是真的坏数据。
            $this->error = 'bad json: ' . substr($payload, 0, 80);
            return [];
        }

        // 流内错误事件：`{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}`
        // 对应非流式下的 HTTP 529。记下并结束（同 SseParser 对 error 的处理）。
        if (($decoded['type'] ?? null) === 'error') {
            $err = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $msg = $err['message'] ?? 'unknown error';
            $this->error = is_string($msg) ? $msg : 'unknown error';
            $this->done = true;
            return [];
        }

        return match ($decoded['type'] ?? null) {
            'content_block_start' => $this->onBlockStart($decoded),
            'content_block_delta' => $this->onBlockDelta($decoded),
            'message_delta'       => $this->onMessageDelta($decoded),
            'message_stop'        => $this->onMessageStop(),
            // message_start / content_block_stop / ping 不产事件；未知类型按官方
            // 「版本策略」要求优雅忽略（未来新增事件类型不该让老客户端崩）
            default => [],
        };
    }

    /**
     * 内容块开始。只有 `tool_use` 块需要在这里产出事件 —— 它带着 `id` 与 `name`
     * （后续的 `input_json_delta` 只给参数分片，不带这两样），与 OpenAI 的
     * 「首片带 id/name、后续片只带 arguments」形态对齐。
     * text 块不需要事件：正文全靠 `text_delta`，`content_block_start` 里的 text 恒为空串。
     * @param array<string,mixed> $decoded
     * @return list<array<string,mixed>>
     */
    private function onBlockStart(array $decoded): array
    {
        $block = $decoded['content_block'] ?? null;
        if (!is_array($block) || ($block['type'] ?? null) !== 'tool_use') {
            return [];
        }
        return [[
            'type'       => 'tool_delta',
            'index'      => is_int($decoded['index'] ?? null) ? $decoded['index'] : 0,
            'id'         => isset($block['id']) && is_string($block['id']) ? $block['id'] : null,
            'name'       => isset($block['name']) && is_string($block['name']) ? $block['name'] : null,
            'args_delta' => '',
        ]];
    }

    /**
     * 内容块增量。两种 delta：
     *  - `text_delta` → 正文增量（`delta.text`）
     *  - `input_json_delta` → 工具参数分片（`delta.partial_json`），**不能逐片 decode**
     *    （它只是 JSON 的一部分），累加后由 `ChatModel` 在流结束统一解析。
     * @param array<string,mixed> $decoded
     * @return list<array<string,mixed>>
     */
    private function onBlockDelta(array $decoded): array
    {
        $delta = $decoded['delta'] ?? null;
        if (!is_array($delta)) {
            return [];
        }
        $kind = $delta['type'] ?? null;
        if ($kind === 'text_delta') {
            $text = $delta['text'] ?? null;
            return is_string($text) && $text !== '' ? [['type' => 'delta', 'text' => $text]] : [];
        }
        if ($kind === 'input_json_delta') {
            $part = $delta['partial_json'] ?? null;
            if (!is_string($part)) {
                return [];
            }
            // 空分片是官方会发的（参数开始前会先来一片空串），照发不误：
            // 归并方累加空串无害，而「有 id/name 但没有参数分片」的调用也能因此建出槽位。
            return [[
                'type'       => 'tool_delta',
                'index'      => is_int($decoded['index'] ?? null) ? $decoded['index'] : 0,
                'id'         => null,
                'name'       => null,
                'args_delta' => $part,
            ]];
        }
        // thinking_delta / signature_delta 等新类型：当前不展示，忽略而非报错
        return [];
    }

    /**
     * 消息级增量：`{"delta":{"stop_reason":"end_turn"}}`。
     * `stop_reason` 可能是 null（中途的 usage-only 帧），非字符串一律当「无信息」不发事件。
     * @param array<string,mixed> $decoded
     * @return list<array<string,mixed>>
     */
    private function onMessageDelta(array $decoded): array
    {
        $delta = $decoded['delta'] ?? null;
        $reason = is_array($delta) ? ($delta['stop_reason'] ?? null) : null;
        if (!is_string($reason) || $reason === '') {
            return [];
        }
        return [['type' => 'finish', 'reason' => $reason]];
    }

    /**
     * 整条消息结束 —— Anthropic 的流终止信号（**没有 `data: [DONE]`**）。
     * @return list<array<string,mixed>>
     */
    private function onMessageStop(): array
    {
        $this->done = true;
        return [];
    }
}
