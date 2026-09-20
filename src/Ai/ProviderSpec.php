<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * 一个已解析的 Provider 配置（id + 已落入环境的 key/base_url + 模型列表 + 当前模型能力 + 协议）。
 *
 * 与 `config/providers.php` 里的原始数组区分开：原始数组只有「去哪个环境变量读」，
 * 这里是**读完之后**的值。分层的意义是让「配置解析（含 env 读取）」可被单测替换，
 * 而调用方只认这个不可变对象。
 *
 * ⚠️ 协议（`protocol`）是 **provider 的属性，不是调用时的参数**：同一个 provider 的 key、
 * 端点、模型列表、折扣时段都跟着它走，协议单独用参数传会散落成一堆形参。
 * `ChatModel` 据此选择 `ProviderInterface` 实现与 SSE 解析器（见两处 `PROTOCOL_*` 分支）。
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

    // ── 协议标识（provider 的 `protocol` 字段）────────────────────────────
    /** OpenAI 兼容协议（`POST <base>/chat/completions`）。**不写 protocol 时的默认值**，老配置零迁移。 */
    public const PROTOCOL_OPENAI = 'openai';

    /** Anthropic Messages API（`POST <base>/messages`）。 */
    public const PROTOCOL_ANTHROPIC = 'anthropic';

    /**
     * Anthropic 的 `max_tokens` 是**必填**参数（缺了直接 400），且各家模型上限不同，
     * 故给一个宽松但不至于失控的默认值；provider 级 `max_tokens` 可覆盖。
     * 注意这个默认只对**必填**的协议有意义（OpenAI 兼容侧不发这个字段，行为不变）。
     */
    public const DEFAULT_MAX_TOKENS = 4096;

    /**
     * @param string[] $models
     * @param string|null $keyEnv 读到 key 的那个环境变量名。缺 key 时 UI 要提示「请设置 XXX」，
     *                            所以得一路带到 UI 层；反过来它**不是**密钥本身，暴露无害。
     * @param string[] $capabilities 当前**选中模型**的能力（provider 默认 ∩ 模型级声明，
     *                               解析规则见 ProviderRegistry；未声明时按历史行为 = ['tools']）
     * @param string $protocol 协议标识（`PROTOCOL_*`）。未知串由 ProviderRegistry 归一为 `openai`，
     *                         所以这里只会是已知值。
     * @param int $maxTokens 输出上限（Anthropic 必填；OpenAI 兼容侧不使用）
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
        public readonly string $protocol = self::PROTOCOL_OPENAI,
        public readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
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

    /** 当前模型是否支持函数调用 —— 决定请求里要不要带 tools（见 ChatModel） */
    public function supportsTools(): bool
    {
        return $this->supports(self::TOOLS);
    }

    /** 本 provider 走 Anthropic Messages API */
    public function isAnthropic(): bool
    {
        return $this->protocol === self::PROTOCOL_ANTHROPIC;
    }

    /**
     * 补全端点（base_url 允许以 / 结尾）。
     *
     * 两条协议的路径**刻意不同**，这不是笔误：
     *  - OpenAI 兼容：`<base>/chat/completions`（base_url 惯例含 `/v1`，如 `https://api.openai.com/v1`）；
     *  - Anthropic：`<base>/messages`（base_url 惯例是站点根 `https://api.anthropic.com`，
     *    `/v1` 由这里补）。
     * 把 `/v1` 的位置交给协议而不是用户，是因为两条协议的 base_url 惯例本就不同
     * （用户随手把 Anthropic 的 base_url 写成带 `/v1` 也不会出错，见下）。
     */
    public function chatUrl(): string
    {
        $base = rtrim($this->baseUrl, '/');
        if ($this->isAnthropic()) {
            // 容错：用户按 OpenAI 的惯例把 /v1 写进了 base_url 时不再重复拼一层
            return str_ends_with($base, '/v1') ? $base . '/messages' : $base . '/v1/messages';
        }
        return $base . '/chat/completions';
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
