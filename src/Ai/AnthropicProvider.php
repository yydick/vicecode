<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * Anthropic Messages API 的 Provider（`POST <base>/v1/messages`）。
 *
 * 传输骨架见 `CurlSseTransport`（curl 子进程 / key 不进 argv / 临时文件生命周期），
 * 流事件解析见 `AnthropicSseParser`。本类负责**请求侧**的两件事：
 * 把本项目内部消息形状与工具定义，翻译成 Anthropic 的 wire 形状。
 *
 * ## 为什么内部表示不改（转换只在这一层）
 * `$this->messages` 用的是 OpenAI 语义（`tool_calls` / `role:tool` / `tool_call_id`），
 * 它同时被**存档**（ChatStore）、**渲染**（AiPanel，含 `meta.display` 工具摘要）、
 * **复制**（整条消息复制）三处消费。改成"协议中立"表示要动这四个地方，
 * 而这个转换只需要一个纯函数。故：**内部一律 OpenAI 语义，出口处翻译**。
 *
 * ## 两条协议的四个形状差异（易错，逐条在测试里锚死）
 *  1. **`max_tokens` 必填**：Anthropic 缺了这个参数直接 400（OpenAI 兼容侧可选）。
 *  2. **`system` 是顶层参数**：Messages API **没有 `system` 角色**，
 *     内部若有 `role:system` 消息必须提到顶层 `system` 字段。
 *  3. **工具结果不是独立消息**：`role:tool` 要变成 **user 消息里的 `tool_result` 内容块**
 *     （靠 `tool_use_id` 关联），而不是 OpenAI 那样的独立 `role:tool` 消息。
 *  4. **工具参数是对象**：`tool_use.input` 必须是 JSON **对象**，`{}` 而不是 `[]`
 *     —— PHP 的 `json_decode('{}', true)` 得 `[]`、`json_encode([])` 又变回 `[]`，
 *     不显式转 `(object)` 会在空参数时被端点 400。
 */
final class AnthropicProvider implements ProviderInterface
{
    use CurlSseTransport;

    /** Anthropic 要求显式声明 API 版本；这是官方当前稳定版（落地时按官方文档复核）。 */
    public const API_VERSION = '2023-06-01';

    /**
     * 构造可直接交给 `CommandRunner::start()` 的 shell 命令。
     *
     * @param array<int,array<string,mixed>> $messages 本项目内部形状（含私有 `meta`，发送前剥掉）
     * @param array<int,array<string,mixed>>|null $tools 本项目中立工具定义（OpenAI 形状），
     *                                                    本类负责转成 `input_schema`
     */
    public function buildCommand(ProviderSpec $spec, array $messages, int $timeout = self::DEFAULT_TIMEOUT, ?array $tools = null): string
    {
        [$system, $converted] = self::toMessages($messages);

        $payload = [
            'model'      => $spec->model,
            // 必填：缺了 Anthropic 直接 400（见类注释①）。默认值由 ProviderSpec 给。
            'max_tokens' => $spec->maxTokens,
            'messages'   => $converted,
            'stream'     => true,
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }
        $convertedTools = self::toTools($tools);
        if ($convertedTools !== []) {
            $payload['tools'] = $convertedTools;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            // 理论上不可达（内容都是 UTF-8 字符串）；保底给一个结构合法的空请求，
            // 让失败表现为服务端报错而不是 curl 收到空 body。
            $body = json_encode([
                'model'      => $spec->model,
                'max_tokens' => $spec->maxTokens,
                'messages'   => [],
                'stream'     => true,
            ], JSON_UNESCAPED_UNICODE) ?: '{"messages":[],"stream":true}';
        }

        // 注意头名与 OpenAI 完全不同：`x-api-key` 而非 `Authorization: Bearer`，
        // 且 `anthropic-version` 是**必需**头（缺了 400）。
        $headers = "x-api-key: " . ($spec->apiKey ?? '') . "\n"
            . "anthropic-version: " . self::API_VERSION . "\n"
            . "Content-Type: application/json\n"
            . "Accept: text/event-stream\n";

        return $this->curlc($spec->chatUrl(), $body, $headers, $timeout);
    }

