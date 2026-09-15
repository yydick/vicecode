<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * 一个已解析的 Provider 配置（id + 已落入环境的 key/base_url + 模型列表 + 当前模型能力）。
 *
 * 与 `config/providers.php` 里的原始数组区分开：原始数组只有「去哪个环境变量读」，
 * 这里是**读完之后**的值。分层的意义是让「配置解析（含 env 读取）」可被单测替换，
 * 而调用方只认这个不可变对象。
 */
final class ProviderSpec
{
    // ── 模型能力标识（`config/providers.php` 的 capabilities / 模型能力列表里写这些串）──
    /** 函数调用（Agent loop 的 list_files / read_file 走 OpenAI tools 协议，依赖它） */
    public const TOOLS = 'tools';
    /** 推理 / 思维链模型 */
    public const REASONING = 'reasoning';
    /** 识图（可接收图片输入） */
    public const VISION = 'vision';
    /** 语音 */
    public const AUDIO = 'audio';

    /** 已知能力：用于 UI 翻译。配置里写别的串也**接受**（只是按原文显示），保证可前向扩展。 */
    public const KNOWN = [self::TOOLS, self::REASONING, self::VISION, self::AUDIO];

    /**
     * @param string[] $models
     * @param string|null $keyEnv 读到 key 的那个环境变量名。缺 key 时 UI 要提示「请设置 XXX」，
     *                            所以得一路带到 UI 层；反过来它**不是**密钥本身，暴露无害。
     * @param string[] $capabilities 当前**选中模型**的能力（provider 默认 ∩ 模型级声明，
     *                               解析规则见 ProviderRegistry；未声明时按历史行为 = ['tools']）
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly ?string $apiKey,
        public readonly array $models,
        public readonly string $model,
        public readonly ?string $keyEnv = null,
        public readonly array $capabilities = [],
    ) {
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== null && trim($this->apiKey) !== '';
    }

    /** 当前模型是否具备某项能力 */
    public function supports(string $cap): bool
    {
        return in_array($cap, $this->capabilities, true);
    }

    /** 当前模型是否支持函数调用 —— 决定请求里要不要带 OpenAI `tools`（见 ChatModel） */
    public function supportsTools(): bool
    {
        return $this->supports(self::TOOLS);
    }

    /** 补全 /chat/completions 端点（base_url 允许以 / 结尾） */
    public function chatUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/chat/completions';
    }

    /**
     * 模型白名单校验：模型列表最终是可配置的，但发给服务商之前必须确认它在列表里，
     * 否则手滑配错模型会拿到一个看不懂的 400。
     */
    public function supportsModel(string $model): bool
    {
        return in_array($model, $this->models, true);
    }
}
