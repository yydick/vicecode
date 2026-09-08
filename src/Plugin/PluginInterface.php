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
 * 文件级禁 require 等只管「编入二进制的核心源码」，见 project_plugin.md）。
 *
 * 可选配置能力（VSCode 式「插件声明默认 + 用户 ~/.vicerc 覆盖」）：
 *  - configDefaults(): array —— 返回本插件默认配置（键 => 默认值）；
 *  - configure(array $config): void —— 接收「默认 ∩ 用户覆盖」后的最终配置。
 * 核心用 method_exists 探测，未实现则跳过配置注入；不强制所有插件实现。
 * 用户配置位于 ~/.vicerc 的 plugins.<id> 段（由 ConfigStore 读写，会被应用退出时的
 * saveConfig() 合并保留，手改不被冲掉）。
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
