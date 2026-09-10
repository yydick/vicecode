<?php
declare(strict_types=1);

namespace App\Plugin;

use App\App;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Tui\Widget\Widget;

/**
 * 插件面板值对象（自定义面板 V1.1）。一个插件可声明多个面板，核心把它们收进
 * 一个可开合浮层（PluginPanelHost），内部用 tab 切换。
 *
 * 与 PluginCommand / StatusSegment 同为「插件向核心声明能力的纯数据对象」：
 * 插件在 PluginInterface::panels() 里返回 PluginPanel 列表即可，无需继承任何基类。
 *
 *  - id：插件内局部唯一（核心以 "<插件id>.<局部id>" 完全限定，天然免撞）；
 *  - title：tab 标题（不过 i18n，由插件作者自行负责语言）；
 *  - render：内容渲染闭包，给定内部可用区域宽高，返回一个 php-tui Widget；
 *  - onChar / onKey：可选交互回调。浮层打开时，若当前 tab 的面板提供了对应回调，
 *    按键会先交给它处理（返回 true 即已消费），否则由宿主自己处理（Tab 切 tab / Esc 关闭）。
 *
 * 见 docs/plugins.md §3.10。
 */
final class PluginPanel
{
    /**
     * @param \Closure(App $app, int $width, int $height): Widget $render
     * @param (\Closure(CharKeyEvent): bool)|null $onChar
     * @param (\Closure(CodedKeyEvent): bool)|null $onKey
     */
    public function __construct(
        public string $id,
        public string $title,
        public \Closure $render,
        public ?\Closure $onChar = null,
        public ?\Closure $onKey = null,
    ) {
    }
}
