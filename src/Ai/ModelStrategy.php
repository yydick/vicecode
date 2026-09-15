<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * 一条**模型策略**（用户级配置 `@strategies` 段里的一项）：把「这次要干什么」映射到一个具体模型。
 *
 * 它存在的意义不只是"给 provider/model 起个好名字"——真正的作用是**带上能力要求**。
 * 本项目里这件事很要紧：Agent loop 依赖 OpenAI `tools` 协议，而 `ChatModel` 是按
 * `ProviderSpec::supportsTools()` 决定要不要把 tools 发出去的（不发**不会报错**）。
 * 于是「降级到便宜模型」如果撞上一个没声明 `tools` 的模型，表现是**工具静默失效**，
 * 用户只会觉得"Agent 怎么不动了"。策略因此可以声明 `requires: ['tools']`，
 * 由 `ChatModel::applyStrategy()` 在切换时**拒绝**，而不是让它悄悄坏掉。
 *
 * 与 `ProviderSpec` 的关系：策略只是"指向"，真正解析出的 base_url/key/能力仍然来自
 * `ProviderSpec`（策略不复制这些字段，避免两处真相）。
 *
 * **按任务类型自动选档**：策略可以声明 `kinds`（如 `['unittest', 'refactor']`），
 * 于是「任务类型 → 用哪一档」这件事写在策略自己身上，而不是另开一张映射表。
 * 任务类型只有两个来源（都零误判）：**快捷动作**（explain/comment/refactor/unittest）与
 * **输入框里的指令前缀**（`/plan …`）。不猜、不做关键词启发式——猜错会静默降级。
 */
final class ModelStrategy
{
    /**
     * @param string $name       策略标识（配置里的键，如 `plan`）
     * @param string $label      展示名（缺省用 name）
     * @param string $providerId 目标 provider id（必须已在 provider 配置里登记）
     * @param string|null $model 目标模型；null = 用该 provider 的默认模型
     * @param string[] $requires 能力要求（需被目标模型满足，否则拒绝应用）
     * @param string[] $kinds    任务类型；请求带这些 kind 时**自动**用本档（人工钉住时不生效）
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $providerId,
        public readonly ?string $model = null,
        public readonly array $requires = [],
        public readonly array $kinds = [],
    ) {
    }
}
