<?php
declare(strict_types=1);

namespace App\Plugin;

/**
 * 插件命令值对象（V1.1）。一条命令可以被三处触发，最终都汇到
 * `App::runPluginCommand()`：菜单项、快捷键、状态栏段点击。
 *
 *  - id：**插件内局部唯一**。核心以 "<插件id>.<局部id>" 完全限定注册，天然免撞；
 *  - title：菜单显示文案。**不过 i18n**（插件作者自己的文案，自己负责语言）；
 *  - shortcut：可选。只支持 `Ctrl+字母` 与 `F1`–`F12` 两种写法，其余判为不支持；
 *    与系统键冲突或被别的插件先占时，只降级为「仅菜单触发」，命令本身仍可用；
 *  - order：菜单内排序（升序），与 StatusSegment::$order 同语义。
 *
 * 见 docs/plugins.md §3.6。
 */
final class PluginCommand
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $shortcut = null,
        public int $order = 100,
    ) {
    }
}
