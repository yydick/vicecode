<?php
declare(strict_types=1);

namespace App\Plugin;

use App\App;

/**
 * 插件契约（V1：状态栏段扩展）。
 *
 * 插件以「目录 + plugin.json」形式存在于 plugins/<id>/，由核心在运行时
 * 动态 require 入口文件并实例化（见 PluginLoader）——**不走 composer autoload**，
 * 以兼容产品版（AOT 编译的核心二进制不编入插件，插件由内嵌 Zend 运行时解释）。
 *
 * 插件文件本身写普通 PHP 即可，不受 AOT 编码规范约束（全局声明 / 禁 Closure::bind /
 * 文件级禁 require 等只管「编入二进制的核心源码」，见 docs/AOT_INCOMPATIBILITY_REPORT.md
 * 与 docs/plugins.md §6「加载机制与容错」）。
 *
 * 可选配置能力（VSCode 式「插件声明默认 + 用户配置覆盖」）：
 *  - configDefaults(): array —— 返回本插件默认配置（键 => 默认值）；
 *  - configure(array $config): void —— 接收「默认 ∩ 用户覆盖」后的最终配置。
 * 核心用 method_exists 探测，未实现则跳过配置注入；不强制所有插件实现。
 * 用户配置位于插件专用文件 ~/.vicecode.plugins.json 的 <id> 段（由 ConfigStore 读写，
 * 与应用自身的 ~/.vicerc 完全分离；旧版写在 ~/.vicerc 的 plugins 段仍一次性回退兼容）。
 *
 * 可选面板能力（V1.1，均为可选）：
 *  - panels(): array —— 返回本插件提供的面板列表（list<PluginPanel>）。
 *    核心在装载期一次性收集进「插件面板」浮层（PluginPanelHost），内部用 tab 切换，
 *    不改动六面板布局（见 docs/plugins.md §3.10）。未实现则无面板。
 *
 * 可选补全能力（V1.2，可选）：
 *  - completions(string $context, string $text, int $cursor, string $prefix): array
 *    —— 返回本插件在该输入上下文下的补全候选（list<CompletionItem>），未实现则无候选。
 *    上下文取值：`ai_input` / `editor` / `search` / `commit`（见 App\Core\CompletionState）。
 *
 *    ⚠️ **按键由核心独占，插件只提供数据**：Tab/Shift+Tab 的语义是核心定的
 *    （有候选 → Tab 接受、Shift+Tab 上一个候选；无候选 → 缩进），插件拿不到也不该拿按键。
 *    这样插件不可能把全局键玩坏，也不必关心候选怎么画 —— 与既有能力（声明数据 + 核心渲染）一致。
 *
 *    ⚠️ **同步调用**：核心在**每次按键之后**重算一次候选，实现必须快。
 *    将来若要接 AI 自动补全这类慢查询，需要给 provider 补「pending/流式」语义（尚未设计）。
 *    本能力也是「插件将来接 AI 补全与提示」的落点（见 docs/plugins.md §3.12）。
 */
interface PluginInterface
{
    /** 稳定唯一 id（应与 plugin.json 的 id 对应） */
    public function id(): string;

    /**
     * 返回本帧要注入状态栏的段；空数组表示当前无内容。
     * 段与系统段走同一套「按优先级丢弃 + 按 order 摆放」逻辑。
     * @return list<StatusSegment>
     */
    public function statusSegments(App $app): array;

    /**
     * 是否需要周期重绘以更新自身内容（秒），null=不需要。
     * 如时钟插件返回 1，主循环在 idle 时按最小间隔触发重绘，使其持续走动。
     */
    public function tickInterval(): ?int;
}