    /**
     * 内部消息 → Anthropic 的 `messages`（+ 顶层 `system`）。
     *
     * 返回 `[system 文本(可能为空串), messages 数组]`。抽成 public static 纯函数便于直接单测
     * （不必去解析 curl 命令行里的临时文件）。
     *
     * @param array<int,array<string,mixed>> $messages
     * @return array{0:string,1:list<array<string,mixed>>}
     */
    public static function toMessages(array $messages): array
    {
        $system = [];
        $out = [];
        /** 最近一条「tool_result 组」在 $out 里的下标；null = 上一条不是 tool_result 组 */
        $lastToolResultIdx = null;

        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = $m['role'] ?? null;
            $content = $m['content'] ?? '';
            if (!is_string($content)) {
                $content = '';
            }

            // ① system（防御性：当前代码不产生，但存档/手工构造可能带）→ 提到顶层
            if ($role === 'system') {
                if ($content !== '') {
                    $system[] = $content;
                }
                continue;
            }

            // ② 工具结果 → user 消息里的 tool_result 内容块。
            //    **连续的 role:tool 合并进同一条 user 消息**：Anthropic 会合并相邻同角色消息，
            //    显式合并语义更明确，也避免出现一串「只含 tool_result 的 user 消息」这种边界形状。
            if ($role === 'tool') {
                $block = [
                    'type'        => 'tool_result',
                    'tool_use_id' => is_string($m['tool_call_id'] ?? null) ? $m['tool_call_id'] : '',
                    'content'     => $content,
                ];
                if ($lastToolResultIdx !== null) {
                    $out[$lastToolResultIdx]['content'][] = $block;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$block]];
                    $lastToolResultIdx = count($out) - 1;
                }
                continue;
            }

            // ③ assistant 带 tool_calls → content 数组 [text?, tool_use…]
            $calls = $m['tool_calls'] ?? null;
            if ($role === 'assistant' && is_array($calls) && $calls !== []) {
                $blocks = [];
                if ($content !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $content];
                }
                foreach ($calls as $tc) {
                    if (!is_array($tc)) {
                        continue;
                    }
                    // 内部是 OpenAI 形状 {id,type:'function',function:{name,arguments}}；
                    // 也容忍"已经是扁平形状"的输入（存档兼容/测试便利）
                    $fn = is_array($tc['function'] ?? null) ? $tc['function'] : $tc;
                    $blocks[] = [
                        'type'  => 'tool_use',
                        'id'    => is_string($tc['id'] ?? null) ? $tc['id'] : '',
                        'name'  => is_string($fn['name'] ?? null) ? $fn['name'] : '',
                        // ⚠️ 必须是对象：见类注释④
                        'input' => self::decodeInput($fn['arguments'] ?? null),
                    ];
                }
                $out[] = ['role' => 'assistant', 'content' => $blocks];
                $lastToolResultIdx = null; // 断开了 tool_result 组的连续性
                continue;
            }

            // ④ 普通 user / assistant：content 是字符串时不带数组外壳（官方允许简写，
            //    也能让「纯文本对话」的 wire 更小更好读）
            //
            //    ⚠️ 但**空内容的 assistant 必须丢掉**：它是内部占位符（startRequest() 追加它，
            //    好让流式 delta 有地方落），不是真实对话内容。OpenAI 兼容端点对「尾部预填充
            //    assistant」是宽容的，而 Anthropic 的 text 块**最小长度是 1** —— 原样发过去
            //    会拿到 400。丢掉它也让请求正确地以 user 结尾（官方期望的"生成下一轮"形状）。
            //    （带 tool_calls 的空 assistant 不算占位符，上面 ③ 已处理。）
            if ($role === 'assistant' && $content === '') {
                $lastToolResultIdx = null;
                continue;
            }
            $out[] = ['role' => is_string($role) ? $role : 'user', 'content' => $content];
            $lastToolResultIdx = null;
        }

        return [implode("\n\n", $system), $out];
    }

    /**
     * 把内部累积的 `arguments`（JSON 串）解成 Anthropic 要求的**对象**。
     *
     * 三种输入都要能活：合法 JSON 对象 → 用它；`{}` → `(object)[]`（空对象）；
     * 非法/空串 → `(object)[]`（宁可发空参数让模型/用户看到"没参数"，
     * 也不要因为拼不出对象把整轮请求搞成 400）。
     */
    private static function decodeInput(mixed $arguments): object
    {
        if (is_string($arguments) && trim($arguments) !== '') {
            $decoded = json_decode($arguments, true);
            if (is_array($decoded)) {
                // json_decode('{}', true) 得 []，json_encode 会变成 [] —— 必须转对象。
                // 空数组也走这里（语义上它就是空参数对象）。
                return (object) $decoded;
            }
            if (is_object($decoded)) {
                return $decoded; // 理论上 assoc=true 时不会出现，防御
            }
        }
        return (object) [];
    }

    /**
     * 内部工具定义（OpenAI 形状）→ Anthropic 的 tools。
     *
     * 输入：`[{type:'function', function:{name, description, parameters}}]`
     * 输出：`[{name, description, input_schema}]`（**丢掉 `type:'function'` 外壳与 `function` 包裹层**）
     *
     * @param array<int,array<string,mixed>>|null $tools
     * @return list<array<string,mixed>>
     */
    public static function toTools(?array $tools): array
    {
        if ($tools === null || $tools === []) {
            return [];
        }
        $out = [];
        foreach ($tools as $t) {
            if (!is_array($t)) {
                continue;
            }
            $fn = is_array($t['function'] ?? null) ? $t['function'] : $t;
            $name = $fn['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue; // 无名工具发出去只会换来 400，丢掉
            }
            $schema = $fn['parameters'] ?? null;
            if (!is_array($schema)) {
                $schema = ['type' => 'object', 'properties' => (object) []];
            }
            $item = ['name' => $name];
            if (is_string($fn['description'] ?? null) && $fn['description'] !== '') {
                $item['description'] = $fn['description'];
            }
            $item['input_schema'] = $schema;
            $out[] = $item;
        }
        return $out;
    }
}
