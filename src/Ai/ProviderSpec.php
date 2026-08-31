<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * 一个已解析的 Provider 配置（id + 已落入环境的 key/base_url + 模型列表）。
 *
 * 与 `config/providers.php` 里的原始数组区分开：原始数组只有「去哪个环境变量读」，
 * 这里是**读完之后**的值。分层的意义是让「配置解析（含 env 读取）」可被单测替换，
 * 而调用方只认这个不可变对象。
 */
final class ProviderSpec
{
    /**
     * @param string[] $models
     * @param string|null $keyEnv 读到 key 的那个环境变量名。缺 key 时 UI 要提示「请设置 XXX」，
     *                            所以得一路带到 UI 层；反过来它**不是**密钥本身，暴露无害。
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly ?string $apiKey,
        public readonly array $models,
        public readonly string $model,
        public readonly ?string $keyEnv = null,
    ) {
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== null && trim($this->apiKey) !== '';
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
