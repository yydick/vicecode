<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * OpenAI 兼容协议的 Provider（M5 R1/R2）。
 *
 * 传输骨架（curl 子进程 / 非阻塞管道 / 临时文件的**生命周期与安全约束** / `-D` 的用处）
 * 见 `CurlSseTransport` 的类注释 —— 那些结论是实测出来的，两条协议共用一套，不重复实现。
 *
 * ## 本类只管一件事：把内部消息形状拼成 OpenAI 兼容的**请求体**
 * 内部表示用的就是 OpenAI 的语义（`role` / `content` / `tool_calls` / `tool_call_id`），
 * 所以这里**不做转换**：剥掉私有 `meta` 键后原样透传即可，`tools` 也是原样（本就是 OpenAI 形状）。
 *
 * ⚠️ 本类只管 OpenAI 兼容协议。Anthropic Messages API 的请求体形状（`max_tokens` 必填、
 * `system` 顶层参数）、消息形状（`tool_use`/`tool_result` 内容块）与流事件名都不同，
 * 由 `AnthropicProvider` 单独实现，两者共用 `ProviderInterface` 契约。
 */
final class OpenAiCompatProvider implements ProviderInterface
{
    use CurlSseTransport;

    /**
     * 构造可直接交给 `CommandRunner::start()` 的 shell 命令。
     *
     * @param array<int,array<string,mixed>> $messages wire 形状（可含本项目私有 `meta` 键，发送前剥掉）
     * @param array<int,array<string,mixed>>|null $tools OpenAI tools 定义（Agent 模式），null=不带
     */
    public function buildCommand(ProviderSpec $spec, array $messages, int $timeout = self::DEFAULT_TIMEOUT, ?array $tools = null): string
    {
        $payload = [
            'model'    => $spec->model,
            'messages' => self::stripMeta($messages),
            'stream'   => true,
        ];
        if ($tools !== null && $tools !== []) {
            $payload['tools'] = $tools;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $body = '{"model":"","messages":[],"stream":true}';
        }

        // key 只进这个临时文件，绝不进 argv（`ps` 对同机其他用户可见，见 trait 注释）
        $headers = "Authorization: Bearer " . ($spec->apiKey ?? '') . "\n"
            . "Content-Type: application/json\n"
            . "Accept: text/event-stream\n";

        return $this->curlc($spec->chatUrl(), $body, $headers, $timeout);
    }
}
