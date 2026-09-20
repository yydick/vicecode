<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * 把一段对话历史**文本化**成给模型看的纯文本（上下文压缩的摘要请求用）。
 *
 * ## 为什么需要它（一个真 bug 的修法）
 * `ChatModel::beginCompact()` 原本把历史清空后直接起请求，而 `startRequest()` 只会追加一条
 * **空的 assistant 消息** —— 于是实际发出去的是
 * `{"messages":[{"role":"assistant","content":""}]}`：
 * 既没有旧历史、也没有"请总结"的指令。在真实端点上这等于让模型**续写一个空回复**，
 * 续写出来的东西会被当成摘要**覆盖掉整段真实历史**（静默的数据损失）。
 * 单测没暴露是因为 mock 端点的 MOCK_SUMMARY 无条件回固定文案、不看输入。
 *
 * ## 为什么单独一个类、且是纯静态函数
 * 从历史到文本的映射规则（哪个角色写成什么样、工具调用怎么表达）与压缩流程的生命周期无关，
 * 抽出来就能**直接单测**（`ChatModel` 的压缩路径要跑 curl 才能到，测起来很重）。
 * 另一个好处：Anthropic 与 OpenAI 两条协议共用同一份文本化结果 —— 它只是**给模型看的输入**，
 * 不是 wire 形状，所以不必按协议分叉。
 *
 * ## 输出形态
 * 每行一条消息，前缀标角色；工具调用与结果**合并成可读的引用关系**（而不是倾倒原始 JSON 结构），
 * 因为摘要模型需要理解"它查了什么、拿到了什么"，而不是需要重新解析一遍 tool_calls。
 * 单条内容过长时截断（避免摘要请求本身就撑爆上下文 —— 那样压缩就没意义了）。
 */
final class ConversationTranscript
{
    /** 单条消息进摘要请求的最大字符数（超出截断并标注）。默认给足够大但不至于失控。 */
    public const MAX_CHARS_PER_MESSAGE = 4000;

    /**
     * @param array<int,array<string,mixed>> $messages 本项目内部消息形状
     * @return string 纯文本（可能为空串 = 没有可总结的内容）
     */
    public static function render(array $messages, int $maxCharsPerMessage = self::MAX_CHARS_PER_MESSAGE): string
    {
        $limit = max(1, $maxCharsPerMessage);
        $lines = [];
        $index = 0;
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = is_string($m['role'] ?? null) ? $m['role'] : 'unknown';
            $content = $m['content'] ?? '';
            if (!is_string($content)) {
                $content = '';
            }

            // 工具调用与结果用独立行表达；文本部分照常。
            // 顺序刻意是「先文本、后调用」，与模型生成时的实际顺序一致（先说的话再说要干什么）。
            if ($role === 'assistant' && is_array($m['tool_calls'] ?? null) && $m['tool_calls'] !== []) {
                $lines[] = self::line($index++, 'Assistant', $content, $limit);
                foreach ($m['tool_calls'] as $tc) {
                    if (!is_array($tc)) {
                        continue;
                    }
                    $fn = is_array($tc['function'] ?? null) ? $tc['function'] : $tc;
                    $name = is_string($fn['name'] ?? null) ? $fn['name'] : '?';
                    $args = is_string($fn['arguments'] ?? null) ? $fn['arguments'] : '';
                    $lines[] = '  [tool call] ' . $name . '(' . self::truncate($args, $limit) . ')';
                }
                continue;
            }

            if ($role === 'tool') {
                $lines[] = '  [tool result] ' . self::truncate($content, $limit);
                continue;
            }

            $label = match ($role) {
                'user'      => 'User',
                'assistant' => 'Assistant',
                'system'    => 'System',
                default     => $role,
            };
            $lines[] = self::line($index++, $label, $content, $limit);
        }
        return implode("\n", $lines);
    }

    /** 拼一行（空内容也保留角色行 —— "这里有一轮"本身是摘要需要的信息） */
    private static function line(int $index, string $label, string $content, int $limit): string
    {
        $text = trim($content);
        if ($text === '') {
            return $label . ': (empty)';
        }
        return $label . ': ' . self::truncate($text, $limit);
    }

    /**
     * 按**字符**（不是字节）截断，避免把一个 UTF-8 字符劈成半个。
     * 超限时即使截断也要给出明确标注，否则摘要模型会以为内容就这么短。
     */
    private static function truncate(string $s, int $limit): string
    {
        if (mb_strlen($s) <= $limit) {
            return $s;
        }
        return mb_substr($s, 0, $limit) . '…(truncated)';
    }
}
