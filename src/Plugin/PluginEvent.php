<?php
declare(strict_types=1);

namespace App\Plugin;

/**
 * 应用生命周期事件（V1.1）。由 `App::emitPluginEvent()` 广播给实现了可选方法
 * `onEvent(PluginEvent $e): void` 的插件。
 *
 * 已知事件名（详见 docs/plugins.md §3.7）：
 *   app.ready / file.opened / file.saved / file.closed / buffer.switched /
 *   focus.changed / terminal.output / config.reloaded
 *
 * 约定：
 *  - 单个插件抛异常不影响其它插件与主流程（与 V1 的加载容错同套哲学）；
 *  - 事件回调里再触发事件会被丢弃（App 有防重入），避免递归爆栈；
 *  - `terminal.output` 给的是**原始字节**（可能含 ANSI 与 OSC 7 的 cwd 上报），
 *    且不节流，插件需自行降频。
 *
 * ⚠️ 新字段一律加在尾部并给默认值，保证老插件不被破坏。
 */
final class PluginEvent
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public string $name,
        public array $payload = [],
        public ?\App\App $app = null,
    ) {
    }
}
